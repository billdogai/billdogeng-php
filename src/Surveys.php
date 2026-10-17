<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Surveys DATA API (no UI rendering). Wraps the `bdsurvey-*` edge functions.
 *
 * Lifecycle: `list` → `fetch` → `start` → `recordPartial`* → `submit`
 * (or `abandon`). The same `idempotencyKey` SHOULD be carried across `start`
 * and `submit` so retries are safe.
 *
 * Answers mirror the bdsurvey-submit answer shape
 * `{ question_id, choice_id?, answer_text?, answer_number?, answer_json? }`.
 * New question types (emoji/thumbs/link) are transparent — they're just data.
 */
final class Surveys
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $apiKey
    ) {
    }

    /** @return array<string,string> */
    private function authHeader(): array
    {
        return ['X-BillDog-API-Key' => $this->apiKey];
    }

    /**
     * List active surveys eligible for the (optionally identified) user.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(?string $distinctId = null, ?string $anonymousId = null): array
    {
        $body = ['api_key' => $this->apiKey];
        if ($distinctId !== null) {
            $body['customer_id'] = $distinctId;
        }
        if ($anonymousId !== null) {
            $body['anonymous_id'] = $anonymousId;
        }
        $data = $this->transport->request('/bdsurvey-list', $body, $this->authHeader(), gzip: false);
        return (is_array($data) ? ($data['surveys'] ?? []) : []) ?: [];
    }

    /**
     * Fetch the full configuration for a single survey.
     *
     * @return array<string,mixed>
     */
    public function fetch(string $surveyId, ?string $distinctId = null, ?string $anonymousId = null): array
    {
        $body = ['api_key' => $this->apiKey, 'survey_id' => $surveyId];
        if ($distinctId !== null) {
            $body['customer_id'] = $distinctId;
        }
        if ($anonymousId !== null) {
            $body['anonymous_id'] = $anonymousId;
        }
        $data = $this->transport->request('/bdsurvey-fetch', $body, $this->authHeader(), gzip: false);
        return is_array($data) ? $data : [];
    }

    /**
     * Begin a survey response. Returns the new `respondent_id`.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function start(string $surveyId, array $context = []): array
    {
        $data = $this->transport->request(
            '/bdsurvey-submit',
            $this->buildBody('start', $surveyId, null, $context),
            $this->authHeader(),
            gzip: false
        );
        return is_array($data) ? $data : [];
    }

    /**
     * Progressively persist a partial set of answers under an in-progress respondent.
     *
     * @param array<int,array<string,mixed>> $answers
     * @param array<string,mixed> $context
     */
    public function recordPartial(string $surveyId, string $respondentId, array $answers, array $context = []): mixed
    {
        $context['respondentId'] = $respondentId;
        return $this->transport->request(
            '/bdsurvey-submit',
            $this->buildBody('partial', $surveyId, $answers, $context),
            $this->authHeader(),
            gzip: false
        );
    }

    /**
     * Submit the final answers, completing the response.
     *
     * @param array<int,array<string,mixed>> $answers
     * @param array<string,mixed> $context
     */
    public function submit(string $surveyId, array $answers, array $context = []): mixed
    {
        return $this->transport->request(
            '/bdsurvey-submit',
            $this->buildBody('submit', $surveyId, $answers, $context),
            $this->authHeader(),
            gzip: false
        );
    }

    /** Mark an in-progress response as abandoned. */
    public function abandon(string $surveyId, string $respondentId): mixed
    {
        return $this->transport->request(
            '/bdsurvey-submit',
            $this->buildBody('abandon', $surveyId, null, ['respondentId' => $respondentId]),
            $this->authHeader(),
            gzip: false
        );
    }

    /**
     * @param array<int,array<string,mixed>>|null $answers
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildBody(string $action, string $surveyId, ?array $answers, array $context): array
    {
        $body = [
            'action' => $action,
            'api_key' => $this->apiKey,
            'survey_id' => $surveyId,
        ];
        if ($answers !== null) {
            $body['answers'] = $answers;
        }
        $map = [
            'respondentId' => 'respondent_id',
            'customerId' => 'customer_id',
            'anonymousId' => 'anonymous_id',
            'sessionId' => 'session_id',
            'platform' => 'platform',
            'deviceInfo' => 'device_info',
            'durationMs' => 'duration_ms',
            'context' => 'context',
            'collectorId' => 'collector_id',
            'collectorType' => 'collector_type',
            'idempotencyKey' => 'idempotency_key',
        ];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $context) && $context[$camel] !== null) {
                $body[$snake] = $context[$camel];
            }
        }
        return $body;
    }
}
