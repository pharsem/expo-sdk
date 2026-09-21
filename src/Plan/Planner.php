<?php

declare(strict_types=1);

namespace Expo\Push\Plan;

use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Exception\MessageTooLargeException;
use Expo\Push\PushMessage;
use Expo\Push\PushTicket;
use Expo\Push\PushToken;
use Expo\Push\Result\ReceiptReference;
use Expo\Push\Result\SendResult;
use Expo\Push\Support\Json;
use Expo\Push\TicketCollection;
use Generator;

/**
 * Turns your input into a normalized plan, before the first request.
 *
 * The planner reads the whole input, checks every item, and encodes every
 * message. An invalid message in the last chunk therefore raises an exception
 * before the first chunk goes out.
 */
final class Planner
{
    /**
     * @param PushMessage|iterable<array-key, PushMessage> $messages
     * @param bool                                         $validateSize checks the payload estimate of every message
     * @param int                                          $sizeLimit    the byte limit for that check
     *
     * @throws InvalidMessageException   when an item is not a message, a reference repeats, or a value is not JSON
     * @throws MessageTooLargeException  when a message is above the limit
     */
    public static function plan(
        PushMessage|iterable $messages,
        bool $validateSize = true,
        int $sizeLimit = PushMessage::MAX_SIZE,
    ): SendPlan {
        $notifications = [];
        $payloads = [];
        $references = [];
        $index = 0;
        $ordinal = 0;

        foreach (self::iterate($messages) as $key => $message) {
            $base = $message->toExpoArray();
            unset($base['to']);

            $size = Json::byteSize($base === [] ? new \stdClass() : $base, sprintf('message %s', (string) $key));

            if ($validateSize && $size > $sizeLimit) {
                throw new MessageTooLargeException($size, $sizeLimit);
            }

            if ($message->reference !== null) {
                if (isset($references[$message->reference])) {
                    throw new InvalidMessageException(sprintf(
                        'Two messages of this operation use the reference "%s". A reference must name one message.',
                        $message->reference
                    ));
                }

                $references[$message->reference] = true;
            }

            $payloads[] = new MessagePayload($base, $key, $message->reference, $size);

            foreach ($message->to as $position => $token) {
                $notifications[] = new PlannedNotification(
                    index: $index++,
                    messageKey: $key,
                    messageOrdinal: $ordinal,
                    recipientIndex: $position,
                    token: $token,
                    reference: $message->reference,
                );
            }

            ++$ordinal;
        }

        return new SendPlan($notifications, $payloads);
    }

    /**
     * Builds a receipt lookup plan out of almost anything that holds receipt IDs.
     *
     * The planner drops a repeated ID and keeps every reference that pointed at
     * it. Two references that give the same ID two different devices raise an
     * exception before any request goes out.
     *
     * @param SendResult|TicketCollection|PushTicket|ReceiptReference|string|iterable<mixed> $input
     *
     * @throws InvalidMessageException
     */
    public static function planReceipts(
        SendResult|TicketCollection|PushTicket|ReceiptReference|string|iterable $input,
    ): ReceiptPlan {
        $ids = [];
        $first = [];
        $all = [];

        foreach (self::references($input) as $reference) {
            $id = $reference->id;

            if ($id === '') {
                throw new InvalidMessageException('A receipt ID must not be empty.');
            }

            if (!isset($first[$id])) {
                $ids[] = $id;
                $first[$id] = $reference;
                $all[$id] = [$reference];

                continue;
            }

            $known = $first[$id]->token;
            $incoming = $reference->token;

            if ($known !== null && $incoming !== null && !$known->equals($incoming)) {
                throw new InvalidMessageException(sprintf(
                    'The receipt ID "%s" is given two different device tokens. Fix the input before the lookup.',
                    $id
                ));
            }

            if ($known === null && $incoming !== null) {
                $first[$id] = $first[$id]->withToken($incoming);
            }

            $all[$id][] = $reference;
        }

        return new ReceiptPlan($ids, $first, $all);
    }

    /**
     * Groups messages into chunks that fit one request, without building a plan.
     *
     * Use it to put one chunk on a queue for each job. A message with more
     * devices than the limit becomes several messages.
     *
     * There is no whole operation check here: a broken message in a later chunk
     * shows up only when that chunk runs.
     *
     * @param PushMessage|iterable<array-key, PushMessage> $messages
     *
     * @return Generator<int, list<PushMessage>>
     */
    public static function lazyChunks(PushMessage|iterable $messages, int $limit): Generator
    {
        if ($limit < 1) {
            throw new InvalidMessageException('The chunk limit must be 1 or more.');
        }

        $current = [];
        $room = $limit;
        $number = 0;

        foreach (self::iterate($messages) as $message) {
            $tokens = $message->to;
            $total = count($tokens);
            $offset = 0;

            while ($offset < $total) {
                if ($room === 0) {
                    yield $number++ => $current;
                    $current = [];
                    $room = $limit;
                }

                $take = min($room, $total - $offset);
                $current[] = $take === $total
                    ? $message
                    : $message->recipients(array_slice($tokens, $offset, $take));

                $offset += $take;
                $room -= $take;
            }
        }

        if ($current !== []) {
            yield $number => $current;
        }
    }

    /**
     * @param PushMessage|iterable<array-key, PushMessage> $messages
     *
     * @return Generator<array-key, PushMessage>
     */
    private static function iterate(PushMessage|iterable $messages): Generator
    {
        if ($messages instanceof PushMessage) {
            yield 0 => $messages;

            return;
        }

        foreach ($messages as $key => $message) {
            if (!$message instanceof PushMessage) {
                throw new InvalidMessageException(sprintf(
                    'Every item must be a PushMessage. Item %s is a %s.',
                    (string) $key,
                    get_debug_type($message)
                ));
            }

            yield $key => $message;
        }
    }

    /**
     * @param SendResult|TicketCollection|PushTicket|ReceiptReference|string|iterable<mixed> $input
     *
     * @return Generator<int, ReceiptReference>
     */
    private static function references(
        SendResult|TicketCollection|PushTicket|ReceiptReference|string|iterable $input,
    ): Generator {
        if ($input instanceof SendResult) {
            yield from $input->receiptReferences();

            return;
        }

        if ($input instanceof TicketCollection) {
            yield from $input->references();

            return;
        }

        if ($input instanceof ReceiptReference) {
            yield $input;

            return;
        }

        if ($input instanceof PushTicket) {
            $reference = $input->receiptReference();

            if ($reference !== null) {
                yield $reference;
            }

            return;
        }

        if (is_string($input)) {
            yield new ReceiptReference($input);

            return;
        }

        foreach ($input as $item) {
            if (
                $item instanceof SendResult
                || $item instanceof TicketCollection
                || $item instanceof ReceiptReference
                || $item instanceof PushTicket
                || is_string($item)
            ) {
                yield from self::references($item);

                continue;
            }

            throw new InvalidMessageException(sprintf(
                'Every item must be a receipt ID, a PushTicket, a ReceiptReference, a TicketCollection or a '
                . 'SendResult. Found a %s.',
                get_debug_type($item)
            ));
        }
    }

    /**
     * Builds a reference for a raw ID, with a token that you supply yourself.
     *
     * A raw ID carries no device. The SDK never guesses one.
     */
    public static function reference(string $id, ?PushToken $token = null): ReceiptReference
    {
        return new ReceiptReference($id, $token);
    }
}
