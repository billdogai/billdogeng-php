<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * LLM observability — capture a model invocation as a trace span.
 *
 * Wraps `POST /llm/trace`. The wire payload is camelCase (matching the edge
 * function's Zod schema) and authenticates with `X-BillDog-API-Key`.
 */
final class Llm
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $apiKey
    ) {
    }

    /**
     * Record a single LLM trace span.
     *
     * @param array<string,mixed> $params {
     *   traceId, spanId, model, inputText, outputText (required),
     *   parentSpanId?, promptTokens?, completionTokens?, durationMs?, costUsd?,
     *   properties?, metadata?, timestamp?
     * }
     */
    public function captureTrace(array $params): mixed
    {
        $body = [
            'api_key' => $this->apiKey,
            'traceId' => $params['traceId'],
            'spanId' => $params['spanId'],
            'parentSpanId' => $params['parentSpanId'] ?? '',
            'model' => $params['model'],
            'inputText' => $params['inputText'],
            'outputText' => $params['outputText'],
            'promptTokens' => $params['promptTokens'] ?? 0,
            'completionTokens' => $params['completionTokens'] ?? 0,
            'durationMs' => $params['durationMs'] ?? 0,
            'costUsd' => $params['costUsd'] ?? 0,
            'properties' => (object) ($params['properties'] ?? []),
            'metadata' => (object) ($params['metadata'] ?? []),
            'timestamp' => $params['timestamp'] ?? gmdate('Y-m-d\TH:i:s.') . sprintf('%03dZ', (int) (microtime(true) * 1000) % 1000),
        ];

        return $this->transport->request(
            '/llm/trace',
            $body,
            ['X-BillDog-API-Key' => $this->apiKey],
            gzip: false
        );
    }
}
