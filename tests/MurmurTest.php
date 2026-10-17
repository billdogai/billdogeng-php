<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use BillDogEng\FlagVerdict;
use BillDogEng\Murmur;
use PHPUnit\Framework\TestCase;

/**
 * Spec test #3 (part A): the 12 canonical murmurhash3 vectors. These are
 * cross-checked against the JS / iOS / Android implementations — they MUST all
 * match exactly, otherwise local flag bucketing diverges across platforms.
 *
 * Part B: rollout / targeting / multivariate local evaluation.
 */
final class MurmurTest extends TestCase
{
    /** @return array<int,array{string,int,int}> */
    public static function vectors(): array
    {
        return [
            ['', 0, 0],
            ['a', 1009084850, 50],
            ['ab', 2613040991, 91],
            ['abc', 3017643002, 2],
            ['abcd', 1139631978, 78],
            ['hello', 613153351, 51],
            ['feature_x.user-123', 1651817416, 16],
            ['new_checkout.user-42', 2069292701, 1],
            ['dark_mode.alice', 325027212, 12],
            ['beta.bob', 3544697655, 55],
            ['my_flag.00000000-0000-0000-0000-000000000000', 2457227110, 10],
            ['flag.user_with_a_fairly_long_identifier_string_1234567890', 1645539598, 98],
        ];
    }

    /**
     * @dataProvider vectors
     */
    public function testCanonicalVector(string $input, int $hash, int $mod100): void
    {
        $this->assertSame($hash, Murmur::hash($input), "hash(\"{$input}\")");
        $this->assertSame($mod100, Murmur::hash($input) % 100, "bucket(\"{$input}\")");
    }

    public function testAllTwelveVectorsInOneSweep(): void
    {
        $actual = [];
        foreach (self::vectors() as [$input]) {
            $h = Murmur::hash($input);
            $actual[] = [$input, $h, $h % 100];
        }
        $this->assertSame(self::vectors(), $actual);
    }

    // ─── Part B: local evaluation ────────────────────────────────────────────

    private function makeClient(): BillDogEng
    {
        // Pure local-evaluation tests: no stub, so exposure flushes would hit the real network. Disable
        // `$feature_flag_called` here — these assert verdicts, not exposure telemetry.
        return new BillDogEng('bd_test_x', ['localEvaluation' => true, 'sendFeatureFlagEvents' => false]);
    }

    public function testRolloutGating(): void
    {
        $bd = $this->makeClient();
        // CANONICAL buckets — murmur("flag:{key}:{user}") % 100, verified against the backend-generated
        // tests/fixtures/flag-bucketing.json: dark_mode/alice = 82, off_flag/alice = 86.
        //
        // These previously said "bucket 12", asserted against the OLD "{key}.{user}" hash input. The SDK
        // was corrected to the backend's canonical "flag:{key}:{id}" form; these expectations were not,
        // so this suite has been red since. FlagBucketingConformanceTest now pins the SDK to the backend's
        // own vectors, so a hand-computed bucket can never again be the source of truth.
        $bd->featureFlags()->setDefinitions([
            ['key' => 'dark_mode', 'active' => true, 'rollout_percentage' => 83], // 82 < 83 → ON
            ['key' => 'off_flag', 'active' => true, 'rollout_percentage' => 86],  // 86 < 86 → false
        ]);
        $this->assertTrue($bd->getFeatureFlag('dark_mode', 'alice'));
        $this->assertFalse($bd->getFeatureFlag('off_flag', 'alice'));
    }

    public function testInactiveFlagIsFalse(): void
    {
        $bd = $this->makeClient();
        $bd->featureFlags()->setDefinitions([
            ['key' => 'dark_mode', 'active' => false, 'rollout_percentage' => 100],
        ]);
        $this->assertFalse($bd->getFeatureFlag('dark_mode', 'alice'));
    }

    public function testUnknownFlagIsNull(): void
    {
        $bd = $this->makeClient();
        $bd->featureFlags()->setDefinitions([]);
        $this->assertNull($bd->getFeatureFlag('nope', 'alice'));
    }

    public function testTargetingRuleAllConditionsMustMatch(): void
    {
        $bd = $this->makeClient();
        // The ONE canonical audience DSL (Targeting). This test used to carry the flat
        // `targeting_rules => [{attribute, operator, value}]` shape — a SECOND condition DSL that could
        // express neither OR, nor a date window, nor group membership. It is gone.
        $bd->featureFlags()->setDefinitions([[
            'key' => 'beta',
            'active' => true,
            'rollout_percentage' => 100,
            'targeting_rule' => [
                'status' => 'active',
                'enabled' => true,
                'conditions' => [
                    'logic' => 'AND',
                    'rules' => [
                        ['type' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                        ['type' => 'attribute', 'field' => 'age', 'operator' => 'gt', 'value' => 18],
                    ],
                ],
            ],
        ]]);
        // beta/bob → rollout bucket 26, within rollout 100.
        $this->assertTrue($bd->getFeatureFlag('beta', 'bob', [
            'personProperties' => ['plan' => 'pro', 'age' => 30],
        ]));
        $this->assertFalse($bd->getFeatureFlag('beta', 'bob', [
            'personProperties' => ['plan' => 'free', 'age' => 30],
        ]));
        $this->assertFalse($bd->getFeatureFlag('beta', 'bob', [
            'personProperties' => ['plan' => 'pro', 'age' => 15],
        ]));
    }

    public function testTargetingRuleReadsTheEvalContextNotJustProperties(): void
    {
        $bd = $this->makeClient();
        // country / platform / app_version / sdk_version / groups / experiment_variants are first-class
        // eval options now — no flat attribute bag can express `group` or an OR.
        $bd->featureFlags()->setDefinitions([[
            'key' => 'beta',
            'active' => true,
            'rollout_percentage' => 100,
            'targeting_rule' => [
                'status' => 'active',
                'enabled' => true,
                'conditions' => [
                    'logic' => 'OR',
                    'rules' => [
                        ['type' => 'group', 'group_type' => 'organisation', 'operator' => 'is', 'value' => 'acme'],
                        ['type' => 'country', 'operator' => 'is', 'value' => 'JP'],
                    ],
                ],
            ],
        ]]);
        // Group membership — resolved LOCALLY, with no server round-trip.
        $this->assertTrue($bd->getFeatureFlag('beta', 'bob', ['groups' => ['organisation' => 'acme']]));
        // OR's other arm, from the explicit country context.
        $this->assertTrue($bd->getFeatureFlag('beta', 'bob', ['country' => 'JP']));
        // Country also falls back to a same-named person property.
        $this->assertTrue($bd->getFeatureFlag('beta', 'bob', ['personProperties' => ['country' => 'JP']]));
        // Neither arm matches.
        $this->assertFalse($bd->getFeatureFlag('beta', 'bob', [
            'groups' => ['organisation' => 'globex'],
            'country' => 'US',
        ]));
    }

    public function testDbBackedConditionGoesColdRatherThanFalse(): void
    {
        $bd = $this->makeClient();
        // A `segment` condition can only be answered by the database. Local evaluation must say "I don't
        // know" (FlagVerdict::Cold) so the caller asks the server — NEVER `false`, which would silently
        // withhold the flag, and never `true`, which would hand a targeted feature to everyone.
        $bd->featureFlags()->setDefinitions([[
            'key' => 'beta',
            'active' => true,
            'rollout_percentage' => 100,
            'targeting_rule' => [
                'status' => 'active',
                'enabled' => true,
                'conditions' => [
                    'logic' => 'AND',
                    'rules' => [
                        ['type' => 'country', 'operator' => 'is', 'value' => 'US'],
                        ['type' => 'segment', 'operator' => 'is', 'value' => 'seg-1'],
                    ],
                ],
            ],
        ]]);
        $this->assertSame(
            FlagVerdict::Cold,
            $bd->featureFlags()->evaluateLocally('beta', 'bob', ['country' => 'US'])
        );
        // The pure `country` condition still decides an AND rule on its own when it FAILS — no server needed.
        $this->assertFalse($bd->featureFlags()->evaluateLocally('beta', 'bob', ['country' => 'FR']));
    }

    public function testMultivariateWalk(): void
    {
        $bd = $this->makeClient();
        // Variant selection uses an INDEPENDENT hash — murmur("flag-variant:{key}:{user}") % 100 — NOT
        // the rollout bucket. beta/alice: rollout 88 (< 100 → ON), variant 36 → "test" in [30,70).
        //
        // This previously asserted beta/BOB → "test" via "bucket 55" — the ROLLOUT bucket. That is the
        // starvation bug written down as an expectation. beta/bob's true variant bucket is 96 → "big".
        $bd->featureFlags()->setDefinitions([[
            'key' => 'beta',
            'active' => true,
            'rollout_percentage' => 100,
            'variants' => [
                ['key' => 'control', 'rollout_percentage' => 30, 'payload' => ['color' => 'blue']],
                ['key' => 'test', 'rollout_percentage' => 40, 'payload' => ['color' => 'green']],
                ['key' => 'big', 'rollout_percentage' => 30],
            ],
        ]]);
        $this->assertSame('test', $bd->getFeatureFlag('beta', 'alice')); // variant 36 ∈ [30,70)
        $this->assertSame(['color' => 'green'], $bd->getFeatureFlagPayload('beta', 'alice'));
    }

    public function testGetAllFlags(): void
    {
        $bd = $this->makeClient();
        $bd->featureFlags()->setDefinitions([
            ['key' => 'dark_mode', 'active' => true, 'rollout_percentage' => 83], // alice bucket 82 → ON
            ['key' => 'never', 'active' => true, 'rollout_percentage' => 0],
        ]);
        $this->assertSame(['dark_mode' => true, 'never' => false], $bd->getAllFlags('alice'));
    }
}
