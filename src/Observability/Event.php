<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Result\OperationType;

/**
 * The base of every observer event.
 *
 * Every field of every event is safe to log. The SDK never puts a device token,
 * a message body, custom data, an authorization value or a raw response body in
 * an event.
 *
 * A storage array is the opposite: it holds your application data on purpose.
 * Never send one to a logger.
 */
abstract readonly class Event
{
    /**
     * @param string        $operationId a random ID that ties the events of one operation together
     * @param OperationType $operation   send or receipts
     * @param int           $atUtcMs     the UTC wall time of the event
     */
    public function __construct(
        public string $operationId,
        public OperationType $operation,
        public int $atUtcMs,
    ) {
    }

    /**
     * The name that a log line can use.
     */
    public function name(): string
    {
        $parts = explode('\\', static::class);

        return $parts[count($parts) - 1];
    }

    /**
     * Fields that are safe to log, as a flat array.
     *
     * @return array<string, scalar|null>
     */
    abstract public function fields(): array;
}
