<?php

declare(strict_types=1);

namespace Expo\Push;

use ArrayIterator;
use Countable;
use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Storage\StorageEnvelope;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * The receipts that Expo returned.
 *
 * The collection holds returned receipts and nothing else. It does not know what
 * you asked for, so it cannot tell you what is missing, and filtering it can
 * never invent a missing ID. `ReceiptResult` owns the coverage of a lookup:
 * read `ReceiptResult::missingIds()`, `failedIds()` and `notAttemptedIds()`.
 *
 * @implements IteratorAggregate<int, PushReceipt>
 */
final readonly class ReceiptCollection implements Countable, IteratorAggregate, JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.receipts';

    /** @var list<PushReceipt> */
    private array $receipts;

    /**
     * @param iterable<PushReceipt> $receipts
     */
    public function __construct(iterable $receipts = [])
    {
        $this->receipts = is_array($receipts) ? array_values($receipts) : iterator_to_array($receipts, false);
    }

    /**
     * @return list<PushReceipt>
     */
    public function all(): array
    {
        return $this->receipts;
    }

    public function first(): ?PushReceipt
    {
        return $this->receipts[0] ?? null;
    }

    public function get(string $id): ?PushReceipt
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt->id === $id) {
                return $receipt;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->receipts === [];
    }

    #[\Override]
    public function count(): int
    {
        return count($this->receipts);
    }

    /**
     * The IDs that this collection holds.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (PushReceipt $receipt): string => $receipt->id, $this->receipts);
    }

    /**
     * The receipts that Apple or Google took. This is not device delivery.
     */
    #[\NoDiscard]
    public function ok(): self
    {
        return $this->filter(static fn (PushReceipt $receipt): bool => $receipt->isOk());
    }

    /**
     * The receipts that report a failure.
     */
    #[\NoDiscard]
    public function errors(): self
    {
        return $this->filter(static fn (PushReceipt $receipt): bool => $receipt->isError());
    }

    public function hasErrors(): bool
    {
        foreach ($this->receipts as $receipt) {
            if ($receipt->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The receipts with one error code.
     */
    #[\NoDiscard]
    public function withError(PushError $error): self
    {
        return $this->filter(static fn (PushReceipt $receipt): bool => $receipt->error === $error);
    }

    /**
     * The devices that Expo marked `DeviceNotRegistered`. Delete these tokens.
     *
     * @return list<PushToken>
     */
    public function unregisteredTokens(): array
    {
        $tokens = [];

        foreach ($this->withError(PushError::DeviceNotRegistered) as $receipt) {
            if ($receipt->token !== null) {
                $tokens[$receipt->token->value] = $receipt->token;
            }
        }

        return array_values($tokens);
    }

    /**
     * @param callable(PushReceipt): bool $filter
     */
    #[\NoDiscard]
    public function filter(callable $filter): self
    {
        return new self(array_values(array_filter($this->receipts, $filter)));
    }

    /**
     * @template T
     *
     * @param callable(PushReceipt): T $callback
     *
     * @return list<T>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->receipts);
    }

    /**
     * Builds one collection out of many, in one pass.
     *
     * @param iterable<self> $collections
     */
    #[\NoDiscard]
    public static function concat(iterable $collections): self
    {
        $receipts = [];

        foreach ($collections as $collection) {
            foreach ($collection->all() as $receipt) {
                $receipts[] = $receipt;
            }
        }

        return new self($receipts);
    }

    #[\NoDiscard]
    public function merge(self $other): self
    {
        return new self([...$this->receipts, ...$other->all()]);
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->receipts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, [
            'receipts' => array_map(
                static fn (PushReceipt $receipt): array => $receipt->toStorageArray(),
                $this->receipts
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @throws InvalidStorageException
     */
    public static function fromStorageArray(array $stored): self
    {
        $data = StorageEnvelope::unwrap(self::STORAGE_TYPE, $stored);

        return new self(array_map(
            static fn (array $entry): PushReceipt => PushReceipt::fromStorageArray($entry),
            StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'receipts')
        ));
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toStorageArray();
    }
}
