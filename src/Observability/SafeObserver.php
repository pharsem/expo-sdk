<?php

declare(strict_types=1);

namespace Expo\Push\Observability;

use Expo\Push\Support\Redact;
use Throwable;

/**
 * Wraps your observer and keeps its failures away from the operation.
 *
 * The SDK builds this for you. It catches every `Throwable` of an observer,
 * counts it, keeps the first few redacted summaries, and stops calling the
 * observer after `MAX_FAILURES`. A broken observer can slow nothing down and can
 * erase no result.
 *
 * The wrapper never reports its own failures through the observer, so one broken
 * call cannot start a loop of error reports.
 */
final class SafeObserver
{
    /**
     * The SDK stops calling an observer after this many failures.
     */
    public const int MAX_FAILURES = 5;

    /**
     * The number of redacted failure summaries that the wrapper keeps.
     */
    public const int MAX_SUMMARIES = 3;

    private int $failures = 0;

    /** @var list<string> */
    private array $summaries = [];

    public function __construct(private readonly Observer $observer)
    {
    }

    public function emit(Event $event): void
    {
        if ($this->failures >= self::MAX_FAILURES) {
            return;
        }

        try {
            $this->observer->onEvent($event);
        } catch (Throwable $exception) {
            ++$this->failures;

            if (count($this->summaries) < self::MAX_SUMMARIES) {
                $this->summaries[] = sprintf('%s: %s', $event->name(), Redact::exception($exception));
            }
        }
    }

    public function failures(): int
    {
        return $this->failures;
    }

    /**
     * @return list<string>
     */
    public function failureSummaries(): array
    {
        return $this->summaries;
    }
}
