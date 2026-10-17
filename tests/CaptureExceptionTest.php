<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use PHPUnit\Framework\TestCase;

/**
 * Tier-3: captureException → canonical `$exception` event (PostHog error-tracking parity).
 * Mirrors the Node SDK's `test/capture-exception.test.ts`.
 */
final class CaptureExceptionTest extends TestCase
{
    private function client(StubServer $stub, array $opts = []): BillDogEng
    {
        $opts['host'] = $stub->url();
        return new BillDogEng('bd_test_x', $opts, new LoopbackHttpHandler($stub));
    }

    /** Throw + catch so the throwable carries a real (non-empty) trace with an in-app frame. */
    private function boom(): \Throwable
    {
        try {
            throw new \TypeError('boom');
        } catch (\Throwable $e) {
            return $e;
        }
    }

    public function testEmitsExceptionEventWithCanonicalList(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 1]));
        $bd = $this->client($stub, ['flushAt' => 1000]);

        $bd->captureException($this->boom(), 'alice', ['route' => '/checkout']);
        $bd->flush();
        $bd->shutdown();

        $ingest = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
        $this->assertCount(1, $ingest);
        $events = $ingest[0]->body['events'];
        $this->assertCount(1, $events);

        $e = $events[0];
        $this->assertSame('$exception', $e['event_name']);
        $this->assertSame('alice', $e['user_id']);

        $props = $e['properties'];
        $this->assertSame('/checkout', $props['route']);
        $this->assertSame('TypeError', $props['$exception_type']);
        $this->assertSame('boom', $props['$exception_message']);
        $this->assertSame('error', $props['$exception_level']);

        $this->assertIsArray($props['$exception_list']);
        $entry = $props['$exception_list'][0];
        $this->assertSame('TypeError', $entry['type']);
        $this->assertSame('boom', $entry['value']);
        $this->assertSame(['handled' => true, 'synthetic' => false], $entry['mechanism']);
        $this->assertSame('raw', $entry['stacktrace']['type']);

        $frames = $entry['stacktrace']['frames'];
        $this->assertGreaterThan(0, count($frames));
        // The throw site is the LAST frame (oldest-first ordering); it lives in this test file → in_app.
        $frame = $frames[count($frames) - 1];
        $this->assertIsString($frame['filename']);
        $this->assertIsInt($frame['lineno']);
        $this->assertIsBool($frame['in_app']);
        $this->assertTrue($frame['in_app']);
        $this->assertStringContainsString('CaptureExceptionTest.php', $frame['filename']);

        $stub->close();
    }

    public function testStacklessThrowableHasEmptyFrames(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 1]));
        $bd = $this->client($stub, ['flushAt' => 1000]);

        // A stackless throwable (empty trace) — the PHP analogue of Node's non-Error / stackless value.
        // getTrace() is final, so force the internal trace empty via reflection to exercise that path.
        $stackless = new \RuntimeException('just a string');
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($stackless, []);
        $bd->captureException($stackless, 'bob');
        $bd->flush();
        $bd->shutdown();

        $props = $stub->requests[0]->body['events'][0]['properties'];
        $this->assertSame('just a string', $props['$exception_message']);
        $this->assertSame([], $props['$exception_list'][0]['stacktrace']['frames']);

        $stub->close();
    }

    public function testInertWhenDisabled(): void
    {
        $stub = new StubServer(fn () => StubServer::raw([], 500));
        $bd = $this->client($stub, ['disabled' => true]);

        $bd->captureException(new \Exception('x'), 'alice');
        $this->assertSame(0, $bd->queueLength());

        $bd->shutdown();
        $stub->close();
    }
}
