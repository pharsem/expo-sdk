<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Any other PSR-18 client failure.
 */
final class FakeClientException extends RuntimeException implements ClientExceptionInterface
{
}
