<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * The smallest PSR-7 request that the SDK needs.
 */
final class FakeRequest implements RequestInterface
{
    use FakeMessage;

    public function __construct(
        private string $method,
        private UriInterface $uri,
    ) {
        $this->body = new FakeStream();
    }

    #[\Override]
    public function getRequestTarget(): string
    {
        return $this->uri->getPath();
    }

    #[\Override]
    public function withRequestTarget(string $requestTarget): static
    {
        return $this;
    }

    #[\Override]
    public function getMethod(): string
    {
        return $this->method;
    }

    #[\Override]
    public function withMethod(string $method): static
    {
        $copy = clone $this;
        $copy->method = $method;

        return $copy;
    }

    #[\Override]
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    #[\Override]
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $copy = clone $this;
        $copy->uri = $uri;

        return $copy;
    }
}
