<?php

declare(strict_types=1);

namespace Expo\Push\RateLimit;

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Support\Clock;
use Expo\Push\Support\SystemClock;

/**
 * A weighted sliding window limiter for one process.
 *
 * The window moves with every call, so the limiter never lets a burst through at
 * a window boundary. A fixed window of one second allows 600 notifications at
 * 0.999 s and 600 more at 1.001 s. This one does not.
 *
 * The default is 600 notifications per second, the documented Expo limit for one
 * project. Each bucket keeps its own window.
 *
 * One instance coordinates every client that shares it inside one PHP process.
 * A second process, a second pod and a second worker each get their own window.
 * Write a `RateLimiter` against a shared store when you need one limit for all
 * of them.
 */
final class SlidingWindowRateLimiter implements RateLimiter
{
    /**
     * The rate that Expo documents for one project.
     */
    public const int EXPO_NOTIFICATIONS_PER_SECOND = 600;

    /** @var array<string, list<array{0: int, 1: int}>> */
    private array $windows = [];

    /**
     * @param int   $permitsPerWindow how many notifications fit in one window
     * @param int   $windowMs         the length of the window in milliseconds
     * @param Clock $clock            the monotonic clock that measures the window
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(
        private readonly int $permitsPerWindow = self::EXPO_NOTIFICATIONS_PER_SECOND,
        private readonly int $windowMs = 1_000,
        private readonly Clock $clock = new SystemClock(),
    ) {
        if ($permitsPerWindow < 1) {
            throw new InvalidConfigurationException('The rate limiter needs a capacity of 1 or more.');
        }

        if ($windowMs < 1) {
            throw new InvalidConfigurationException('The rate limiter needs a window of 1 millisecond or more.');
        }
    }

    #[\Override]
    public function capacity(): int
    {
        return $this->permitsPerWindow;
    }

    #[\Override]
    public function acquire(string $bucket, int $permits): PermitDecision
    {
        if ($permits < 1) {
            return PermitDecision::granted();
        }

        if ($permits > $this->permitsPerWindow) {
            throw new InvalidConfigurationException(sprintf(
                'A request of %d notifications can never fit a limiter of %d permits for each window.',
                $permits,
                $this->permitsPerWindow
            ));
        }

        $now = $this->clock->monotonicMillis();
        $entries = $this->prune($bucket, $now);

        $used = 0;

        foreach ($entries as $entry) {
            $used += $entry[1];
        }

        if ($used + $permits <= $this->permitsPerWindow) {
            $entries[] = [$now, $permits];
            $this->windows[$bucket] = $entries;

            return PermitDecision::granted();
        }

        $needed = $used + $permits - $this->permitsPerWindow;
        $freed = 0;

        foreach ($entries as $entry) {
            $freed += $entry[1];

            if ($freed >= $needed) {
                return PermitDecision::wait(max(1, $entry[0] + $this->windowMs - $now));
            }
        }

        // Every entry has to leave the window before this request fits.
        return PermitDecision::wait($this->windowMs);
    }

    /**
     * The permits that a bucket can get right now. Useful in a test.
     */
    public function available(string $bucket): int
    {
        $entries = $this->prune($bucket, $this->clock->monotonicMillis());
        $used = 0;

        foreach ($entries as $entry) {
            $used += $entry[1];
        }

        return max(0, $this->permitsPerWindow - $used);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function prune(string $bucket, int $now): array
    {
        $entries = $this->windows[$bucket] ?? [];
        $oldest = $now - $this->windowMs;
        $kept = [];

        foreach ($entries as $entry) {
            if ($entry[0] > $oldest) {
                $kept[] = $entry;
            }
        }

        $this->windows[$bucket] = $kept;

        return $kept;
    }
}
