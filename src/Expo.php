<?php

declare(strict_types=1);

namespace Expo\Push;

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\MessageTooLargeException;
use Expo\Push\Execution\ConcurrentDispatcher;
use Expo\Push\Execution\Dispatcher;
use Expo\Push\Execution\ReceiptOperation;
use Expo\Push\Execution\SendOperation;
use Expo\Push\Execution\SequentialDispatcher;
use Expo\Push\Http\ConcurrentHttpClient;
use Expo\Push\Http\CurlHttpClient;
use Expo\Push\Http\HttpClient;
use Expo\Push\Http\RequestFactory;
use Expo\Push\Observability\NullObserver;
use Expo\Push\Observability\Observer;
use Expo\Push\Observability\SafeObserver;
use Expo\Push\Plan\Planner;
use Expo\Push\RateLimit\NullRateLimiter;
use Expo\Push\RateLimit\RateLimiter;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Result\SendResult;
use Expo\Push\Retry\DeliveryRetryPolicy;
use Expo\Push\Retry\RetryEngine;
use Expo\Push\Retry\RetryPolicy;
use Expo\Push\Support\Clock;
use Expo\Push\Support\FullJitter;
use Expo\Push\Support\Jitter;
use Expo\Push\Support\Sleeper;
use Expo\Push\Support\SystemClock;
use Expo\Push\Support\SystemSleeper;
use Generator;
use stdClass;

/**
 * The client of the Expo push notification service.
 *
 * ```php
 * $expo = new Expo();
 * $result = $expo->notify($token, 'Hello', 'World');
 *
 * foreach ($result->accepted() as $outcome) {
 *     $store->save($outcome->receiptId());
 * }
 * ```
 *
 * `send()` and `receipts()` return a structured result. They do not raise for an
 * operational failure, so a later chunk can never take the earlier answers with
 * it. Invalid input and invalid settings still raise, before any request.
 *
 * Three milestones, and only the first two exist in the API:
 *
 * 1. Expo accepted the notification. That is a ticket with the status `ok`.
 * 2. Apple or Google took the notification. That is a receipt with `ok`.
 * 3. The device showed the notification. Nothing reports this.
 */
final readonly class Expo
{
    public const string VERSION = '2.0.0';

    /**
     * The largest number of notifications in one send request, from the Expo API
     * documentation.
     */
    public const int MESSAGE_CHUNK_LIMIT = 100;

    /**
     * The largest number of receipt IDs in one lookup request, from the Expo API
     * documentation.
     */
    public const int RECEIPT_CHUNK_LIMIT = 1_000;

    /**
     * The largest concurrency that this SDK supports. It is a choice of this SDK,
     * not a limit of the Expo service.
     */
    public const int MAX_CONCURRENCY = 6;

    private HttpClient $httpClient;

    private RetryPolicy $retryPolicy;

    private RetryEngine $engine;

    private RateLimiter $rateLimiter;

    private string $bucket;

    private Clock $clock;

    private Sleeper $sleeper;

    private SafeObserver $observer;

    private RequestFactory $requests;

    /**
     * @param string|null       $accessToken          the Expo access token, needed when the project uses enhanced security
     * @param HttpClient|null   $httpClient           your own transport. The default one uses cURL
     * @param RetryPolicy|null  $retryPolicy          the retry rules. The default is `DeliveryRetryPolicy`
     * @param int               $concurrency          requests in flight, 1 to 6. The default is 1
     * @param RateLimiter|null  $rateLimiter          an optional notification limiter. Off by default
     * @param string|null       $rateLimitBucket      the project key of the limiter. Required with a limiter
     * @param Observer|null     $observer             an optional watcher for the lifecycle events
     * @param bool              $compress             gzip for a body above 1024 bytes, when zlib is available
     * @param bool              $validateSize         checks the 4096 byte estimate before the send
     * @param bool              $continueAfterFailure activates later chunks after a chunk failed
     * @param int|null          $operationDeadlineMs  bounds the whole operation. Off by default
     * @param bool              $enforceHardDeadline  demands a transport that can stop a running request
     * @param int               $sendChunkSize        notifications in one send request, at most 100
     * @param int               $receiptChunkSize     IDs in one lookup request, at most 1000
     * @param string            $baseUrl              the API host. Change it only for a test server
     * @param Clock|null        $clock                the clock. Inject a `FrozenClock` in a test
     * @param Sleeper|null      $sleeper              the sleeper. Inject a `RecordingSleeper` in a test
     * @param Jitter|null       $jitter               the backoff jitter. Inject a `FixedJitter` in a test
     *
     * @throws InvalidConfigurationException when a setting cannot work
     */
    public function __construct(
        private ?string $accessToken = null,
        ?HttpClient $httpClient = null,
        ?RetryPolicy $retryPolicy = null,
        private int $concurrency = 1,
        ?RateLimiter $rateLimiter = null,
        ?string $rateLimitBucket = null,
        ?Observer $observer = null,
        private bool $compress = true,
        private bool $validateSize = true,
        private bool $continueAfterFailure = false,
        private ?int $operationDeadlineMs = null,
        bool $enforceHardDeadline = false,
        private int $sendChunkSize = self::MESSAGE_CHUNK_LIMIT,
        private int $receiptChunkSize = self::RECEIPT_CHUNK_LIMIT,
        private string $baseUrl = 'https://exp.host',
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
        ?Jitter $jitter = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->retryPolicy = $retryPolicy ?? new DeliveryRetryPolicy();
        $this->rateLimiter = $rateLimiter ?? new NullRateLimiter();
        $this->clock = $clock ?? new SystemClock();
        $this->sleeper = $sleeper ?? new SystemSleeper();
        $this->observer = new SafeObserver($observer ?? new NullObserver());
        $this->engine = new RetryEngine($this->retryPolicy, $jitter ?? new FullJitter());

        $capabilities = $this->httpClient->capabilities();

        if ($concurrency < 1 || $concurrency > self::MAX_CONCURRENCY) {
            throw new InvalidConfigurationException(sprintf(
                'The concurrency must be between 1 and %d. It is %d.',
                self::MAX_CONCURRENCY,
                $concurrency
            ));
        }

        if ($concurrency > $capabilities->maxConcurrency) {
            throw new InvalidConfigurationException(sprintf(
                'The transport %s runs at most %d request(s) at one time, and the concurrency is %d.',
                $this->httpClient::class,
                $capabilities->maxConcurrency,
                $concurrency
            ));
        }

        if ($concurrency > 1 && !$this->httpClient instanceof ConcurrentHttpClient) {
            throw new InvalidConfigurationException(sprintf(
                'The transport %s does not implement ConcurrentHttpClient, so it cannot run %d requests at one time.',
                $this->httpClient::class,
                $concurrency
            ));
        }

        if ($enforceHardDeadline && !$capabilities->canEnforceHardDeadline) {
            throw new InvalidConfigurationException(sprintf(
                'The transport %s cannot stop a request that is already running, so it cannot enforce a hard '
                . 'deadline. Set the timeout on your own client, and leave enforceHardDeadline off.',
                $this->httpClient::class
            ));
        }

        if ($enforceHardDeadline && $operationDeadlineMs === null) {
            throw new InvalidConfigurationException(
                'enforceHardDeadline needs an operationDeadlineMs value.'
            );
        }

        if ($operationDeadlineMs !== null && $operationDeadlineMs < 1) {
            throw new InvalidConfigurationException('The operation deadline must be 1 millisecond or more.');
        }

        if ($sendChunkSize < 1 || $sendChunkSize > self::MESSAGE_CHUNK_LIMIT) {
            throw new InvalidConfigurationException(sprintf(
                'The send chunk size must be between 1 and %d. Expo accepts no more.',
                self::MESSAGE_CHUNK_LIMIT
            ));
        }

        if ($receiptChunkSize < 1 || $receiptChunkSize > self::RECEIPT_CHUNK_LIMIT) {
            throw new InvalidConfigurationException(sprintf(
                'The receipt chunk size must be between 1 and %d. Expo accepts no more.',
                self::RECEIPT_CHUNK_LIMIT
            ));
        }

        if ($rateLimiter !== null && ($rateLimitBucket === null || trim($rateLimitBucket) === '')) {
            throw new InvalidConfigurationException(
                'A rate limiter needs a rateLimitBucket. Use the Expo project as the key. The SDK never reads a '
                . 'project out of a push token.'
            );
        }

        $capacity = $this->rateLimiter->capacity();

        if ($capacity !== null && $sendChunkSize > $capacity) {
            throw new InvalidConfigurationException(sprintf(
                'A send chunk of %d notifications can never fit the limiter capacity of %d. Lower the chunk size '
                . 'or raise the limit.',
                $sendChunkSize,
                $capacity
            ));
        }

        $this->bucket = $rateLimitBucket ?? 'default';

        $settings = $this->retryPolicy->settings();

        $this->requests = new RequestFactory(
            baseUrl: $baseUrl,
            accessToken: $accessToken,
            userAgent: 'expo-sdk-php/' . self::VERSION,
            compress: $compress,
            connectTimeoutMs: $settings->connectTimeoutMs,
            requestTimeoutMs: $settings->requestTimeoutMs,
        );
    }

    /**
     * Sends one message, or many messages, and returns one outcome for each
     * message and device pair.
     *
     * The SDK normalizes and checks the whole input before the first request, so
     * an invalid message in the last chunk raises before the first chunk goes out.
     *
     * @param PushMessage|iterable<array-key, PushMessage> $messages
     *
     * @throws InvalidMessageException  when an item is not a message, a reference repeats, or a value is not JSON
     * @throws MessageTooLargeException when a message is above 4096 bytes and the size check is on
     */
    #[\NoDiscard('Read the result. It says what Expo accepted and what stays unknown.')]
    public function send(PushMessage|iterable $messages): SendResult
    {
        $plan = Planner::plan($messages, $this->validateSize, PushMessage::MAX_SIZE);

        if ($plan->isEmpty()) {
            return new SendResult();
        }

        $operation = new SendOperation(
            plan: $plan,
            chunks: $plan->chunks($this->sendChunkSize),
            requests: $this->requests,
            engine: $this->engine,
            dispatcher: $this->dispatcher(),
            clock: $this->clock,
            sleeper: $this->sleeper,
            limiter: $this->rateLimiter,
            bucket: $this->bucket,
            observer: $this->observer,
            operationId: self::operationId(),
            continueAfterFailure: $this->continueAfterFailure,
            operationDeadlineMs: $this->operationDeadlineMs,
        );

        return $operation->run();
    }

    /**
     * Sends a simple notification to one or more devices.
     *
     * @param PushToken|string|iterable<PushToken|string> $to
     * @param array<string, mixed>|stdClass|null         $data
     *
     * @throws InvalidMessageException
     * @throws MessageTooLargeException
     */
    #[\NoDiscard('Read the result. It says what Expo accepted and what stays unknown.')]
    public function notify(
        PushToken|string|iterable $to,
        string $title,
        ?string $body = null,
        array|stdClass|null $data = null,
        ?string $reference = null,
    ): SendResult {
        return $this->send(new PushMessage(
            to: $to,
            title: $title,
            body: $body,
            data: $data === [] ? null : $data,
            reference: $reference,
        ));
    }

    /**
     * Reads the handoff result of earlier tickets.
     *
     * Read the receipts some minutes after the send. Expo keeps a receipt for a
     * limited time only, so a missing receipt can also mean an expired one.
     *
     * Accepts a `SendResult`, a `TicketCollection`, single tickets, receipt
     * references, raw ID strings, or any iterable of those.
     *
     * @param SendResult|TicketCollection|PushTicket|ReceiptReference|string|iterable<mixed> $tickets
     *
     * @throws InvalidMessageException when an item is not usable, or two items give one ID two devices
     */
    #[\NoDiscard('Read the result. It says which IDs came back and which did not.')]
    public function receipts(
        SendResult|TicketCollection|PushTicket|ReceiptReference|string|iterable $tickets,
    ): ReceiptResult {
        $plan = Planner::planReceipts($tickets);

        if ($plan->isEmpty()) {
            return new ReceiptResult();
        }

        $operation = new ReceiptOperation(
            plan: $plan,
            chunks: $plan->chunks($this->receiptChunkSize),
            requests: $this->requests,
            engine: $this->engine,
            dispatcher: $this->dispatcher(),
            clock: $this->clock,
            sleeper: $this->sleeper,
            limiter: $this->rateLimiter,
            bucket: $this->bucket,
            observer: $this->observer,
            operationId: self::operationId(),
            continueAfterFailure: $this->continueAfterFailure,
            operationDeadlineMs: $this->operationDeadlineMs,
        );

        return $operation->run();
    }

    /**
     * Groups messages into chunks that fit one request.
     *
     * A message with more devices than the limit becomes several messages. Put
     * one chunk on a queue for each job, and store each message with
     * `PushMessage::toStorageArray()`.
     *
     * This helper does no whole operation check. A broken message in a later
     * chunk shows up only when that chunk runs. `send()` checks everything first.
     *
     * @param PushMessage|iterable<array-key, PushMessage> $messages
     *
     * @return list<list<PushMessage>>
     */
    #[\NoDiscard]
    public static function chunk(PushMessage|iterable $messages, int $limit = self::MESSAGE_CHUNK_LIMIT): array
    {
        return iterator_to_array(Planner::lazyChunks($messages, $limit), false);
    }

    /**
     * The same split as `chunk()`, one chunk at a time.
     *
     * Use it for an input that does not fit in memory. The generator reads your
     * input as it goes, so nothing holds the whole operation.
     *
     * @param PushMessage|iterable<array-key, PushMessage> $messages
     *
     * @return Generator<int, list<PushMessage>>
     */
    #[\NoDiscard]
    public static function lazyChunks(
        PushMessage|iterable $messages,
        int $limit = self::MESSAGE_CHUNK_LIMIT,
    ): Generator {
        return Planner::lazyChunks($messages, $limit);
    }

    /**
     * True when the value looks like an Expo push token.
     *
     * A valid shape says nothing about registration.
     */
    public static function isExpoPushToken(string $value): bool
    {
        return PushToken::isValid($value);
    }

    private function dispatcher(): Dispatcher
    {
        if ($this->concurrency > 1 && $this->httpClient instanceof ConcurrentHttpClient) {
            return new ConcurrentDispatcher($this->httpClient, $this->concurrency);
        }

        return new SequentialDispatcher($this->httpClient);
    }

    private static function operationId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
