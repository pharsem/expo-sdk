<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Support\Redact;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RedactTest extends TestCase
{
    public function testAFingerprintIsStableAndHidesTheToken(): void
    {
        $token = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

        self::assertSame(Redact::token($token), Redact::token($token));
        self::assertStringNotContainsString('aaaaaaaa', Redact::token($token));
        self::assertSame(16, strlen(Redact::token($token)));
    }

    public function testItRemovesATokenFromAText(): void
    {
        $text = 'the device ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa] is gone';

        self::assertSame('the device [token] is gone', Redact::text($text));
    }

    public function testItRemovesTheShortTokenFormToo(): void
    {
        self::assertSame('bad [token]', Redact::text('bad ExpoPushToken[zzzz]'));
    }

    public function testItRemovesABearerCredential(): void
    {
        self::assertSame('Bearer [redacted] refused', Redact::text('Bearer secret-value refused'));
    }

    public function testItRemovesAUuid(): void
    {
        self::assertSame(
            'device [uuid]',
            Redact::text('device f5e9a0a6-0e4c-4b0c-8e4e-9e2c0a5f0b31')
        );
    }

    public function testItCutsALongText(): void
    {
        $long = str_repeat('a', 500);
        $cut = Redact::text($long);

        self::assertNotNull($cut);
        self::assertSame(Redact::MAX_TEXT + 3, strlen($cut));
        self::assertStringEndsWith('...', $cut);
    }

    public function testItCollapsesWhitespace(): void
    {
        self::assertSame('one two three', Redact::text("one\n  two\tthree"));
    }

    public function testAnEmptyTextBecomesNull(): void
    {
        self::assertNull(Redact::text('   '));
        self::assertNull(Redact::text(null));
    }

    public function testAnExceptionSummaryKeepsTheClassAndHidesTheToken(): void
    {
        $summary = Redact::exception(new RuntimeException(
            'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa] failed'
        ));

        self::assertSame('RuntimeException: [token] failed', $summary);
    }

    public function testAnExceptionWithoutAMessageStillReads(): void
    {
        self::assertSame('RuntimeException: (no message)', Redact::exception(new RuntimeException()));
    }
}
