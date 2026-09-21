<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Http\HttpResponse;
use Expo\Push\Retry\RetryAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryAfterTest extends TestCase
{
    private const int NOW = 1_700_000_000_000;

    /**
     * @return iterable<string, array{0: string|null, 1: int|null}>
     */
    public static function values(): iterable
    {
        yield 'missing' => [null, null];
        yield 'empty' => ['', null];
        yield 'whitespace' => ['   ', null];
        yield 'zero' => ['0', 0];
        yield 'seconds' => ['30', 30_000];
        yield 'seconds with whitespace' => ["  45\t", 45_000];
        yield 'long seconds' => ['120', 120_000];
        yield 'negative' => ['-5', null];
        yield 'decimal' => ['1.5', null];
        yield 'text' => ['soon', null];
        yield 'overflow' => ['99999999999999999999', RetryAfter::MAX_MILLIS];
        yield 'relative text' => ['next monday', null];
    }

    #[DataProvider('values')]
    public function testItParsesAHeaderValue(?string $value, ?int $expected): void
    {
        self::assertSame($expected, RetryAfter::parse($value, self::NOW));
    }

    public function testItReadsAnHttpDateInTheFuture(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', intdiv(self::NOW, 1000) + 90);

        self::assertSame(90_000, RetryAfter::parse($at, self::NOW));
    }

    public function testADateInThePastMeansRetryNow(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', intdiv(self::NOW, 1000) - 500);

        self::assertSame(0, RetryAfter::parse($at, self::NOW));
    }

    public function testItReadsTheAsctimeFormat(): void
    {
        $at = gmdate('D M j H:i:s Y', intdiv(self::NOW, 1000) + 10);

        self::assertSame(10_000, RetryAfter::parse($at, self::NOW));
    }

    public function testADateFarInTheFutureStopsAtTheCap(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', intdiv(self::NOW, 1000) + 400_000);

        self::assertSame(RetryAfter::MAX_MILLIS, RetryAfter::parse($at, self::NOW));
    }

    public function testTheHeaderNameIsCaseInsensitive(): void
    {
        $response = new HttpResponse(429, '', ['ReTrY-AfTeR' => '12']);

        self::assertSame(12_000, RetryAfter::fromResponse($response, self::NOW));
    }

    public function testARepeatedHeaderUsesTheFirstValue(): void
    {
        $response = new HttpResponse(429, '', ['retry-after' => ['5', '99']]);

        self::assertSame(5_000, RetryAfter::fromResponse($response, self::NOW));
        self::assertSame(['5', '99'], $response->headerValues('Retry-After'));
    }

    public function testAResponseWithoutTheHeaderGivesNull(): void
    {
        self::assertNull(RetryAfter::fromResponse(new HttpResponse(500, ''), self::NOW));
    }
}
