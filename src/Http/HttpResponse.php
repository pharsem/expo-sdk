<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * The answer of one HTTP request.
 */
final readonly class HttpResponse
{
    /**
     * @param int                                $status  the HTTP status code
     * @param string                             $body    the body, already decompressed
     * @param array<string, string|list<string>> $headers the response headers, with lower case names
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $value;
    }

    /**
     * The seconds that the server asks you to wait, from the `Retry-After` header.
     */
    public function retryAfter(): ?int
    {
        $value = $this->header('retry-after');

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        $date = strtotime($value);

        if ($date === false) {
            return null;
        }

        return max(0, $date - time());
    }
}
