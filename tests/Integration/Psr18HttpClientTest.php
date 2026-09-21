<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Integration;

use Expo\Push\Exception\TransportException;
use Expo\Push\Expo;
use Expo\Push\Http\Psr18HttpClient;
use Expo\Push\Http\TransportFailureKind;
use Expo\Push\PushMessage;
use Expo\Push\Result\Acceptance;
use Expo\Push\Result\FailureCategory;
use Expo\Push\Retry\NoRetryPolicy;
use Expo\Push\Tests\Support\Psr\FakePsr18Client;
use Expo\Push\Tests\Support\Psr\FakePsrFactory;
use Expo\Push\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

final class Psr18HttpClientTest extends TestCase
{
    private function client(FakePsr18Client $psr18, bool $failOnCreate = false): Psr18HttpClient
    {
        $factory = new FakePsrFactory($failOnCreate);

        return new Psr18HttpClient($psr18, $factory, $factory);
    }

    public function testItSendsThroughAPsr18Client(): void
    {
        $psr18 = (new FakePsr18Client())->queue('{"data":[{"status":"ok","id":"r1"}]}');

        $result = $this->expo($this->client($psr18))->send(PushMessage::to(self::TOKEN_A)->title('Hi'));

        self::assertCount(1, $result->accepted());
        self::assertSame(1, $psr18->requestCount());
        self::assertSame('POST', $psr18->requests[0]->getMethod());
        self::assertSame('application/json', $psr18->requests[0]->getHeaderLine('content-type'));
    }

    public function testAnOrdinaryErrorStatusIsNotAnException(): void
    {
        $psr18 = (new FakePsr18Client())->queue('{"errors":[{"code":"UNAUTHORIZED"}]}', 401);

        $result = $this->expo($this->client($psr18), new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(401, $result->requestFailures()[0]->httpStatus);
        self::assertSame(FailureCategory::Api, $result->requestFailures()[0]->category);
        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
    }

    public function testANetworkExceptionLeavesTheTransmissionUnknown(): void
    {
        $psr18 = (new FakePsr18Client())->queueNetworkError();

        $result = $this->expo($this->client($psr18), new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(Acceptance::Unknown, $result->outcomes()[0]->acceptance);
        self::assertTrue($result->outcomes()[0]->duplicateRisk);
        self::assertSame(FailureCategory::Transport, $result->requestFailures()[0]->category);
    }

    public function testARequestExceptionMeansNothingLeftThisProcess(): void
    {
        $psr18 = (new FakePsr18Client())->queueRequestError();

        $result = $this->expo($this->client($psr18), new NoRetryPolicy())->send(PushMessage::to(self::TOKEN_A));

        self::assertSame(Acceptance::NotAccepted, $result->outcomes()[0]->acceptance);
        self::assertSame(
            \Expo\Push\Result\NotAcceptedReason::NotTransmitted,
            $result->outcomes()[0]->reason
        );
    }

    public function testAnotherClientFailureIsItsOwnKind(): void
    {
        $psr18 = (new FakePsr18Client())->queueClientError();

        try {
            $this->client($psr18)->send(
                new \Expo\Push\Http\HttpRequest('https://exp.host/x', '{}', [], 1_000, 500)
            );
            self::fail('The client must raise TransportException.');
        } catch (TransportException $exception) {
            self::assertSame(TransportFailureKind::ClientFailure, $exception->failure->kind);
        }
    }

    public function testAFactoryFailureIsARequestConstructionError(): void
    {
        $psr18 = new FakePsr18Client();

        try {
            $this->client($psr18, failOnCreate: true)->send(
                new \Expo\Push\Http\HttpRequest('https://exp.host/x', '{}', [], 1_000, 500)
            );
            self::fail('The client must raise TransportException.');
        } catch (TransportException $exception) {
            self::assertSame(TransportFailureKind::RequestConstruction, $exception->failure->kind);
            self::assertSame(0, $psr18->requestCount());
        }
    }

    public function testThePsr18TransportReportsItsLimits(): void
    {
        $capabilities = $this->client(new FakePsr18Client())->capabilities();

        self::assertSame(1, $capabilities->maxConcurrency);
        self::assertFalse($capabilities->canEnforceHardDeadline);
        self::assertFalse($capabilities->decompressesResponses);
    }

    #[RequiresPhpExtension('zlib')]
    public function testItDecompressesABodyThatTheClientLeftCompressed(): void
    {
        $plain = '{"data":[{"status":"ok","id":"r1"}]}';
        $psr18 = (new FakePsr18Client())->queue(
            (string) gzencode($plain),
            200,
            ['Content-Encoding' => 'gzip']
        );

        $result = $this->expo($this->client($psr18))->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
        self::assertSame('r1', $result->outcomes()[0]->receiptId());
    }

    public function testItNeverDecompressesABodyTwice(): void
    {
        // The client already decoded the body and kept the header, the way most
        // PSR-18 clients do.
        $psr18 = (new FakePsr18Client())->queue(
            '{"data":[{"status":"ok","id":"r1"}]}',
            200,
            ['Content-Encoding' => 'gzip']
        );

        $result = $this->expo($this->client($psr18))->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
    }

    /**
     * RFC 9110 defines `deflate` as the zlib format of RFC 1950, which
     * gzcompress() writes and gzuncompress() reads.
     */
    #[RequiresPhpExtension('zlib')]
    public function testItDecompressesAZlibWrappedDeflateBody(): void
    {
        $plain = '{"data":[{"status":"ok","id":"r1"}]}';
        $psr18 = (new FakePsr18Client())->queue(
            (string) gzcompress($plain),
            200,
            ['Content-Encoding' => 'deflate']
        );

        $result = $this->expo($this->client($psr18))->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
        self::assertSame('r1', $result->outcomes()[0]->receiptId());
    }

    /**
     * Some servers send a raw deflate stream instead. The adapter reads that too.
     */
    #[RequiresPhpExtension('zlib')]
    public function testItDecompressesARawDeflateBody(): void
    {
        $plain = '{"data":[{"status":"ok","id":"r1"}]}';
        $psr18 = (new FakePsr18Client())->queue(
            (string) gzdeflate($plain),
            200,
            ['Content-Encoding' => 'deflate']
        );

        $result = $this->expo($this->client($psr18))->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
    }

    /**
     * A PSR-18 transport cannot decompress by itself, so the SDK asks for an
     * encoding only when this build can decode it.
     */
    public function testThePsr18RequestAsksForAnEncodingThatTheSdkCanRead(): void
    {
        $psr18 = (new FakePsr18Client())->queue('{"data":[{"status":"ok","id":"r1"}]}');

        $result = $this->expo($this->client($psr18))->send(PushMessage::to(self::TOKEN_A));

        self::assertCount(1, $result->accepted());
        self::assertSame(
            extension_loaded('zlib') ? 'gzip, deflate' : 'identity',
            $psr18->requests[0]->getHeaderLine('accept-encoding')
        );
    }

    public function testAConcurrencyAboveOneIsRejectedForThisTransport(): void
    {
        $this->expectException(\Expo\Push\Exception\InvalidConfigurationException::class);

        new Expo(httpClient: $this->client(new FakePsr18Client()), concurrency: 2);
    }
}
