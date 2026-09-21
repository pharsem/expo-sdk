<?php

declare(strict_types=1);

namespace Expo\Push\Http;

use CurlHandle;
use CurlMultiHandle;
use Expo\Push\Exception\InvalidConfigurationException;
use Expo\Push\Exception\TransportException;
use Throwable;

/**
 * The default transport. It uses ext-curl and needs no other package.
 *
 * The client keeps its handles, so it reuses the TLS connection between requests.
 * It resets every handle before each use, so no header, no callback and no buffer
 * survives from one request to the next.
 *
 * TLS verification is always on. The client never follows a redirect, so a
 * credential can never travel to another host.
 */
final class CurlHttpClient implements ConcurrentHttpClient
{
    public const int MAX_CONCURRENCY = 6;

    /** cURL error 60: the server certificate did not verify. */
    private const int ERR_PEER_FAILED_VERIFICATION = 60;

    /** cURL error 83: the certificate issuer check failed. */
    private const int ERR_SSL_ISSUER_ERROR = 83;

    /** cURL error 58: the local client certificate is broken. */
    private const int ERR_SSL_CERTPROBLEM = 58;

    /** cURL error 48: the SDK passed an option that this libcurl does not know. */
    private const int ERR_UNKNOWN_OPTION = 48;

    /**
     * cURL options that you may pass. Anything else raises an exception.
     *
     * The list leaves out every option that decides the URL, the body, the
     * headers, the callbacks or the TLS checks. The SDK owns those.
     *
     * @var list<int>
     */
    private const array ALLOWED_OPTIONS = [
        CURLOPT_PROXY,
        CURLOPT_PROXYPORT,
        CURLOPT_PROXYTYPE,
        CURLOPT_PROXYUSERPWD,
        CURLOPT_NOPROXY,
        CURLOPT_INTERFACE,
        CURLOPT_CAINFO,
        CURLOPT_CAPATH,
        CURLOPT_SSLCERT,
        CURLOPT_SSLKEY,
        CURLOPT_SSLKEYPASSWD,
        CURLOPT_SSLVERSION,
        CURLOPT_DNS_SERVERS,
        CURLOPT_IPRESOLVE,
        CURLOPT_TCP_KEEPALIVE,
        CURLOPT_TCP_KEEPIDLE,
        CURLOPT_TCP_KEEPINTVL,
        CURLOPT_HTTP_VERSION,
        CURLOPT_LOW_SPEED_LIMIT,
        CURLOPT_LOW_SPEED_TIME,
    ];

    /** @var list<CurlHandle> */
    private array $idle = [];

    /** @var array<int, array{handle: CurlHandle, buffer: HeaderBuffer, request: HttpRequest, startedAt: float}> */
    private array $active = [];

    private ?CurlMultiHandle $multi = null;

    /** @var array<int, mixed> */
    private array $options;

    /**
     * @param array<int, mixed> $options            extra cURL options from the allowed list, such as a proxy
     * @param bool              $allowPlaintextHttp lets the client talk to an `http://` URL. Use it for a local test server only
     *
     * @throws InvalidConfigurationException when an option is not on the allowed list
     */
    public function __construct(
        array $options = [],
        private readonly bool $allowPlaintextHttp = false,
    ) {
        foreach (array_keys($options) as $option) {
            if (!in_array($option, self::ALLOWED_OPTIONS, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'The cURL option %d is not on the allowed list. The SDK owns the URL, the body, the headers, '
                    . 'the callbacks and the TLS settings.',
                    $option
                ));
            }
        }

        $this->options = $options;
    }

    #[\Override]
    public function capabilities(): TransportCapabilities
    {
        return new TransportCapabilities(
            maxConcurrency: self::MAX_CONCURRENCY,
            canEnforceHardDeadline: true,
            decompressesResponses: true,
        );
    }

    #[\Override]
    public function send(HttpRequest $request): HttpResponse
    {
        $handle = $this->take();
        $buffer = new HeaderBuffer();
        $startedAt = microtime(true);

        try {
            $this->configure($handle, $request, $buffer);

            $body = curl_exec($handle);
            $errno = curl_errno($handle);
            $error = curl_error($handle);

            if ($body === false || $errno !== 0) {
                throw new TransportException(self::failureFor($handle, $errno, $error));
            }

            return self::responseFrom($handle, is_string($body) ? $body : '', $buffer, $startedAt);
        } finally {
            $this->release($handle);
        }
    }

    #[\Override]
    public function start(int $id, HttpRequest $request): void
    {
        if (isset($this->active[$id])) {
            throw new InvalidConfigurationException(sprintf('The request id %d is already in flight.', $id));
        }

        if (count($this->active) >= self::MAX_CONCURRENCY) {
            throw new InvalidConfigurationException(sprintf(
                'This transport runs at most %d requests at one time.',
                self::MAX_CONCURRENCY
            ));
        }

        $handle = $this->take();
        $buffer = new HeaderBuffer();

        try {
            $this->configure($handle, $request, $buffer);
            curl_multi_add_handle($this->multi(), $handle);
        } catch (Throwable $exception) {
            $this->release($handle);

            throw $exception;
        }

        $this->active[$id] = [
            'handle' => $handle,
            'buffer' => $buffer,
            'request' => $request,
            'startedAt' => microtime(true),
        ];
    }

    #[\Override]
    public function inFlight(): int
    {
        return count($this->active);
    }

    #[\Override]
    public function poll(int $timeoutMs): array
    {
        if ($this->active === []) {
            return [];
        }

        $multi = $this->multi();
        $running = 0;

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        $completed = $this->reap($multi);

        if ($completed !== [] || $timeoutMs <= 0) {
            return $completed;
        }

        // curl_multi_select blocks until a handle has news, so the loop never
        // spins. It returns -1 when it has no socket to wait on, and then a short
        // sleep keeps the loop quiet.
        $ready = curl_multi_select($multi, $timeoutMs / 1000);

        if ($ready === -1) {
            usleep(min($timeoutMs, 10) * 1000);
        }

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        return $this->reap($multi);
    }

    #[\Override]
    public function cancelAll(): void
    {
        foreach ($this->active as $entry) {
            if ($this->multi !== null) {
                curl_multi_remove_handle($this->multi, $entry['handle']);
            }

            $this->release($entry['handle']);
        }

        $this->active = [];
    }

    public function __destruct()
    {
        $this->cancelAll();

        // Dropping the references frees every handle and closes its connections.
        $this->idle = [];

        if ($this->multi !== null) {
            curl_multi_close($this->multi);
            $this->multi = null;
        }
    }

    /**
     * @return list<CompletedRequest>
     */
    private function reap(CurlMultiHandle $multi): array
    {
        $completed = [];

        while (($info = curl_multi_info_read($multi)) !== false) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }

            $handle = $info['handle'];

            if (!$handle instanceof CurlHandle) {
                continue;
            }

            $id = $this->idFor($handle);

            if ($id === null) {
                curl_multi_remove_handle($multi, $handle);

                continue;
            }

            $entry = $this->active[$id];
            unset($this->active[$id]);

            $errno = is_int($info['result']) ? $info['result'] : CURLE_OK;
            $body = (string) curl_multi_getcontent($handle);

            $completed[] = $errno === CURLE_OK
                ? CompletedRequest::response(
                    $id,
                    self::responseFrom($handle, $body, $entry['buffer'], $entry['startedAt'])
                )
                : CompletedRequest::failure($id, self::failureFor($handle, $errno, curl_error($handle)));

            curl_multi_remove_handle($multi, $handle);
            $this->release($handle);
        }

        return $completed;
    }

    private function idFor(CurlHandle $handle): ?int
    {
        foreach ($this->active as $id => $entry) {
            if ($entry['handle'] === $handle) {
                return $id;
            }
        }

        return null;
    }

    private function multi(): CurlMultiHandle
    {
        return $this->multi ??= curl_multi_init();
    }

    private function take(): CurlHandle
    {
        $handle = array_pop($this->idle);

        if ($handle instanceof CurlHandle) {
            // curl_reset drops every option and every callback of the last request
            // and keeps the connection cache of the handle.
            curl_reset($handle);

            return $handle;
        }

        $fresh = curl_init();

        if (!$fresh instanceof CurlHandle) {
            throw TransportException::of(
                TransportFailureKind::InvalidConfiguration,
                'The SDK could not start cURL.'
            );
        }

        return $fresh;
    }

    private function release(CurlHandle $handle): void
    {
        curl_reset($handle);

        if (count($this->idle) <= self::MAX_CONCURRENCY) {
            $this->idle[] = $handle;
        }

        // A handle that the pool does not keep goes out of scope here. PHP frees
        // it, and it closes its connections. The SDK never calls curl_close():
        // PHP 8.5 deprecates that function for exactly this reason.
    }

    private function configure(CurlHandle $handle, HttpRequest $request, HeaderBuffer $buffer): void
    {
        $this->assertUrl($request->url);

        $options = $this->options;

        $options[CURLOPT_URL] = $request->url;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $request->body;
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_TIMEOUT_MS] = max(1, $request->timeoutMs);
        $options[CURLOPT_CONNECTTIMEOUT_MS] = max(1, $request->connectTimeoutMs);
        $options[CURLOPT_FOLLOWLOCATION] = false;
        $options[CURLOPT_SSL_VERIFYPEER] = true;
        $options[CURLOPT_SSL_VERIFYHOST] = 2;
        // An empty string asks cURL for every encoding that it can decode, and
        // cURL hands the SDK a decompressed body.
        $options[CURLOPT_ENCODING] = '';
        $options[CURLOPT_HTTPHEADER] = self::headerLines($request->headers);
        $options[CURLOPT_HEADERFUNCTION] = static function (CurlHandle $handle, string $line) use ($buffer): int {
            $length = strlen($line);
            $trimmed = trim($line);

            // A status line starts a new header block. An interim 1xx answer and a
            // proxy CONNECT answer each send one, so drop what came before it.
            if (stripos($trimmed, 'HTTP/') === 0) {
                $buffer->startBlock();

                return $length;
            }

            $parts = explode(':', $trimmed, 2);

            if (count($parts) === 2) {
                $buffer->add(trim($parts[0]), trim($parts[1]));
            }

            return $length;
        };

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[constant('CURLOPT_PROTOCOLS_STR')] = $this->allowPlaintextHttp ? 'http,https' : 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = $this->allowPlaintextHttp
                ? CURLPROTO_HTTP | CURLPROTO_HTTPS
                : CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);
    }

    private function assertUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && $this->allowPlaintextHttp) {
            return;
        }

        throw TransportException::of(
            TransportFailureKind::InvalidConfiguration,
            sprintf(
                'The SDK refuses the URL scheme "%s". Use https, or build the transport with allowPlaintextHttp '
                . 'for a local test server.',
                $scheme === '' ? '(none)' : $scheme
            )
        );
    }

    private static function responseFrom(
        CurlHandle $handle,
        string $body,
        HeaderBuffer $buffer,
        float $startedAt,
    ): HttpResponse {
        /** @var int $status */
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        /** @var float $uploaded */
        $uploaded = curl_getinfo($handle, CURLINFO_SIZE_UPLOAD);

        return new HttpResponse(
            status: $status,
            body: $body,
            headers: $buffer->headers,
            bytesUploaded: (int) $uploaded,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    private static function failureFor(CurlHandle $handle, int $errno, string $error): TransportFailure
    {
        /** @var float $uploaded */
        $uploaded = curl_getinfo($handle, CURLINFO_SIZE_UPLOAD);
        /** @var float $connectTime */
        $connectTime = curl_getinfo($handle, CURLINFO_CONNECT_TIME);

        return TransportFailure::of(
            kind: self::kindFor($errno, $connectTime > 0.0),
            message: $error === '' ? sprintf('cURL failed with error %d.', $errno) : $error,
            code: 'CURLE_' . $errno,
            bytesUploaded: (int) $uploaded,
        );
    }

    /**
     * @param bool $connected true when cURL opened the connection before it failed
     */
    private static function kindFor(int $errno, bool $connected = true): TransportFailureKind
    {
        // cURL reports a connect timeout and a transfer timeout with the same
        // error number. Only the second one is ambiguous: the first one never
        // put a byte on the wire.
        if ($errno === CURLE_OPERATION_TIMEOUTED && !$connected) {
            return TransportFailureKind::ConnectFailed;
        }

        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY => TransportFailureKind::NameResolutionFailed,
            CURLE_COULDNT_CONNECT => TransportFailureKind::ConnectFailed,
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CIPHER => TransportFailureKind::TlsFailed,
            self::ERR_PEER_FAILED_VERIFICATION,
            self::ERR_SSL_ISSUER_ERROR,
            self::ERR_SSL_CERTPROBLEM,
            CURLE_SSL_CACERT_BADFILE => TransportFailureKind::TlsVerificationFailed,
            CURLE_OPERATION_TIMEOUTED => TransportFailureKind::Timeout,
            CURLE_PARTIAL_FILE,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR => TransportFailureKind::Interrupted,
            CURLE_UNSUPPORTED_PROTOCOL,
            CURLE_URL_MALFORMAT,
            self::ERR_UNKNOWN_OPTION => TransportFailureKind::InvalidConfiguration,
            default => TransportFailureKind::Unknown,
        };
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value) === 1) {
                throw TransportException::of(
                    TransportFailureKind::InvalidConfiguration,
                    sprintf('The header "%s" holds a line break.', $name)
                );
            }

            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
