<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\HttpHandler;
use BillDogEng\HttpResponse;

/**
 * Test HTTP handler that drives an in-process {@see StubServer} synchronously.
 *
 * On each `send` it: opens a client socket, writes the raw HTTP request,
 * asks the stub to accept + respond ({@see StubServer::serveNext}), then reads
 * the response. This keeps the whole client↔server round-trip in one PHP
 * process — no forking, no race — the analogue of injecting `fetch` in Node.
 */
final class LoopbackHttpHandler implements HttpHandler
{
    public function __construct(private readonly StubServer $stub)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            "tcp://{$this->stub->host}:{$this->stub->port}",
            $errno,
            $errstr,
            $timeoutSeconds
        );
        if ($client === false) {
            return new HttpResponse(0, "connect failed: {$errstr}");
        }

        $headerLines = '';
        $hasHost = false;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'host') {
                $hasHost = true;
            }
            $headerLines .= "{$name}: {$value}\r\n";
        }
        if (!$hasHost) {
            $headerLines .= "Host: {$this->stub->host}:{$this->stub->port}\r\n";
        }
        $payload = $body ?? '';
        $headerLines .= 'Content-Length: ' . strlen($payload) . "\r\n";

        $request = "{$method} {$path} HTTP/1.1\r\n{$headerLines}Connection: close\r\n\r\n{$payload}";
        fwrite($client, $request);

        // Let the stub accept this connection and write its response.
        $this->stub->serveNext($timeoutSeconds);

        // Read the full response.
        stream_set_timeout($client, (int) $timeoutSeconds);
        $raw = '';
        while (!feof($client)) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        fclose($client);

        return $this->parseResponse($raw);
    }

    private function parseResponse(string $raw): HttpResponse
    {
        $split = explode("\r\n\r\n", $raw, 2);
        $head = $split[0] ?? '';
        $body = $split[1] ?? '';

        $statusLine = strtok($head, "\r\n") ?: '';
        $status = 0;
        if (preg_match('#HTTP/\d\.\d\s+(\d{3})#', $statusLine, $m)) {
            $status = (int) $m[1];
        }

        return new HttpResponse($status, $body);
    }
}
