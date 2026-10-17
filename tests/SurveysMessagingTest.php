<?php

declare(strict_types=1);

namespace BillDogEng\Tests;

use BillDogEng\BillDogEng;
use PHPUnit\Framework\TestCase;

/**
 * Spec test #5: survey data round-trip + messaging dispatch payload shape,
 * plus LLM captureTrace shape and network-fetched flag definitions.
 *
 * Every endpoint (bdsurvey-*, messaging-dispatch, llm, feature-flag-definitions)
 * answers the `{ success, data, meta }` envelope; the stub mirrors it.
 */
final class SurveysMessagingTest extends TestCase
{
    private const SURVEY_ID = '11111111-1111-1111-1111-111111111111';
    private const QUESTION_ID = '22222222-2222-2222-2222-222222222222';
    private const CHOICE_ID = '33333333-3333-3333-3333-333333333333';
    private const RESPONDENT_ID = '44444444-4444-4444-4444-444444444444';
    private const PROJECT_ID = '55555555-5555-5555-5555-555555555555';

    private function client(StubServer $stub, array $opts = []): BillDogEng
    {
        $opts['host'] = $stub->url();
        $opts['flushAt'] = $opts['flushAt'] ?? 100;
        return new BillDogEng('bd_test_x', $opts, new LoopbackHttpHandler($stub));
    }

    public function testSurveyRoundTrip(): void
    {
        $stub = new StubServer(function (CapturedRequest $req): array {
            if ($req->path === '/bdsurvey-list') {
                return StubServer::envelope(['surveys' => [
                    ['id' => self::SURVEY_ID, 'name' => 'NPS', 'type' => 'nps', 'status' => 'active'],
                ]]);
            }
            if ($req->path === '/bdsurvey-fetch') {
                return StubServer::envelope([
                    'id' => self::SURVEY_ID,
                    'name' => 'NPS',
                    'should_show' => true,
                    'pages' => [['id' => 'p1', 'questions' => [['id' => self::QUESTION_ID, 'type' => 'nps']]]],
                ]);
            }
            if ($req->path === '/bdsurvey-submit') {
                $action = $req->body['action'] ?? 'submit';
                if ($action === 'start') {
                    return StubServer::envelope(['respondent_id' => self::RESPONDENT_ID, 'status' => 'in_progress']);
                }
                if ($action === 'partial') {
                    return StubServer::envelope(['respondent_id' => self::RESPONDENT_ID, 'status' => 'in_progress', 'answers_count' => 1]);
                }
                if ($action === 'abandon') {
                    return StubServer::envelope(['respondent_id' => self::RESPONDENT_ID, 'status' => 'abandoned']);
                }
                return StubServer::envelope(['respondent_id' => self::RESPONDENT_ID, 'status' => 'completed', 'answers_count' => 1]);
            }
            return StubServer::raw([], 404);
        });

        $bd = $this->client($stub);

        $list = $bd->surveys->list('user-123');
        $this->assertCount(1, $list);
        $this->assertSame(self::SURVEY_ID, $list[0]['id']);

        $config = $bd->surveys->fetch(self::SURVEY_ID, 'user-123');
        $this->assertTrue($config['should_show']);
        $this->assertSame(self::SURVEY_ID, $config['id']);

        $started = $bd->surveys->start(self::SURVEY_ID, ['customerId' => 'user-123', 'idempotencyKey' => 'idem-1']);
        $this->assertSame(self::RESPONDENT_ID, $started['respondent_id']);

        $answers = [['question_id' => self::QUESTION_ID, 'choice_id' => self::CHOICE_ID, 'answer_number' => 9]];
        $bd->surveys->recordPartial(self::SURVEY_ID, $started['respondent_id'], $answers, ['customerId' => 'user-123']);

        $submitRes = $bd->surveys->submit(self::SURVEY_ID, $answers, [
            'respondentId' => $started['respondent_id'],
            'customerId' => 'user-123',
            'idempotencyKey' => 'idem-1',
        ]);
        $this->assertSame('completed', $submitRes['status']);

        $bd->surveys->abandon(self::SURVEY_ID, $started['respondent_id']);
        $bd->shutdown();

        $submits = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/bdsurvey-submit'));
        $this->assertSame(
            ['start', 'partial', 'submit', 'abandon'],
            array_map(fn (CapturedRequest $r) => $r->body['action'], $submits)
        );

        $startBody = $submits[0]->body;
        $this->assertSame('start', $startBody['action']);
        $this->assertSame(self::SURVEY_ID, $startBody['survey_id']);
        $this->assertSame('user-123', $startBody['customer_id']);
        $this->assertSame('idem-1', $startBody['idempotency_key']);

        $submitBody = $submits[2]->body;
        $this->assertSame(self::RESPONDENT_ID, $submitBody['respondent_id']);
        $this->assertSame(
            ['question_id' => self::QUESTION_ID, 'choice_id' => self::CHOICE_ID, 'answer_number' => 9],
            $submitBody['answers'][0]
        );

        $this->assertSame('bd_test_x', $submits[0]->headers['x-billdog-api-key']);
        $stub->close();
    }

    public function testMessagingDispatchPayloadShape(): void
    {
        $stub = new StubServer(function (CapturedRequest $req): array {
            if ($req->path === '/messaging-dispatch') {
                return StubServer::envelope(['sent' => 42, 'failed' => 0]);
            }
            return StubServer::raw([], 404);
        });

        $bd = $this->client($stub);

        $result = $bd->messaging->dispatch([
            'projectId' => self::PROJECT_ID,
            'channel' => 'push',
            'content' => ['title' => 'Hi', 'body' => 'There'],
            'targeting' => ['type' => 'all'],
            'scheduling' => ['deliveryType' => 'immediate'],
            'accessToken' => 'jwt-token-abc',
        ]);
        $this->assertSame(['sent' => 42, 'failed' => 0], $result);

        $bd->shutdown();

        $dispatch = null;
        foreach ($stub->requests as $r) {
            if ($r->path === '/messaging-dispatch') {
                $dispatch = $r;
            }
        }
        $this->assertNotNull($dispatch);
        $this->assertSame([
            'action' => 'send',
            'project_id' => self::PROJECT_ID,
            'channel' => 'push',
            'content' => ['title' => 'Hi', 'body' => 'There'],
            'targeting' => ['type' => 'all'],
            'scheduling' => ['deliveryType' => 'immediate'],
        ], $dispatch->body);

        // Messaging authenticates with Bearer JWT (not the X-BillDog-API-Key project key).
        $this->assertSame('Bearer jwt-token-abc', $dispatch->headers['authorization']);
        $stub->close();
    }

    public function testLlmCaptureTracePayloadShape(): void
    {
        $stub = new StubServer(function (CapturedRequest $req): array {
            if ($req->path === '/llm/trace') {
                return StubServer::envelope(['success' => true]);
            }
            return StubServer::raw([], 404);
        });

        $bd = $this->client($stub);

        $bd->llm->captureTrace([
            'traceId' => 't-1',
            'spanId' => 's-1',
            'model' => 'claude-opus-4-8',
            'inputText' => 'hello',
            'outputText' => 'hi',
            'promptTokens' => 10,
            'completionTokens' => 5,
            'durationMs' => 123,
            'costUsd' => 0.002,
        ]);

        $bd->shutdown();

        $trace = null;
        foreach ($stub->requests as $r) {
            if ($r->path === '/llm/trace') {
                $trace = $r;
            }
        }
        $this->assertNotNull($trace);
        $body = $trace->body;
        $this->assertSame('t-1', $body['traceId']);
        $this->assertSame('s-1', $body['spanId']);
        $this->assertSame('', $body['parentSpanId']);
        $this->assertSame('claude-opus-4-8', $body['model']);
        $this->assertSame('hello', $body['inputText']);
        $this->assertSame('hi', $body['outputText']);
        $this->assertSame(10, $body['promptTokens']);
        $this->assertSame(5, $body['completionTokens']);
        $this->assertSame(123, $body['durationMs']);
        $this->assertSame(0.002, $body['costUsd']);
        $this->assertIsString($body['timestamp']);
        $this->assertSame('bd_test_x', $trace->headers['x-billdog-api-key']);
        $stub->close();
    }

    public function testLocalFlagDefinitionsFetchedOverNetwork(): void
    {
        $stub = new StubServer(function (CapturedRequest $req): array {
            if ($req->path === '/feature-flag-definitions') {
                return StubServer::envelope([
                    'flags' => [['key' => 'dark_mode', 'active' => true, 'rollout_percentage' => 83]],
                ]);
            }
            return StubServer::raw([], 404);
        });

        // This stub 404s on /ingest-events, and shutdown() flushes; disable exposure events so this
        // definitions-caching test does not exercise the exposure flush path.
        $bd = $this->client($stub, ['localEvaluation' => true, 'sendFeatureFlagEvents' => false]);
        // CANONICAL: murmur("flag:dark_mode:alice") % 100 = 82 < 83 → ON
        $this->assertTrue($bd->getFeatureFlag('dark_mode', 'alice'));
        // Second call uses cache (still one definitions fetch).
        $bd->getFeatureFlag('dark_mode', 'bob');

        $defReqs = array_values(array_filter($stub->requests, fn (CapturedRequest $r) => $r->path === '/feature-flag-definitions'));
        $this->assertCount(1, $defReqs);

        $bd->shutdown();
        $stub->close();
    }
}
