<?php

declare(strict_types=1);

namespace Expo\Push\Protocol;

use Expo\Push\PushTicket;
use Expo\Push\PushToken;
use Expo\Push\Support\Json;
use stdClass;

/**
 * Reads the answer of `/--/api/v2/push/send`.
 *
 * The rules:
 *
 * - The envelope must be a JSON object with a `data` array.
 * - The array must hold exactly one entry for each notification of the request.
 *   A different count makes every position untrustworthy, so the parser reports
 *   a protocol failure. It never guesses that the first N entries belong to the
 *   first N devices.
 * - With the right count, one malformed entry marks only its own position. The
 *   entries at the other positions stay valid.
 * - A malformed entry never becomes a rejected device.
 * - A ticket with the status `ok` needs a non empty `id`.
 * - An unknown status is a protocol failure for that position, not a rejection.
 */
final class SendResponseParser
{
    /**
     * @param list<PushToken> $tokens one token for each notification, in request order
     */
    public static function parse(string $body, array $tokens): SendResponse
    {
        $decoded = Json::tryDecode($body);

        if ($decoded['ok'] === false) {
            return new SendResponse(ProtocolFailure::of(
                ProtocolFailureKind::NotJson,
                'the answer is not JSON: ' . $decoded['error'],
                $body
            ));
        }

        /** @var mixed $value */
        $value = $decoded['value'];

        if (!$value instanceof stdClass) {
            return new SendResponse(ProtocolFailure::of(
                ProtocolFailureKind::EnvelopeNotObject,
                sprintf('the answer is a %s, not a JSON object', get_debug_type($value)),
                $body
            ));
        }

        $envelope = Json::objectToArray($value);
        $apiErrors = self::readErrors($envelope);
        $expected = count($tokens);

        if (!array_key_exists('data', $envelope) || $envelope['data'] === null) {
            return new SendResponse(
                ProtocolFailure::of(
                    $apiErrors === [] ? ProtocolFailureKind::DataMissing : ProtocolFailureKind::ErrorsWithoutData,
                    $apiErrors === []
                        ? 'the answer has no data array'
                        : 'Expo rejected the request: ' . self::errorSummary($apiErrors),
                    $body
                ),
                apiErrors: $apiErrors,
            );
        }

        /** @var mixed $data */
        $data = $envelope['data'];

        if (!is_array($data) || !array_is_list($data)) {
            return new SendResponse(
                ProtocolFailure::of(
                    ProtocolFailureKind::DataWrongType,
                    sprintf('the data field is a %s, not a JSON array', get_debug_type($data)),
                    $body
                ),
                apiErrors: $apiErrors,
            );
        }

        if (count($data) !== $expected) {
            return new SendResponse(
                ProtocolFailure::of(
                    ProtocolFailureKind::TicketCountMismatch,
                    sprintf('the request held %d notifications and the answer holds %d tickets', $expected, count($data)),
                    $body
                ),
                apiErrors: $apiErrors,
                uncorrelatedIds: self::readIds($data),
            );
        }

        $tickets = [];
        $warnings = [];
        $malformed = 0;

        foreach ($data as $index => $entry) {
            $ticket = $entry instanceof stdClass
                ? PushTicket::fromExpoArray(Json::entryToArray($entry), $tokens[$index])
                : null;

            if ($ticket === null) {
                ++$malformed;
            }

            $tickets[] = $ticket;
        }

        if ($malformed > 0) {
            $warnings[] = sprintf(
                '%d of %d ticket entries are malformed. Those positions are unknown.',
                $malformed,
                $expected
            );
        }

        if ($apiErrors !== []) {
            $warnings[] = 'the answer holds both tickets and request level errors: ' . self::errorSummary($apiErrors);
        }

        return new SendResponse(
            failure: null,
            tickets: $tickets,
            apiErrors: $apiErrors,
            warnings: $warnings,
        );
    }

    /**
     * Reads the request level errors of a body that the SDK cannot otherwise use.
     *
     * The HTTP layer calls this for a 4xx or a 5xx answer. It never fails: a body
     * that is not JSON gives an empty list.
     *
     * @return list<ExpoApiError>
     */
    public static function errorsOnly(string $body): array
    {
        $decoded = Json::tryDecode($body);

        if ($decoded['ok'] === false) {
            return [];
        }

        /** @var mixed $value */
        $value = $decoded['value'];

        return $value instanceof stdClass ? self::readErrors(Json::objectToArray($value)) : [];
    }

    /**
     * @param array<string, mixed> $envelope
     *
     * @return list<ExpoApiError>
     */
    private static function readErrors(array $envelope): array
    {
        /** @var mixed $errors */
        $errors = $envelope['errors'] ?? null;

        if (!is_array($errors)) {
            return [];
        }

        $list = [];

        foreach ($errors as $entry) {
            if ($entry instanceof stdClass) {
                $list[] = ExpoApiError::fromArray(Json::entryToArray($entry));
            } elseif (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $list[] = ExpoApiError::fromArray($entry);
            }
        }

        return $list;
    }

    /**
     * Keeps the receipt IDs of an answer that the SDK cannot correlate.
     *
     * @param list<mixed> $data
     *
     * @return list<string>
     */
    private static function readIds(array $data): array
    {
        $ids = [];

        foreach ($data as $entry) {
            if (!$entry instanceof stdClass) {
                continue;
            }

            $fields = Json::entryToArray($entry);
            $status = $fields['status'] ?? null;
            $id = $fields['id'] ?? null;

            if ($status === PushTicket::STATUS_OK && is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<ExpoApiError> $errors
     */
    private static function errorSummary(array $errors): string
    {
        return implode('; ', array_map(static fn (ExpoApiError $error): string => $error->summary(), $errors));
    }
}
