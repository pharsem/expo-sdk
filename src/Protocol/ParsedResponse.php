<?php

declare(strict_types=1);

namespace Expo\Push\Protocol;

/**
 * What a parser gives back for one 2xx answer.
 *
 * The runner asks one question only: can the SDK trust this answer? The shape of
 * the data is the business of the caller that knows the endpoint.
 */
interface ParsedResponse
{
    public function protocolFailure(): ?ProtocolFailure;

    /**
     * The request level errors that Expo reported, if any.
     *
     * @return list<ExpoApiError>
     */
    public function errors(): array;

    /**
     * Redacted notes about contradictions in the answer.
     *
     * @return list<string>
     */
    public function notes(): array;
}
