<?php

declare(strict_types=1);

namespace Expo\Push\Storage;

use Expo\Push\Exception\InvalidStorageException;

/**
 * The small versioned wrapper around every stored array of the SDK.
 *
 * ```
 * ['_v' => 1, '_type' => 'expo.send_result', 'data' => [...]]
 * ```
 *
 * The SDK reads one version. It rejects anything else with a clear message. There
 * is no migration framework here: when the version changes, read the old version
 * with your own code, or send the work again.
 *
 * A stored array holds real device tokens and real message fields. Treat it as
 * application data, never as a log line.
 */
final class StorageEnvelope
{
    /**
     * The only schema version that this SDK writes and reads.
     */
    public const int VERSION = 1;

    public const string VERSION_KEY = '_v';

    public const string TYPE_KEY = '_type';

    public const string DATA_KEY = 'data';

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function wrap(string $type, array $data): array
    {
        return [
            self::VERSION_KEY => self::VERSION,
            self::TYPE_KEY => $type,
            self::DATA_KEY => $data,
        ];
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     *
     * @throws InvalidStorageException
     */
    public static function unwrap(string $type, array $stored): array
    {
        $version = $stored[self::VERSION_KEY] ?? null;

        if (!is_int($version)) {
            throw new InvalidStorageException(sprintf(
                'The stored array has no "%s" field. It does not come from this SDK.',
                self::VERSION_KEY
            ));
        }

        if ($version !== self::VERSION) {
            throw InvalidStorageException::unsupportedVersion($type, $version, self::VERSION);
        }

        $found = $stored[self::TYPE_KEY] ?? null;

        if (!is_string($found)) {
            throw InvalidStorageException::missingField($type, self::TYPE_KEY);
        }

        if ($found !== $type) {
            throw InvalidStorageException::wrongType($type, $found);
        }

        $data = $stored[self::DATA_KEY] ?? null;

        if (!is_array($data)) {
            throw InvalidStorageException::missingField($type, self::DATA_KEY);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Reads a list field from a stored array.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidStorageException
     */
    public static function listOfArrays(string $type, array $data, string $field): array
    {
        $value = $data[$field] ?? [];

        if (!is_array($value) || !array_is_list($value)) {
            throw InvalidStorageException::missingField($type, $field);
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                throw InvalidStorageException::missingField($type, $field);
            }

            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Reads a list of strings from a stored array.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     *
     * @throws InvalidStorageException
     */
    public static function listOfStrings(string $type, array $data, string $field): array
    {
        $value = $data[$field] ?? [];

        if (!is_array($value) || !array_is_list($value)) {
            throw InvalidStorageException::missingField($type, $field);
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw InvalidStorageException::missingField($type, $field);
            }

            $items[] = $item;
        }

        return $items;
    }
}
