<?php

declare(strict_types=1);

namespace Expo\Push\Http;

use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Exception\InvalidMessageException;
use Expo\Push\Support\Json;

/**
 * Builds the HTTP request of one chunk.
 *
 * The factory owns the URL, the headers, the encoding and the timeouts. A
 * transport must not change any of them.
 */
final readonly class RequestFactory
{
    public const string SEND_PATH = '/--/api/v2/push/send';

    public const string RECEIPTS_PATH = '/--/api/v2/push/getReceipts';

    /**
     * Bodies above this size go out compressed, when zlib is available.
     */
    public const int GZIP_THRESHOLD = 1024;

    /**
     * @param string      $baseUrl          the API host
     * @param string|null $accessToken      the Expo access token, for a project with enhanced security
     * @param string      $userAgent        the user agent header
     * @param bool        $compress         compresses a large body with gzip when zlib is available
     * @param int         $connectTimeoutMs the connection timeout of one attempt
     * @param int         $requestTimeoutMs the total timeout of one attempt
     */
    public function __construct(
        private string $baseUrl,
        private ?string $accessToken,
        private string $userAgent,
        private bool $compress,
        private int $connectTimeoutMs,
        private int $requestTimeoutMs,
    ) {
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));

        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new InvalidConfigurationException(sprintf(
                'The base URL "%s" must start with https:// or, for a local test server, http://.',
                $baseUrl
            ));
        }

        if ($accessToken !== null && preg_match('/[\r\n]/', $accessToken) === 1) {
            throw new InvalidConfigurationException('The access token must not hold a line break.');
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @throws InvalidMessageException when the body does not encode as JSON
     */
    public function send(array $messages): HttpRequest
    {
        return $this->build(self::SEND_PATH, Json::encode($messages, 'send request'));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidMessageException when the body does not encode as JSON
     */
    public function receipts(array $body): HttpRequest
    {
        return $this->build(self::RECEIPTS_PATH, Json::encode($body, 'receipt request'));
    }

    private function build(string $path, string $json): HttpRequest
    {
        $headers = [
            'accept' => 'application/json',
            'accept-encoding' => 'gzip, deflate',
            'content-type' => 'application/json',
            'user-agent' => $this->userAgent,
        ];

        if ($this->accessToken !== null && $this->accessToken !== '') {
            $headers['authorization'] = 'Bearer ' . $this->accessToken;
        }

        if ($this->compress && strlen($json) > self::GZIP_THRESHOLD && function_exists('gzencode')) {
            $compressed = gzencode($json, 6);

            if (is_string($compressed)) {
                $json = $compressed;
                $headers['content-encoding'] = 'gzip';
            }
        }

        return new HttpRequest(
            url: rtrim($this->baseUrl, '/') . $path,
            body: $json,
            headers: $headers,
            timeoutMs: $this->requestTimeoutMs,
            connectTimeoutMs: $this->connectTimeoutMs,
        );
    }
}
