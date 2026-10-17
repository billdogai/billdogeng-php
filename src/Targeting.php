<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Targeting.php — the canonical audience-rule evaluator, ported from the backend.
 *
 * This is a port of `evaluateTargetingRulePure` + `compare` in
 * `supabase/functions/_shared/edge-serving/pure.ts`. It is pinned to
 * `tests/fixtures/flag-targeting.json`, which is GENERATED FROM that backend evaluator. It is never
 * checked against another SDK — nine hand-ports of the murmur hash once all agreed with each other and
 * all disagreed with the server, for months, precisely because each SDK's tests were written against a
 * sibling SDK.
 *
 * WHY THIS EXISTS. The SDK previously received a flat `{attribute, operator, value}` array, which could
 * express none of: an OR combinator, a date window, a paused rule, group membership, or most of the
 * operator set. Every flag using one of those was deferred to the server even though the SDK held
 * everything needed to answer it. That flat shape was also a SECOND condition DSL, duplicated across nine
 * SDKs. It is gone: the SDK now receives the rule verbatim in the one canonical DSL and resolves it here.
 * `Flags::matchesRule` — the old private operator matcher — MOVED here as {@see Targeting::compare}.
 * There is exactly ONE compare in this SDK.
 *
 * THE COLD CONTRACT. Some condition types (`segment`, `cohort`, `survey_answer`,
 * `event_fired_in_window`, `group_property`) are answerable only by querying the database. We cannot
 * resolve them and we must not guess: a failed lookup silently reading as "no match" is how targeting
 * breaks quietly. Those conditions return COLD, which propagates to `['cold' => true]`, and the caller
 * falls back to the server. In practice the backend already marks such flags `requires_server_evaluation`
 * so they never reach this code — the COLD path here is the belt to that braces, and it fails SAFE.
 *
 * The rule shape (all keys optional):
 *   [
 *     'status'      => 'active'|'paused'|'archived',
 *     'enabled'     => bool,
 *     'start_date'  => ISO-8601 string|null,
 *     'end_date'    => ISO-8601 string|null,
 *     'segment_ids' => string[],                       // LEGACY — fails CLOSED
 *     'conditions'  => ['logic' => 'AND'|'OR', 'rules' => [
 *        ['type' => …, 'operator' => …, 'value' => …, 'field' => …, 'group_type' => …], …
 *     ]],
 *   ]
 *
 * The context shape (all keys optional):
 *   ['country', 'platform', 'app_version', 'sdk_version', 'custom_attributes', 'groups',
 *    'experiment_variants']
 */
final class Targeting
{
    /**
     * DB-backed condition types. Not resolvable in-process. Mirrors COLD_CONDITION_TYPES in pure.ts.
     *
     * Group PROPERTIES read the `groups` table. Group MEMBERSHIP (`group`) does not — it comes from the
     * caller-supplied groups map — so it stays purely evaluable.
     *
     * @var list<string>
     */
    public const COLD_CONDITION_TYPES = [
        'segment',
        'cohort',
        'survey_answer',
        'event_fired_in_window',
        'group_property',
    ];

    /** Internal sentinel for "this condition needs the database". Never `false` — see the COLD contract. */
    private const COLD = "\0cold-path-required\0";

    /**
     * Evaluate an audience rule against a context.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $ctx
     * @return array{matched: bool, cold: bool} `matched` when fully determined from context; `cold` when a
     *   DB-backed condition is load-bearing and the caller MUST re-resolve via the server. Note an OR rule
     *   already satisfied by a context condition is decided WITHOUT the server, even if it also contains a
     *   DB-backed condition.
     */
    public static function evaluateTargetingRule(array $rule, array $ctx): array
    {
        $status = $rule['status'] ?? null;
        if (($rule['enabled'] ?? null) === false || $status === 'paused' || $status === 'archived') {
            return ['matched' => false, 'cold' => false];
        }

        $nowMs = (int) round(microtime(true) * 1000);
        $startMs = self::parseAnyDateMs($rule['start_date'] ?? null);
        if ($startMs !== null && $startMs > $nowMs) {
            return ['matched' => false, 'cold' => false];
        }
        $endMs = self::parseAnyDateMs($rule['end_date'] ?? null);
        if ($endMs !== null && $endMs < $nowMs) {
            return ['matched' => false, 'cold' => false];
        }

        // Legacy `segment_ids` — fail CLOSED. These are a DB-backed allow-list we cannot read, and
        // treating them as absent would hand the flag to everyone.
        $segmentIds = $rule['segment_ids'] ?? null;
        if (is_array($segmentIds) && count($segmentIds) > 0) {
            return ['matched' => false, 'cold' => false];
        }

        $conditions = $rule['conditions'] ?? null;
        $rules = is_array($conditions) && is_array($conditions['rules'] ?? null) ? $conditions['rules'] : [];
        $logic = is_array($conditions) && ($conditions['logic'] ?? null) === 'OR' ? 'OR' : 'AND';
        if (count($rules) === 0) {
            return ['matched' => true, 'cold' => false];
        }

        $sawCold = false;
        /** @var list<bool> $pureResults */
        $pureResults = [];
        foreach ($rules as $condition) {
            $r = self::evaluateCondition(is_array($condition) ? $condition : [], $ctx);
            if ($r === self::COLD) {
                $sawCold = true;
            } else {
                $pureResults[] = $r;
            }
        }

        if ($logic === 'OR') {
            if (in_array(true, $pureResults, true)) {
                return ['matched' => true, 'cold' => false];
            }
            return $sawCold ? ['matched' => false, 'cold' => true] : ['matched' => false, 'cold' => false];
        }

        // AND
        if (in_array(false, $pureResults, true)) {
            return ['matched' => false, 'cold' => false];
        }
        return $sawCold ? ['matched' => false, 'cold' => true] : ['matched' => true, 'cold' => false];
    }

    /**
     * Byte-for-byte port of `compare()` in pure.ts — same operator set, same aliases, same
     * string-coercion and ISO-date-or-number semantics. THE single operator matcher in this SDK.
     */
    public static function compare(string $operator, mixed $actual, mixed $expected): bool
    {
        $actualString = self::toComparable($actual);

        if ($operator === 'exists') {
            return $actualString !== null && $actualString !== '';
        }
        if ($operator === 'not_exists') {
            return $actualString === null || $actualString === '';
        }
        if ($actualString === null) {
            return false;
        }

        return match ($operator) {
            'is', 'equals' => $actualString === self::jsString($expected),
            'is_not', 'not_equals' => $actualString !== self::jsString($expected),
            'any_of' => self::isJsArray($expected)
                && in_array($actualString, array_map([self::class, 'jsString'], $expected), true),
            'not_any_of' => self::isJsArray($expected)
                && !in_array($actualString, array_map([self::class, 'jsString'], $expected), true),
            'contains' => str_contains($actualString, self::jsString($expected)),
            'not_contains' => !str_contains($actualString, self::jsString($expected)),
            'greater_than', 'gt' => self::compareNumOrDate($actual, $actualString, $expected, '>'),
            'less_than', 'lt' => self::compareNumOrDate($actual, $actualString, $expected, '<'),
            'greater_than_or_equal', 'gte' => self::compareNumOrDate($actual, $actualString, $expected, '>='),
            'less_than_or_equal', 'lte' => self::compareNumOrDate($actual, $actualString, $expected, '<='),
            default => false,
        };
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $ctx
     * @return bool|string `true` / `false`, or the COLD sentinel.
     */
    private static function evaluateCondition(array $condition, array $ctx): bool|string
    {
        $type = (string) ($condition['type'] ?? '');
        $operator = (string) ($condition['operator'] ?? '');
        $value = $condition['value'] ?? null;

        if (in_array($type, self::COLD_CONDITION_TYPES, true)) {
            return self::COLD;
        }

        // Group MEMBERSHIP is resolvable from the caller-supplied groups map alone.
        if ($type === 'group') {
            $groupType = $condition['group_type'] ?? $condition['field'] ?? null;
            if ($groupType === null || $groupType === '') {
                return false;
            }
            $groups = is_array($ctx['groups'] ?? null) ? $ctx['groups'] : [];
            return self::compare($operator, $groups[$groupType] ?? null, $value);
        }
        if ($type === 'experiment_variant') {
            $experimentId = $condition['field'] ?? $condition['attribute_key'] ?? null;
            // Mirrors `!experimentId || !ctx.experiment_variants` — an absent map yields false outright.
            if ($experimentId === null || $experimentId === '' || !isset($ctx['experiment_variants'])) {
                return false;
            }
            $variants = is_array($ctx['experiment_variants']) ? $ctx['experiment_variants'] : [];
            return self::compare($operator, $variants[$experimentId] ?? null, $value);
        }
        if ($type === 'attribute' || $type === 'custom_attribute') {
            $key = $condition['field'] ?? $condition['attribute_key'] ?? null;
            if ($key === null || $key === '') {
                return false;
            }
            return self::compare($operator, self::attr($ctx, (string) $key), $value);
        }
        if ($type === 'country') {
            return self::compare($operator, $ctx['country'] ?? null, $value);
        }
        if ($type === 'platform') {
            return self::compare($operator, $ctx['platform'] ?? null, $value);
        }
        if ($type === 'app_version') {
            return self::compare($operator, $ctx['app_version'] ?? null, $value);
        }
        if ($type === 'sdk_version') {
            return self::compare($operator, $ctx['sdk_version'] ?? null, $value);
        }
        if ($type === 'subscription_status') {
            return self::compare($operator, self::attr($ctx, 'subscription_status'), $value);
        }
        if ($type === 'entitlement') {
            return self::compare($operator, self::attr($ctx, 'entitlement_id'), $value);
        }

        // Derived/unknown types yield false, matching the backend.
        return false;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function attr(array $ctx, string $key): mixed
    {
        $attrs = is_array($ctx['custom_attributes'] ?? null) ? $ctx['custom_attributes'] : [];
        return $attrs[$key] ?? null;
    }

    /** Mirror of pure.ts `toComparable`: null/undefined → null, else String(value). */
    private static function toComparable(mixed $value): ?string
    {
        return $value === null ? null : self::jsString($value);
    }

    /** JS `String(...)` coercion. */
    private static function jsString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            // JS `String([a, b])` is `Array.prototype.join(',')`; a plain object is '[object Object]'.
            return self::isJsArray($value)
                ? implode(',', array_map([self::class, 'jsString'], $value))
                : '[object Object]';
        }
        if (is_object($value)) {
            return '[object Object]';
        }
        return (string) $value;
    }

    /** JS `Array.isArray`: a JSON object decodes to an assoc array in PHP and is NOT an array in JS. */
    private static function isJsArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** JS `Number(...)`, including its NaN — which makes every ordering comparison false, as in JS. */
    private static function jsNumber(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $t = trim($value);
            if ($t === '') {
                return 0.0;
            }
            if (is_numeric($t)) {
                return (float) $t;
            }
            if (preg_match('/^0[xX][0-9a-fA-F]+$/', $t) === 1) {
                return (float) hexdec($t);
            }
            if ($t === 'Infinity' || $t === '+Infinity') {
                return INF;
            }
            if ($t === '-Infinity') {
                return -INF;
            }
            return NAN;
        }
        if (self::isJsArray($value)) {
            // JS: Number([]) === 0, Number([x]) === Number(String(x)), Number([a, b]) is NaN.
            return match (count($value)) {
                0 => 0.0,
                1 => self::jsNumber(self::jsString($value[0])),
                default => NAN,
            };
        }
        return NAN;
    }

    /**
     * Mirror of pure.ts `tryParseDate`: parse only strings shaped like an ISO date (`^\d{4}-\d{2}-\d{2}`)
     * that the date parser accepts, so a plain number like "10" is never mistaken for a date. Returns the
     * timestamp in milliseconds.
     */
    private static function tryParseDateMs(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }
        return self::parseAnyDateMs($value);
    }

    /** `new Date(value).getTime()` for the rule's own date window — no ISO-shape prefilter. */
    private static function parseAnyDateMs(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : $ts * 1000;
    }

    /**
     * Date-or-number comparison, mirroring pure.ts: if BOTH actual and expected parse as ISO dates,
     * compare their ms timestamps; otherwise compare them as JS numbers.
     */
    private static function compareNumOrDate(mixed $actual, string $actualString, mixed $expected, string $op): bool
    {
        $actMs = self::tryParseDateMs($actual);
        $expMs = self::tryParseDateMs($expected);
        if ($actMs !== null && $expMs !== null) {
            return match ($op) {
                '>' => $actMs > $expMs,
                '<' => $actMs < $expMs,
                '>=' => $actMs >= $expMs,
                '<=' => $actMs <= $expMs,
            };
        }

        // pure.ts compares `Number(actualString)` — the STRING view of actual — against `Number(expected)`.
        $a = self::jsNumber($actualString);
        $e = self::jsNumber($expected);
        if (is_nan($a) || is_nan($e)) {
            return false; // every relational comparison with NaN is false in JS, too.
        }
        return match ($op) {
            '>' => $a > $e,
            '<' => $a < $e,
            '>=' => $a >= $e,
            '<=' => $a <= $e,
        };
    }
}
