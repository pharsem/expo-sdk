<?php

declare(strict_types=1);

namespace Expo\Push\Http;

use Expo\Push\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends the requests through any PSR-18 client, such as Guzzle or Symfony HttpClient.
 *
 * Install `psr/http-client` and `psr/http-factory` to use this class. The core
 * package needs neither.
 *
 * ```php
 * $expo = new Expo(httpClient: new Psr18HttpClient($guzzle, $requestFactory, $streamFactory));
 * ```
 *
 * What this adapter can and cannot promise:
 *
 * - One request at a time. The SDK rejects a concurrency above 1 with this transport.
 * - No hard deadline. PHP cannot interrupt a synchronous client from outside, so
 *   the SDK refuses `enforceHardDeadline: true` here. Set the timeout on your own
 *   client. The retry budget still bounds how much new work the SDK schedules.
 * - The adapter decompresses a gzip or deflate body only when the client did not.
 */
final readonly class Psr18HttpClient implements HttpClient
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    #[\Override]
    public function capabilities(): TransportCapabilities
    {
        return new TransportCapabilities(
            maxConcurrency: 1,
            canEnforceHardDeadline: false,
            decompressesResponses: false,
        );
    }

    #[\Override]
    public function send(HttpRequest $httpRequest): HttpResponse
    {
        $startedAt = microtime(true);

        try {
            $request = $this->requestFactory->createRequest('POST', $httpRequest->url)
                ->withBody($this->streamFactory->createStream($httpRequest->body));

            foreach ($httpRequest->headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        } catch (\Throwable $exception) {
            throw new TransportException(TransportFailure::of(
                TransportFailureKind::RequestConstruction,
                $exception->getMessage(),
                $exception::class,
                0,
                $exception
            ));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (RequestExceptionInterface $exception) {
            // The client refused the request itself, so nothing left this process.
            throw new TransportException(TransportFailure::of(
                TransportFailureKind::RequestConstruction,
                $exception->getMessage(),
                $exception::class,
                0,
                $exception
            ));
        } catch (NetworkExceptionInterface $exception) {
            // PSR-18 does not say whether the server saw the request, so the SDK
            // must treat the transmission as unknown.
            throw new TransportException(TransportFailure::of(
                TransportFailureKind::Unknown,
                $exception->getMessage(),
                $exception::class,
                null,
                $exception
            ));
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException(TransportFailure::of(
                TransportFailureKind::ClientFailure,
                $exception->getMessage(),
                $exception::class,
                null,
                $exception
            ));
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = array_values($values);
        }

        return new HttpResponse(
            status: $response->getStatusCode(),
            body: self::decode((string) $response->getBody(), $headers),
            headers: $headers,
            bytesUploaded: strlen($httpRequest->body),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * Decompresses the body when the client left it compressed.
     *
     * Most PSR-18 clients decode the body and keep the `content-encoding` header,
     * so the header alone proves nothing. The method looks at the bytes: a gzip
     * body starts with 0x1f 0x8b. A body that is already plain text stays as it is.
     *
     * @param array<string, list<string>> $headers
     */
    private static function decode(string $body, array $headers): string
    {
        $encoding = strtolower(trim($headers['content-encoding'][0] ?? ''));

        if ($body === '' || $encoding === '' || $encoding === 'identity') {
            return $body;
        }

        if ($encoding === 'gzip' || $encoding === 'x-gzip') {
            if (substr($body, 0, 2) !== "\x1f\x8b" || !function_exists('gzdecode')) {
                return $body;
            }

            $plain = @gzdecode($body);

            return $plain === false ? $body : $plain;
        }

        if ($encoding === 'deflate' && function_exists('gzinflate')) {
            $plain = @gzinflate($body);

            if ($plain === false) {
                $plain = function_exists('gzuncompress') ? @gzuncompress($body) : false;
            }

            return $plain === false ? $body : $plain;
        }

        return $body;
    }
}
