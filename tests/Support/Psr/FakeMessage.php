<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Message\StreamInterface;

/**
 * The header and body handling that the fake request and the fake response share.
 */
trait FakeMessage
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    private StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): static
    {
        return $this;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, mixed $value): static
    {
        $copy = clone $this;
        $copy->headers[strtolower($name)] = is_array($value) ? array_values($value) : [(string) $value];

        return $copy;
    }

    public function withAddedHeader(string $name, mixed $value): static
    {
        $copy = clone $this;
        $key = strtolower($name);

        foreach (is_array($value) ? $value : [$value] as $single) {
            $copy->headers[$key][] = (string) $single;
        }

        return $copy;
    }

    public function withoutHeader(string $name): static
    {
        $copy = clone $this;
        unset($copy->headers[strtolower($name)]);

        return $copy;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $copy = clone $this;
        $copy->body = $body;

        return $copy;
    }
}
