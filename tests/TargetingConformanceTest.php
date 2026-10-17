<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\Targeting;
use PHPUnit\Framework\TestCase;

/**
 * CANONICAL TARGETING CONFORMANCE.
 *
 * Pinned to `tests/fixtures/flag-targeting.json` — vectors GENERATED FROM the backend evaluator
 * (`evaluateTargetingRulePure` in supabase/functions/_shared/edge-serving/pure.ts). NEVER against another
 * SDK. Nine hand-ports of the murmur hash once all agreed with each other and all disagreed with the
 * server, for months, because every SDK's tests were written against a sibling SDK. Local rule evaluation
 * is a far bigger surface than a hash, so it gets the same discipline from day one.
 *
 * What this proves, beyond "the operators work":
 *   - group MEMBERSHIP resolves locally (it was round-tripped to the server before Phase 3),
 *   - an OR rule is not silently ANDed,
 *   - a paused / expired / not-yet-started rule withholds the flag,
 *   - legacy `segment_ids` fail CLOSED rather than being ignored,
 *   - DB-backed conditions are never resolved locally — they are refused.
 */
final class TargetingConformanceTest extends TestCase
{
    /**
     * Walk up from this file to the repo root so the test works from any working directory.
     *
     * A missing fixture FAILS — it never skips. A test that silently skips is worse than no test.
     *
     * @return array{
     *   rules: array<int,array{name: string, rule: array<string,mixed>}>,
     *   server_only_rules: array<int,array{name: string, rule: array<string,mixed>}>,
     *   contexts: array<int,array{name: string, ctx: array<string,mixed>}>,
     *   vectors: array<int,array{rule_name: string, context_name: string, matched: bool}>
     * }
     */
    private static function loadFixture(): array
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $path = $dir . '/tests/fixtures/flag-targeting.json';
            if (is_file($path)) {
                $raw = file_get_contents($path);
                self::assertIsString($raw, "could not read $path");
                $fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($fixture);
                return $fixture;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break; // hit the filesystem root
            }
            $dir = $parent;
        }
        self::fail('could not find tests/fixtures/flag-targeting.json walking up from ' . __DIR__);
    }

    /**
     * @param array<int,array{name: string, rule?: array<string,mixed>, ctx?: array<string,mixed>}> $items
     * @return array<string,array<string,mixed>>
     */
    private static function byName(array $items, string $field): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[$item['name']] = $item[$field];
        }
        return $out;
    }

    public function testMatchesTheBackendVerdictForEveryVector(): void
    {
        $fixture = self::loadFixture();
        $ruleOf = self::byName($fixture['rules'], 'rule');
        $ctxOf = self::byName($fixture['contexts'], 'ctx');
        $vectors = $fixture['vectors'];

        $this->assertGreaterThanOrEqual(
            100,
            count($vectors),
            sprintf('fixture has only %d vectors — too few to be meaningful', count($vectors))
        );

        $mismatches = [];
        foreach ($vectors as $v) {
            $this->assertArrayHasKey($v['rule_name'], $ruleOf, 'unknown rule in vector');
            $this->assertArrayHasKey($v['context_name'], $ctxOf, 'unknown context in vector');

            ['matched' => $matched, 'cold' => $cold] = Targeting::evaluateTargetingRule(
                $ruleOf[$v['rule_name']],
                $ctxOf[$v['context_name']]
            );

            if ($cold) {
                // The backend resolved this rule purely; going COLD would send it needlessly to the server.
                $mismatches[] = sprintf(
                    '%s x %s: went COLD, backend resolved it purely',
                    $v['rule_name'],
                    $v['context_name']
                );
            } elseif ($matched !== $v['matched']) {
                $mismatches[] = sprintf(
                    '%s x %s: php=%s backend=%s',
                    $v['rule_name'],
                    $v['context_name'],
                    var_export($matched, true),
                    var_export($v['matched'], true)
                );
            }
        }

        $this->assertSame(
            [],
            array_slice($mismatches, 0, 8),
            sprintf(
                '%d/%d vectors disagree with the BACKEND — this SDK targets differently than the server',
                count($mismatches),
                count($vectors)
            )
        );
    }

    /**
     * Guards the guard: a fixture whose every vector is `false` would be satisfied by an evaluator that
     * always returns false. It would certify a completely broken SDK.
     */
    public function testFixtureIsDiscriminating(): void
    {
        $fixture = self::loadFixture();
        $matched = 0;
        foreach ($fixture['vectors'] as $v) {
            if ($v['matched']) {
                $matched++;
            }
        }
        $this->assertGreaterThan(10, $matched, 'fixture is nearly all-miss — it cannot detect a broken evaluator');
        $this->assertLessThan(
            count($fixture['vectors']) - 10,
            $matched,
            'fixture is nearly all-match — it cannot detect a broken evaluator'
        );
    }

    /**
     * The backend marks these `requires_server_evaluation`, so they should never reach the local
     * evaluator. If one ever does, it must go COLD — never resolve to a verdict. Resolving a `segment`
     * condition to `false` would silently withhold the flag; resolving it to `true` would hand a targeted
     * feature to everyone.
     */
    public function testRefusesDbBackedConditionsRatherThanGuessing(): void
    {
        $fixture = self::loadFixture();
        $ctxOf = self::byName($fixture['contexts'], 'ctx');
        $this->assertGreaterThan(0, count($fixture['server_only_rules']));

        foreach ($fixture['server_only_rules'] as $entry) {
            $name = $entry['name'];
            $conditions = $entry['rule']['conditions']['rules'] ?? [];

            $hasCold = false;
            foreach ($conditions as $c) {
                if (in_array($c['type'] ?? '', Targeting::COLD_CONDITION_TYPES, true)) {
                    $hasCold = true;
                }
            }
            $this->assertTrue($hasCold, "$name is listed server-only but has no DB-backed condition");

            // Against a context that satisfies every OTHER condition, the DB-backed one is load-bearing.
            $verdict = Targeting::evaluateTargetingRule($entry['rule'], $ctxOf['us-ios-acme-pro']);
            $this->assertTrue($verdict['cold'], "$name resolved locally instead of deferring to the server");
            $this->assertFalse($verdict['matched'], "$name must never claim a match it could not resolve");
        }
    }

    /**
     * Before Phase 3 this required a server round-trip, not because the data was missing — the SDK holds
     * the groups map — but because the flat wire shape had nowhere to put `group_type`.
     */
    public function testGroupMembershipResolvesLocally(): void
    {
        $fixture = self::loadFixture();
        $rule = self::byName($fixture['rules'], 'rule')['group-org-is'];

        $hit = Targeting::evaluateTargetingRule($rule, ['groups' => ['organisation' => 'acme']]);
        $this->assertFalse($hit['cold']);
        $this->assertTrue($hit['matched']);

        $miss = Targeting::evaluateTargetingRule($rule, ['groups' => ['organisation' => 'globex']]);
        $this->assertFalse($miss['cold']);
        $this->assertFalse($miss['matched']);
    }
}
