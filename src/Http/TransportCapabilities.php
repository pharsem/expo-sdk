<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * What a transport can promise.
 *
 * The constructor of `Expo` reads this and rejects a setting that the transport
 * cannot honour, before any request goes out.
 */
final readonly class TransportCapabilities
{
    /**
     * @param int  $maxConcurrency        the largest number of requests in flight at one time
     * @param bool $canEnforceHardDeadline true when the transport can stop a request that is already running
     * @param bool $decompressesResponses  true when the transport gives the SDK a decompressed body
     */
    public function __construct(
        public int $maxConcurrency = 1,
        public bool $canEnforceHardDeadline = false,
        public bool $decompressesResponses = false,
    ) {
    }

    public static function sequential(): self
    {
        return new self(1, false, false);
    }
}
