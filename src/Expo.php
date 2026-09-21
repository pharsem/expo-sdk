<?php

declare(strict_types=1);

namespace Expo\Push;

use Expo\Push\Exception\ExpoApiException;
use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\MessageTooLargeException;
use Expo\Push\Exception\TransportException;
use Expo\Push\Http\CurlHttpClient;
use Expo\Push\Http\HttpClient;
use Expo\Push\Http\HttpResponse;

/**
 * The client of the Expo push notification service.
 *
 * ```php
 * $expo = new Expo();
 * $tickets = $expo->notify($token, 'Hello', 'World');
 * ```
 *
 * The client splits large sends into chunks, retries a rate limited request, and
 * maps every ticket and every receipt back to the device token.
 */
final readonly class Expo
{
    public const string VERSION = '1.0.0';

    /**
     * The largest number of notifications in one send request.
     */
    public const int MESSAGE_CHUNK_LIMIT = 100;

    /**
     * The largest number of receipt IDs in one receipt request.
     */
    public const int RECEIPT_CHUNK_LIMIT = 300;

    private const string SEND_PATH = '/--/api/v2/push/send';

    private const string RECEIPTS_PATH = '/--/api/v2/push/getReceipts';

    private const int GZIP_THRESHOLD = 1024;

    /**
     * The SDK never waits longer than this between two tries.
     */
    private const int MAX_RETRY_DELAY_MS = 60_000;

    private HttpClient $httpClient;

    /**
     * @param string|null     $accessToken  the Expo access token, needed when the project uses enhanced security
     * @param HttpClient|null $httpClient   your own HTTP client. The default one uses cURL
     * @param int             $maxRetries   the number of retries after a 429 or a 5xx answer
     * @param int             $retryDelayMs the first backoff delay in milliseconds. It doubles for each retry
     * @param bool            $compress     compresses a body above 1024 bytes with gzip
     * @param bool            $validateSize checks the 4096 byte limit before the send
     * @param string          $baseUrl      the API host. Change it only for a test server
     */
    public function __construct(
        private ?string $accessToken = null,
        ?HttpClient $httpClient = null,
        private int $maxRetries = 2,
        private int $retryDelayMs = 1000,
        private bool $compress = true,
        private bool $validateSize = true,
        private string $baseUrl = 'https://exp.host',
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * Sends one message, or many messages, and returns one ticket for each device.
     *
     * The client sends as many requests as it needs. The tickets come back in the
     * order of the devices.
     *
     * @param PushMessage|iterable<PushMessage> $messages
     *
     * @throws MessageTooLargeException when a message is above 4096 bytes
     * @throws ExpoApiException         when Expo rejects the whole request
     * @throws TransportException       when the SDK cannot reach Expo
     */
    #[\NoDiscard('Read the tickets. A device can fail while the request succeeds.')]
    public function send(PushMessage|iterable $messages): TicketCollection
    {
        $tickets = new TicketCollection();

        foreach (self::chunk($messages) as $chunk) {
            $tickets = $tickets->merge($this->sendChunk($chunk));
        }

        return $tickets;
    }

    /**
     * Sends a simple notification to one or more devices.
     *
     * @param PushToken|string|iterable<PushToken|string> $to
     * @param array<string, mixed>                        $data
     *
     * @throws MessageTooLargeException when the message is above 4096 bytes
     * @throws ExpoApiException         when Expo rejects the whole request
     * @throws TransportException       when the SDK cannot reach Expo
     */
    #[\NoDiscard('Read the tickets. A device can fail while the request succeeds.')]
    public function notify(
        PushToken|string|iterable $to,
        string $title,
        ?string $body = null,
        array $data = [],
    ): TicketCollection {
        return $this->send(new PushMessage(
            to: $to,
            title: $title,
            body: $body,
            data: $data === [] ? null : $data,
        ));
    }

    /**
     * Reads the delivery result of earlier tickets.
     *
     * Wait about 15 minutes after the send. Expo keeps a receipt for 24 hours.
     * Pass the tickets to get the device token on each receipt.
     *
     * @param TicketCollection|PushTicket|string|iterable<PushTicket|string> $tickets
     *
     * @throws ExpoApiException   when Expo rejects the whole request
     * @throws TransportException when the SDK cannot reach Expo
     */
    #[\NoDiscard('Read the receipts. They hold the delivery result.')]
    public function receipts(TicketCollection|PushTicket|string|iterable $tickets): ReceiptCollection
    {
        $tokensById = [];
        $ids = [];

        foreach (self::toTicketList($tickets) as $ticket) {
            if (is_string($ticket)) {
                $ids[$ticket] = true;

                continue;
            }

            if ($ticket->id === null) {
                continue;
            }

            $ids[$ticket->id] = true;

            if ($ticket->token !== null) {
                $tokensById[$ticket->id] = $ticket->token;
            }
        }

        $ids = array_keys($ids);
        $receipts = new ReceiptCollection();

        foreach (array_chunk($ids, self::RECEIPT_CHUNK_LIMIT) as $chunk) {
            $receipts = $receipts->merge($this->receiptChunk($chunk, $tokensById));
        }

        return $receipts;
    }

    /**
     * Groups messages into chunks that fit one request.
     *
     * A message with more devices than the limit becomes several messages. Use this
     * method to put one chunk on a queue for each job.
     *
     * @param PushMessage|iterable<PushMessage> $messages
     *
     * @return list<list<PushMessage>>
     */
    #[\NoDiscard]
    public static function chunk(PushMessage|iterable $messages, int $limit = self::MESSAGE_CHUNK_LIMIT): array
    {
        if ($limit < 1) {
            throw new InvalidMessageException('The chunk limit must be one or more.');
        }

        $chunks = [];
        $current = [];
        $room = $limit;

        foreach (self::toMessageList($messages) as $message) {
            $tokens = $message->to;
            $total = count($tokens);
            $offset = 0;

            while ($offset < $total) {
                if ($room === 0) {
                    $chunks[] = $current;
                    $current = [];
                    $room = $limit;
                }

                $take = min($room, $total - $offset);
                $current[] = $take === $total
                    ? $message
                    : $message->recipients(array_slice($tokens, $offset, $take));

                $offset += $take;
                $room -= $take;
            }
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * True when the value looks like an Expo push token.
     */
    public static function isExpoPushToken(string $value): bool
    {
        return PushToken::isValid($value);
    }

    /**
     * @param list<PushMessage> $messages
     */
    private function sendChunk(array $messages): TicketCollection
    {
        $payload = [];
        $tokens = [];

        foreach ($messages as $message) {
            if ($this->validateSize && $message->sizeInBytes() > PushMessage::MAX_SIZE) {
                throw new MessageTooLargeException($message->sizeInBytes(), PushMessage::MAX_SIZE);
            }

            $payload[] = $message->jsonSerialize();

            foreach ($message->to as $token) {
                $tokens[] = $token;
            }
        }

        $body = $this->request($this->baseUrl . self::SEND_PATH, $payload);
        $data = $body['data'] ?? null;

        if (!is_array($data)) {
            throw new TransportException('Expo answered the send request without a data array.');
        }

        $tickets = [];

        foreach (array_values($data) as $index => $entry) {
            $tickets[] = PushTicket::fromArray(
                is_array($entry) ? $entry : [],
                $tokens[$index] ?? null
            );
        }

        return new TicketCollection($tickets);
    }

    /**
     * @param list<string>            $ids
     * @param array<string, PushToken> $tokensById
     */
    private function receiptChunk(array $ids, array $tokensById): ReceiptCollection
    {
        if ($ids === []) {
            return new ReceiptCollection();
        }

        $body = $this->request($this->baseUrl . self::RECEIPTS_PATH, ['ids' => $ids]);
        $data = $body['data'] ?? null;

        if (!is_array($data)) {
            throw new TransportException('Expo answered the receipt request without a data object.');
        }

        $receipts = [];

        foreach ($data as $id => $entry) {
            $id = (string) $id;
            $receipts[] = PushReceipt::fromArray(
                $id,
                is_array($entry) ? $entry : [],
                $tokensById[$id] ?? null
            );
        }

        return new ReceiptCollection($receipts, $ids);
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $url, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new InvalidMessageException('The SDK could not encode the request: ' . json_last_error_msg());
        }

        $headers = [
            'accept' => 'application/json',
            'accept-encoding' => 'gzip, deflate',
            'content-type' => 'application/json',
            'user-agent' => 'expo-sdk-php/' . self::VERSION,
        ];

        if ($this->accessToken !== null && $this->accessToken !== '') {
            $headers['authorization'] = 'Bearer ' . $this->accessToken;
        }

        if ($this->compress && strlen($json) > self::GZIP_THRESHOLD && function_exists('gzencode')) {
            $compressed = gzencode($json, 6);

            if (is_string($compressed)) {
                $json = $compressed;
                $headers['content-encoding'] = 'gzip';
            }
        }

        $response = $this->sendWithRetries($url, $json, $headers);

        return $this->decode($response);
    }

    /**
     * @param array<string, string> $headers
     */
    private function sendWithRetries(string $url, string $body, array $headers): HttpResponse
    {
        $attempt = 0;

        while (true) {
            $response = $this->httpClient->post($url, $body, $headers);

            if (!self::isRetryable($response) || $attempt >= $this->maxRetries) {
                return $response;
            }

            $this->wait($attempt, $response->retryAfter());
            ++$attempt;
        }
    }

    private static function isRetryable(HttpResponse $response): bool
    {
        return $response->status === 429 || $response->status >= 500;
    }

    private function wait(int $attempt, ?int $retryAfterSeconds): void
    {
        $milliseconds = $retryAfterSeconds !== null
            ? $retryAfterSeconds * 1000
            : $this->retryDelayMs * (1 << min($attempt, 16));

        $milliseconds = min($milliseconds, self::MAX_RETRY_DELAY_MS);

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
        $body = json_decode($response->body, true);

        if (!is_array($body)) {
            throw new TransportException(sprintf(
                'Expo answered with status %d and a body that is not JSON: %s',
                $response->status,
                self::snippet($response->body)
            ));
        }

        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            /** @var list<array{code?: string, message?: string, details?: mixed}> $errors */
            throw ExpoApiException::fromErrors(array_values($errors), $response->status);
        }

        if (!$response->isSuccessful()) {
            throw ExpoApiException::fromErrors([], $response->status);
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    private static function snippet(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return '(empty)';
        }

        return strlen($body) > 200 ? substr($body, 0, 200) . '...' : $body;
    }

    /**
     * @param PushMessage|iterable<PushMessage> $messages
     *
     * @return list<PushMessage>
     */
    private static function toMessageList(PushMessage|iterable $messages): array
    {
        if ($messages instanceof PushMessage) {
            return [$messages];
        }

        $list = [];

        foreach ($messages as $message) {
            if (!$message instanceof PushMessage) {
                throw new InvalidMessageException('Every item must be a PushMessage.');
            }

            $list[] = $message;
        }

        return $list;
    }

    /**
     * @param TicketCollection|PushTicket|string|iterable<PushTicket|string> $tickets
     *
     * @return list<PushTicket|string>
     */
    private static function toTicketList(TicketCollection|PushTicket|string|iterable $tickets): array
    {
        if ($tickets instanceof PushTicket || is_string($tickets)) {
            return [$tickets];
        }

        $list = [];

        foreach ($tickets as $ticket) {
            if (!$ticket instanceof PushTicket && !is_string($ticket)) {
                throw new InvalidMessageException('Every item must be a PushTicket or a receipt ID.');
            }

            $list[] = $ticket;
        }

        return $list;
    }
}
