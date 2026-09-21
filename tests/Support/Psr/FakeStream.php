<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * The smallest PSR-7 stream that the SDK needs. It holds a string.
 */
final class FakeStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private string $contents = '')
    {
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->contents;
    }

    #[\Override]
    public function close(): void
    {
    }

    #[\Override]
    public function detach()
    {
        return null;
    }

    #[\Override]
    public function getSize(): int
    {
        return strlen($this->contents);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->position = $whence === SEEK_END ? strlen($this->contents) + $offset : $offset;
    }

    #[\Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

    #[\Override]
    public function isWritable(): bool
    {
        return true;
    }

    #[\Override]
    public function write(string $string): int
    {
        $this->contents .= $string;

        return strlen($string);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        $rest = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $rest;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        if ($key === null) {
            return [];
        }

        throw new RuntimeException('The fake stream holds no metadata.');
    }
}
