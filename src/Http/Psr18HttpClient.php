<?php

declare(strict_types=1);

namespace Expo\Push\Http;

use Expo\Push\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends the requests through any PSR-18 client, such as Guzzle or Symfony HttpClient.
 *
 * Install `psr/http-client` and `psr/http-factory` to use this class.
 *
 * ```php
 * $expo = new Expo(httpClient: new Psr18HttpClient($guzzle, $requestFactory, $streamFactory));
 * ```
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
    public function post(string $url, string $body, array $headers): HttpResponse
    {
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withBody($this->streamFactory->createStream($body));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException(
                sprintf('The SDK could not reach %s: %s', $url, $exception->getMessage()),
                0,
                $exception
            );
        }

        $responseHeaders = [];

        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower($name)] = array_values($values);
        }

        return new HttpResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
            $responseHeaders,
        );
    }
}
