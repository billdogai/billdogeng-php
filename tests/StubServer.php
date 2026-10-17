<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

/**
 * A captured request as parsed by {@see StubServer}.
 */
final class CapturedRequest
{
    /**
     * @param array<string,string> $headers lowercased header name → value
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly mixed $body
    ) {
    }
}

/**
 * A minimal blocking HTTP/1.1 stub server for resilience / round-trip tests
 * (permitted by the spec for these tests only). It is the PHP analogue of the
 * Node SDK's `test/stub-server.ts`.
 *
 * The handler receives ($request, $hits) and returns an array
 * `['status' => int, 'body' => string]`. A 0-based hit counter lets a test vary
 * behavior per-attempt (e.g. 503 then 200).
 *
 * Requests are served one at a time, on demand, from {@see serveNext}. The SDK
 * call runs in the same process, so the test must register the request to make
 * via a callback that runs after the listening socket is open. To keep things
 * simple and synchronous, callers use {@see run}: it forks a child process to
 * issue the client request while the parent accepts and serves.
 *
 * To avoid fork complexity, this implementation instead runs the listener in a
 * non-blocking accept loop driven by the {@see HttpHandler} the SDK uses: the
 * test injects {@see LoopbackHttpHandler} which, on each `send`, opens a socket
 * to this server, and the server accepts+responds inline.
 */
final class StubServer
{
    /** @var resource */
    private $socket;

    public readonly string $host;
    public readonly int $port;

    /** @var array<int,CapturedRequest> */
    public array $requests = [];

    private int $hits = 0;

    /** @var callable(CapturedRequest,int):array{status:int,body:string} */
    private $handler;

    /**
     * @param callable(CapturedRequest,int):array{status:int,body:string} $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
        $errno = 0;
        $errstr = '';
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("failed to bind stub server: {$errstr} ({$errno})");
        }
        $this->socket = $socket;
        $name = stream_socket_get_name($socket, false);
        $parts = explode(':', $name);
        $this->port = (int) array_pop($parts);
        $this->host = '127.0.0.1';
    }

    public function url(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * Accept and serve exactly one request, invoking the handler. Blocks until
     * a connection arrives. Returns after the response is written.
     */
    public function serveNext(float $timeoutSeconds = 5.0): void
    {
        stream_set_timeout($this->socket, (int) $timeoutSeconds);
        $conn = @stream_socket_accept($this->socket, $timeoutSeconds);
        if ($conn === false) {
            throw new \RuntimeException('stub server: accept timed out');
        }

        $request = $this->readRequest($conn);
        $this->requests[] = $request;
        $hit = $this->hits;
        $this->hits++;

        $result = ($this->handler)($request, $hit);
        $status = $result['status'] ?? 200;
        $body = $result['body'] ?? '';

        $reason = $status >= 400 ? 'Error' : 'OK';
        $response = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
        fwrite($conn, $response);
        fclose($conn);
    }

    /**
     * @param resource $conn
     */
    private function readRequest($conn): CapturedRequest
    {
        stream_set_timeout($conn, 5);
        $headerText = '';
        while (!str_contains($headerText, "\r\n\r\n")) {
            $chunk = fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $headerText .= $chunk;
        }

        [$rawHeaders, $rest] = array_pad(explode("\r\n\r\n", $headerText, 2), 2, '');
        $lines = explode("\r\n", $rawHeaders);
        $requestLine = array_shift($lines) ?? '';
        $parts = explode(' ', $requestLine);
        $method = $parts[0] ?? 'GET';
        $path = $parts[1] ?? '/';

        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                $value = trim(substr($line, $pos + 1));
                $headers[$name] = $value;
            }
        }

        $contentLength = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
        $bodyRaw = $rest;
        while (strlen($bodyRaw) < $contentLength) {
            $chunk = fread($conn, $contentLength - strlen($bodyRaw));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bodyRaw .= $chunk;
        }

        if (($headers['content-encoding'] ?? '') === 'gzip' && $bodyRaw !== '') {
            $decoded = @gzdecode($bodyRaw);
            if ($decoded !== false) {
                $bodyRaw = $decoded;
            }
        }

        $body = null;
        if ($bodyRaw !== '') {
            $decoded = json_decode($bodyRaw, true);
            $body = json_last_error() === JSON_ERROR_NONE ? $decoded : $bodyRaw;
        }

        return new CapturedRequest($method, $path, $headers, $body);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /** Convenience: a `{ success, data, meta }` envelope response body + status. */
    public static function envelope(mixed $data, int $status = 200): array
    {
        return [
            'status' => $status,
            'body' => json_encode([
                'success' => $status < 400,
                'data' => $data,
                'meta' => ['request_id' => 'stub'],
            ]),
        ];
    }

    /** Convenience: a raw JSON body response (error statuses and non-envelope negatives). */
    public static function raw(mixed $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($body)];
    }
}
