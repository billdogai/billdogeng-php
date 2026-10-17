<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Minimal HTTP response value object returned by an {@see HttpHandler}.
 * Mirrors the small slice of the `fetch` Response the SDK actually uses.
 */
final class HttpResponse
{
    /**
     * @param int    $status HTTP status code (0 = network failure / timeout).
     * @param string $body   Raw response body text.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body
    ) {
    }
}
