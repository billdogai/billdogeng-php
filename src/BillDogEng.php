<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * The BilldogEng server SDK client.
 *
 * Engagement suite for server-side use: Analytics, Feature Flags (remote +
 * local evaluation), Surveys (data API), Messaging dispatch, and LLM tracing.
 *
 * @example
 * ```php
 * $bd = new BillDogEng('bd_test_xxx', ['localEvaluation' => true]);
 * $bd->capture('user-123', 'order_completed', ['revenue' => 49.99]);
 * $on = $bd->isFeatureEnabled('new_checkout', 'user-123');
 * $bd->shutdown();
 * ```
 */
final class BillDogEng
{
    public readonly Options $options;

    private readonly Transport $transport;
    private readonly Analytics $analytics;
    private readonly Flags $flags;

    /** Surveys data API. */
    public readonly Surveys $surveys;
    /** Messaging dispatch. */
    public readonly Messaging $messaging;
    /** LLM observability. */
    public readonly Llm $llm;

    /**
     * @param array<string,mixed>|Options $options Either a loose options array
     *   or a fully-resolved {@see Options}.
     * @param HttpHandler|null            $handler Injectable HTTP handler (tests / custom transports).
     * @param callable|null               $sleeper Injectable sleep(seconds) function (tests).
     */
    public function __construct(
        string $apiKey,
        array|Options $options = [],
        ?HttpHandler $handler = null,
        ?callable $sleeper = null
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('BilldogEng: apiKey is required');
        }

        $this->options = $options instanceof Options ? $options : Options::fromArray($options);

        $this->transport = new Transport(
            host: $this->options->host,
            requestTimeoutSeconds: $this->options->requestTimeoutSeconds(),
            gzip: $this->options->gzip,
            maxRetries: $this->options->maxRetries,
            handler: $handler ?? new CurlHttpHandler(),
            enableLogging: $this->options->enableLogging,
            sleeper: $sleeper,
        );

        $this->analytics = new Analytics($this->transport, $apiKey, $this->options);
        $this->flags = new Flags(
            $this->transport,
            $apiKey,
            $this->options->localEvaluation,
            $this->options->enableLogging,
            $this->options->sendFeatureFlagEvents,
            // Route `$feature_flag_called` exposure events through the SAME analytics capture pipeline as
            // any other event, so they batch + bill identically. Fire-and-forget from the flag read.
            fn (string $distinctId, string $event, array $properties): mixed
                => $this->analytics->capture($distinctId, $event, $properties),
            $this->options->disabled,
            $this->options->featureFlagsPollingInterval,
        );
        // Late-bind so capture(..., ['sendFeatureFlags' => true]) can inject the user's active flags without
        // a construction-order cycle (analytics is built before flags, but the resolver needs flags).
        $this->analytics->setFlagResolver(
            fn (string $distinctId, array $groups): array
                => $this->flags->evaluateAllLocalSync($distinctId, $groups)
        );
        $this->surveys = new Surveys($this->transport, $apiKey);
        $this->messaging = new Messaging($this->transport);
        $this->llm = new Llm($this->transport, $apiKey);
    }

    // ─── Analytics ────────────────────────────────────────────────────────────

    /**
     * Capture an event for a user. Batched; flushed per the configured policy.
     *
     * @param array<string,mixed> $properties
     * @param array<string,string> $groups
     * @param array{timestamp?:int|\DateTimeInterface,sendFeatureFlags?:bool} $options per-call options —
     *   `timestamp` overrides the event timestamp (epoch ms or a \DateTimeInterface); `sendFeatureFlags`
     *   attaches the user's active flags as `$feature/<key>` + `$active_feature_flags` (local-only,
     *   best-effort). See {@see Analytics::capture}.
     */
    public function capture(
        string $distinctId,
        string $event,
        array $properties = [],
        array $groups = [],
        array $options = []
    ): void {
        $this->analytics->capture($distinctId, $event, $properties, $groups, $options);
    }

    /**
     * Capture a throwable as a canonical `$exception` event (PostHog error-tracking parity). Parses the
     * throwable's class/message/stack into the `$exception_list` shape and emits it through the normal
     * capture pipeline (batched/flushed). `disabled` → no-op. See {@see Analytics::captureException}.
     *
     * @param array<string,mixed> $properties caller-supplied properties, merged into the event
     */
    public function captureException(\Throwable $error, string $distinctId, array $properties = []): void
    {
        $this->analytics->captureException($error, $distinctId, $properties);
    }

    /**
     * Set person properties. Emits `$identify`. When `$options['setOnce']` is a non-empty map, ALSO
     * persists those `$set_once` properties to the profile store via {@see setPersonProperties}. Default
     * behaviour (no `setOnce`) is unchanged.
     *
     * @param array<string,mixed> $properties
     * @param array{setOnce?:array<string,mixed>} $options
     */
    public function identify(string $distinctId, array $properties = [], array $options = []): void
    {
        $this->analytics->identify($distinctId, $properties, $options);
    }

    /**
     * Apply person-property operations (`set`/`setOnce`/`add`/`append`/`unset`) to the profile store via
     * POST `/user-properties`. Best-effort — see {@see Analytics::setPersonProperties}.
     *
     * @param array{set?:array<string,mixed>,setOnce?:array<string,mixed>,add?:array<string,int|float>,append?:array<string,mixed>,unset?:array<int,string>} $ops
     */
    public function setPersonProperties(string $distinctId, array $ops): void
    {
        $this->analytics->setPersonProperties($distinctId, $ops);
    }

    /**
     * Set group properties. Emits `$groupidentify`.
     *
     * @param array<string,mixed> $properties
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        $this->analytics->groupIdentify($groupType, $groupKey, $properties);
    }

    /** Alias one distinct id to another. Emits `$create_alias`. */
    public function alias(string $distinctId, string $alias): void
    {
        $this->analytics->alias($distinctId, $alias);
    }

    /** Flush queued analytics events now. */
    public function flush(): void
    {
        $this->analytics->flush();
    }

    /** Number of events currently queued (test/observability aid). */
    public function queueLength(): int
    {
        return $this->analytics->queueLength();
    }

    // ─── Feature flags ──────────────────────────────────────────────────────

    /**
     * Get a flag value: bool, variant key string, or null if unknown.
     *
     * @param array<string,mixed> $opts
     */
    public function getFeatureFlag(string $key, string $distinctId, array $opts = []): bool|string|null
    {
        return $this->flags->getFeatureFlag($key, $distinctId, $opts);
    }

    /**
     * Whether a flag is enabled for the user.
     *
     * @param array<string,mixed> $opts
     */
    public function isFeatureEnabled(string $key, string $distinctId, array $opts = []): bool
    {
        return $this->flags->isFeatureEnabled($key, $distinctId, $opts);
    }

    /**
     * Get a flag's payload (variant config), local mode.
     *
     * @param array<string,mixed> $opts
     */
    public function getFeatureFlagPayload(string $key, string $distinctId, array $opts = []): mixed
    {
        return $this->flags->getFeatureFlagPayload($key, $distinctId, $opts);
    }

    /**
     * Evaluate all known flags for a user.
     *
     * @param array<string,mixed> $opts
     * @return array<string,bool|string>
     */
    public function getAllFlags(string $distinctId, array $opts = []): array
    {
        return $this->flags->getAllFlags($distinctId, $opts);
    }

    /**
     * Evaluate all known flags AND their payloads for a user (payloads resolve in local mode; remote mode
     * yields `null` payloads). Each entry is `['value' => bool|string, 'payload' => mixed]`.
     *
     * @param array<string,mixed> $opts
     * @return array<string,array{value:bool|string,payload:mixed}>
     */
    public function getAllFlagsAndPayloads(string $distinctId, array $opts = []): array
    {
        return $this->flags->getAllFlagsAndPayloads($distinctId, $opts);
    }

    /** Reload local flag definitions (local mode). */
    public function reloadFeatureFlagDefinitions(): void
    {
        $this->flags->reloadFeatureFlagDefinitions();
    }

    /** Direct access to the flags client (advanced: setDefinitions, evaluateLocally). */
    public function featureFlags(): Flags
    {
        return $this->flags;
    }

    // ─── Messaging convenience ──────────────────────────────────────────────

    /**
     * Dispatch a message (see {@see Messaging::dispatch}).
     *
     * @param array<string,mixed> $params
     */
    public function dispatchMessage(array $params): mixed
    {
        return $this->messaging->dispatch($params);
    }

    // ─── LLM convenience ────────────────────────────────────────────────────

    /**
     * Capture an LLM trace span (see {@see Llm::captureTrace}).
     *
     * @param array<string,mixed> $params
     */
    public function captureTrace(array $params): mixed
    {
        return $this->llm->captureTrace($params);
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    /** Flush remaining events. */
    public function shutdown(): void
    {
        $this->analytics->shutdown();
    }
}
