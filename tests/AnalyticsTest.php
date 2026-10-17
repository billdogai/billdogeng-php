<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use PHPUnit\Framework\TestCase;

/**
 * Spec test #1 (batching) and #2 (identify/group/alias event shapes).
 */
final class AnalyticsTest extends TestCase
{
    private function client(StubServer $stub, array $opts = []): BillDogEng
    {
        $opts['host'] = $stub->url();
        return new BillDogEng('bd_test_x', $opts, new LoopbackHttpHandler($stub));
    }

    public function testTenCapturesProduceOneBatchedPostWithTenEvents(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 10, 'duplicates' => 0, 'invalid' => 0]));
        // flushAt high so the 10 captures don't auto-flush; we flush explicitly.
        $bd = $this->client($stub, ['flushAt' => 100]);

        for ($i = 0; $i < 10; $i++) {
            $bd->capture('user-123', 'order_completed', ['n' => $i]);
        }
        $this->assertSame(10, $bd->queueLength());

        $bd->flush();
        $bd->shutdown();

        $ingest = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
        $this->assertCount(1, $ingest);

        $body = $ingest[0]->body;
        $this->assertSame('bd_test_x', $body['api_key']);
        $this->assertCount(10, $body['events']);

        $ev = $body['events'][0];
        $this->assertSame('order_completed', $ev['event_name']);
        $this->assertSame('user-123', $ev['user_id']);
        $this->assertIsInt($ev['event_timestamp']);
        $this->assertSame(0, $ev['properties']['n']);

        $this->assertSame('bd_test_x', $ingest[0]->headers['x-billdog-api-key']);

        $stub->close();
    }

    public function testAutoFlushesWhenQueueReachesFlushAt(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 5]));
        $bd = $this->client($stub, ['flushAt' => 5]);

        for ($i = 0; $i < 5; $i++) {
            $bd->capture('u', 'e', ['i' => $i]);
        }
        $bd->shutdown();

        $ingest = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
        $this->assertGreaterThanOrEqual(1, count($ingest));
        $total = array_sum(array_map(fn (CapturedRequest $r) => count($r->body['events']), $ingest));
        $this->assertSame(5, $total);
        $stub->close();
    }

    public function testIdentifyEmitsIdentifyWithSet(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 1]));
        $bd = $this->client($stub, ['flushAt' => 100]);

        $bd->identify('user-123', ['email' => 'a@b.com', 'plan' => 'pro']);
        $bd->flush();
        $bd->shutdown();

        $ev = $stub->requests[0]->body['events'][0];
        $this->assertSame('$identify', $ev['event_name']);
        $this->assertSame('user-123', $ev['user_id']);
        $this->assertSame(['email' => 'a@b.com', 'plan' => 'pro'], $ev['properties']['$set']);
        $stub->close();
    }

    public function testGroupIdentifyEmitsGroupIdentify(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 1]));
        $bd = $this->client($stub, ['flushAt' => 100]);

        $bd->groupIdentify('company', 'acme', ['seats' => 50]);
        $bd->flush();
        $bd->shutdown();

        $ev = $stub->requests[0]->body['events'][0];
        $this->assertSame('$groupidentify', $ev['event_name']);
        $this->assertSame('acme', $ev['user_id']);
        $props = $ev['properties'];
        $this->assertSame('company', $props['$group_type']);
        $this->assertSame('acme', $props['$group_key']);
        $this->assertSame(['seats' => 50], $props['$group_set']);
        $stub->close();
    }

    public function testAliasEmitsCreateAlias(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 1]));
        $bd = $this->client($stub, ['flushAt' => 100]);

        $bd->alias('user-123', 'anon-abc');
        $bd->flush();
        $bd->shutdown();

        $ev = $stub->requests[0]->body['events'][0];
        $this->assertSame('$create_alias', $ev['event_name']);
        $this->assertSame('anon-abc', $ev['properties']['alias']);
        $stub->close();
    }

    public function testCaptureSendsGroupsOnlyNoPositionalGroupN(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 1]));
        $bd = $this->client($stub, ['flushAt' => 100]);

        $bd->capture('user-123', 'event', ['x' => 1], ['company' => 'acme', 'team' => 'core']);
        $bd->flush();
        $bd->shutdown();

        $props = $stub->requests[0]->body['events'][0]['properties'];
        $this->assertSame(['company' => 'acme', 'team' => 'core'], $props['$groups']);
        // `$groups` is the only group wire shape: no positional $group_N keys.
        $positional = array_filter(array_keys($props), fn ($k) => str_starts_with((string) $k, '$group_'));
        $this->assertSame([], array_values($positional));
        $stub->close();
    }
}
