<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use PHPUnit\Framework\TestCase;

/**
 * Spec test #3 — person-property operations (`$set_once` parity) via POST /user-properties.
 *
 * The `$identify` event alone can't express `$set_once` (write-only-if-absent), so
 * `setPersonProperties` (and `identify`'s `setOnce` option) persist the full op vocabulary to the
 * profile store. Default `identify(id, props)` behaviour is unchanged — it does NOT hit
 * /user-properties. Mirrors node/test/person-properties.test.ts; the PHP call is synchronous, so
 * the round-trip completes before the assertion (no polling needed).
 */
final class PersonPropertiesTest extends TestCase
{
    private function client(StubServer $stub, array $opts = []): BillDogEng
    {
        $opts['host'] = $stub->url();
        return new BillDogEng('bd_test_x', $opts, new LoopbackHttpHandler($stub));
    }

    /** Stub that serves both /user-properties and /ingest-events. */
    private function stub(): StubServer
    {
        return new StubServer(function (CapturedRequest $req) {
            if ($req->path === '/user-properties') {
                return StubServer::envelope(['updated' => true]);
            }
            if ($req->path === '/ingest-events') {
                return StubServer::envelope(['processed' => 1]);
            }
            return StubServer::raw([], 404);
        });
    }

    /** @return CapturedRequest[] */
    private function userProps(StubServer $stub): array
    {
        return array_values(array_filter(
            $stub->requests,
            fn (CapturedRequest $r) => $r->path === '/user-properties'
        ));
    }

    public function testMapsSetSetOnceUnsetToOperationsWireShape(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000]);

        $bd->setPersonProperties('alice', [
            'set' => ['plan' => 'pro'],
            'setOnce' => ['signup' => '2026-01-01'],
            'unset' => ['tmp'],
        ]);

        $reqs = $this->userProps($stub);
        $this->assertCount(1, $reqs);
        $body = $reqs[0]->body;
        $this->assertSame('alice', $body['distinct_id']);
        $this->assertSame('bd_test_x', $body['api_key']);
        $this->assertSame([
            ['operation' => '$set', 'key' => 'plan', 'value' => 'pro'],
            ['operation' => '$set_once', 'key' => 'signup', 'value' => '2026-01-01'],
            ['operation' => '$unset', 'key' => 'tmp'],
        ], $body['operations']);
        $this->assertSame('bd_test_x', $reqs[0]->headers['x-billdog-api-key']);

        $bd->shutdown();
        $stub->close();
    }

    public function testAddAndAppendAreEmittedInOrder(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000]);

        $bd->setPersonProperties('bob', [
            'add' => ['visits' => 3],
            'append' => ['tags' => 'vip'],
        ]);

        $reqs = $this->userProps($stub);
        $this->assertCount(1, $reqs);
        $this->assertSame([
            ['operation' => '$add', 'key' => 'visits', 'value' => 3],
            ['operation' => '$append', 'key' => 'tags', 'value' => 'vip'],
        ], $reqs[0]->body['operations']);

        $bd->shutdown();
        $stub->close();
    }

    public function testNoOpWhenThereAreNoOperations(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000]);

        $bd->setPersonProperties('alice', []);

        $this->assertCount(0, $this->userProps($stub));

        $bd->shutdown();
        $stub->close();
    }

    public function testIdentifySetOncePersistsAndStillEmitsIdentifyEvent(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000]);

        $bd->identify('alice', ['email' => 'a@b.co'], ['setOnce' => ['first_seen' => '2026-01-01']]);

        $reqs = $this->userProps($stub);
        $this->assertCount(1, $reqs);
        $this->assertSame([
            ['operation' => '$set_once', 'key' => 'first_seen', 'value' => '2026-01-01'],
        ], $reqs[0]->body['operations']);

        // The $identify event is still emitted to /ingest-events on flush.
        $bd->flush();
        $identifyEvents = [];
        foreach ($stub->requests as $r) {
            if ($r->path !== '/ingest-events') {
                continue;
            }
            foreach (($r->body['events'] ?? []) as $ev) {
                if (($ev['event_name'] ?? null) === '$identify') {
                    $identifyEvents[] = $ev;
                }
            }
        }
        $this->assertCount(1, $identifyEvents);

        $bd->shutdown();
        $stub->close();
    }

    public function testDefaultIdentifyDoesNotCallUserProperties(): void
    {
        $stub = $this->stub();
        $bd = $this->client($stub, ['flushAt' => 1000]);

        $bd->identify('alice', ['email' => 'a@b.co']);

        $this->assertCount(0, $this->userProps($stub));

        $bd->flush();
        $bd->shutdown();
        $stub->close();
    }
}
