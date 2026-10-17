<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use PHPUnit\Framework\TestCase;

/**
 * OPT-IN real-backend E2E smoke test.
 *
 * Unlike every other test in this suite (which use the in-process stub
 * server), this file hits the LIVE BillDog API. It is gated on
 * BD_E2E_API_KEY and skips cleanly when unset, so the regular phpunit run
 * stays hermetic.
 *
 * To run for real:
 *   BD_E2E_API_KEY=bd_test_xxxxxxxx ./vendor/bin/phpunit --filter E2ESmoke
 *   # optionally override the host (defaults to https://api.billdog.io/v1):
 *   BD_E2E_HOST=https://api.billdog.io/v1 BD_E2E_API_KEY=bd_test_xxx ./vendor/bin/phpunit --filter E2ESmoke
 *
 * It performs a minimal real round-trip and asserts only that the live
 * endpoints accept the requests (no DB-row assertions -- no DB creds needed).
 */
final class E2ESmokeTest extends TestCase
{
    public function testCapturesFlushesFetchesFlagListsSurveysLiveBackend(): void
    {
        $apiKey = getenv('BD_E2E_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            $this->markTestSkipped('BD_E2E_API_KEY not set -- skipping live E2E smoke');
        }
        $host = getenv('BD_E2E_HOST');
        if ($host === false || $host === '') {
            $host = 'https://api.billdog.io/v1';
        }

        $bd = new BillDogEng($apiKey, ['host' => $host, 'flushAt' => 100]);
        $distinctId = sprintf('e2e-php-%d-%d', (int) (microtime(true) * 1000), random_int(0, 1 << 30));

        // 1. capture + flush -- the ingest endpoint must accept the batch.
        $bd->capture($distinctId, 'bd_e2e_smoke', ['sdk' => 'php', 'ts' => (int) (microtime(true) * 1000)]);
        $bd->flush(); // throws on transport error
        $this->addToAssertionCount(1);

        // 2. feature flag fetch -- unknown flag resolves to a value
        //    (false/null/string), not an exception, when the HTTP call succeeds.
        $flag = $bd->getFeatureFlag('bd_e2e_nonexistent_flag', $distinctId);
        $this->assertTrue($flag === false || $flag === null || is_string($flag));

        // 3. surveys->list -- the data API must return an array (possibly empty).
        $surveys = $bd->surveys->list($distinctId);
        $this->assertIsArray($surveys);

        $bd->shutdown();
    }
}
