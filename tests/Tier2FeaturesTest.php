<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use PHPUnit\Framework\TestCase;

/**
 * Tier-2 PostHog-parity features (mirrors node/test/tier2-features.test.ts):
 * getAllFlagsAndPayloads, capture timestamp override, sendFeatureFlags ($feature/ injection),
 * onlyEvaluateLocally, disabled kill-switch, beforeSend hook, and featureFlagsPollingInterval
 * (configurable definitions TTL).
 */
final class Tier2FeaturesTest extends TestCase
{
    private function client(StubServer $stub, array $opts = []): BillDogEng
    {
        $opts['host'] = $stub->url();
        return new BillDogEng('bd_test_x', $opts, new LoopbackHttpHandler($stub));
    }

    /** Stub that serves /ingest-events and 404s everything else. */
    private function ingestStub(): StubServer
    {
        return new StubServer(function (CapturedRequest $req): array {
            if ($req->path === '/ingest-events') {
                return StubServer::envelope(['processed' => 1]);
            }
            return StubServer::raw([], 404);
        });
    }

    /**
     * All events across every recorded /ingest-events batch.
     *
     * @return array<int,array<string,mixed>>
     */
    private function ingested(StubServer $stub): array
    {
        $events = [];
        foreach ($stub->requests as $r) {
            if ($r->path === '/ingest-events' && is_array($r->body)) {
                foreach ($r->body['events'] ?? [] as $e) {
                    $events[] = $e;
                }
            }
        }
        return $events;
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<string,mixed>|null
     */
    private function byName(array $events, string $name): ?array
    {
        foreach ($events as $e) {
            if (($e['event_name'] ?? null) === $name) {
                return $e;
            }
        }
        return null;
    }

    // ─── 1. getAllFlagsAndPayloads ──────────────────────────────────────────

    public function testGetAllFlagsAndPayloadsReturnsValueAndPayloadPerFlag(): void
    {
        $stub = $this->ingestStub();
        $bd = $this->client($stub, ['localEvaluation' => true, 'sendFeatureFlagEvents' => false]);
        $bd->featureFlags()->setDefinitions([
            ['key' => 'plain', 'active' => true, 'rollout_percentage' => 100, 'payload' => ['color' => 'blue']],
            ['key' => 'off', 'active' => true, 'rollout_percentage' => 0],
        ]);

        $all = $bd->getAllFlagsAndPayloads('alice');
        $this->assertSame(['value' => true, 'payload' => ['color' => 'blue']], $all['plain']);
        $this->assertSame(['value' => false, 'payload' => null], $all['off']);

        $bd->shutdown();
        $stub->close();
    }

    // ─── 2. capture timestamp override ──────────────────────────────────────

    public function testCaptureTimestampOverrideEpochMsAndDateTime(): void
    {
        $stub = $this->ingestStub();
        $bd = $this->client($stub, ['flushAt' => 1000]);

        $bd->capture('alice', 'e1', [], [], ['timestamp' => 1000]);
        $bd->capture('bob', 'e2', [], [], ['timestamp' => new \DateTimeImmutable('@2')]); // 2s = 2000ms
        $bd->flush();

        $evts = $this->ingested($stub);
        $this->assertSame(1000, $this->byName($evts, 'e1')['event_timestamp']);
        $this->assertSame(2000, $this->byName($evts, 'e2')['event_timestamp']);

        $bd->shutdown();
        $stub->close();
    }

    // ─── 3. sendFeatureFlags injection ──────────────────────────────────────

    public function testSendFeatureFlagsAttachesFeatureAndActiveFlags(): void
    {
        $stub = $this->ingestStub();
        $bd = $this->client($stub, [
            'flushAt' => 1000,
            'localEvaluation' => true,
            'sendFeatureFlagEvents' => false,
        ]);
        $bd->featureFlags()->setDefinitions([
            ['key' => 'on', 'active' => true, 'rollout_percentage' => 100],
            ['key' => 'off', 'active' => true, 'rollout_percentage' => 0],
        ]);

        $bd->capture('alice', 'purchase', [], [], ['sendFeatureFlags' => true]);
        $bd->flush();

        $evt = $this->byName($this->ingested($stub), 'purchase');
        $this->assertNotNull($evt);
        $props = $evt['properties'];
        $this->assertSame(true, $props['$feature/on']);
        $this->assertSame(false, $props['$feature/off']);
        $this->assertSame(['on'], $props['$active_feature_flags']);

        $bd->shutdown();
        $stub->close();
    }

    // ─── 4. onlyEvaluateLocally ─────────────────────────────────────────────

    public function testOnlyEvaluateLocallyReturnsNullForServerOnlyFlagAndNeverCallsServer(): void
    {
        $stub = new StubServer(function (CapturedRequest $req): array {
            if ($req->path === '/feature-flag-definitions') {
                return StubServer::envelope([
                    'flags' => [
                        ['key' => 'seg', 'active' => true, 'rollout_percentage' => 100, 'requires_server_evaluation' => true],
                    ],
                ]);
            }
            if ($req->path === '/ingest-events') {
                return StubServer::envelope(['processed' => 1]);
            }
            return StubServer::raw([], 404);
        });
        $bd = $this->client($stub, [
            'flushAt' => 1000,
            'localEvaluation' => true,
            'sendFeatureFlagEvents' => false,
        ]);

        $this->assertNull($bd->getFeatureFlag('seg', 'alice', ['onlyEvaluateLocally' => true]));
        $configHits = array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/experiment-config');
        $this->assertCount(0, $configHits);

        $bd->shutdown();
        $stub->close();
    }

    // ─── 5. disabled kill-switch ────────────────────────────────────────────

    public function testDisabledKillSwitchNoOpsWithZeroNetwork(): void
    {
        // Any hit would be a 500 (surfaced as an error); with disabled there must be zero hits.
        $stub = new StubServer(fn (CapturedRequest $req): array => StubServer::raw([], 500));
        $bd = $this->client($stub, ['disabled' => true, 'localEvaluation' => true]);

        $bd->capture('alice', 'e1');
        $bd->identify('alice', ['x' => 1]);
        $bd->setPersonProperties('alice', ['set' => ['plan' => 'pro']]);
        $this->assertSame(0, $bd->queueLength());
        $this->assertNull($bd->getFeatureFlag('any', 'alice'));
        $this->assertSame([], $bd->getAllFlags('alice'));
        $this->assertNull($bd->getFeatureFlagPayload('any', 'alice'));
        $this->assertSame([], $bd->getAllFlagsAndPayloads('alice'));
        $bd->flush();

        $this->assertCount(0, $stub->requests);

        $bd->shutdown();
        $stub->close();
    }

    // ─── 6. beforeSend hook ─────────────────────────────────────────────────

    public function testBeforeSendMutatesAndDropsEvents(): void
    {
        $stub = $this->ingestStub();
        $bd = $this->client($stub, [
            'flushAt' => 1000,
            'beforeSend' => function (array $e): ?array {
                if ($e['event_name'] === 'secret') {
                    return null; // drop
                }
                $e['properties']['tagged'] = true; // mutate
                return $e;
            },
        ]);

        $bd->capture('alice', 'keep');
        $bd->capture('alice', 'secret');
        $bd->flush();

        $evts = $this->ingested($stub);
        $names = array_map(fn (array $e) => $e['event_name'], $evts);
        $this->assertSame(['keep'], $names);
        $this->assertSame(true, $evts[0]['properties']['tagged']);

        $bd->shutdown();
        $stub->close();
    }

    // ─── 7. featureFlagsPollingInterval ─────────────────────────────────────

    public function testFeatureFlagsPollingIntervalRefetchesAfterTtl(): void
    {
        $stub = new StubServer(function (CapturedRequest $req): array {
            if ($req->path === '/feature-flag-definitions') {
                return StubServer::envelope([
                    'flags' => [['key' => 'plain', 'active' => true, 'rollout_percentage' => 100]],
                ]);
            }
            if ($req->path === '/ingest-events') {
                return StubServer::envelope(['processed' => 1]);
            }
            return StubServer::raw([], 404);
        });
        $bd = $this->client($stub, [
            'flushAt' => 1000,
            'localEvaluation' => true,
            'sendFeatureFlagEvents' => false,
            'featureFlagsPollingInterval' => 20,
        ]);

        $bd->getFeatureFlag('plain', 'alice');
        usleep(35 * 1000); // let the 20ms TTL lapse
        $bd->getFeatureFlag('plain', 'bob');

        $defReqs = array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/feature-flag-definitions');
        $this->assertGreaterThanOrEqual(2, count($defReqs));

        $bd->shutdown();
        $stub->close();
    }
}
