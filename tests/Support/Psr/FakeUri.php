<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Message\UriInterface;

/**
 * The smallest PSR-7 URI that the SDK needs. It holds a string.
 */
final class FakeUri implements UriInterface
{
    /** @var array<string, mixed> */
    private array $parts;

    public function __construct(private readonly string $uri)
    {
        $parsed = parse_url($uri);
        $this->parts = is_array($parsed) ? $parsed : [];
    }

    #[\Override]
    public function getScheme(): string
    {
        return is_string($this->parts['scheme'] ?? null) ? $this->parts['scheme'] : '';
    }

    #[\Override]
    public function getAuthority(): string
    {
        return $this->getHost();
    }

    #[\Override]
    public function getUserInfo(): string
    {
        return '';
    }

    #[\Override]
    public function getHost(): string
    {
        return is_string($this->parts['host'] ?? null) ? $this->parts['host'] : '';
    }

    #[\Override]
    public function getPort(): ?int
    {
        return is_int($this->parts['port'] ?? null) ? $this->parts['port'] : null;
    }

    #[\Override]
    public function getPath(): string
    {
        return is_string($this->parts['path'] ?? null) ? $this->parts['path'] : '';
    }

    #[\Override]
    public function getQuery(): string
    {
        return is_string($this->parts['query'] ?? null) ? $this->parts['query'] : '';
    }

    #[\Override]
    public function getFragment(): string
    {
        return '';
    }

    #[\Override]
    public function withScheme(string $scheme): static
    {
        return $this;
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): static
    {
        return $this;
    }

    #[\Override]
    public function withHost(string $host): static
    {
        return $this;
    }

    #[\Override]
    public function withPort(?int $port): static
    {
        return $this;
    }

    #[\Override]
    public function withPath(string $path): static
    {
        return $this;
    }

    #[\Override]
    public function withQuery(string $query): static
    {
        return $this;
    }

    #[\Override]
    public function withFragment(string $fragment): static
    {
        return $this;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->uri;
    }
}
