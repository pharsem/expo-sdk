<?php

declare(strict_types=1);

namespace Expo\Push;

use BackedEnum;
use DateTimeInterface;
use Expo\Push\Exception\InvalidMessageException;
use JsonSerializable;

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
 */
final readonly class PushMessage implements JsonSerializable
{
    /**
     * The largest payload that Expo accepts, in bytes.
     */
    public const int MAX_SIZE = 4096;

    /** @var list<PushToken> */
    public array $to;

    public ?Sound $sound;

    public ?Priority $priority;

    public ?InterruptionLevel $interruptionLevel;

    /**
     * Use the named arguments, or start from `PushMessage::to()` and chain the methods.
     *
     * @param PushToken|string|iterable<PushToken|string> $to                one or more Expo push tokens
     * @param string|null                                 $title             the title of the notification
     * @param string|null                                 $body              the text of the notification
     * @param array<string, mixed>|null                   $data              custom JSON that the app reads
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
     *
     * @throws InvalidMessageException when a value is out of range or the token list is empty
     */
    public function __construct(
        PushToken|string|iterable $to,
        public ?string $title = null,
        public ?string $body = null,
        public ?array $data = null,
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
    ) {
        $this->to = self::normalizeTokens($to);
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

        if ($this->relevanceScore !== null && ($this->relevanceScore < 0.0 || $this->relevanceScore > 1.0)) {
            throw new InvalidMessageException('The relevance score must be between 0.0 and 1.0.');
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
     * Adds more devices to the message.
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

    /**
     * The number of devices that this message goes to.
     */
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
    }Move 

    #[\NoDiscard('Use the new message that this method returns.')]
    public function subtitle(?string $subtitle): self
    {
        return $this->with('subtitle', $subtitle);
    }

    /**
     * Replaces the custom JSON that the app reads.
     *
     * @param array<string, mixed>|null $data
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function data(?array $data): self
    {
        return $this->with('data', $data);
    }

    /**
     * Adds one key to the custom JSON and keeps the other keys.
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function withDatum(string $key, mixed $value): self
    {
        $data = $this->data ?? [];
        $data[$key] = $value;

        return $this->with('data', $data);
    }

    #[\NoDiscard('Use the new message that this method returns.')]
    public function sound(Sound|string|null $sound): self
    {
        return $this->with('sound', $sound);
    }

    /**
     * Sends the notification without a sound.
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
    public function mutableContent(bool $mutableContent = true): self
    {
        return $this->with('mutableContent', $mutableContent);
    }

    /**
     * Wakes the iOS app in the background to handle the message.
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function contentAvailable(bool $contentAvailable = true): self
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
     * The size of the message in bytes, for one device.
     *
     * Expo rejects a message above `PushMessage::MAX_SIZE`.
     */
    public function sizeInBytes(): int
    {
        $payload = $this->jsonSerialize();
        unset($payload['to']);

        return strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Splits the message into one message for each device.
     *
     * @return list<self>
     */
    #[\NoDiscard('Use the new message that this method returns.')]
    public function perRecipient(): array
    {
        return array_map(fn (PushToken $token): self => $this->with('to', [$token]), $this->to);
    }

    /**
     * The message in the shape that the Expo API reads.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $payload = [
            'to' => count($this->to) === 1
                ? $this->to[0]->value
                : array_map(static fn (PushToken $token): string => $token->value, $this->to),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'body' => $this->body,
            'data' => $this->data,
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

        // A silent notification needs the literal null, so put that key back.
        $silent = $this->sound !== null && $this->sound->name === null && !$this->sound->critical;

        $payload = array_filter($payload, static fn (mixed $value): bool => $value !== null);

        if ($silent) {
            $payload['sound'] = null;
        }

        return $payload;
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
     * @param list<PushToken> $tokens
     *
     * @return list<PushToken>
     */
    private static function unique(array $tokens): array
    {
        $seen = [];

        foreach ($tokens as $token) {
            $seen[$token->value] = $token;
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
