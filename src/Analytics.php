<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Analytics client: capture/identify/groupIdentify/alias + batching.
 *
 * Events accumulate in an in-memory queue and are flushed as a single batched
 * POST to `/ingest-events` when:
 *   - the queue reaches `flushAt`,
 *   - more than `flushInterval` has elapsed since the last flush (checked
 *     opportunistically on enqueue — PHP has no background thread, so this is
 *     the idiomatic equivalent of the Node SDK's interval timer), or
 *   - `flush()` / `shutdown()` is called.
 *
 * A failed flush re-queues its batch (front of the queue) so events are never
 * lost on a single transient failure; the {@see Transport} also retries with
 * backoff before a flush is considered failed.
 *
 * A `quota_limited` 200 is NOT such a failure and is handled separately: ingest
 * reports an exhausted allowance with HTTP 200 + a top-level `quota_limited`
 * array rather than an error status, so the batch is silently discarded server
 * side. The flush loop then suspends itself for {@see QUOTA_SUSPENSION_COOLDOWN_SECONDS}
 * (see {@see suspendForQuota}) instead of re-queueing — in a long-lived server
 * process an ever-growing buffer of un-storable events is a leak, not a rescue.
 */
final class Analytics
{
    /**
     * How long the flush loop stays dark after a `quota_limited` 200 before it
     * spends ONE probe batch on finding out whether the allowance is back.
     *
     * Unlike a mobile session, a server process outlives the condition: a monthly
     * allowance resets and an account can upgrade at any time, so suspending
     * "for the session" would leave a box dark for weeks. 15 minutes is short
     * enough to recover unattended and long enough that a fully-metered account
     * costs 4 refused requests an hour instead of one per flush interval.
     */
    private const QUOTA_SUSPENSION_COOLDOWN_SECONDS = 900.0;

    /** @var array<int,array<string,mixed>> */
    private array $queue = [];

    private float $lastFlushAt;

    /**
     * Set once ingest answered a flush with 200 + `quota_limited`; cleared only when a
     * probe flush is stored. Stays true across the cooldown boundary, so the probe's
     * outcome is still recognisable as "recovered" rather than an ordinary flush.
     */
    private bool $quotaSuspended = false;

    /** Clock reading (seconds) at which the current suspension — or re-suspension — began. */
    private float $quotaSuspendedAt = 0.0;

    /**
     * Resolves a user's active flags for `capture(..., ['sendFeatureFlags' => true])`. Late-bound by
     * {@see BillDogEng} to avoid a construction-order cycle (analytics is built before flags, but the
     * resolver needs the flags client). Signature `(string $distinctId, array $groups): array<string,bool|string>`.
     */
    private ?\Closure $flagResolver = null;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $apiKey,
        private readonly Options $options,
        /** Clock (seconds) for the quota cooldown — overridable in tests to avoid a real 15-minute wait. */
        private $clock = null
    ) {
        if ($this->clock === null) {
            $this->clock = static function (): float {
                return microtime(true);
            };
        }
        $this->lastFlushAt = microtime(true);
    }

    /**
     * Late-bind the flag resolver used by `capture(..., ['sendFeatureFlags' => true])`. Called once from
     * {@see BillDogEng} after both clients exist.
     *
     * @param \Closure $fn (string $distinctId, array<string,string> $groups): array<string,bool|string>
     */
    public function setFlagResolver(\Closure $fn): void
    {
        $this->flagResolver = $fn;
    }

    private function log(string $message): void
    {
        if ($this->options->enableLogging) {
            fwrite(STDERR, '[BilldogEng:analytics] ' . $message . "\n");
        }
    }

    /**
     * Ungated counterpart to {@see log}, for the one condition a host must never miss:
     * the pipeline going dark while every call still returns normally. Gating this on
     * `enableLogging` (default `false`) would make a silently discarded event stream the
     * default experience.
     *
     * Routed through `error_log()` rather than `fwrite(STDERR, …)` because this one is
     * not opt-in: `STDERR` exists only under the CLI SAPI, so writing to it from php-fpm
     * would raise an Error out of the flush path — the opposite of the contract.
     */
    private function warn(string $message): void
    {
        error_log('[BilldogEng:analytics] ' . $message);
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Capture an arbitrary event for a user.
     *
     * @param array<string,mixed> $properties
     * @param array<string,string> $groups type → key
     * @param array{timestamp?:int|\DateTimeInterface,sendFeatureFlags?:bool} $options {
     *   timestamp?: epoch ms OR a \DateTimeInterface — overrides the event timestamp (backfill/import);
     *               absent → now,
     *   sendFeatureFlags?: when true, attach the user's active feature flags as `$feature/<key>` properties
     *               (plus `$active_feature_flags`) — best-effort and LOCAL-only via the late-bound resolver;
     *               uses already-cached definitions, never blocks the capture on a fetch, skips server-only/
     *               cold flags
     * }
     */
    public function capture(
        string $distinctId,
        string $event,
        array $properties = [],
        array $groups = [],
        array $options = []
    ): void {
        // Disabled, or dark while a quota suspension cools down: return before the
        // flag resolver and property assembly, so a suspended pipeline costs nothing.
        if ($this->options->disabled || $this->isQuotaSuspended()) {
            return;
        }
        $props = $this->withGroups($properties, $groups);

        // sendFeatureFlags: attach the user's active flags as `$feature/<key>` (+ `$active_feature_flags`).
        // Best-effort/local-only via the late-bound resolver — never blocks capture on a fetch.
        if (($options['sendFeatureFlags'] ?? false) && $this->flagResolver !== null) {
            $active = ($this->flagResolver)($distinctId, $groups);
            $enabled = [];
            foreach ($active as $key => $value) {
                $props['$feature/' . $key] = $value;
                if ($value !== false) {
                    $enabled[] = (string) $key;
                }
            }
            $props['$active_feature_flags'] = $enabled;
        }

        $this->enqueue($distinctId, $event, $props, $this->resolveTimestamp($options['timestamp'] ?? null));
    }

    /**
     * Capture an exception as a canonical `$exception` event (PostHog error-tracking parity). Parses the
     * throwable's class / message / stack into the `$exception_list` shape so downstream error-tracking can
     * group and symbolicate it — do NOT hand-build `$exception` via {@see capture}. A server SDK requires
     * the distinctId (there is no ambient user).
     *
     * Emitted THROUGH {@see capture}, so it batches and flushes like any other event. `disabled` → no-op.
     * Stack parsing is fully defensive: a malformed/absent trace never throws out of here (frames = []).
     *
     * @param array<string,mixed> $properties caller-supplied properties, merged into the event
     */
    public function captureException(\Throwable $error, string $distinctId, array $properties = []): void
    {
        // Guarded here too so a suspended pipeline never pays for stack parsing.
        if ($this->options->disabled || $this->isQuotaSuspended()) {
            return;
        }
        $exc = $this->buildExceptionEntry($error);
        $this->capture($distinctId, '$exception', array_merge($properties, [
            '$exception_list' => [$exc],
            '$exception_type' => $exc['type'],
            '$exception_message' => $exc['value'],
            '$exception_level' => 'error',
        ]));
    }

    /**
     * Build one `$exception_list` entry (PostHog's canonical exception shape) from a throwable.
     *
     * @return array{type:string,value:string,mechanism:array{handled:bool,synthetic:bool},stacktrace:array{type:string,frames:array<int,array<string,mixed>>}}
     */
    private function buildExceptionEntry(\Throwable $error): array
    {
        return [
            'type' => get_class($error),
            'value' => $error->getMessage(),
            'mechanism' => ['handled' => true, 'synthetic' => false],
            'stacktrace' => ['type' => 'raw', 'frames' => $this->parseStack($error)],
        ];
    }

    /**
     * Parse a throwable's stack into PostHog `raw` frames (best-effort, defensive). Frames are ordered
     * OLDEST-FIRST (the throw site is the LAST frame), matching the Node SDK.
     *
     * `$error->getTrace()` is newest-first and lists *call sites* (each entry's file/line is where that
     * function was invoked). When the trace is non-empty we additionally synthesize an innermost frame
     * from the throwable's own `getFile()`/`getLine()` — the actual throw location. A stackless throwable
     * (empty trace) yields `[]`, mirroring the Node SDK's non-Error / stackless case.
     * `in_app` is false for `vendor/` (dependency) frames, true otherwise.
     *
     * @return array<int,array{filename:string,function:string,lineno:int|null,in_app:bool}>
     */
    private function parseStack(\Throwable $error): array
    {
        $newestFirst = [];
        try {
            $trace = $error->getTrace();
            if (!is_array($trace) || count($trace) === 0) {
                // Stackless throwable → no frames (parity with the Node non-Error / stackless case).
                return [];
            }

            // Innermost (newest) frame: the actual throw site. Its function name is the routine that was
            // executing when the throw happened — i.e. getTrace()[0]['function'].
            $throwFn = isset($trace[0]) && is_array($trace[0]) ? $this->frameFunction($trace[0]) : '<unknown>';
            $newestFirst[] = $this->makeFrame($error->getFile(), $error->getLine(), $throwFn);

            foreach ($trace as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $newestFirst[] = $this->makeFrame(
                    isset($t['file']) && is_string($t['file']) ? $t['file'] : '<unknown>',
                    isset($t['line']) && is_int($t['line']) ? $t['line'] : null,
                    $this->frameFunction($t),
                );
            }
        } catch (\Throwable $e) {
            // Never let a malformed stack throw out of captureException.
            $this->log('captureException: stack parse failed (swallowed): ' . $e->getMessage());
        }

        // getTrace() is newest-first; PostHog wants oldest-first (throw site last).
        return array_reverse($newestFirst);
    }

    /**
     * Compose a frame's function label from a trace entry: `Class::method` / `Class->method` / `function`.
     *
     * @param array<string,mixed> $t
     */
    private function frameFunction(array $t): string
    {
        $fn = isset($t['function']) && is_string($t['function']) ? $t['function'] : '<anonymous>';
        $class = isset($t['class']) && is_string($t['class']) ? $t['class'] : '';
        $type = isset($t['type']) && is_string($t['type']) ? $t['type'] : '';
        return $class !== '' ? $class . $type . $fn : $fn;
    }

    /**
     * @return array{filename:string,function:string,lineno:int|null,in_app:bool}
     */
    private function makeFrame(string $filename, ?int $lineno, string $function): array
    {
        return [
            'filename' => $filename,
            'function' => $function,
            'lineno' => $lineno,
            'in_app' => !str_contains($filename, 'vendor/') && $filename !== '<unknown>',
        ];
    }

    /**
     * Normalize a caller-supplied timestamp (epoch ms int/float or \DateTimeInterface) to epoch ms;
     * `null` → now.
     */
    private function resolveTimestamp(int|float|\DateTimeInterface|null $ts): int
    {
        if ($ts instanceof \DateTimeInterface) {
            return (int) round(((float) $ts->format('U.u')) * 1000);
        }
        if (is_int($ts) || is_float($ts)) {
            return (int) $ts;
        }
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Set person properties. Emits the `$identify` event (identity/analytics) and, when `setOnce` is
     * supplied via `$options['setOnce']`, ALSO persists `$set_once` properties to the profile store via
     * {@see setPersonProperties} ("only if absent" — e.g. `signup_date`). Default behaviour (no `setOnce`)
     * is unchanged.
     *
     * @param array<string,mixed> $properties
     * @param array{setOnce?:array<string,mixed>} $options
     */
    public function identify(string $distinctId, array $properties = [], array $options = []): void
    {
        $this->enqueue($distinctId, '$identify', array_merge(['$set' => $properties], $properties));

        $setOnce = $options['setOnce'] ?? [];
        if (is_array($setOnce) && count($setOnce) > 0) {
            $this->setPersonProperties($distinctId, ['setOnce' => $setOnce]);
        }
    }

    /**
     * Apply person-property operations to the profile store (POST `/user-properties`), the PostHog-parity
     * property-op vocabulary the `$identify` event alone cannot express: `$set` (overwrite), `$set_once`
     * (write only if absent), `$add` (numeric increment), `$append` (push to a list), `$unset` (delete).
     *
     * Operations are built in a fixed order — set → setOnce → add → append → unset — and POSTed with the
     * body `{api_key, distinct_id, operations}` (no `project_id`; the backend derives the project from the
     * API key) and the `X-BillDog-API-Key` header, gzip disabled. An empty operation set makes NO request.
     *
     * Best-effort: the {@see Transport} retries transient failures; a final failure is swallowed (logged
     * only) so this never blocks the caller with an exception — matching the SDK's telemetry contract.
     *
     * @param array{set?:array<string,mixed>,setOnce?:array<string,mixed>,add?:array<string,int|float>,append?:array<string,mixed>,unset?:array<int,string>} $ops
     */
    public function setPersonProperties(string $distinctId, array $ops): void
    {
        if ($this->options->disabled) {
            return;
        }
        $operations = [];
        foreach (($ops['set'] ?? []) as $key => $value) {
            $operations[] = ['operation' => '$set', 'key' => (string) $key, 'value' => $value];
        }
        foreach (($ops['setOnce'] ?? []) as $key => $value) {
            $operations[] = ['operation' => '$set_once', 'key' => (string) $key, 'value' => $value];
        }
        foreach (($ops['add'] ?? []) as $key => $value) {
            $operations[] = ['operation' => '$add', 'key' => (string) $key, 'value' => $value];
        }
        foreach (($ops['append'] ?? []) as $key => $value) {
            $operations[] = ['operation' => '$append', 'key' => (string) $key, 'value' => $value];
        }
        foreach (($ops['unset'] ?? []) as $key) {
            $operations[] = ['operation' => '$unset', 'key' => (string) $key];
        }

        if (count($operations) === 0) {
            return;
        }

        try {
            $this->transport->request(
                '/user-properties',
                [
                    'api_key' => $this->apiKey,
                    'distinct_id' => $distinctId,
                    'operations' => $operations,
                ],
                ['X-BillDog-API-Key' => $this->apiKey],
                false, // gzip disabled for this small payload
            );
        } catch (BillDogEngException $e) {
            // Best-effort: never surface a property-persist failure to the caller.
            $this->log('setPersonProperties failed (swallowed): ' . $e->getMessage());
        }
    }

    /**
     * Set properties on a group. Emits `$groupidentify`.
     *
     * @param array<string,mixed> $properties
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        $this->enqueue($groupKey, '$groupidentify', [
            '$group_type' => $groupType,
            '$group_key' => $groupKey,
            '$group_set' => $properties,
        ]);
    }

    /** Alias one distinct id to another. Emits `$create_alias`. */
    public function alias(string $distinctId, string $alias): void
    {
        $this->enqueue($distinctId, '$create_alias', ['alias' => $alias, 'distinct_id' => $distinctId]);
    }

    /**
     * Force-flush the queue immediately.
     *
     * A `quota_limited` 200 is NOT a failed flush: it never throws, it suspends the
     * pipeline (see {@see suspendForQuota}) — and while suspended this returns at once.
     *
     * @throws BillDogEngException on a failed flush (batch is re-queued first).
     */
    public function flush(): void
    {
        $this->doFlush();
    }

    /** Flush remaining events. (Provided for parity with the Node `shutdown`.) */
    public function shutdown(): void
    {
        $this->flush();
    }

    /** Current number of queued (not-yet-flushed) events. */
    public function queueLength(): int
    {
        return count($this->queue);
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $properties
     * @param array<string,string> $groups
     * @return array<string,mixed>
     */
    private function withGroups(array $properties, array $groups): array
    {
        if (count($groups) === 0) {
            return $properties;
        }
        $out = $properties;
        // `$groups` ({type: key}) is the only group wire shape.
        $out['$groups'] = $groups;
        return $out;
    }

    /**
     * PostHog-parity person-profile control. Resolves the configured
     * `personProfiles` mode into the `$process_person_profile` wire flag the
     * ingest pipeline honors. Called from the single {@see enqueue} choke point,
     * so every event (capture/identify/alias/groupIdentify) is covered.
     *
     *   'never'  → false  (event stays anonymous even after identify())
     *   'always' → true   (ingest promotes the id to a person — every device a billable MAU)
     *   'identified_only' (default) → attach nothing (historical behaviour)
     *
     * A caller-supplied `$process_person_profile` in $properties always wins.
     * Note: the array KEY '$process_person_profile' is a single-quoted string,
     * so the leading `$` is a literal character, not a PHP variable sigil.
     *
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     */
    private function withPersonProfile(array $properties): array
    {
        if (array_key_exists('$process_person_profile', $properties)) {
            return $properties;
        }
        switch ($this->options->personProfiles) {
            case 'never':
                $properties['$process_person_profile'] = false;
                break;
            case 'always':
                $properties['$process_person_profile'] = true;
                break;
            default:
                // 'identified_only' / unknown → attach nothing.
                break;
        }
        return $properties;
    }

    /**
     * @param array<string,mixed> $properties
     * @param int|null $timestamp epoch ms; `null` → now.
     */
    private function enqueue(string $distinctId, string $eventName, array $properties, ?int $timestamp = null): void
    {
        // Disabled SDK: stay fully inert — no queueing, no network.
        if ($this->options->disabled) {
            return;
        }
        // Quota-suspended: ingest is discarding everything sent with this key, so queueing
        // would grow an unbounded buffer of events that provably cannot be stored. The
        // single choke point, so identify/group/alias and `$feature_flag_called` are covered.
        if ($this->isQuotaSuspended()) {
            return;
        }
        $properties = $this->withPersonProfile($properties);

        $wireEvent = [
            'event_name' => $eventName,
            'event_timestamp' => $timestamp ?? (int) round(microtime(true) * 1000),
            'properties' => $properties,
            'user_id' => $distinctId,
        ];

        // beforeSend hook: mutate or DROP (null) the event before it is queued. Runs at this single choke
        // point, so it covers capture/identify/group/alias AND the `$feature_flag_called` exposure.
        if ($this->options->beforeSend !== null) {
            $result = ($this->options->beforeSend)($wireEvent);
            if ($result === null) {
                return;
            }
            $wireEvent = $result;
        }
        // Serialize `properties` as a JSON object even when empty (parity with the Node wire shape).
        if (isset($wireEvent['properties']) && is_array($wireEvent['properties'])) {
            $wireEvent['properties'] = (object) $wireEvent['properties'];
        }
        $this->queue[] = $wireEvent;

        $count = count($this->queue);
        if ($count > $this->options->maxQueueSize) {
            $dropped = $count - $this->options->maxQueueSize;
            array_splice($this->queue, 0, $dropped);
            $this->log("max queue size exceeded, dropped {$dropped} oldest event(s)");
        }

        $elapsedMs = (microtime(true) - $this->lastFlushAt) * 1000;
        if (count($this->queue) >= $this->options->flushAt || $elapsedMs >= $this->options->flushInterval) {
            $this->doFlush();
        }
    }

    private function doFlush(): void
    {
        $this->lastFlushAt = microtime(true);
        // Dark until the cooldown expires. Nothing has queued in the meantime (enqueue
        // no-ops), and another batch would only be discarded server side.
        if ($this->isQuotaSuspended()) {
            $this->queue = [];
            return;
        }
        if (count($this->queue) === 0) {
            return;
        }

        $batch = $this->queue;
        $this->queue = [];

        $body = [
            'api_key' => $this->apiKey,
            'events' => $batch,
        ];

        // GeoIP control (PostHog `disableGeoip` parity): a server SDK's request IP is the customer's
        // backend, so tell ingest how precisely to resolve it — or not to (`'none'`). Only sent when
        // configured; otherwise the project default applies.
        $headers = ['X-BillDog-API-Key' => $this->apiKey];
        if ($this->options->geoipResolutionLevel !== null) {
            $headers['x-geoip-resolution-level'] = $this->options->geoipResolutionLevel;
        }

        try {
            $response = $this->transport->request('/ingest-events', $body, $headers);
        } catch (BillDogEngException $e) {
            // Re-queue the batch at the front so it is retried on the next flush;
            // never lose events on a single failure.
            $this->queue = array_merge($batch, $this->queue);
            $this->log('flush failed, re-queued ' . count($batch) . ' event(s): ' . $e->getMessage());
            throw $e;
        }

        // A 2xx no longer proves the batch was stored: an exhausted allowance answers
        // 200 + `quota_limited` so the customer's app is never broken by their own meter
        // filling up — which makes THIS client the thing that has to stop the traffic.
        // Reporting a flush here would be reporting success for data the server threw away.
        if (self::isQuotaLimited($response)) {
            $this->suspendForQuota($response, count($batch));
            return;
        }

        if ($this->quotaSuspended) {
            // The post-cooldown probe landed: the allowance reset, or the account upgraded.
            $this->quotaSuspended = false;
            $this->quotaSuspendedAt = 0.0;
            $this->warn('event ingest resumed — the allowance is available again');
        }

        $this->log('flushed ' . count($batch) . ' event(s)');
    }

    /**
     * Suspended AND still inside the cooldown — i.e. genuinely dark.
     *
     * Goes false again once the cooldown elapses (while {@see $quotaSuspended} stays
     * true) so the queue may refill with the ONE probe batch that finds out whether
     * the allowance came back. If that probe is refused too, {@see suspendForQuota}
     * restarts the cooldown, so only one batch per window is ever spent.
     */
    private function isQuotaSuspended(): bool
    {
        return $this->quotaSuspended
            && (($this->clock)() - $this->quotaSuspendedAt) < self::QUOTA_SUSPENSION_COOLDOWN_SECONDS;
    }

    /**
     * Enter — or silently renew — the quota suspension after ingest reported a batch dropped.
     *
     * The batch is NOT re-queued the way a transient failure is: ingest is refusing on
     * purpose and will refuse identically until the allowance resets or the account
     * upgrades, so holding those events would grow a buffer in a long-lived process for
     * data that provably cannot be stored.
     *
     * The reason is logged EXACTLY once, on entry — a refused probe only restarts the
     * cooldown — and at warn level via {@see warn}, because a pipeline that has gone dark
     * while every call still returns normally is the one thing the host cannot infer.
     */
    private function suspendForQuota(mixed $response, int $dropped): void
    {
        $firstEntry = !$this->quotaSuspended;
        $this->quotaSuspended = true;
        $this->quotaSuspendedAt = ($this->clock)();
        $this->queue = [];

        if ($firstEntry) {
            $this->warn(
                'event ingest suspended, ' . $dropped . ' event(s) dropped — '
                . self::quotaLimitedReason($response) . ' Retrying in '
                . (int) (self::QUOTA_SUSPENSION_COOLDOWN_SECONDS / 60) . ' minutes; '
                . 'capture() is a no-op until then.'
            );
        }
    }

    /**
     * Did ingest answer this 2xx by refusing to STORE the batch?
     *
     * The ingestion endpoints report an exhausted allowance — the plan envelope, a
     * sandbox cap, or the account's own spend limit — with HTTP 200 and a top-level,
     * non-empty `quota_limited` array naming the drained meters, never an error status
     * (see supabase/functions/_shared/quota-limited.ts for that reasoning). An absent or
     * empty array means the batch was stored normally.
     *
     * Deliberately tolerant: the argument is a response body the SDK does not control,
     * so anything unexpected reads as "not limited" rather than throwing inside the
     * flush path. Mirrors `isQuotaLimited` in packages/core.
     */
    private static function isQuotaLimited(mixed $response): bool
    {
        if (!is_array($response) || !isset($response['quota_limited'])) {
            return false;
        }
        return is_array($response['quota_limited']) && count($response['quota_limited']) > 0;
    }

    /**
     * One-line reason for a `quota_limited` 200, preferring the server's own prose (which
     * names the plan, the meter and the cap) and falling back to naming the drained
     * meters, so the reason a pipeline went quiet is never a bare boolean.
     *
     * Describes the ACCOUNT OWNER's billing state — a developer-log line, never something
     * to put in front of an end user.
     */
    private static function quotaLimitedReason(mixed $response): string
    {
        if (is_array($response) && isset($response['message']) && is_string($response['message'])
            && $response['message'] !== '') {
            return $response['message'];
        }
        $metrics = [];
        if (is_array($response) && isset($response['quota_limited']) && is_array($response['quota_limited'])) {
            $metrics = array_filter($response['quota_limited'], 'is_string');
        }
        return 'allowance exhausted for: ' . (count($metrics) > 0 ? implode(', ', $metrics) : 'unknown')
            . ' — this data was not stored.';
    }
}
