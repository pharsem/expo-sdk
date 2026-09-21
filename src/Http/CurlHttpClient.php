<?php

declare(strict_types=1);

namespace Expo\Push\Http;

use CurlHandle;
use Expo\Push\Exception\TransportException;

/**
 * The default HTTP client. It uses ext-curl and needs no other package.
 */
final readonly class CurlHttpClient implements HttpClient
{
    /**
     * @param int                  $timeout        seconds for the whole request
     * @param int                  $connectTimeout seconds for the connection
     * @param array<int, mixed>    $options        more cURL options, such as a proxy
     */
    public function __construct(
        private int $timeout = 30,
        private int $connectTimeout = 10,
        private array $options = [],
    ) {
    }

    #[\Override]
    public function post(string $url, string $body, array $headers): HttpResponse
    {
        $handle = curl_init();

        if (!$handle instanceof CurlHandle) {
            throw new TransportException('The SDK could not start cURL.');
        }

        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => self::formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        foreach ($this->options as $option => $value) {
            $options[$option] = $value;
        }

        curl_setopt_array($handle, $options);

        $result = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($result === false || $error !== '') {
            throw new TransportException(sprintf(
                'The SDK could not reach %s: %s',
                $url,
                $error === '' ? 'unknown cURL error' : $error
            ));
        }

        /** @var array<string, string> $responseHeaders */
        return new HttpResponse($status, (string) $result, $responseHeaders);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private static function formatHeaders(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
