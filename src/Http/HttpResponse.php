<?php

declare(strict_types=1);

namespace Expo\Push\Http;

/**
 * The answer of one HTTP attempt.
 *
 * Every status reaches the SDK, including 4xx and 5xx. A transport must not
 * raise an exception for a status code.
 */
final readonly class HttpResponse
{
    /** @var array<string, list<string>> */
    public array $headers;

    /**
     * @param int                                             $status        the HTTP status code
     * @param string                                          $body          the body, decompressed
     * @param array<string, string|list<string>>              $headers       response headers, any case, repeats kept
     * @param int|null                                        $bytesUploaded the request bytes that left this process
     * @param int|null                                        $durationMs    the time of the whole attempt
     */
    public function __construct(
        public int $status,
        public string $body,
        array $headers = [],
        public ?int $bytesUploaded = null,
        public ?int $durationMs = null,
    ) {
        $this->headers = self::normalize($headers);
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The first value of a header, whatever case the server used.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * Every value of a repeated header, in the order that the server sent them.
     *
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private static function normalize(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $key = strtolower(trim((string) $name));

            if ($key === '') {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $single) {
                $normalized[$key][] = $single;
            }
        }

        return $normalized;
    }
}
