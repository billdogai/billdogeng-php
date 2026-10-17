<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use BillDogEng\BillDogEngException;
use PHPUnit\Framework\TestCase;

/**
 * Spec test #4: a 503 then 200 succeeds after backoff against a local stub.
 *
 * A recording (non-sleeping) sleeper is injected so the test runs fast while
 * still asserting the exponential backoff schedule (1s, 2s, …) that a real run
 * would wait. Two HTTP attempts (one 503, one 200) prove the retry happened.
 */
final class RetryTest extends TestCase
{
    /** @var array<int,float> */
    private array $sleeps = [];

    private function recordingSleeper(): callable
    {
        $this->sleeps = [];
        return function (float $seconds): void {
            $this->sleeps[] = $seconds;
        };
    }

    public function testFiveOhThreeThenTwoHundredSucceedsAfterBackoff(): void
    {
        $stub = new StubServer(function (CapturedRequest $req, int $hits): array {
            if ($hits === 0) {
                return [
                    'status' => 503,
                    'body' => json_encode(['success' => false, 'error' => ['code' => 'UNAVAILABLE', 'message' => 'try later']]),
                ];
            }
            return StubServer::envelope(['processed' => 1]);
        });

        $bd = new BillDogEng(
            'bd_test_x',
            ['host' => $stub->url(), 'flushAt' => 100, 'maxRetries' => 3],
            new LoopbackHttpHandler($stub),
            $this->recordingSleeper()
        );
        $bd->capture('user-123', 'event', ['a' => 1]);

        $bd->flush(); // should not throw
        $bd->shutdown();

        // Two attempts total: one 503, one 200.
        $ingest = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
        $this->assertCount(2, $ingest);

        // The first backoff is 1s.
        $this->assertNotEmpty($this->sleeps);
        $this->assertSame(1.0, $this->sleeps[0]);

        // Queue fully drained.
        $this->assertSame(0, $bd->queueLength());
        $stub->close();
    }

    public function testExhaustsRetriesThenThrowsAndRequeues(): void
    {
        $stub = new StubServer(fn () => [
            'status' => 500,
            'body' => json_encode(['success' => false, 'error' => ['code' => 'BOOM', 'message' => 'always down']]),
        ]);

        $bd = new BillDogEng(
            'bd_test_x',
            ['host' => $stub->url(), 'flushAt' => 100, 'maxRetries' => 1],
            new LoopbackHttpHandler($stub),
            $this->recordingSleeper()
        );
        $bd->capture('user-123', 'event');

        $threw = false;
        try {
            $bd->flush();
        } catch (BillDogEngException $e) {
            $threw = true;
        }
        $this->assertTrue($threw);
        // Batch re-queued so it is not lost.
        $this->assertSame(1, $bd->queueLength());

        try {
            $bd->shutdown();
        } catch (BillDogEngException) {
            // shutdown re-attempts the failing flush; swallow.
        }

        // maxRetries:1 → 2 attempts on the first flush + retries on shutdown flush.
        $ingest = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
        $this->assertGreaterThanOrEqual(2, count($ingest));
        $stub->close();
    }

    public function testDoesNotRetryFourxx(): void
    {
        $stub = new StubServer(fn () => [
            'status' => 400,
            'body' => json_encode(['success' => false, 'error' => ['code' => 'BAD', 'message' => 'bad request']]),
        ]);

        $bd = new BillDogEng(
            'bd_test_x',
            ['host' => $stub->url(), 'flushAt' => 100, 'maxRetries' => 3],
            new LoopbackHttpHandler($stub),
            $this->recordingSleeper()
        );
        $bd->capture('u', 'e');

        $status = null;
        try {
            $bd->flush();
        } catch (BillDogEngException $e) {
            $status = $e->status;
        }
        $this->assertSame(400, $status);

        $ingest = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
        $this->assertCount(1, $ingest); // no retries

        try {
            $bd->shutdown();
        } catch (BillDogEngException) {
        }
        $stub->close();
    }
}
