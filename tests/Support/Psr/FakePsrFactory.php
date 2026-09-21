<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * A PSR-17 request factory and stream factory in one class.
 */
final class FakePsrFactory implements RequestFactoryInterface, StreamFactoryInterface
{
    public function __construct(private readonly bool $failOnCreate = false)
    {
    }

    #[\Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        if ($this->failOnCreate) {
            throw new RuntimeException('the factory cannot build this request');
        }

        return new FakeRequest($method, $uri instanceof UriInterface ? $uri : new FakeUri((string) $uri));
    }

    #[\Override]
    public function createStream(string $content = ''): StreamInterface
    {
        return new FakeStream($content);
    }

    #[\Override]
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return new FakeStream((string) file_get_contents($filename));
    }

    /**
     * @param resource $resource
     */
    #[\Override]
    public function createStreamFromResource($resource): StreamInterface
    {
        return new FakeStream((string) stream_get_contents($resource));
    }
}
