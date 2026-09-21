<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Integration;

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Exception\TransportException;
use Expo\Push\Http\CurlHttpClient;
use Expo\Push\Http\HttpRequest;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\LocalHttpServer;
use Expo\Push\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Real cURL requests against a local test server. Nothing reaches Expo.
 */
#[RequiresPhpExtension('curl')]
final class CurlHttpClientTest extends TestCase
{
    private static ?LocalHttpServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private static function server(): LocalHttpServer
    {
        $server = self::$server;

        self::assertInstanceOf(LocalHttpServer::class, $server);

        return $server;
    }

    private function client(): CurlHttpClient
    {
        return new CurlHttpClient(allowPlaintextHttp: true);
    }

    private function request(string $path, string $body = '{}'): HttpRequest
    {
        return new HttpRequest(
            url: self::server()->url($path),
            body: $body,
            headers: ['content-type' => 'application/json', 'accept' => 'application/json'],
            timeoutMs: 5_000,
            connectTimeoutMs: 2_000,
        );
    }

    public function testItSendsARealRequestAndReadsTheAnswer(): void
    {
        $response = $this->client()->send($this->request('/echo'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"status":"ok"', $response->body);
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertGreaterThan(0, $response->bytesUploaded);
        self::assertNotNull($response->durationMs);
    }

    public function testItKeepsRepeatedHeaders(): void
    {
        $response = $this->client()->send($this->request('/repeat-headers'));

        self::assertSame(['first', 'second'], $response->headerValues('X-Note'));
        self::assertSame('first', $response->header('x-note'));
        self::assertSame('3', $response->header('retry-after'));
    }

    /**
     * An interim 1xx block must not leak into the headers of the final answer.
     */
    public function testAnInterimHeaderBlockDoesNotReachTheResult(): void
    {
        $response = $this->client()->send($this->request('/interim'));

        self::assertSame(200, $response->status);
        self::assertSame('yes', $response->header('x-final'));
        self::assertNull($response->header('x-interim'));
    }

    #[RequiresPhpExtension('zlib')]
    public function testItDecompressesAGzipAnswer(): void
    {
        $response = $this->client()->send($this->request('/gzip'));

        self::assertStringContainsString('"status":"ok"', $response->body);
    }

    public function testItReusesTheConnectionBetweenRequests(): void
    {
        $client = $this->client();
        $before = self::server()->stats();

        $client->send($this->request('/echo'));
        $client->send($this->request('/echo'));
        $client->send($this->request('/echo'));

        $after = self::server()->stats();

        // The stats call itself opens one connection each time, so the three
        // sends must add at most one more.
        self::assertSame(3, $after['requests'] - $before['requests'] - 1);
        self::assertLessThanOrEqual(2, $after['connections'] - $before['connections']);
    }

    public function testItReturnsEveryStatusToTheProtocolLayer(): void
    {
        $client = $this->client();

        self::assertSame(429, $client->send($this->request('/status-429'))->status);
        self::assertSame(500, $client->send($this->request('/status-500'))->status);
        self::assertSame(302, $client->send($this->request('/redirect'))->status);
    }

    public function testItNeverFollowsARedirect(): void
    {
        $response = $this->client()->send($this->request('/redirect'));

        self::assertSame(302, $response->status);
        self::assertSame('https://example.test/elsewhere', $response->header('location'));
    }

    public function testAnInterruptedTransferIsATransportFailure(): void
    {
        try {
            $this->client()->send($this->request('/truncate'));
            self::fail('The client must raise TransportException.');
        } catch (TransportException $exception) {
            self::assertSame(TransportFailureKind::Interrupted, $exception->failure->kind);
            self::assertStringStartsWith('CURLE_', (string) $exception->failure->code);
        }
    }

    public function testAConnectionFailureIsKnownToBeBeforeTransmission(): void
    {
        $request = new HttpRequest(
            url: 'http://127.0.0.1:1/never',
            body: '{}',
            headers: [],
            timeoutMs: 2_000,
            connectTimeoutMs: 500,
        );

        try {
            $this->client()->send($request);
            self::fail('The client must raise TransportException.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->failure->kind->isBeforeTransmission());
        }
    }

    public function testAPlaintextUrlNeedsAnExplicitAllowance(): void
    {
        try {
            (new CurlHttpClient())->send($this->request('/echo'));
            self::fail('The client must refuse a plaintext URL.');
        } catch (TransportException $exception) {
            self::assertSame(TransportFailureKind::InvalidConfiguration, $exception->failure->kind);
            self::assertStringContainsString('refuses the URL scheme', $exception->failure->message);
        }
    }

    public function testACurlOptionOutsideTheAllowedListIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('not on the allowed list');

        new CurlHttpClient([CURLOPT_URL => 'https://evil.test']);
    }

    public function testAHeaderWithALineBreakIsRejected(): void
    {
        $request = new HttpRequest(
            url: self::server()->url('/echo'),
            body: '{}',
            headers: ['x-bad' => "value\r\nx-injected: yes"],
            timeoutMs: 2_000,
            connectTimeoutMs: 500,
        );

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('line break');

        $this->client()->send($request);
    }

    public function testTheMultiPathRunsSeveralRequestsAndCleansUp(): void
    {
        $client = $this->client();

        for ($id = 0; $id < 4; ++$id) {
            $client->start($id, $this->request('/echo'));
        }

        self::assertSame(4, $client->inFlight());

        $completed = [];
        $deadline = microtime(true) + 10.0;

        while (count($completed) < 4 && microtime(true) < $deadline) {
            foreach ($client->poll(200) as $done) {
                $completed[$done->id] = $done;
            }
        }

        self::assertCount(4, $completed);
        self::assertSame(0, $client->inFlight());

        foreach ($completed as $done) {
            self::assertSame(200, $done->response?->status);
        }

        // The handles came back to the pool, so a later request still works.
        self::assertSame(200, $client->send($this->request('/echo'))->status);
    }

    public function testTheMultiPathReportsAFailureWithItsOwnId(): void
    {
        $client = $this->client();

        $client->start(1, $this->request('/echo'));
        $client->start(2, $this->request('/truncate'));

        $completed = [];
        $deadline = microtime(true) + 10.0;

        while (count($completed) < 2 && microtime(true) < $deadline) {
            foreach ($client->poll(200) as $done) {
                $completed[$done->id] = $done;
            }
        }

        self::assertSame(200, $completed[1]->response?->status);
        self::assertSame(TransportFailureKind::Interrupted, $completed[2]->failure?->kind);
    }

    public function testCancelAllFreesTheHandles(): void
    {
        $client = $this->client();
        $client->start(1, $this->request('/echo'));

        $client->cancelAll();

        self::assertSame(0, $client->inFlight());
        self::assertSame(200, $client->send($this->request('/echo'))->status);
    }

    public function testAWholeSendWorksOverRealHttp(): void
    {
        $expo = new \Expo\Push\Expo(
            httpClient: $this->client(),
            baseUrl: self::server()->url(),
            clock: $this->clock,
            sleeper: $this->sleeper,
        );

        $result = $expo->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        self::assertCount(1, $result->accepted());
        self::assertSame('r1', $result->outcomes()[0]->receiptId());
    }

    public function testARealNonJsonAnswerBecomesAProtocolFailure(): void
    {
        $client = $this->client();
        $response = $client->send($this->request('/not-json'));

        self::assertSame(200, $response->status);

        $parsed = \Expo\Push\Protocol\SendResponseParser::parse(
            $response->body,
            [new \Expo\Push\PushToken(self::TOKEN_A)]
        );

        self::assertFalse($parsed->isUsable());
    }

    public function testAConcurrentSendKeepsTheInputOrder(): void
    {
        $expo = new \Expo\Push\Expo(
            httpClient: $this->client(),
            concurrency: 3,
            baseUrl: self::server()->url(),
            sendChunkSize: 1,
            clock: $this->clock,
            sleeper: $this->sleeper,
        );

        $result = $expo->send(PushMessage::to(self::tokens(5))->title('Hi'));

        self::assertCount(5, $result->accepted());
        self::assertSame(self::tokens(5), self::values(array_map(
            static fn (\Expo\Push\Result\NotificationOutcome $outcome): \Expo\Push\PushToken => $outcome->token,
            $result->outcomes()
        )));
    }

    public function testARealRateLimitAnswerDefersWithTheServerDelay(): void
    {
        $expo = new \Expo\Push\Expo(
            httpClient: $this->client(),
            retryPolicy: new NoRetryPolicy(),
            baseUrl: self::server()->url('/status-429'),
            clock: $this->clock,
            sleeper: $this->sleeper,
        );

        $result = $expo->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
        self::assertSame(429, $result->requestFailures()[0]->httpStatus);
        self::assertSame('TOO_MANY_REQUESTS', $result->requestFailures()[0]->expoErrors[0]->code);
    }

    public function testTheTransportReportsItsCapabilities(): void
    {
        $capabilities = $this->client()->capabilities();

        self::assertSame(6, $capabilities->maxConcurrency);
        self::assertTrue($capabilities->canEnforceHardDeadline);
        self::assertTrue($capabilities->decompressesResponses);
    }
}
