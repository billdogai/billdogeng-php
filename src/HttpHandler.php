<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Pluggable low-level HTTP transport. The default is {@see CurlHttpHandler};
 * tests inject a handler that points at a local stub server (the analogue of
 * the Node SDK's injectable `fetch`).
 */
interface HttpHandler
{
    /**
     * Perform a single HTTP request.
     *
     * @param string                $method         HTTP method (GET/POST).
     * @param string                $url            Absolute request URL.
     * @param array<string,string>  $headers        Request headers.
     * @param string|null           $body           Raw request body (already gzipped if applicable), or null.
     * @param float                 $timeoutSeconds Per-request timeout in seconds.
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse;
}
