<?php

declare(strict_types=1);

namespace Expo\Push\Execution;

use Expo\Push\Protocol\ExpoApiError;
use Expo\Push\Protocol\ParsedResponse;
use Expo\Push\Result\AttemptRecord;
use Expo\Push\Result\FailureCategory;

/**
 * The final state of one chunk.
 *
 * The object carries the evidence that the result builder needs to decide the
 * acceptance of every notification in the chunk.
 */
final readonly class ChunkOutcome
{
    /**
     * @param bool                $succeeded            true when the SDK holds a trustworthy answer
     * @param ParsedResponse|null $response             the parsed answer, when there is one
     * @param FailureCategory|null $category            why the chunk failed
     * @param string              $message              a redacted explanation
     * @param int|null            $httpStatus           the status of the last attempt
     * @param string|null         $transportCode        the code of the last attempt
     * @param list<ExpoApiError>  $expoErrors           request level errors that Expo reported
     * @param list<AttemptRecord> $attempts             every attempt, oldest first
     * @param bool                $retryable            true when a later attempt can work
     * @param bool                $deferred             true when the SDK stopped to wait, not because it gave up
     * @param int|null            $earliestRetryAtUtcMs the first UTC moment at which a retry makes sense
     * @param bool                $anyAmbiguousAttempt  true when at least one attempt can already have reached Expo
     * @param bool                $serverRefused        true when the last attempt got a 4xx answer
     * @param bool                $dispatched           true when the SDK sent at least one request for this chunk
     * @param list<string>        $warnings             redacted notes about contradictions
     */
    public function __construct(
        public bool $succeeded,
        public ?ParsedResponse $response = null,
        public ?FailureCategory $category = null,
        public string $message = '',
        public ?int $httpStatus = null,
        public ?string $transportCode = null,
        public array $expoErrors = [],
        public array $attempts = [],
        public bool $retryable = false,
        public bool $deferred = false,
        public ?int $earliestRetryAtUtcMs = null,
        public bool $anyAmbiguousAttempt = false,
        public bool $serverRefused = false,
        public bool $dispatched = false,
        public array $warnings = [],
    ) {
    }

    /**
     * True when the SDK knows that Expo accepted nothing of this chunk.
     *
     * Either every attempt failed before a byte left this process, or the server
     * refused the request with a 4xx status. The chunk was attempted, so this is
     * not "not attempted".
     */
    public function knownNotAccepted(): bool
    {
        return $this->dispatched && !$this->anyAmbiguousAttempt;
    }
}
