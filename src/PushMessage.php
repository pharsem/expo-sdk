<?php

declare(strict_types=1);

namespace Expo\Push;

use BackedEnum;
use DateTimeInterface;
use Expo\Push\Exception\InvalidStorageException;
use Expo\Push\Exception\MessageTooLargeException;
use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Storage\StorageEnvelope;
use Expo\Push\Support\Json;
use JsonSerializable;
use stdClass;

/**
 * One push notification for one or more devices.
 *
 * The object never changes. Every method returns a new message.
 *
 * ```php
 * $message = PushMessage::to($token)
 *     ->title('Your order is on the way')
 *     ->body('It arrives before 18:00.')
 *     ->data(['orderId' => 42]);
 * ```
 *
 * Recipients inside one message are deduplicated, and the first position of a
 * token wins. Two separate messages that hold the same token stay separate: each
 * one is its own notification, and each one gets its own ticket.
 */
final readonly class PushMessage implements JsonSerializable
{
    /**
     * The largest payload that Expo accepts, in bytes.
     */
    public const int MAX_SIZE = 4096;

    public const string STORAGE_TYPE = 'expo.message';

    /** @var list<PushToken> */
    public array $to;

    /** @var array<string, mixed>|stdClass|null */
    public array|stdClass|null $data;

    public ?Sound $sound;

    public ?Priority $priority;

    public ?InterruptionLevel $interruptionLevel;

    /**
     * Use the named arguments, or start from `PushMessage::to()` and chain the methods.
     *
     * @param PushToken|string|iterable<PushToken|string> $to                one or more Expo push tokens
     * @param string|null                                 $title             the title of the notification
     * @param string|null                                 $body              the text of the notification
     * @param array<string, mixed>|stdClass|null          $data              custom JSON that the app reads
     * @param string|null                                 $subtitle          a second line below the title (iOS)
     * @param Sound|string|null                           $sound             the sound to play (iOS)
     * @param int|null                                    $ttl               seconds that Expo keeps the message for redelivery
     * @param int|null                                    $expiration        a Unix timestamp. The ttl field wins over this one
     * @param Priority|string|null                        $priority          the delivery priority
     * @param InterruptionLevel|string|null               $interruptionLevel how much the notification interrupts (iOS)
     * @param int|null                                    $badge             the number on the app icon (iOS)
     * @param string|null                                 $channelId         the notification channel (Android)
     * @param string|null                                 $icon              the name of a drawable resource (Android)
     * @param string|null                                 $image             the URL of an image in the notification
     * @param string|null                                 $categoryId        the category that holds the action buttons
     * @param bool|null                                   $mutableContent    lets the app change the notification first (iOS)
     * @param bool|null                                   $contentAvailable  starts the app in the background (iOS)
     * @param string|null                                 $collapseId        replaces an earlier message with the same value
     * @param string|null                                 $tag               replaces a notification on screen (Android)
     * @param string|null                                 $threadId          groups notifications on screen (iOS)
     * @param string|null                                 $targetContentId   the window to bring forward (iOS)
     * @param float|null                                  $relevanceScore    a value from 0.0 to 1.0 for the summary (iOS)
     * @param string|null                                 $filterCriteria    the Focus filter criteria (iOS)
     * @param string|null                                 $reference         your own correlation value. The SDK never sends it to Expo
     *
     * @throws InvalidMessageException when a value is out of range or the token list is empty
     */
    public function __construct(
        PushToken|string|iterable $to,
        public ?string $title = null,
        public ?string $body = null,
        array|stdClass|null $data = null,
        public ?string $subtitle = null,
        Sound|string|null $sound = null,
        public ?int $ttl = null,
        public ?int $expiration = null,
        Priority|string|null $priority = null,
        InterruptionLevel|string|null $interruptionLevel = null,
        public ?int $badge = null,
        public ?string $channelId = null,
        public ?string $icon = null,
        public ?string $image = null,
        public ?string $categoryId = null,
        public ?bool $mutableContent = null,
        public ?bool $contentAvailable = null,
        public ?string $collapseId = null,
        public ?string $tag = null,
        public ?string $threadId = null,
        public ?string $targetContentId = null,
        public ?float $relevanceScore = null,
        public ?string $filterCriteria = null,
        public ?string $reference = null,
    ) {
        $this->to = self::normalizeTokens($to);
        $this->data = self::normalizeData($data);
        $this->sound = $sound === null ? null : Sound::from($sound);
        $this->priority = $priority === null ? null : self::toPriority($priority);
        $this->interruptionLevel = $interruptionLevel === null
            ? null
            : self::toInterruptionLevel($interruptionLevel);

        if ($this->badge !== null && $this->badge < 0) {
            throw new InvalidMessageException('The badge must be zero or more.');
        }

        if ($this->ttl !== null && $this->ttl < 0) {
            throw new InvalidMessageException('The ttl must be zero or more seconds.');
        }

        if ($this->relevanceScore !== null && !is_finite($this->relevanceScore)) {
            throw new InvalidMessageException('The relevance score must be a finite number.');
        }

        if ($this->relevanceScore !== null && ($this->relevanceScore < 0.0 || $this->relevanceScore > 1.0)) {
            throw new InvalidMessageException('The relevance score must be between 0.0 and 1.0.');
        }

        foreach (self::textFields() as $name) {
            /** @var string|null $value */
            $value = $this->{$name};

            if ($value !== null && !Json::isUtf8($value)) {
                throw new InvalidMessageException(sprintf('The %s is not valid UTF-8.', $name));
            }
        }
    }

    /**
     * Starts a message for one or more devices.
     *
     * @param PushToken|string|iterable<PushToken|string> $to
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public static function to(PushToken|string|iterable $to): self
    {
        return new self($to);
    }

    /**
     * Replaces every device of the message.
     *
     * @param PushToken|string|iterable<PushToken|string> $to
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function recipients(PushToken|string|iterable $to): self
    {
        return $this->with('to', self::normalizeTokens($to));
    }

    /**
     * Adds more devices to the message, and keeps the first position of each token.
     *
     * @param PushToken|string|iterable<PushToken|string> $to
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function addRecipients(PushToken|string|iterable $to): self
    {
        $tokens = $this->to;

        foreach (self::normalizeTokens($to) as $token) {
            $tokens[] = $token;
        }

        return $this->with('to', self::unique($tokens));
    }

    public function recipientCount(): int
    {
        return count($this->to);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function title(?string $title): self
    {
        return $this->with('title', $title);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function body(?string $body): self
    {
        return $this->with('body', $body);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function subtitle(?string $subtitle): self
    {
        return $this->with('subtitle', $subtitle);
    }

    /**
     * Replaces the custom JSON that the app reads.
     *
     * The value must be a JSON object: an associative array, an empty array, or a
     * `stdClass`. A list such as `[1, 2, 3]` is not a JSON object, so the SDK
     * rejects it. An empty array goes on the wire as `{}`.
     *
     * The SDK copies the value. A later change to your own array or object cannot
     * reach the message.
     *
     * @param array<string, mixed>|stdClass|null $data
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function data(array|stdClass|null $data): self
    {
        return $this->with('data', $data);
    }

    /**
     * Adds one key to the custom JSON and keeps the other keys.
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function withDatum(string $key, mixed $value): self
    {
        $data = $this->data;

        if ($data instanceof stdClass) {
            $copy = clone $data;
            $copy->{$key} = $value;

            return $this->with('data', $copy);
        }

        $copy = $data ?? [];
        $copy[$key] = $value;

        return $this->with('data', $copy);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function sound(Sound|string|null $sound): self
    {
        return $this->with('sound', $sound);
    }

    /**
     * Sends the notification without a sound.
     *
     * This is not the same as leaving the sound out: the SDK writes the literal
     * `"sound": null` on the wire.
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function silent(): self
    {
        return $this->with('sound', Sound::none());
    }

    /**
     * @param int|null $seconds the time that Expo keeps the message for redelivery
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function ttl(?int $seconds): self
    {
        return $this->with('ttl', $seconds);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function expiration(DateTimeInterface|int|null $expiration): self
    {
        return $this->with(
            'expiration',
            $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() : $expiration
        );
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function priority(Priority|string|null $priority): self
    {
        return $this->with('priority', $priority);
    }

    /**
     * Asks the platform to deliver the message at once.
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function highPriority(): self
    {
        return $this->with('priority', Priority::High);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function interruptionLevel(InterruptionLevel|string|null $level): self
    {
        return $this->with('interruptionLevel', $level);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function badge(?int $badge): self
    {
        return $this->with('badge', $badge);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function channelId(?string $channelId): self
    {
        return $this->with('channelId', $channelId);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function icon(?string $icon): self
    {
        return $this->with('icon', $icon);
    }

    /**
     * @param string|null $url the URL of an image that the notification shows
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function image(?string $url): self
    {
        return $this->with('image', $url);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function categoryId(?string $categoryId): self
    {
        return $this->with('categoryId', $categoryId);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function mutableContent(?bool $mutableContent = true): self
    {
        return $this->with('mutableContent', $mutableContent);
    }

    /**
     * Wakes the iOS app in the background to handle the message.
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function contentAvailable(?bool $contentAvailable = true): self
    {
        return $this->with('contentAvailable', $contentAvailable);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function collapseId(?string $collapseId): self
    {
        return $this->with('collapseId', $collapseId);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function tag(?string $tag): self
    {
        return $this->with('tag', $tag);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function threadId(?string $threadId): self
    {
        return $this->with('threadId', $threadId);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function targetContentId(?string $targetContentId): self
    {
        return $this->with('targetContentId', $targetContentId);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function relevanceScore(?float $score): self
    {
        return $this->with('relevanceScore', $score);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function filterCriteria(?string $criteria): self
    {
        return $this->with('filterCriteria', $criteria);
    }

    /**
     * Your own correlation value. The SDK keeps it on every result and in storage,
     * and never sends it to Expo.
     *
     * Every recipient of this message shares the reference. Two different messages
     * in one operation must not share one.
     *
     * A reference is not an idempotency key. It does not stop a duplicate.
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function reference(?string $reference): self
    {
        return $this->with('reference', $reference);
    }

    /**
     * An estimate of the payload size in bytes, for one device.
     *
     * The number counts the JSON that the SDK sends to Expo for this message,
     * without the `to` field. It is not the size of the final Apple or Google
     * payload, and gzip on the request does not make it smaller. Expo can still
     * answer `MessageTooBig` for a message that passes this check.
     *
     * @throws InvalidMessageException when the message does not encode as JSON
     */
    public function sizeInBytes(): int
    {
        $payload = $this->toExpoArray();
        unset($payload['to']);

        return Json::byteSize($payload === [] ? new stdClass() : $payload, 'message');
    }

    /**
     * Raises `MessageTooLargeException` when the estimate is above the Expo limit.
     *
     * @throws MessageTooLargeException
     * @throws InvalidMessageException
     */
    public function assertWithinSizeLimit(int $limit = self::MAX_SIZE): void
    {
        $size = $this->sizeInBytes();

        if ($size > $limit) {
            throw new MessageTooLargeException($size, $limit);
        }
    }

    /**
     * Splits the message into one message for each device.
     *
     * @return list<self>
     */
    #[\NoDiscard('Use the new messages that this method returns.')]
    public function perRecipient(): array
    {
        return array_map(fn (PushToken $token): self => $this->with('to', [$token]), $this->to);
    }

    /**
     * The message in the shape that the Expo API reads.
     *
     * The `reference` field is not part of it. Expo never sees your correlation
     * value.
     *
     * @return array<string, mixed>
     */
    public function toExpoArray(): array
    {
        $payload = [
            'to' => count($this->to) === 1
                ? $this->to[0]->value
                : array_map(static fn (PushToken $token): string => $token->value, $this->to),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'body' => $this->body,
            'data' => $this->data === [] ? new stdClass() : $this->data,
            'sound' => $this->sound?->jsonSerialize(),
            'ttl' => $this->ttl,
            'expiration' => $this->expiration,
            'priority' => $this->priority?->value,
            'interruptionLevel' => $this->interruptionLevel?->value,
            'badge' => $this->badge,
            'channelId' => $this->channelId,
            'icon' => $this->icon,
            'richContent' => $this->image === null ? null : ['image' => $this->image],
            'categoryId' => $this->categoryId,
            'mutableContent' => $this->mutableContent,
            'contentAvailable' => $this->contentAvailable,
            'collapseId' => $this->collapseId,
            'tag' => $this->tag,
            'threadId' => $this->threadId,
            'targetContentId' => $this->targetContentId,
            'relevanceScore' => $this->relevanceScore,
            'filterCriteria' => $this->filterCriteria,
        ];

        // A silent notification needs the literal null on the wire, so put that
        // key back after the filter. `false` and `0` are meaningful values and
        // the filter keeps them.
        $silent = $this->sound !== null && $this->sound->name === null && !$this->sound->critical;

        $payload = array_filter($payload, static fn (mixed $value): bool => $value !== null);

        if ($silent) {
            $payload['sound'] = null;
        }

        return $payload;
    }

    /**
     * The message in the shape that the SDK stores and reads back.
     *
     * The storage shape holds the tokens and your reference. It is application
     * data, not a log line.
     *
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        $payload = $this->toExpoArray();
        $payload['to'] = array_map(static fn (PushToken $token): string => $token->value, $this->to);

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }

        return StorageEnvelope::wrap(self::STORAGE_TYPE, $payload);
    }

    /**
     * Builds a message from `toStorageArray()`.
     *
     * @param array<string, mixed> $stored
     *
     * @throws InvalidStorageException
     * @throws InvalidMessageException
     */
    public static function fromStorageArray(array $stored): self
    {
        $data = StorageEnvelope::unwrap(self::STORAGE_TYPE, $stored);

        $to = $data['to'] ?? null;

        if (is_string($to)) {
            $to = [$to];
        }

        if (!is_array($to) || $to === []) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'to');
        }

        $tokens = [];

        foreach ($to as $value) {
            if (!is_string($value)) {
                throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'to');
            }

            $tokens[] = $value;
        }

        $rich = $data['richContent'] ?? null;
        $image = is_array($rich) && isset($rich['image']) && is_string($rich['image']) ? $rich['image'] : null;

        $custom = self::storedData($data);

        return new self(
            to: $tokens,
            title: self::storedString($data, 'title'),
            body: self::storedString($data, 'body'),
            data: $custom,
            subtitle: self::storedString($data, 'subtitle'),
            sound: array_key_exists('sound', $data) ? Sound::fromStored($data['sound']) : null,
            ttl: self::storedInt($data, 'ttl'),
            expiration: self::storedInt($data, 'expiration'),
            priority: self::storedString($data, 'priority'),
            interruptionLevel: self::storedString($data, 'interruptionLevel'),
            badge: self::storedInt($data, 'badge'),
            channelId: self::storedString($data, 'channelId'),
            icon: self::storedString($data, 'icon'),
            image: $image,
            categoryId: self::storedString($data, 'categoryId'),
            mutableContent: self::storedBool($data, 'mutableContent'),
            contentAvailable: self::storedBool($data, 'contentAvailable'),
            collapseId: self::storedString($data, 'collapseId'),
            tag: self::storedString($data, 'tag'),
            threadId: self::storedString($data, 'threadId'),
            targetContentId: self::storedString($data, 'targetContentId'),
            relevanceScore: self::storedFloat($data, 'relevanceScore'),
            filterCriteria: self::storedString($data, 'filterCriteria'),
            reference: self::storedString($data, 'reference'),
        );
    }

    /**
     * The same shape as `toExpoArray()`.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toExpoArray();
    }

    /**
     * Copies the message and changes one property.
     *
     * Every property of this final class is a constructor argument, so the copy
     * passes them back as named arguments.
     */
    private function with(string $property, mixed $value): self
    {
        /** @var array<string, mixed> $arguments */
        $arguments = get_object_vars($this);
        $arguments[$property] = $value;

        return new self(...$arguments);
    }

    /**
     * @return list<string>
     */
    private static function textFields(): array
    {
        return [
            'title', 'body', 'subtitle', 'channelId', 'icon', 'image', 'categoryId',
            'collapseId', 'tag', 'threadId', 'targetContentId', 'filterCriteria', 'reference',
        ];
    }

    /**
     * @param array<string, mixed>|stdClass|null $data
     *
     * @return array<string, mixed>|stdClass|null
     */
    private static function normalizeData(array|stdClass|null $data): array|stdClass|null
    {
        if ($data === null) {
            return null;
        }

        if (is_array($data) && $data !== [] && array_is_list($data)) {
            throw new InvalidMessageException(
                'The data field must be a JSON object. A list such as [1, 2, 3] has no keys, so the app cannot '
                . 'read it. Give an associative array or a stdClass.'
            );
        }

        /** @var array<string, mixed>|stdClass $snapshot */
        $snapshot = Json::snapshot($data);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private static function storedData(array $data): ?array
    {
        $value = $data['data'] ?? null;

        if ($value === null) {
            return null;
        }

        if ($value instanceof stdClass) {
            $array = Json::objectToArray($value);

            return $array === [] ? [] : $array;
        }

        if (!is_array($value)) {
            throw InvalidStorageException::missingField(self::STORAGE_TYPE, 'data');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function storedString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function storedInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function storedBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function storedFloat(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    /**
     * @param PushToken|string|iterable<PushToken|string> $to
     *
     * @return list<PushToken>
     */
    private static function normalizeTokens(PushToken|string|iterable $to): array
    {
        if ($to instanceof PushToken || is_string($to)) {
            $to = [$to];
        }

        $tokens = [];

        foreach ($to as $token) {
            if (!$token instanceof PushToken && !is_string($token)) {
                throw new InvalidMessageException('Every recipient must be a PushToken or a string.');
            }

            $tokens[] = PushToken::from($token);
        }

        if ($tokens === []) {
            throw new InvalidMessageException('A message needs at least one recipient.');
        }

        return self::unique($tokens);
    }

    /**
     * Keeps the first position of every token.
     *
     * @param list<PushToken> $tokens
     *
     * @return list<PushToken>
     */
    private static function unique(array $tokens): array
    {
        $seen = [];

        foreach ($tokens as $token) {
            if (!isset($seen[$token->value])) {
                $seen[$token->value] = $token;
            }
        }

        return array_values($seen);
    }

    private static function toPriority(Priority|string $value): Priority
    {
        return $value instanceof Priority ? $value : self::case(Priority::class, $value);
    }

    private static function toInterruptionLevel(InterruptionLevel|string $value): InterruptionLevel
    {
        return $value instanceof InterruptionLevel ? $value : self::case(InterruptionLevel::class, $value);
    }

    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function case(string $enum, string $value): BackedEnum
    {
        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw new InvalidMessageException(sprintf(
                '"%s" is not a valid value. Use one of: %s.',
                $value,
                implode(', ', array_column($enum::cases(), 'value'))
            ));
        }

        return $case;
    }
}
