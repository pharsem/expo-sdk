<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A PSR-18 request error. The client refused the request itself, so nothing
 * left this process.
 */
final class FakeRequestException extends RuntimeException implements RequestExceptionInterface
{
    #[\Override]
    public function getRequest(): RequestInterface
    {
        return new FakeRequest('POST', new FakeUri('https://exp.host/'));
    }
}
