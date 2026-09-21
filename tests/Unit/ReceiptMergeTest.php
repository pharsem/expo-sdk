<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Unit;

use Expo\Push\PushReceipt;
use Expo\Push\PushToken;
use Expo\Push\Result\ReceiptEntry;
use Expo\Push\Result\ReceiptResult;
use Expo\Push\Result\ReceiptState;
use Expo\Push\Tests\Support\TestCase;

/**
 * A merge never loses a contradiction that either side already knew.
 *
 * The reviewed merge kept the conflicts of the left operand only, so
 * `(new ReceiptResult())->merge($conflicted)` answered with an empty list.
 * Receipt equality also ignored the structured details of the provider.
 */
final class ReceiptMergeTest extends TestCase
{
    private static function returned(string $id, PushReceipt $receipt): ReceiptResult
    {
        return new ReceiptResult([new ReceiptEntry($id, ReceiptState::Returned, $receipt)]);
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function error(string $id, string $code = 'DeviceNotRegistered', array $details = []): PushReceipt
    {
        return new PushReceipt(
            $id,
            'error',
            null,
            'the device is gone',
            null,
            $code,
            $details === [] ? ['error' => $code] : $details
        );
    }

    /**
     * The invariant of the review.
     */
    public function testAnEmptyResultOnTheLeftKeepsEveryConflict(): void
    {
        $conflicted = self::returned('r-1', new PushReceipt('r-1', 'ok'))
            ->merge(self::returned('r-1', self::error('r-1')));

        $combined = (new ReceiptResult())->merge($conflicted);

        self::assertSame(['r-1'], $conflicted->conflicts());
        self::assertSame($conflicted->conflicts(), $combined->conflicts());
        self::assertSame('ok', $combined->get('r-1')?->status, 'the winning receipt survives too');
    }

    public function testAnEmptyResultOnTheRightKeepsEveryConflict(): void
    {
        $conflicted = self::returned('r-1', new PushReceipt('r-1', 'ok'))
            ->merge(self::returned('r-1', self::error('r-1')));

        self::assertSame(['r-1'], $conflicted->merge(new ReceiptResult())->conflicts());
    }

    public function testTheGroupingOfAChainOfMergesNeverChangesTheConflicts(): void
    {
        $a = self::returned('r-1', new PushReceipt('r-1', 'ok'));
        $b = self::returned('r-1', self::error('r-1'));
        $c = self::returned('r-2', new PushReceipt('r-2', 'ok'));

        $left = $a->merge($b)->merge($c);
        $right = $a->merge($b->merge($c));

        self::assertSame(['r-1'], $left->conflicts());
        self::assertSame(['r-1'], $right->conflicts());
    }

    public function testARepeatedMergeNeverRepeatsAConflict(): void
    {
        $conflicted = self::returned('r-1', new PushReceipt('r-1', 'ok'))
            ->merge(self::returned('r-1', self::error('r-1')));

        $again = $conflicted->merge($conflicted)->merge($conflicted);

        self::assertSame(['r-1'], $again->conflicts());
        self::assertSame(1, $again->count());
    }

    public function testTwoConflictsOfTwoLookupsBothSurvive(): void
    {
        $first = (new ReceiptResult([
            new ReceiptEntry('r-1', ReceiptState::Returned, new PushReceipt('r-1', 'ok')),
        ]))->merge(new ReceiptResult([
            new ReceiptEntry('r-1', ReceiptState::Returned, self::error('r-1')),
        ]));

        $second = (new ReceiptResult([
            new ReceiptEntry('r-2', ReceiptState::Returned, new PushReceipt('r-2', 'ok')),
        ]))->merge(new ReceiptResult([
            new ReceiptEntry('r-2', ReceiptState::Returned, self::error('r-2')),
        ]));

        self::assertSame(['r-1', 'r-2'], $first->merge($second)->conflicts());
        self::assertSame(['r-2', 'r-1'], $second->merge($first)->conflicts());
    }

    public function testTheSameReceiptTwiceIsNoConflict(): void
    {
        $merged = self::returned('r-1', self::error('r-1'))->merge(self::returned('r-1', self::error('r-1')));

        self::assertSame([], $merged->conflicts());
        self::assertSame(1, $merged->count());
    }

    public function testChangedProviderDetailsAreAConflict(): void
    {
        $first = self::returned('r-1', self::error('r-1', 'ProviderError', [
            'error' => 'ProviderError',
            'fault' => 'apns',
            'code' => 429,
        ]));
        $second = self::returned('r-1', self::error('r-1', 'ProviderError', [
            'error' => 'ProviderError',
            'fault' => 'fcm',
            'code' => 429,
        ]));

        self::assertSame(['r-1'], $first->merge($second)->conflicts());
    }

    public function testDetailsWithTheSameKeysInAnotherOrderAreNoConflict(): void
    {
        $first = self::returned('r-1', self::error('r-1', 'ProviderError', [
            'error' => 'ProviderError',
            'fault' => 'apns',
            'nested' => ['a' => 1, 'b' => 2],
        ]));
        $second = self::returned('r-1', self::error('r-1', 'ProviderError', [
            'nested' => ['b' => 2, 'a' => 1],
            'fault' => 'apns',
            'error' => 'ProviderError',
        ]));

        self::assertSame([], $first->merge($second)->conflicts());
    }

    public function testAListKeepsItsOrderAndNeverEqualsAnObject(): void
    {
        $list = self::returned('r-1', self::error('r-1', 'ProviderError', ['tries' => [1, 2, 3]]));
        $shuffled = self::returned('r-1', self::error('r-1', 'ProviderError', ['tries' => [3, 2, 1]]));
        $object = self::returned('r-1', self::error('r-1', 'ProviderError', ['tries' => (object) [0 => 1, 1 => 2, 2 => 3]]));

        self::assertSame(['r-1'], $list->merge($shuffled)->conflicts(), 'a different order is a different list');
        self::assertSame(['r-1'], $list->merge($object)->conflicts(), 'a JSON object is not a JSON list');
    }

    public function testADetailThatOnlyOneSideHoldsIsAConflict(): void
    {
        $first = self::returned('r-1', self::error('r-1', 'ProviderError', ['error' => 'ProviderError']));
        $second = self::returned('r-1', self::error('r-1', 'ProviderError', [
            'error' => 'ProviderError',
            'extra' => null,
        ]));

        self::assertSame(['r-1'], $first->merge($second)->conflicts());
    }

    /**
     * A returned receipt never falls back to a weaker state.
     */
    public function testAReturnedReceiptSurvivesAnOlderMissingOrFailedLookup(): void
    {
        $returned = self::returned('r-1', new PushReceipt('r-1', 'ok'));
        $missing = new ReceiptResult([new ReceiptEntry('r-1', ReceiptState::Missing)]);
        $failed = new ReceiptResult([new ReceiptEntry('r-1', ReceiptState::LookupFailed)]);

        self::assertSame(ReceiptState::Returned, $returned->merge($missing)->state('r-1'));
        self::assertSame(ReceiptState::Returned, $returned->merge($failed)->state('r-1'));
        self::assertSame('ok', $returned->merge($missing)->get('r-1')?->status);

        // And a later returned receipt does replace a missing one.
        self::assertSame(ReceiptState::Returned, $missing->merge($returned)->state('r-1'));
    }

    public function testAMergeKeepsTheTokenOfEitherSideWithoutRepeatingTheReceipt(): void
    {
        $withToken = new ReceiptResult([
            new ReceiptEntry('r-1', ReceiptState::Missing, null, new PushToken(self::TOKEN_A), 3, 'order-3'),
        ]);
        $withReceipt = self::returned('r-1', new PushReceipt('r-1', 'ok'));

        $merged = $withToken->merge($withReceipt);

        $entry = $merged->entry('r-1');

        self::assertNotNull($entry);
        self::assertSame(1, $merged->count());
        self::assertSame(self::TOKEN_A, $entry->token?->value);
        self::assertSame(3, $entry->notificationIndex);
        self::assertSame('order-3', $entry->reference);
        self::assertSame('ok', $merged->get('r-1')?->status);
        // One receipt, and the correlation of the first lookup on the entry
        // itself. The second lookup asked by bare ID, so it adds no index.
        self::assertSame([3], $entry->notificationIndexes());
    }

    public function testAConflictSurvivesAStorageRoundTrip(): void
    {
        $conflicted = self::returned('r-1', new PushReceipt('r-1', 'ok'))
            ->merge(self::returned('r-1', self::error('r-1')));

        $json = json_encode($conflicted->toStorageArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = ReceiptResult::fromStorageArray($decoded);

        self::assertSame(['r-1'], $restored->conflicts());
        // And a merge of the restored result still keeps it.
        self::assertSame(['r-1'], (new ReceiptResult())->merge($restored)->conflicts());
        self::assertSame(['r-1'], $restored->merge(self::returned('r-1', new PushReceipt('r-1', 'ok')))->conflicts());
    }
}
