<?php

declare(strict_types=1);

namespace Expo\Push;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Every receipt that Expo returned for a list of receipt IDs.
 *
 * Expo leaves out a receipt that is not ready. Read the missing IDs again later.
 *
 * @implements IteratorAggregate<int, PushReceipt>
 */
final readonly class ReceiptCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var list<PushReceipt> */
    private array $receipts;

    /**
     * @param iterable<PushReceipt> $receipts
     * @param list<string>          $requestedIds the IDs that you asked for
     */
    public function __construct(
        iterable $receipts = [],
        private array $requestedIds = [],
    ) {
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
     * The receipts of the delivered notifications.
     */
    #[\NoDiscard]
    public function ok(): self
    {
        return $this->filter(static fn (PushReceipt $receipt): bool => $receipt->isOk());
    }

    /**
     * The receipts of the failed notifications.
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
     * The IDs that Expo did not answer yet. Ask for them again in some minutes.
     *
     * @return list<string>
     */
    public function pendingIds(): array
    {
        $answered = array_map(static fn (PushReceipt $receipt): string => $receipt->id, $this->receipts);

        return array_values(array_diff($this->requestedIds, $answered));
    }

    /**
     * The dead devices. Delete these tokens from your database.
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
        return new self(array_values(array_filter($this->receipts, $filter)), $this->requestedIds);
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

    #[\NoDiscard]
    public function merge(self $other): self
    {
        return new self(
            [...$this->receipts, ...$other->all()],
            [...$this->requestedIds, ...$other->requestedIds],
        );
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->receipts);
    }

    /**
     * @return list<PushReceipt>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->receipts;
    }
}
