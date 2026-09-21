<?php

declare(strict_types=1);

namespace Expo\Push\Tests\Support\Psr;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client that answers from a queue.
 */
final class FakePsr18Client implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $steps = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function queue(string $body, int $status = 200, array $headers = []): self
    {
        $this->steps[] = new FakeResponse($status, $body, $headers);

        return $this;
    }

    public function queueNetworkError(string $message = 'connection reset'): self
    {
        $this->steps[] = new FakeNetworkException($message);

        return $this;
    }

    public function queueRequestError(string $message = 'the request is not valid'): self
    {
        $this->steps[] = new FakeRequestException($message);

        return $this;
    }

    public function queueClientError(string $message = 'something else broke'): self
    {
        $this->steps[] = new FakeClientException($message);

        return $this;
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $step = array_shift($this->steps);

        if ($step === null) {
            throw new RuntimeException('The fake PSR-18 client has no answer left.');
        }

        if ($step instanceof ResponseInterface) {
            return $step;
        }

        throw $step;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
