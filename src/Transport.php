<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Thin HTTP client around an {@see HttpHandler} with:
 *  - per-request timeout
 *  - optional gzip request bodies (when large enough to benefit)
 *  - exponential backoff retry (1s, 2s, 4s …) for 5xx / 429 / network errors
 *  - unwrapping of the `{ success, data, meta }` envelope (required on 2xx)
 */
final class Transport
{
    private const GZIP_THRESHOLD_BYTES = 1024;

    public function __construct(
        private readonly string $host,
        private readonly float $requestTimeoutSeconds,
        private readonly bool $gzip,
        private readonly int $maxRetries,
        private readonly HttpHandler $handler,
        private readonly bool $enableLogging,
        /** Sleep function (seconds) — overridable in tests to avoid real waits. */
        private $sleeper = null
    ) {
        if ($this->sleeper === null) {
            $this->sleeper = static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            };
        }
    }

    private function log(string $message): void
    {
        if ($this->enableLogging) {
            fwrite(STDERR, '[BilldogEng] ' . $message . "\n");
        }
    }

    private static function isRetryable(int $status): bool
    {
        return $status === 0 || $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
    }

    /**
     * Perform a POST (or GET when $body is null) request with retry/backoff.
     * Returns the parsed JSON body, unwrapped from the standard envelope.
     *
     * @param array<string,string> $headers
     * @throws BillDogEngException after the final retry fails.
     */
    public function request(string $path, mixed $body, array $headers = [], bool $gzip = true): mixed
    {
        $url = $this->host . $path;
        $maxAttempts = $this->maxRetries + 1;
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                $backoff = 1.0 * (2 ** ($attempt - 1)); // 1s, 2s, 4s …
                $this->log("retry {$attempt}/{$this->maxRetries} after {$backoff}s → {$path}");
                ($this->sleeper)($backoff);
            }

            try {
                return $this->attempt($url, $body, $headers, $gzip);
            } catch (BillDogEngException $e) {
                $lastError = $e;
                if (!self::isRetryable($e->status) || $attempt === $maxAttempts - 1) {
                    throw $e;
                }
                $this->log("request failed (status {$e->status}), will retry: {$e->getMessage()}");
            }
        }

        throw $lastError ?? new BillDogEngException('request failed', 0);
    }

    /**
     * @param array<string,string> $headers
     */
    private function attempt(string $url, mixed $body, array $headers, bool $gzip): mixed
    {
        $requestHeaders = array_merge([
            'Content-Type' => 'application/json',
            'User-Agent' => 'billdogeng-php',
        ], $headers);

        $payload = null;
        $method = 'GET';
        if ($body !== null) {
            $method = 'POST';
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new BillDogEngException('failed to encode request body', 0, 'ENCODE_ERROR');
            }
            $useGzip = $this->gzip && $gzip && strlen($json) >= self::GZIP_THRESHOLD_BYTES;
            if ($useGzip) {
                $requestHeaders['Content-Encoding'] = 'gzip';
                $payload = gzencode($json, 6);
            } else {
                $payload = $json;
            }
        }

        $res = $this->handler->send($method, $url, $requestHeaders, $payload, $this->requestTimeoutSeconds);

        $parsed = null;
        if ($res->body !== '') {
            $decoded = json_decode($res->body, true);
            $parsed = json_last_error() === JSON_ERROR_NONE ? $decoded : $res->body;
        }

        if ($res->status === 0) {
            throw new BillDogEngException(
                'network error: ' . ($res->body !== '' ? $res->body : 'unknown'),
                0,
                'NETWORK_ERROR'
            );
        }

        if ($res->status < 200 || $res->status >= 300) {
            $code = null;
            $message = "HTTP {$res->status}";
            if (is_array($parsed) && isset($parsed['error']) && is_array($parsed['error'])) {
                $code = $parsed['error']['code'] ?? null;
                $message = $parsed['error']['message'] ?? $message;
            }
            throw new BillDogEngException($message, $res->status, $code, $parsed);
        }

        // Every endpoint answers the standard `{ success, data, meta }` envelope,
        // and `data` is the payload. The one exception is a quota refusal: a 200
        // whose `quota_limited` sits deliberately TOP LEVEL, outside the envelope
        // and with no `data`, so a client checks one place on every endpoint. It
        // is returned whole — unwrapping it would hand the analytics layer nothing
        // and the flush would report success for events that were discarded.
        // Anything else is not a BillDog answer and is refused, never read as a
        // payload.
        if (is_array($parsed) && array_key_exists('quota_limited', $parsed)) {
            return $parsed;
        }
        if (is_array($parsed)
            && ($parsed['success'] ?? null) === true
            && array_key_exists('data', $parsed)
        ) {
            return $parsed['data'];
        }

        throw new BillDogEngException(
            'malformed response: expected the { success, data } envelope',
            $res->status,
            'MALFORMED_RESPONSE',
            $parsed
        );
    }
}
