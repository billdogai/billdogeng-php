<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use PHPUnit\Framework\TestCase;

/**
 * `$feature_flag_called` exposure events (PostHog parity) + GeoIP resolution header.
 *
 * A flag read must emit ONE `$feature_flag_called` analytics event per (distinctId, flag, value) — the
 * exposure record experiments/analytics attribute outcomes to — deduped, gated by `sendFeatureFlagEvents`,
 * and fire-and-forget. Mirrors node/test/feature-flag-called.test.ts.
 */
final class FeatureFlagCalledTest extends TestCase
{
    /** Serves one locally-evaluable flag and records /ingest-events batches. */
    private function stub(): StubServer
    {
        return new StubServer(function (CapturedRequest $req): array {
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
    }

    private function client(StubServer $stub, array $opts = []): BillDogEng
    {
        $opts['host'] = $stub->url();
        return new BillDogEng('bd_test_x', $opts, new LoopbackHttpHandler($stub));
    }

    /**
     * All events across every recorded /ingest-events batch.
     *
     * @return array<int,array<string,mixed>>
     */
    private function ingestedEvents(StubServer $stub): array
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
     * @return array<int,array<string,mixed>>
     */
    private function flagCalled(array $events): array
    {
        return array_values(array_filter(
            $events,
            fn (array $e) => ($e['event_name'] ?? null) === '$feature_flag_called'
        ));
    }

    public function testEmitsOneExposureEventWithCanonicalShape(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000, 'localEvaluation' => true]);

        $this->assertTrue($bd->getFeatureFlag('plain', 'alice'));
        $bd->flush();

        $calls = $this->flagCalled($this->ingestedEvents($stub));
        $this->assertCount(1, $calls);
        $this->assertSame('alice', $calls[0]['user_id']);
        $this->assertSame('plain', $calls[0]['properties']['$feature_flag']);
        $this->assertSame(true, $calls[0]['properties']['$feature_flag_response']);

        $bd->shutdown();
        $stub->close();
    }

    public function testDedupesPerTripleButNotAcrossUsers(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000, 'localEvaluation' => true]);

        $bd->getFeatureFlag('plain', 'alice');
        $bd->getFeatureFlag('plain', 'alice'); // duplicate → suppressed
        $bd->isFeatureEnabled('plain', 'alice'); // delegates to getFeatureFlag → still deduped
        $bd->getFeatureFlag('plain', 'bob'); // different user → emitted
        $bd->flush();

        $calls = $this->flagCalled($this->ingestedEvents($stub));
        $this->assertCount(2, $calls);
        $userIds = array_map(fn (array $c) => $c['user_id'], $calls);
        sort($userIds);
        $this->assertSame(['alice', 'bob'], $userIds);

        $bd->shutdown();
        $stub->close();
    }

    public function testGlobalSendFeatureFlagEventsFalseSuppresses(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, [
            'flushAt' => 1000,
            'localEvaluation' => true,
            'sendFeatureFlagEvents' => false,
        ]);

        $bd->getFeatureFlag('plain', 'alice');
        $bd->flush();

        $this->assertCount(0, $this->flagCalled($this->ingestedEvents($stub)));

        $bd->shutdown();
        $stub->close();
    }

    public function testPerCallSendFeatureFlagEventsFalseSuppressesJustThatRead(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000, 'localEvaluation' => true]);

        $bd->getFeatureFlag('plain', 'alice', ['sendFeatureFlagEvents' => false]);
        $bd->flush();

        $this->assertCount(0, $this->flagCalled($this->ingestedEvents($stub)));

        $bd->shutdown();
        $stub->close();
    }

    public function testEmitsExposureFromGetFeatureFlagPayload(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000, 'localEvaluation' => true]);

        $bd->getFeatureFlagPayload('plain', 'alice');
        $bd->flush();

        $calls = $this->flagCalled($this->ingestedEvents($stub));
        $this->assertCount(1, $calls);
        $this->assertSame('plain', $calls[0]['properties']['$feature_flag']);
        $this->assertSame(true, $calls[0]['properties']['$feature_flag_response']);

        $bd->shutdown();
        $stub->close();
    }

    public function testGeoipHeaderSentWhenConfigured(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000, 'geoipResolutionLevel' => 'none']);

        $bd->capture('alice', 'evt');
        $bd->flush();

        $ingest = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
        $this->assertCount(1, $ingest);
        $this->assertSame('none', $ingest[0]->headers['x-geoip-resolution-level'] ?? null);

        $bd->shutdown();
        $stub->close();
    }

    public function testGeoipHeaderOmittedWhenNotConfigured(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000]);

        $bd->capture('alice', 'evt');
        $bd->flush();

        $ingest = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
        $this->assertCount(1, $ingest);
        $this->assertArrayNotHasKey('x-geoip-resolution-level', $ingest[0]->headers);

        $bd->shutdown();
        $stub->close();
    }
}
