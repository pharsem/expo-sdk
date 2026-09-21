<?php

declare(strict_types=1);

/**
 * A rate limiter that many processes share.
 *
 * The SDK bundles no Redis client and no distributed lock. It gives you one
 * small interface, and two rules that make a shared limiter correct:
 *
 * 1. `acquire()` must be atomic. Two workers must never both get the last
 *    permits of the same window. A Lua script, a stored procedure or a database
 *    transaction gives you that. A read, then a write, does not.
 * 2. `acquire()` must never sleep. Return `PermitDecision::wait()` and let the
 *    SDK schedule the wait, so the other chunks keep moving.
 *
 * Run it with:
 *   php examples/shared-limiter.php
 */

require __DIR__ . '/bootstrap.php';

use Expo\Push\Expo;
use Expo\Push\PushMessage;
use Expo\Push\RateLimit\PermitDecision;
use Expo\Push\RateLimit\RateLimiter;

/**
 * A stand in for a real shared limiter.
 *
 * The comments show where the Redis call would go. The behaviour is the same:
 * one atomic step that either grants the permits or says how long to wait.
 */
final class FakeSharedLimiter implements RateLimiter
{
    /** @var array<string, array{0: int, 1: int}> */
    private array $windows = [];

    public function __construct(
        private readonly int $permitsPerSecond = 600,
        private readonly bool $unreachable = false,
    ) {
    }

    public function acquire(string $bucket, int $permits): PermitDecision
    {
        if ($this->unreachable) {
            // A shared limiter that cannot answer must raise. The SDK then stops
            // and keeps every result so far. It never sends without a limit.
            throw new RuntimeException('the limiter store is unreachable');
        }

        // In Redis this whole block is one Lua script:
        //
        //   local used = redis.call('INCRBY', KEYS[1], ARGV[1])
        //   if used == tonumber(ARGV[1]) then redis.call('PEXPIRE', KEYS[1], 1000) end
        //   if used > tonumber(ARGV[2]) then
        //     redis.call('DECRBY', KEYS[1], ARGV[1])
        //     return redis.call('PTTL', KEYS[1])
        //   end
        //   return 0
        //
        // One round trip, one atomic decision, and no sleeping inside the script.
        $second = (int) floor(microtime(true));
        [$windowSecond, $used] = $this->windows[$bucket] ?? [$second, 0];

        if ($windowSecond !== $second) {
            $windowSecond = $second;
            $used = 0;
        }

        if ($used + $permits > $this->permitsPerSecond) {
            $this->windows[$bucket] = [$windowSecond, $used];

            return PermitDecision::wait(1_000);
        }

        $this->windows[$bucket] = [$windowSecond, $used + $permits];

        return PermitDecision::granted();
    }

    public function capacity(): int
    {
        // The SDK reads this at construction and refuses a chunk size that can
        // never fit, so no request waits forever for a permit that cannot come.
        return $this->permitsPerSecond;
    }
}

exampleHeading('a send through the shared limiter');

$limiter = new FakeSharedLimiter(permitsPerSecond: 600);

$expo = new Expo(
    httpClient: new OfflineTransport(),
    rateLimiter: $limiter,
    rateLimitBucket: 'project-alpha',
);

$result = $expo->send(PushMessage::to(exampleTokens(300))->title('Broadcast'));

printf("accepted: %d of %d\n", count($result->accepted()), $result->count());

exampleHeading('the limiter fails closed');

$broken = new Expo(
    httpClient: new OfflineTransport(),
    rateLimiter: new FakeSharedLimiter(unreachable: true),
    rateLimitBucket: 'project-alpha',
);

$failed = $broken->send(PushMessage::to(exampleTokens(10))->title('Broadcast'));

printf("accepted:      %d\n", count($failed->accepted()));
printf("not attempted: %d\n", count($failed->notAttempted()));

foreach ($failed->requestFailures() as $failure) {
    printf("%s: %s\n", $failure->category->value, $failure->message);
}

printf("\nNothing went out without a permit, and the result names every position.\n");

exampleHeading('what a shared limiter does not fix');

printf("Two projects need two buckets. The SDK never reads a project from a token.\n");
printf("Grouping the notifications of one project into few requests is your work,\n");
printf("unless you give the SDK the grouping yourself.\n");
