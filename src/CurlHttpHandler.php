<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Default cURL-backed HTTP handler. Returns status 0 on transport-level
 * failures (DNS, connect, timeout) so the {@see Transport} treats them as
 * retryable network errors.
 */
final class CurlHttpHandler implements HttpHandler
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return new HttpResponse(0, 'curl_init failed');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // curl_close() is a no-op (and deprecated) since PHP 8.0; the handle is
        // freed when $ch goes out of scope.
        unset($ch);

        if ($errno !== 0 || $response === false) {
            return new HttpResponse(0, curl_strerror($errno) ?? 'network error');
        }

        return new HttpResponse($status, (string) $response);
    }
}
