<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\Analytics;
use BillDogEng\BillDogEng;
use BillDogEng\BillDogEngException;
use BillDogEng\Options;
use BillDogEng\Transport;
use PHPUnit\Framework\TestCase;

/**
 * The client half of the `quota_limited` contract
 * (supabase/functions/_shared/quota-limited.ts): ingest answers an exhausted allowance
 * with HTTP 200 and a top-level `quota_limited` array rather than an error status, so a
 * 2xx no longer proves the batch was stored.
 *
 * These pin the SERVER-side semantics, which differ from the mobile SDKs' suspend-for-
 * the-session: a long-lived process must stop queueing entirely (an allowance that
 * cannot be spent is a memory leak, not a rescue) and must self-heal on its own, since a
 * monthly reset or an upgrade can arrive at any point in a process that runs for weeks.
 */
final class QuotaLimitedTest extends TestCase
{
    /** File the ungated {@see Analytics::warn} output is captured into, so "logged once" is assertable. */
    private string $errorLog = '';

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'billdogeng-quota-');
        $this->errorLog = $tmp === false ? '' : $tmp;
        ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        if ($this->errorLog !== '' && file_exists($this->errorLog)) {
            @unlink($this->errorLog);
        }
    }

    private function warnings(): string
    {
        if ($this->errorLog === '' || !file_exists($this->errorLog)) {
            return '';
        }
        $contents = file_get_contents($this->errorLog);
        return $contents === false ? '' : $contents;
    }

    /** The canonical over-quota response: 200, top-level `quota_limited`, NOT enveloped. */
    private static function quotaLimited(): array
    {
        return StubServer::raw([
            'success' => true,
            'quota_limited' => ['events'],
            'quota' => ['scope' => 'plan', 'metric' => 'events', 'cap' => 1000000],
            'message' => "Your free plan's monthly events allowance (1,000,000) is exhausted — "
                . 'this data was not stored. Add a payment method / upgrade your Engagement plan to keep sending.',
        ]);
    }

    private function client(StubServer $stub, array $opts = []): BillDogEng
    {
        $opts['host'] = $stub->url();
        return new BillDogEng('bd_test_x', $opts, new LoopbackHttpHandler($stub));
    }

    /**
     * Analytics wired directly so the 15-minute cooldown can be driven by a fake clock —
     * the same trick {@see RetryTest} plays on the transport's sleeper.
     *
     * @param callable():float $clock
     */
    private function analytics(StubServer $stub, callable $clock): Analytics
    {
        $options = new Options(host: $stub->url(), flushAt: 100);
        $transport = new Transport(
            host: $options->host,
            requestTimeoutSeconds: $options->requestTimeoutSeconds(),
            gzip: $options->gzip,
            maxRetries: $options->maxRetries,
            handler: new LoopbackHttpHandler($stub),
            enableLogging: false,
            sleeper: static function (float $seconds): void {
            },
        );

        return new Analytics($transport, 'bd_test_x', $options, $clock);
    }

    /** @return array<int,CapturedRequest> */
    private function ingestRequests(StubServer $stub): array
    {
        return array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/ingest-events'));
    }

    public function testQuotaLimitedTwoHundredSuspendsInsteadOfReportingSuccess(): void
    {
        $stub = new StubServer(fn () => self::quotaLimited());
        $bd = $this->client($stub, ['flushAt' => 100]);

        $bd->capture('user-123', 'order_completed', ['n' => 1]);
        $bd->flush(); // must not throw — a refused allowance is not the host app's problem

        // The batch was NOT stored, so it is neither reported as flushed nor re-queued:
        // unlike a transient failure it can never become storable.
        $this->assertCount(1, $this->ingestRequests($stub));
        $this->assertSame(0, $bd->queueLength());

        // Suspended: further captures are no-ops, so memory cannot grow …
        for ($i = 0; $i < 50; $i++) {
            $bd->capture('user-123', 'order_completed', ['n' => $i]);
        }
        $this->assertSame(0, $bd->queueLength());

        // … and neither flush nor shutdown sends another batch into the closed meter.
        $bd->flush();
        $bd->shutdown();
        $this->assertCount(1, $this->ingestRequests($stub));

        // The server's own prose, logged exactly once — the only signal the host gets.
        $warnings = $this->warnings();
        $this->assertSame(1, substr_count($warnings, 'event ingest suspended'));
        $this->assertStringContainsString("Your free plan's monthly events allowance", $warnings);

        $stub->close();
    }

    public function testNormalTwoHundredStillFlushesAndKeepsThePipelineOpen(): void
    {
        $stub = new StubServer(fn () => StubServer::envelope(['processed' => 1, 'duplicates' => 0, 'invalid' => 0]));
        $bd = $this->client($stub, ['flushAt' => 100]);

        $bd->capture('user-123', 'order_completed', ['n' => 1]);
        $bd->flush();
        $this->assertSame(0, $bd->queueLength());

        $bd->capture('user-123', 'order_completed', ['n' => 2]);
        $this->assertSame(1, $bd->queueLength()); // still queueing: nothing was suspended
        $bd->flush();
        $bd->shutdown();

        $this->assertCount(2, $this->ingestRequests($stub));
        $this->assertStringNotContainsString('event ingest suspended', $this->warnings());
        $stub->close();
    }

    public function testMistypedQuotaLimitedCountsAsStoredRatherThanThrowing(): void
    {
        // A `quota_limited` key of an unexpected shape carries no live signal: it may not
        // throw out of the flush path or suspend anything.
        $stub = new StubServer(fn (CapturedRequest $r, int $hits): array => match ($hits) {
            0 => StubServer::raw(['quota_limited' => 'events']), // string, not an array
            default => StubServer::envelope(['processed' => 1]),
        });
        $bd = $this->client($stub, ['flushAt' => 100]);

        for ($i = 0; $i < 2; $i++) {
            $bd->capture('user-123', 'order_completed', ['n' => $i]);
            $bd->flush();
            $this->assertSame(0, $bd->queueLength());
        }
        $bd->shutdown();

        $this->assertCount(2, $this->ingestRequests($stub));
        $this->assertStringNotContainsString('event ingest suspended', $this->warnings());
        $stub->close();
    }

    public function testNonEnvelopeTwoHundredBodyIsRefusedAndTheBatchKept(): void
    {
        // Not a BillDog answer (not JSON, empty, a bare list, a bare object), so not proof
        // of delivery: the flush fails like any other failed request, the batch is
        // re-queued, and nothing is suspended.
        $stub = new StubServer(fn (CapturedRequest $r, int $hits): array => match ($hits) {
            0 => ['status' => 200, 'body' => 'not json at all'],
            1 => ['status' => 200, 'body' => ''],
            2 => StubServer::raw([1, 2, 3]),
            3 => StubServer::raw(['processed' => 1]),
            default => StubServer::envelope(['processed' => 1]),
        });
        $bd = $this->client($stub, ['flushAt' => 100, 'maxRetries' => 0]);

        $bd->capture('user-123', 'order_completed');
        for ($i = 0; $i < 4; $i++) {
            try {
                $bd->flush();
                $this->fail("reply {$i} must be refused");
            } catch (BillDogEngException $e) {
                $this->assertSame('MALFORMED_RESPONSE', $e->errorCode, "reply {$i}");
            }
            $this->assertSame(1, $bd->queueLength(), "reply {$i}: batch kept for the next flush");
        }

        // Never suspended: the kept batch goes out on the next well-formed answer.
        $bd->flush();
        $this->assertSame(0, $bd->queueLength());
        $bd->shutdown();

        $this->assertCount(5, $this->ingestRequests($stub));
        $this->assertStringNotContainsString('event ingest suspended', $this->warnings());
        $stub->close();
    }

    public function testEmptyQuotaLimitedArrayMeansTheBatchWasStored(): void
    {
        $stub = new StubServer(fn () => StubServer::raw(['success' => true, 'quota_limited' => []]));
        $bd = $this->client($stub, ['flushAt' => 100]);

        $bd->capture('user-123', 'order_completed', ['n' => 1]);
        $bd->flush();
        $bd->capture('user-123', 'order_completed', ['n' => 2]);
        $this->assertSame(1, $bd->queueLength());
        $bd->flush();
        $bd->shutdown();

        $this->assertCount(2, $this->ingestRequests($stub));
        $this->assertStringNotContainsString('event ingest suspended', $this->warnings());
        $stub->close();
    }

    public function testProbeAfterTheCooldownResumesWhenTheAllowanceReturns(): void
    {
        $now = 1_000_000.0;
        $clock = function () use (&$now): float {
            return $now;
        };

        // Refused once, then stored — the monthly reset (or an upgrade) landing.
        $stub = new StubServer(fn (CapturedRequest $r, int $hits): array
            => $hits === 0 ? self::quotaLimited() : StubServer::envelope(['processed' => 1]));
        $analytics = $this->analytics($stub, $clock);

        $analytics->capture('user-123', 'order_completed');
        $analytics->flush();
        $analytics->capture('user-123', 'order_completed');
        $this->assertSame(0, $analytics->queueLength()); // dark

        $now += 901.0; // cooldown elapsed → exactly one probe batch may be assembled
        $analytics->capture('user-123', 'order_completed');
        $this->assertSame(1, $analytics->queueLength());

        $analytics->flush(); // the probe — stored this time
        $this->assertCount(2, $this->ingestRequests($stub));

        // Recovered: queueing and flushing are normal again, announced exactly once.
        $analytics->capture('user-123', 'order_completed');
        $this->assertSame(1, $analytics->queueLength());
        $analytics->flush();
        $analytics->shutdown();
        $this->assertCount(3, $this->ingestRequests($stub));
        $this->assertSame(1, substr_count($this->warnings(), 'event ingest resumed'));

        $stub->close();
    }

    public function testRefusedProbeRestartsTheCooldownWithoutLoggingAgain(): void
    {
        $now = 1_000_000.0;
        $clock = function () use (&$now): float {
            return $now;
        };

        $stub = new StubServer(fn () => self::quotaLimited());
        $analytics = $this->analytics($stub, $clock);

        $analytics->capture('user-123', 'order_completed');
        $analytics->flush();

        $now += 901.0;
        $analytics->capture('user-123', 'order_completed');
        $analytics->flush(); // the probe — refused again
        $this->assertCount(2, $this->ingestRequests($stub));
        $this->assertSame(0, $analytics->queueLength());

        // The cooldown restarted from the probe, not from the original suspension.
        $now += 899.0;
        $analytics->capture('user-123', 'order_completed');
        $this->assertSame(0, $analytics->queueLength());

        $now += 2.0;
        $analytics->capture('user-123', 'order_completed');
        $this->assertSame(1, $analytics->queueLength());

        // Still exactly one warning: a refused probe is not news.
        $this->assertSame(1, substr_count($this->warnings(), 'event ingest suspended'));

        $stub->close();
    }
}
