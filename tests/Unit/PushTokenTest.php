<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\Exception\InvalidTokenException;
use Expo\Push\PushToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushTokenTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function validTokens(): array
    {
        return [
            ['ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]'],
            ['ExpoPushToken[xxxxxxxxxxxxxxxxxxxxxx]'],
            ['F5E9A0A6-0E4C-4B0C-8E4E-9E2C0A5F0B31'],
            ['f5e9a0a6-0e4c-4b0c-8e4e-9e2c0a5f0b31'],
        ];
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidTokens(): array
    {
        return [
            [''],
            ['not-a-token'],
            ['ExponentPushToken[]'],
            ['ExponentPushToken[abc'],
            ['fcm-token-from-another-service'],
            ['ExponentPushToken[with space]'],
            ['ExponentPushToken[abc]extra'],
            ['prefix ExponentPushToken[abc]'],
            ['f5e9a0a6-0e4c-4b0c-8e4e'],
        ];
    }

    #[DataProvider('validTokens')]
    public function testItAcceptsAValidToken(string $value): void
    {
        self::assertTrue(PushToken::isValid($value));
        self::assertSame($value, (new PushToken($value))->value);
    }

    #[DataProvider('invalidTokens')]
    public function testItRejectsAnInvalidToken(string $value): void
    {
        self::assertFalse(PushToken::isValid($value));
        self::assertNull(PushToken::tryFrom($value));

        $this->expectException(InvalidTokenException::class);

        new PushToken($value);
    }

    public function testItTrimsWhitespace(): void
    {
        $token = new PushToken("  ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]\n");

        self::assertSame('ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]', $token->value);
    }

    public function testItNeverChangesTheCaseOfTheOpaquePart(): void
    {
        $mixed = 'ExponentPushToken[AbCdEfGhIjKlMnOpQrStUv]';

        self::assertSame($mixed, (new PushToken($mixed))->value);
        self::assertSame($mixed, (string) PushToken::from($mixed));
    }

    public function testTheFingerprintIsStableAndHidesTheToken(): void
    {
        $token = new PushToken('ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]');
        $same = new PushToken('ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]');
        $other = new PushToken('ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]');

        self::assertSame($token->fingerprint(), $same->fingerprint());
        self::assertNotSame($token->fingerprint(), $other->fingerprint());
        self::assertStringNotContainsString('aaaaaaaa', $token->fingerprint());
        self::assertStringStartsWith('tok_', $token->fingerprint());
    }

    public function testAValidShapeSaysNothingAboutRegistration(): void
    {
        // The SDK cannot know whether a device still accepts notifications. Only
        // a DeviceNotRegistered ticket or receipt says that.
        self::assertTrue(PushToken::isValid('ExponentPushToken[deleted-app-000000]'));
    }

    public function testTheHelperAndTheConstructorAgree(): void
    {
        $values = [
            '  ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]  ',
            'ExponentPushToken[]',
            '',
            "	ExpoPushToken[yyyy]
",
        ];

        foreach ($values as $value) {
            $valid = PushToken::isValid($value);

            self::assertSame($valid, PushToken::tryFrom($value) instanceof PushToken, $value);
            self::assertSame($valid, \Expo\Push\Expo::isExpoPushToken($value), $value);
        }
    }

    public function testItComparesAndCastsToString(): void
    {
        $a = new PushToken('ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]');
        $b = PushToken::from('ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]');
        $c = PushToken::from(new PushToken('ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]'));

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
        self::assertSame('ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]', (string) $a);
        self::assertSame('"ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]"', json_encode($a));
    }
}
