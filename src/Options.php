<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Fully-resolved client configuration. Construct via {@see Options::fromArray}
 * to apply defaults, or directly with named arguments.
 */
final class Options
{
    /**
     * @param string                    $host            Base URL for all API requests.
     * @param int                       $flushAt         Batch size that triggers an automatic flush.
     * @param int                       $flushInterval   Background flush cadence in milliseconds.
     * @param int                       $maxQueueSize    Max queued events before the oldest are dropped.
     * @param bool                      $gzip            Gzip large request bodies.
     * @param bool                      $localEvaluation Enable local (server-side) flag evaluation.
     * @param int                       $requestTimeout  Per-request timeout in milliseconds.
     * @param int                       $maxRetries      Retry attempts for 5xx / network errors.
     * @param bool                      $enableLogging   Emit verbose diagnostics to STDERR.
     * @param string                    $personProfiles  Person-profile processing mode —
     *   PostHog `$process_person_profile` parity. One of:
     *     - `'identified_only'` (DEFAULT) — anonymous until you call {@see BillDogEng::identify},
     *       then identified. Matches historical behaviour; cheapest. Attaches nothing.
     *     - `'never'`  — every event stays ANONYMOUS, even after identify(). The SDK sends
     *       `$process_person_profile:false`; ingest keeps the row anonymous / non-stitched.
     *     - `'always'` — every event is IDENTIFIED, even before identify(): ingest promotes the
     *       anonymous id to a customer. ⚠️ Billing impact — every distinct device then counts
     *       toward billable MAU. Use only when you genuinely want a person profile per visitor.
     *   A per-event `$process_person_profile` boolean in an event's properties overrides this
     *   mode for that single event.
     * @param bool                      $sendFeatureFlagEvents Global toggle for `$feature_flag_called`
     *   exposure events. When `true` (DEFAULT), a flag READ that resolves to a non-null verdict emits ONE
     *   `$feature_flag_called` analytics event per (distinctId, flag, value) — the exposure record
     *   experiments/analytics attribute outcomes to — deduped and fire-and-forget. A per-call
     *   `sendFeatureFlagEvents` in the flag-read options overrides this for that single read.
     * @param string|null               $geoipResolutionLevel GeoIP resolution control (PostHog
     *   `disableGeoip` parity). A server SDK's request IP is the customer's backend, so this tells the
     *   ingest pipeline how precisely to resolve it. One of `'full'`, `'country_region'`, `'country_only'`,
     *   or `'none'` (fully disables enrichment). When SET, sent as the `x-geoip-resolution-level` header on
     *   the `/ingest-events` flush only; when `null` (DEFAULT/unset) NO header is sent and the project
     *   default (`full`) applies.
     * @param bool                      $disabled Kill switch. When `true` the SDK is INERT:
     *   capture/identify/group/alias/setPersonProperties are no-ops, flag reads return `null`/`{}`, and NO
     *   network happens (analytics + flags only — surveys/messaging/llm are explicit user calls and stay
     *   live). For tests and staged rollouts. Default `false`.
     * @param int                       $featureFlagsPollingInterval TTL (ms) for cached local flag
     *   definitions — how stale a definition set may get before the next flag read refetches it. Replaces
     *   the hardcoded 5-minute freshness window. Default `300000` (5 min).
     * @param \Closure|null             $beforeSend Hook run on every event just before it is queued.
     *   Signature `(array $event): ?array` — return the (possibly mutated) event to send it, or `null` to
     *   DROP it. Runs at the single enqueue choke point, so it sees capture/identify/group/alias AND the
     *   `$feature_flag_called` exposure. Default `null` (no hook).
     */
    public function __construct(
        public readonly string $host = 'https://api.billdog.io/v1',
        public readonly int $flushAt = 20,
        public readonly int $flushInterval = 10000,
        public readonly int $maxQueueSize = 1000,
        public readonly bool $gzip = true,
        public readonly bool $localEvaluation = false,
        public readonly int $requestTimeout = 10000,
        public readonly int $maxRetries = 3,
        public readonly bool $enableLogging = false,
        public readonly string $personProfiles = 'identified_only',
        public readonly bool $sendFeatureFlagEvents = true,
        public readonly ?string $geoipResolutionLevel = null,
        public readonly bool $disabled = false,
        public readonly int $featureFlagsPollingInterval = 300000,
        public readonly ?\Closure $beforeSend = null,
    ) {
    }

    /**
     * Build {@see Options} from a loose associative array, applying defaults
     * for any missing key (mirrors the Node SDK's options object).
     *
     * @param array<string,mixed> $opts
     */
    public static function fromArray(array $opts): self
    {
        $d = new self();
        return new self(
            host: isset($opts['host']) ? (string) $opts['host'] : $d->host,
            flushAt: isset($opts['flushAt']) ? (int) $opts['flushAt'] : $d->flushAt,
            flushInterval: isset($opts['flushInterval']) ? (int) $opts['flushInterval'] : $d->flushInterval,
            maxQueueSize: isset($opts['maxQueueSize']) ? (int) $opts['maxQueueSize'] : $d->maxQueueSize,
            gzip: isset($opts['gzip']) ? (bool) $opts['gzip'] : $d->gzip,
            localEvaluation: isset($opts['localEvaluation']) ? (bool) $opts['localEvaluation'] : $d->localEvaluation,
            requestTimeout: isset($opts['requestTimeout']) ? (int) $opts['requestTimeout'] : $d->requestTimeout,
            maxRetries: isset($opts['maxRetries']) ? (int) $opts['maxRetries'] : $d->maxRetries,
            enableLogging: isset($opts['enableLogging']) ? (bool) $opts['enableLogging'] : $d->enableLogging,
            personProfiles: isset($opts['personProfiles']) ? (string) $opts['personProfiles'] : $d->personProfiles,
            sendFeatureFlagEvents: isset($opts['sendFeatureFlagEvents'])
                ? (bool) $opts['sendFeatureFlagEvents']
                : $d->sendFeatureFlagEvents,
            geoipResolutionLevel: isset($opts['geoipResolutionLevel'])
                ? (string) $opts['geoipResolutionLevel']
                : $d->geoipResolutionLevel,
            disabled: isset($opts['disabled']) ? (bool) $opts['disabled'] : $d->disabled,
            featureFlagsPollingInterval: isset($opts['featureFlagsPollingInterval'])
                ? (int) $opts['featureFlagsPollingInterval']
                : $d->featureFlagsPollingInterval,
            beforeSend: isset($opts['beforeSend']) && is_callable($opts['beforeSend'])
                ? \Closure::fromCallable($opts['beforeSend'])
                : $d->beforeSend,
        );
    }

    public function requestTimeoutSeconds(): float
    {
        return $this->requestTimeout / 1000.0;
    }
}
