<?php

declare(strict_types=1);

namespace Expo\Push;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Every ticket of one send, in the order of the devices.
 *
 * @implements IteratorAggregate<int, PushTicket>
 */
final readonly class TicketCollection implements Countable, IteratorAggregate, JsonSerializable
{
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
            if ($ticket->id !== null) {
                $ids[] = $ticket->id;
            }
        }

        return $ids;
    }

    /**
     * Every device of this send.
     *
     * @return list<PushToken>
     */
    public function tokens(): array
    {
        return $this->collectTokens($this->tickets);
    }

    /**
     * The devices of the rejected tickets.
     *
     * @return list<PushToken>
     */
    public function failedTokens(): array
    {
        return $this->collectTokens($this->errors()->all());
    }

    /**
     * The dead devices. Delete these tokens from your database.
     *
     * @return list<PushToken>
     */
    public function unregisteredTokens(): array
    {
        return $this->collectTokens($this->withError(PushError::DeviceNotRegistered)->all());
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
     * @return list<PushTicket>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->tickets;
    }

    /**
     * @param list<PushTicket> $tickets
     *
     * @return list<PushToken>
     */
    private function collectTokens(array $tickets): array
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
