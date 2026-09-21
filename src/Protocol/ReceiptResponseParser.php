<?php

declare(strict_types=1);

namespace Expo\Push\Protocol;

use Expo\Push\PushReceipt;
use Expo\Push\PushToken;
use Expo\Push\Support\Json;
use stdClass;

/**
 * Reads the answer of `/--/api/v2/push/getReceipts`.
 *
 * The rules:
 *
 * - The envelope must be a JSON object with a `data` object. The SDK also accepts
 *   an empty JSON array for an empty map, because the two mean the same thing.
 *   A non empty JSON array is a protocol failure.
 * - The SDK maps every receipt by its ID, never by the position in the answer.
 * - A malformed entry marks only its own ID. Every other requested ID keeps its
 *   valid receipt.
 * - An ID that the SDK did not ask for goes to `unexpectedIds`. The SDK never
 *   attaches a token of another notification to it.
 * - An invalid answer is a failed lookup, never a successful lookup in which
 *   every ID is missing.
 */
final class ReceiptResponseParser
{
    /**
     * @param list<string>              $requestedIds the IDs of this request
     * @param array<string, PushToken>  $tokensById   the device that the SDK knows for an ID
     */
    public static function parse(string $body, array $requestedIds, array $tokensById = []): ReceiptResponse
    {
        $decoded = Json::tryDecode($body);

        if ($decoded['ok'] === false) {
            return new ReceiptResponse(ProtocolFailure::of(
                ProtocolFailureKind::NotJson,
                'the answer is not JSON: ' . $decoded['error'],
                $body
            ));
        }

        /** @var mixed $value */
        $value = $decoded['value'];

        if (!$value instanceof stdClass) {
            return new ReceiptResponse(ProtocolFailure::of(
                ProtocolFailureKind::EnvelopeNotObject,
                sprintf('the answer is a %s, not a JSON object', get_debug_type($value)),
                $body
            ));
        }

        $envelope = Json::objectToArray($value);
        $apiErrors = SendResponseParser::errorsOnly($body);

        if (!array_key_exists('data', $envelope) || $envelope['data'] === null) {
            return new ReceiptResponse(
                ProtocolFailure::of(
                    $apiErrors === [] ? ProtocolFailureKind::DataMissing : ProtocolFailureKind::ErrorsWithoutData,
                    $apiErrors === []
                        ? 'the answer has no data object'
                        : 'Expo rejected the request: ' . self::errorSummary($apiErrors),
                    $body
                ),
                apiErrors: $apiErrors,
            );
        }

        /** @var mixed $data */
        $data = $envelope['data'];

        if (is_array($data) && $data === []) {
            // An empty JSON array and an empty JSON object both mean "no receipts".
            $data = new stdClass();
        }

        if (!$data instanceof stdClass) {
            return new ReceiptResponse(
                ProtocolFailure::of(
                    ProtocolFailureKind::DataWrongType,
                    sprintf('the data field is a %s, not a JSON object', get_debug_type($data)),
                    $body
                ),
                apiErrors: $apiErrors,
            );
        }

        $requested = array_flip($requestedIds);
        $receipts = [];
        $malformed = [];
        $unexpected = [];

        /** @var mixed $entry */
        foreach (get_object_vars($data) as $id => $entry) {
            $id = (string) $id;

            if (!isset($requested[$id])) {
                $unexpected[] = $id;

                continue;
            }

            $receipt = $entry instanceof stdClass
                ? PushReceipt::fromExpoArray($id, Json::entryToArray($entry), $tokensById[$id] ?? null)
                : null;

            if ($receipt === null) {
                $malformed[] = $id;

                continue;
            }

            $receipts[$id] = $receipt;
        }

        $warnings = [];

        if ($malformed !== []) {
            $warnings[] = sprintf('%d receipt entries are malformed.', count($malformed));
        }

        if ($unexpected !== []) {
            $warnings[] = sprintf('the answer holds %d IDs that the SDK did not ask for.', count($unexpected));
        }

        if ($apiErrors !== []) {
            $warnings[] = 'the answer holds both receipts and request level errors: ' . self::errorSummary($apiErrors);
        }

        return new ReceiptResponse(
            failure: null,
            receipts: $receipts,
            malformedIds: $malformed,
            unexpectedIds: $unexpected,
            apiErrors: $apiErrors,
            warnings: $warnings,
        );
    }

    /**
     * @param list<ExpoApiError> $errors
     */
    private static function errorSummary(array $errors): string
    {
        return implode('; ', array_map(static fn (ExpoApiError $error): string => $error->summary(), $errors));
    }
}
