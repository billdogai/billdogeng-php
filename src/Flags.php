<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Feature-flag client supporting both remote and local (server-side)
 * evaluation.
 *
 * Local evaluation (`localEvaluation: true`) fetches flag DEFINITIONS once,
 * caches them with a 5-minute TTL, and evaluates each flag deterministically
 * on this process — the correct home for flag evaluation in a server SDK and
 * the cross-platform-identical algorithm shared with web / iOS / Android.
 *
 * Release conditions are resolved by {@see Targeting}, the ONE canonical audience DSL, shared
 * byte-for-byte with the backend: AND/OR, date windows, rule status, the full operator set and group
 * membership all resolve here, with no server round-trip.
 *
 * Remote evaluation falls back to `POST /experiment-config`, which returns a
 * pre-evaluated `feature_flags` map for the given user.
 */
final class Flags
{
    /**
     * Upper bound on the (distinctId, flag, value) dedupe cache. A server SDK sees many users, so the
     * cache is per-triple, not per-flag; when it fills, the OLDEST inserted entry is evicted (FIFO) so
     * exposures for churned users are eventually re-emitted rather than the SDK leaking memory unboundedly.
     */
    private const MAX_REPORTED_FLAG_CALLS = 50000;

    /** @var array<string,array<string,mixed>> key → definition */
    private array $definitions = [];

    private float $definitionsFetchedAt = 0.0;

    /**
     * Insertion-ordered dedupe set for `$feature_flag_called`, keyed `distinctId:key:value`. PHP arrays
     * preserve insertion order, so the first key is the oldest — evicted first when the cap is hit.
     *
     * @var array<string,true>
     */
    private array $reportedFlagCalls = [];

    /**
     * @param \Closure|null $capture Emit an analytics event — wired to the Analytics client's `capture`
     *   from {@see BillDogEng}. Signature `(string $distinctId, string $event, array $properties): void`.
     * @param bool $disabled Kill switch — when true every flag read is inert (`null`/`{}`) and no network runs.
     * @param int  $pollingIntervalMs TTL (ms) for cached local flag definitions before a read refetches them.
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $apiKey,
        private readonly bool $localEvaluation,
        private readonly bool $enableLogging,
        private readonly bool $sendFeatureFlagEvents = true,
        private readonly ?\Closure $capture = null,
        private readonly bool $disabled = false,
        private readonly int $pollingIntervalMs = 300000
    ) {
    }

    private function log(string $message): void
    {
        if ($this->enableLogging) {
            fwrite(STDERR, '[BilldogEng:flags] ' . $message . "\n");
        }
    }

    /** @return array<string,string> */
    private function authHeader(): array
    {
        return ['X-BillDog-API-Key' => $this->apiKey];
    }

    /** True when a flag's conditions need the database, so it cannot be evaluated in-process. */
    private function serverOnly(string $key): bool
    {
        return (bool) ($this->definitions[$key]['requires_server_evaluation'] ?? false);
    }

    /**
     * Get a flag's value for a user.
     *
     * @param array<string,mixed> $opts {
     *   groups?: array<string,string>,             group memberships, e.g. ['organisation' => 'acme'] —
     *                                             feeds `group` conditions, evaluated LOCALLY,
     *   personProperties?: array<string,mixed>,    read by attribute / entitlement / subscription_status,
     *   country?: string, platform?: string, appVersion?: string, sdkVersion?: string,
     *   experimentVariants?: array<string,string>  read by `experiment_variant` conditions
     * }
     * @return bool|string|null `bool` for a simple flag, the variant key string
     *   for a multivariate flag, or `null` when the flag is unknown.
     */
    public function getFeatureFlag(string $key, string $distinctId, array $opts = []): bool|string|null
    {
        if ($this->disabled) {
            return null;
        }
        if ($this->localEvaluation) {
            $this->ensureDefinitions();
            if (!array_key_exists($key, $this->definitions)) {
                return null;
            }
            // A flag whose release conditions need the database (segment/cohort/…) CANNOT be evaluated
            // here. Evaluating it locally would silently ignore its conditions and turn a targeted flag ON
            // for users who do not match. Fall through to the server.
            if (!$this->serverOnly($key)) {
                $verdict = $this->evaluateLocally($key, $distinctId, $opts);
                if ($verdict !== FlagVerdict::Cold) {
                    $this->reportFeatureFlagCalled($key, $distinctId, $verdict, $opts);
                    return $verdict;
                }
                // Defensive: the server said this flag was locally evaluable but a DB-backed condition
                // turned out to be load-bearing. Ask the server rather than guess.
            }
        }
        // onlyEvaluateLocally: caller forbids a server round-trip — return null rather than ask (this also
        // covers a non-local-mode SDK, where there are no local definitions to evaluate from).
        if ($opts['onlyEvaluateLocally'] ?? false) {
            return null;
        }
        $map = $this->fetchRemote($distinctId, $opts['personProperties'] ?? [], $opts['groups'] ?? []);
        if (!array_key_exists($key, $map)) {
            return null;
        }
        $this->reportFeatureFlagCalled($key, $distinctId, $map[$key], $opts);
        return $map[$key];
    }

    /**
     * Boolean view of {@see getFeatureFlag} (a variant string counts as ON).
     *
     * @param array<string,mixed> $opts
     */
    public function isFeatureEnabled(string $key, string $distinctId, array $opts = []): bool
    {
        $v = $this->getFeatureFlag($key, $distinctId, $opts);
        if ($v === null) {
            return false;
        }
        return $v !== false;
    }

    /**
     * Get a flag's payload (variant config). Only meaningful under local
     * evaluation. Returns the matched variant's payload, else the flag-level
     * payload, else `null`.
     *
     * @param array<string,mixed> $opts
     */
    public function getFeatureFlagPayload(string $key, string $distinctId, array $opts = []): mixed
    {
        if ($this->disabled) {
            return null;
        }
        $onlyLocal = (bool) ($opts['onlyEvaluateLocally'] ?? false);
        $this->ensureDefinitions();
        $def = $this->definitions[$key] ?? null;
        if ($def === null) {
            return null;
        }

        // Server-only conditions: resolve the verdict remotely rather than ignoring the flag's conditions.
        if ($this->serverOnly($key)) {
            if ($onlyLocal) {
                return null; // cannot resolve without the server
            }
            $verdict = $this->remoteVerdict($key, $distinctId, $opts);
        } else {
            $local = $this->evaluateLocally($key, $distinctId, $opts);
            if ($local === FlagVerdict::Cold && $onlyLocal) {
                return null; // a DB-backed condition is load-bearing and the caller forbids the server
            }
            $verdict = $local === FlagVerdict::Cold
                ? $this->remoteVerdict($key, $distinctId, $opts)
                : $local;
        }

        $this->reportFeatureFlagCalled($key, $distinctId, $verdict, $opts);

        if ($verdict === false || $verdict === null) {
            return null;
        }
        if (is_string($verdict) && !empty($def['variants'])) {
            foreach ($def['variants'] as $variant) {
                if (($variant['key'] ?? null) === $verdict && array_key_exists('payload', $variant)) {
                    return $variant['payload'];
                }
            }
        }
        return $def['payload'] ?? null;
    }

    /**
     * Evaluate every known flag for a user.
     *
     * @param array<string,mixed> $opts
     * @return array<string,bool|string>
     */
    public function getAllFlags(string $distinctId, array $opts = []): array
    {
        if ($this->disabled) {
            return [];
        }
        $onlyLocal = (bool) ($opts['onlyEvaluateLocally'] ?? false);
        if (!$this->localEvaluation) {
            // onlyEvaluateLocally: no local definitions to evaluate from → nothing resolvable.
            if ($onlyLocal) {
                return [];
            }
            return $this->fetchRemote($distinctId, $opts['personProperties'] ?? [], $opts['groups'] ?? []);
        }

        $this->ensureDefinitions();

        // Evaluate locally FIRST, so we learn which flags actually need the server — including any that go
        // COLD at runtime — and can then fetch them all in ONE request rather than one per flag.
        /** @var array<string,bool|string> $local */
        $local = [];
        /** @var list<string> $needServer */
        $needServer = [];
        foreach (array_keys($this->definitions) as $key) {
            if ($this->serverOnly($key)) {
                $needServer[] = $key;
                continue;
            }
            $verdict = $this->evaluateLocally($key, $distinctId, $opts);
            if ($verdict === FlagVerdict::Cold) {
                $needServer[] = $key;
            } else {
                $local[$key] = $verdict;
            }
        }

        // onlyEvaluateLocally: skip the server round-trip; flags that needed it resolve to false.
        $remote = count($needServer) > 0 && !$onlyLocal
            ? $this->fetchRemote($distinctId, $opts['personProperties'] ?? [], $opts['groups'] ?? [])
            : [];

        $out = [];
        foreach (array_keys($this->definitions) as $key) {
            $out[$key] = array_key_exists($key, $local) ? $local[$key] : ($remote[$key] ?? false);
        }
        return $out;
    }

    /**
     * Evaluate every known flag AND its payload for a user. Payloads resolve in LOCAL mode (from the
     * cached definitions); in remote mode payloads are unavailable, so `payload` is `null`. The value side
     * matches {@see getAllFlags}. A `false` value maps to a `null` payload. Disabled → `[]`.
     *
     * @param array<string,mixed> $opts
     * @return array<string,array{value:bool|string,payload:mixed}>
     */
    public function getAllFlagsAndPayloads(string $distinctId, array $opts = []): array
    {
        if ($this->disabled) {
            return [];
        }
        $values = $this->getAllFlags($distinctId, $opts);
        $out = [];
        foreach ($values as $key => $value) {
            $out[$key] = ['value' => $value, 'payload' => $this->payloadFor($key, $value)];
        }
        return $out;
    }

    /**
     * The payload a resolved verdict maps to, from cached definitions (variant payload → flag payload →
     * null). A `false` verdict has no payload.
     */
    private function payloadFor(string $key, bool|string $value): mixed
    {
        if ($value === false) {
            return null;
        }
        $def = $this->definitions[$key] ?? null;
        if ($def === null) {
            return null;
        }
        if (is_string($value) && !empty($def['variants'])) {
            foreach ($def['variants'] as $variant) {
                if (($variant['key'] ?? null) === $value && array_key_exists('payload', $variant)) {
                    return $variant['payload'];
                }
            }
        }
        return $def['payload'] ?? null;
    }

    /**
     * Synchronous, LOCAL-ONLY evaluation of every cached flag — used by
     * `capture(..., ['sendFeatureFlags' => true])` to attach `$feature/<key>` properties without blocking
     * on a fetch. Server-only and COLD flags are skipped (they cannot be resolved here). Returns only flags
     * whose verdict is determined locally. Disabled → `[]`.
     *
     * @param array<string,string> $groups
     * @return array<string,bool|string>
     */
    public function evaluateAllLocalSync(string $distinctId, array $groups = []): array
    {
        if ($this->disabled) {
            return [];
        }
        $out = [];
        foreach (array_keys($this->definitions) as $key) {
            if ($this->serverOnly($key)) {
                continue;
            }
            $verdict = $this->evaluateLocally($key, $distinctId, ['groups' => $groups]);
            if ($verdict !== FlagVerdict::Cold) {
                $out[$key] = $verdict;
            }
        }
        return $out;
    }

    /** Force a reload of the cached flag definitions (local mode). */
    public function reloadFeatureFlagDefinitions(): void
    {
        $this->fetchDefinitions();
    }

    /**
     * Inject flag definitions directly, bypassing the network. Primarily for
     * tests and for hosts that distribute definitions through their own channel.
     *
     * Each definition: {key, active, rollout_percentage, variants?, payload?,
     * targeting_rule?, requires_server_evaluation?}. `targeting_rule` is the flag's release conditions in
     * the ONE canonical audience DSL ({@see Targeting}) — the flat `targeting_rules` array is GONE.
     *
     * @param array<int,array<string,mixed>> $defs
     */
    public function setDefinitions(array $defs): void
    {
        $this->definitions = [];
        foreach ($defs as $d) {
            $this->definitions[$d['key']] = $d;
        }
        $this->definitionsFetchedAt = microtime(true);
    }

    /**
     * Deterministic local flag evaluation (spec §D):
     *  1. Missing/inactive → false.
     *  2. The flag's release conditions (`targeting_rule`) must match, else false — resolved by the
     *     canonical audience evaluator {@see Targeting::evaluateTargetingRule}.
     *  3. bucket = murmurhash3("flag:{key}:{distinctId}") % 100; ON iff bucket < rollout.
     *  4. Multivariate: walk variants by cumulative rollout against an INDEPENDENT hash,
     *     murmurhash3("flag-variant:{key}:{distinctId}") % 100 — NOT the rollout bucket.
     *
     * @param array<string,mixed> $opts see {@see getFeatureFlag}
     * @return bool|string|FlagVerdict `false` (off), `true` (on, boolean), the variant key, or
     *   {@see FlagVerdict::Cold} when a DB-backed condition turned out to be load-bearing and only the
     *   server can decide.
     */
    public function evaluateLocally(string $key, string $distinctId, array $opts = []): bool|string|FlagVerdict
    {
        $def = $this->definitions[$key] ?? null;
        if ($def === null || empty($def['active'])) {
            return false;
        }

        if (is_array($def['targeting_rule'] ?? null)) {
            $verdict = Targeting::evaluateTargetingRule($def['targeting_rule'], self::toAudienceContext($opts));
            // A DB-backed condition is load-bearing. We cannot resolve it and MUST NOT guess — treating it
            // as "no match" would silently withhold the flag, and treating it as a match would silently
            // hand a targeted feature to everyone. Tell the caller to ask the server.
            if ($verdict['cold']) {
                return FlagVerdict::Cold;
            }
            if (!$verdict['matched']) {
                return false;
            }
        }

        // CANONICAL bucketing — must match the backend byte-for-byte. Conformance vectors: tests/fixtures/flag-bucketing.json (generated FROM the backend).
        $bucket = Murmur::hash('flag:' . $key . ':' . $distinctId) % 100;
        $rollout = (int) ($def['rollout_percentage'] ?? 0);
        if ($bucket >= $rollout) {
            return false;
        }

        if (!empty($def['variants'])) {
            // INDEPENDENT hash — reusing the rollout bucket starves later variants: at 10% rollout split 50/50 every enabled user has bucket < 10, which is < 50, so variant B was NEVER assigned.
            $variantBucket = Murmur::hash('flag-variant:' . $key . ':' . $distinctId) % 100;
            $cumulative = 0;
            foreach ($def['variants'] as $variant) {
                $cumulative += (int) ($variant['rollout_percentage'] ?? 0);
                if ($variantBucket < $cumulative) {
                    return (string) $variant['key'];
                }
            }
            return true; // weights sum < 100 → on, no specific variant
        }

        return true;
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    /**
     * Emit the `$feature_flag_called` exposure event (PostHog parity) — the record that lets analytics and
     * experiments attribute outcomes to the flag a user was exposed to. Deduped per (distinctId, key, value)
     * so a hot flag read in a request loop bills at most one exposure per user per value. Fire-and-forget:
     * the value goes through the Analytics `capture` (which queues), and any failure there is swallowed, so
     * a flag read is never slowed or failed by telemetry. `null` verdicts (unknown flag) are NOT reported —
     * there was no exposure — so callers only invoke this for a resolved bool/string verdict.
     *
     * Gating: the per-call `sendFeatureFlagEvents` option wins when present; otherwise the global
     * {@see Options::$sendFeatureFlagEvents}. When effectively `false`, nothing is emitted or recorded.
     *
     * @param array<string,mixed> $opts the flag-read options (may carry a per-call `sendFeatureFlagEvents`)
     */
    private function reportFeatureFlagCalled(string $key, string $distinctId, bool|string $response, array $opts): void
    {
        // Per-call override wins when present (incl. an explicit `false`); else the global toggle. `??`
        // falls through only on absent/null, so a per-call `false` correctly suppresses this read.
        $enabled = $opts['sendFeatureFlagEvents'] ?? $this->sendFeatureFlagEvents;
        if ($enabled === false) {
            return;
        }

        // Stable string cast so a boolean verdict and a same-looking variant string never collide.
        $responseStr = is_bool($response) ? ($response ? 'true' : 'false') : (string) $response;
        $dedupeKey = $distinctId . ':' . $key . ':' . $responseStr;
        if (array_key_exists($dedupeKey, $this->reportedFlagCalls)) {
            return;
        }
        if (count($this->reportedFlagCalls) >= self::MAX_REPORTED_FLAG_CALLS) {
            // Evict the oldest (insertion-ordered) entry to bound memory. array_shift preserves the
            // remaining string keys and drops the first-inserted one — FIFO.
            array_shift($this->reportedFlagCalls);
        }
        $this->reportedFlagCalls[$dedupeKey] = true;

        if ($this->capture === null) {
            return;
        }
        // Fire-and-forget: a flag read must never throw because telemetry failed (e.g. an inline flush).
        try {
            ($this->capture)($distinctId, '$feature_flag_called', [
                '$feature_flag' => $key,
                '$feature_flag_response' => $response,
            ]);
        } catch (\Throwable $e) {
            $this->log('feature-flag-called capture failed: ' . $e->getMessage());
        }
    }

    /**
     * Build the audience context the canonical evaluator reads, from the SDK's flag-eval options.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private static function toAudienceContext(array $opts): array
    {
        /** @var array<string,mixed> $props */
        $props = $opts['personProperties'] ?? [];
        return [
            // Explicit context wins; otherwise fall back to a same-named person property, since most
            // callers already carry country/platform in their property bag.
            'country' => $opts['country'] ?? ($props['country'] ?? null),
            'platform' => $opts['platform'] ?? ($props['platform'] ?? null),
            'app_version' => $opts['appVersion'] ?? ($props['app_version'] ?? null),
            'sdk_version' => $opts['sdkVersion'] ?? ($props['sdk_version'] ?? null),
            'custom_attributes' => $props,
            'groups' => $opts['groups'] ?? null,
            'experiment_variants' => $opts['experimentVariants'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $opts
     */
    private function remoteVerdict(string $key, string $distinctId, array $opts): bool|string
    {
        $map = $this->fetchRemote($distinctId, $opts['personProperties'] ?? [], $opts['groups'] ?? []);
        return $map[$key] ?? false;
    }

    private function ensureDefinitions(): void
    {
        $fresh = count($this->definitions) > 0
            && (microtime(true) - $this->definitionsFetchedAt) * 1000 < $this->pollingIntervalMs;
        if ($fresh) {
            return;
        }
        $this->fetchDefinitions();
    }

    private function fetchDefinitions(): void
    {
        try {
            $data = $this->transport->request(
                '/feature-flag-definitions',
                ['api_key' => $this->apiKey],
                $this->authHeader(),
                gzip: false
            );
            $flags = (is_array($data) ? ($data['flags'] ?? []) : []) ?: [];
            $this->definitions = [];
            foreach ($flags as $d) {
                $this->definitions[$d['key']] = $d;
            }
            $this->definitionsFetchedAt = microtime(true);
            $this->log('loaded ' . count($this->definitions) . ' flag definitions');
        } catch (BillDogEngException $e) {
            // Graceful: keep any existing cache; surface in logs only.
            $this->log('failed to load flag definitions: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,string> $groups
     * @return array<string,bool|string>
     */
    private function fetchRemote(string $distinctId, array $attributes, array $groups = []): array
    {
        $data = $this->transport->request(
            '/experiment-config',
            [
                'api_key' => $this->apiKey,
                'user_id' => $distinctId,
                'attributes' => (object) $attributes,
                // GROUP TARGETING: this param existed on the flag API and was silently DROPPED (the server had nowhere to put it). It now feeds the audience engine's group / group_property conditions.
                'groups' => (object) $groups,
            ],
            $this->authHeader(),
            gzip: false
        );
        return (is_array($data) ? ($data['feature_flags'] ?? []) : []) ?: [];
    }
}
