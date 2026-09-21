<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Message\ResponseInterface;

/**
 * The smallest PSR-7 response that the SDK needs.
 */
final class FakeResponse implements ResponseInterface
{
    use FakeMessage;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private int $status = 200,
        string $body = '',
        array $headers = [],
    ) {
        $this->body = new FakeStream($body);

        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = is_array($value) ? array_values($value) : [$value];
        }
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->status;
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $copy = clone $this;
        $copy->status = $code;

        return $copy;
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return '';
    }
}
