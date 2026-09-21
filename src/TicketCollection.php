<?php

declare(strict_types=1);

namespace Expo\Push;

use ArrayIterator;
use Countable;
use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Storage\StorageEnvelope;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * The real tickets of one send.
 *
 * The collection holds only tickets that Expo sent. A request that failed
 * produces no ticket at all: read `SendResult::requestFailures()` for those.
 * An empty collection therefore does not mean "everything worked".
 *
 * @implements IteratorAggregate<int, PushTicket>
 */
final readonly class TicketCollection implements Countable, IteratorAggregate, JsonSerializable
{
    public const string STORAGE_TYPE = 'expo.tickets';

    /** @var list<PushTicket> */
    private array $tickets;

    /**
     * @param iterable<PushTicket> $tickets
     */
    public function __construct(iterable $tickets = [])
    {
        $this->tickets = is_array($tickets) ? array_values($tickets) : iterator_to_array($tickets, false);
    }

    /**
     * @return list<PushTicket>
     */
    public function all(): array
    {
        return $this->tickets;
    }

    public function first(): ?PushTicket
    {
        return $this->tickets[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->tickets === [];
    }

    #[\Override]
    public function count(): int
    {
        return count($this->tickets);
    }

    /**
     * The tickets that Expo accepted.
     */
    #[\NoDiscard]
    public function ok(): self
    {
        return $this->filter(static fn (PushTicket $ticket): bool => $ticket->isOk());
    }

    /**
     * The tickets that Expo rejected.
     */
    #[\NoDiscard]
    public function errors(): self
    {
        return $this->filter(static fn (PushTicket $ticket): bool => $ticket->isError());
    }

    public function hasErrors(): bool
    {
        foreach ($this->tickets as $ticket) {
            if ($ticket->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tickets with one error code.
     */
    #[\NoDiscard]
    public function withError(PushError $error): self
    {
        return $this->filter(static fn (PushTicket $ticket): bool => $ticket->error === $error);
    }

    /**
     * The receipt IDs of the accepted tickets. Give them to `Expo::receipts()`.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];

        foreach ($this->tickets as $ticket) {
            if ($ticket->isOk() && $ticket->id !== null) {
                $ids[] = $ticket->id;
            }
        }

        return $ids;
    }

    /**
     * One receipt reference for each accepted ticket, with its device token.
     *
     * @return list<ReceiptReference>
     */
    public function references(): array
    {
        $references = [];

        foreach ($this->tickets as $ticket) {
            $reference = $ticket->receiptReference();

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * Every device of this collection, without repeats.
     *
     * @return list<PushToken>
     */
    public function tokens(): array
    {
        return self::collectTokens($this->tickets);
    }

    /**
     * The devices of the rejected tickets.
     *
     * @return list<PushToken>
     */
    public function failedTokens(): array
    {
        return self::collectTokens($this->errors()->all());
    }

    /**
     * The devices that Expo marked `DeviceNotRegistered`. Delete these tokens.
     *
     * The list holds only tokens with that explicit evidence. A payload error, a
     * credential error and a provider problem never reach it.
     *
     * @return list<PushToken>
     */
    public function unregisteredTokens(): array
    {
        return self::collectTokens($this->withError(PushError::DeviceNotRegistered)->all());
    }

    /**
     * @param callable(PushTicket): bool $filter
     */
    #[\NoDiscard]
    public function filter(callable $filter): self
    {
        return new self(array_values(array_filter($this->tickets, $filter)));
    }

    /**
     * @template T
     *
     * @param callable(PushTicket): T $callback
     *
     * @return list<T>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->tickets);
    }

    /**
     * Builds one collection out of many, in one pass.
     *
     * Use this instead of `merge()` in a loop: a chain of merges copies every
     * earlier ticket again for each step.
     *
     * @param iterable<self> $collections
     */
    #[\NoDiscard]
    public static function concat(iterable $collections): self
    {
        $tickets = [];

        foreach ($collections as $collection) {
            foreach ($collection->all() as $ticket) {
                $tickets[] = $ticket;
            }
        }

        return new self($tickets);
    }

    #[\NoDiscard]
    public function merge(self $other): self
    {
        return new self([...$this->tickets, ...$other->all()]);
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->tickets);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return StorageEnvelope::wrap(self::STORAGE_TYPE, [
            'tickets' => array_map(static fn (PushTicket $ticket): array => $ticket->toStorageArray(), $this->tickets),
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
            static fn (array $entry): PushTicket => PushTicket::fromStorageArray($entry),
            StorageEnvelope::listOfArrays(self::STORAGE_TYPE, $data, 'tickets')
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

    /**
     * @param list<PushTicket> $tickets
     *
     * @return list<PushToken>
     */
    private static function collectTokens(array $tickets): array
    {
        $tokens = [];

        foreach ($tickets as $ticket) {
            if ($ticket->token !== null) {
                $tokens[$ticket->token->value] = $ticket->token;
            }
        }

        return array_values($tokens);
    }
}
