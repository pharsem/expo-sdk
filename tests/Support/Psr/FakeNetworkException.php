<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A PSR-18 network error. The specification does not say whether the server saw
 * the request, so the SDK must treat the transmission as unknown.
 */
final class FakeNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    #[\Override]
    public function getRequest(): RequestInterface
    {
        return new FakeRequest('POST', new FakeUri('https://exp.host/'));
    }
}
