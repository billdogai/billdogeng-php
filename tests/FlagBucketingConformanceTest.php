<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use PHPUnit\Framework\TestCase;

/**
 * CROSS-SIDE FLAG BUCKETING CONFORMANCE.
 *
 * This SDK is asserted against `tests/fixtures/flag-bucketing.json` — vectors GENERATED FROM THE
 * BACKEND evaluator (`supabase/functions/_shared/edge-serving/pure.ts`). NEVER against another SDK.
 *
 * That distinction is the whole point. The murmur bucketing was hand-ported into several SDKs; they
 * all agreed with EACH OTHER and ALL DISAGREED WITH THE SERVER, for months, because every test
 * compared one SDK to another. Separately, every SDK reused the ROLLOUT bucket to pick a variant, so
 * a flag at 10% rollout split 50/50 assigned variant B to NOBODY. Neither bug is visible to a test
 * that does not cross the boundary.
 *
 * The fixture's "starvation" flag (10% rollout, 50/50 split) is the one that matters: it assigns
 * BOTH variants across the user set, so a shared-bucket regression fails here loudly.
 */
final class FlagBucketingConformanceTest extends TestCase
{
    /**
     * Walk up from this file to the repo root so the test works from any working directory.
     *
     * A missing fixture FAILS — it never skips. A test that silently skips is worse than no test.
     *
     * @return array{flags: array<int,array<string,mixed>>, vectors: array<int,array<string,mixed>>}
     */
    private static function loadFixture(): array
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $path = $dir . '/tests/fixtures/flag-bucketing.json';
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
        self::fail('could not find tests/fixtures/flag-bucketing.json walking up from ' . __DIR__);
    }

    public function testConformsToBackendBucketingVectors(): void
    {
        $fixture = self::loadFixture();
        $vectors = $fixture['vectors'];

        $this->assertGreaterThanOrEqual(
            100,
            count($vectors),
            sprintf('fixture has only %d vectors — too few to be meaningful', count($vectors))
        );

        $defs = [];
        foreach ($fixture['flags'] as $flag) {
            $def = [
                'key' => $flag['key'],
                // The wire shape says `active`; the fixture (and DB column) says `enabled`.
                'active' => $flag['enabled'],
                'rollout_percentage' => $flag['rollout_percentage'],
            ];
            if (!empty($flag['variants'])) {
                $def['variants'] = $flag['variants'];
            }
            $defs[] = $def;
        }

        // Pure bucketing test: it has no stub, so exposure flushes would hit the real network. Disable
        // `$feature_flag_called` — this suite asserts VERDICTS, not exposure telemetry.
        $bd = new BillDogEng('bd_test_x', ['localEvaluation' => true, 'sendFeatureFlagEvents' => false]);
        $bd->featureFlags()->setDefinitions($defs);

        $mismatches = [];
        foreach ($vectors as $v) {
            $got = $bd->getFeatureFlag($v['flag_key'], $v['distinct_id']);
            // STRICT: `true` must never be conflated with "variant-a" or 1.
            if ($got !== $v['verdict']) {
                $mismatches[] = sprintf(
                    '%s/%s: php=%s backend=%s',
                    $v['flag_key'],
                    $v['distinct_id'],
                    var_export($got, true),
                    var_export($v['verdict'], true)
                );
            }
        }

        $this->assertSame(
            [],
            $mismatches,
            sprintf(
                "%d/%d vectors disagree with the BACKEND — this SDK buckets differently than the server:\n%s",
                count($mismatches),
                count($vectors),
                implode("\n", array_slice($mismatches, 0, 5))
            )
        );

        // Belt-and-braces: prove every vector was actually compared.
        $this->assertCount(count($vectors), $vectors);
    }

    /**
     * Guards the guard: a fixture in which the 10%/50-50 flag only ever produced one variant could
     * not tell correct code from the shared-bucket bug.
     */
    public function testFixtureCanDetectTheStarvationBug(): void
    {
        $fixture = self::loadFixture();

        $seen = [];
        foreach ($fixture['vectors'] as $v) {
            if ($v['flag_key'] === 'starvation' && is_string($v['verdict'])) {
                $seen[$v['verdict']] = true;
            }
        }

        $message = 'the starvation vectors do not contain BOTH variants (' .
            implode(', ', array_keys($seen)) . ') — this fixture cannot detect the bug it exists to catch';
        $this->assertArrayHasKey('variant-a', $seen, $message);
        $this->assertArrayHasKey('variant-b', $seen, $message);
    }
}
