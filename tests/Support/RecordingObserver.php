<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support;

use Expo\Push\Observability\Event;
use Expo\Push\Observability\Observer;
use RuntimeException;

/**
 * An observer that keeps every event, and can fail on demand.
 */
final class RecordingObserver implements Observer
{
    /** @var list<Event> */
    public array $events = [];

    /**
     * @param class-string<Event>|null $failOn the event class that makes this observer raise
     */
    public function __construct(private readonly ?string $failOn = null)
    {
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        $this->events[] = $event;

        if ($this->failOn !== null && $event instanceof $this->failOn) {
            throw new RuntimeException('the observer of the application is broken');
        }
    }

    /**
     * @param class-string<Event> $class
     *
     * @return list<Event>
     */
    public function ofType(string $class): array
    {
        return array_values(array_filter($this->events, static fn (Event $event): bool => $event instanceof $class));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (Event $event): string => $event->name(), $this->events);
    }

    /**
     * Every field of every event, as one flat list. A test reads it to prove that
     * no token and no body reached the observer.
     *
     * @return list<string>
     */
    public function textFields(): array
    {
        $texts = [];

        foreach ($this->events as $event) {
            foreach ($event->fields() as $key => $value) {
                if (is_string($value)) {
                    $texts[] = $key . '=' . $value;
                }
            }
        }

        return $texts;
    }
}
