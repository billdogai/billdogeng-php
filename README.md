# billdogeng-php

Official **BilldogEng** server SDK for PHP — the engagement suite for server-side use:
**Analytics**, **Feature Flags** (remote + local evaluation), **Surveys** (data API),
**Messaging** dispatch, and **LLM** observability.

This is the PHP port of the canonical [`billdogeng-node`](../node) reference SDK; the
wire contract and method surface are identical so the cross-language parity corpus passes.

## Requirements

- PHP >= 8.1
- ext-curl, ext-zlib

## Install

```bash
composer require billdog/billdogeng:1.1.1-beta.4
```

## Quickstart

```php
use BillDogEng\BillDogEng;

$bd = new BillDogEng('bd_test_xxx', ['localEvaluation' => true]);

// Analytics (batched + flushed automatically; flush()/shutdown() to drain)
$bd->capture('user-123', 'order_completed', ['revenue' => 49.99], ['company' => 'acme']);
$bd->identify('user-123', ['email' => 'a@b.com', 'plan' => 'pro']);
$bd->groupIdentify('company', 'acme', ['seats' => 50]);
$bd->alias('user-123', 'anon-abc');

// Feature flags (local, deterministic murmurhash3 bucketing)
$on      = $bd->isFeatureEnabled('new_checkout', 'user-123');
$variant = $bd->getFeatureFlag('new_checkout', 'user-123', ['personProperties' => ['plan' => 'pro']]);
$payload = $bd->getFeatureFlagPayload('new_checkout', 'user-123');
$all     = $bd->getAllFlags('user-123');

// Surveys (data API — no UI rendering)
$surveys = $bd->surveys->list('user-123');
$config  = $bd->surveys->fetch($surveyId, 'user-123');
$start   = $bd->surveys->start($surveyId, ['customerId' => 'user-123', 'idempotencyKey' => 'idem-1']);
$bd->surveys->recordPartial($surveyId, $start['respondent_id'], $answers);
$bd->surveys->submit($surveyId, $answers, ['respondentId' => $start['respondent_id']]);
$bd->surveys->abandon($surveyId, $start['respondent_id']);

// Messaging dispatch (Bearer JWT auth, not X-BillDog-API-Key)
$bd->messaging->dispatch([
    'projectId'   => $projectId,
    'channel'     => 'push',          // push | email | sms | in-app | live-activity
    'content'     => ['title' => 'Hi', 'body' => 'There'],
    'targeting'   => ['type' => 'all'],
    'scheduling'  => ['deliveryType' => 'immediate'],
    'accessToken' => $supabaseJwt,
]);

// LLM observability
$bd->llm->captureTrace([
    'traceId' => 't-1', 'spanId' => 's-1', 'model' => 'claude-opus-4-8',
    'inputText' => '…', 'outputText' => '…',
    'promptTokens' => 10, 'completionTokens' => 5, 'durationMs' => 123, 'costUsd' => 0.002,
]);

// Always flush before the process exits.
$bd->shutdown();
```

## Configuration

```php
new BillDogEng('bd_test_xxx', [
    'host'            => 'https://api.billdog.io/v1', // base URL
    'flushAt'         => 20,      // batch size that triggers a flush
    'flushInterval'   => 10000,   // ms; opportunistic time-based flush on enqueue
    'maxQueueSize'    => 1000,
    'gzip'            => true,     // gzip large request bodies
    'localEvaluation' => false,    // enable local (server-side) flag evaluation
    'requestTimeout'  => 10000,    // ms
    'maxRetries'      => 3,        // 5xx / network retries with exponential backoff
    'enableLogging'   => false,
]);
```

Auth: every request sends `X-BillDog-API-Key: <apiKey>` (`bd_test_*` sandbox / `bd_live_*` live),
except **messaging dispatch** (Bearer JWT) and **LLM trace** (`X-BillDog-API-Key`).

> **Background flushing:** PHP has no background thread, so unlike the Node SDK the
> flush timer is approximated — a time-based flush is evaluated opportunistically on
> each `capture`/`identify`. For long-running workers, call `flush()` periodically and
> always `shutdown()` before exit.

> **Exhausted allowance (`quota_limited`):** ingest answers a drained meter with HTTP
> **200** and a top-level `quota_limited` array — never an error status — so your app is
> never broken by your own BillDog meter filling up. The batch is *not* stored, so the
> SDK suspends the flush loop instead of reporting success: `capture()` and friends
> become no-ops (queueing data that cannot be stored would leak memory in a long-lived
> process), and the reason — the server's own message — is written to your error log
> **once**, at warn level. After 15 minutes one probe batch is sent; if it is stored the
> SDK resumes and logs recovery, otherwise the cooldown restarts silently. `flush()` and
> `shutdown()` never throw or block because of this.

## Local feature-flag evaluation

In `localEvaluation` mode the SDK fetches flag **definitions** from
`POST /feature-flag-definitions` (cached ~5 min), then evaluates each flag in-process
using the cross-platform `murmurhash3` algorithm shared with the web / iOS / Android
SDKs, so `murmurhash3("flag:{key}:{distinctId}") % 100` buckets a user identically everywhere.

A flag's **release conditions** (`targeting_rule`) are resolved by `BillDogEng\Targeting`, a
byte-for-byte port of the backend audience engine: AND/OR, date windows, rule status, the full
operator set, and **group membership** all resolve locally, with no server round-trip.

```php
$bd->getFeatureFlag('new_checkout', 'user-123', [
    'groups'             => ['organisation' => 'acme'],  // `group` conditions — evaluated locally
    'personProperties'   => ['plan' => 'pro'],           // `attribute` / `entitlement` / `subscription_status`
    'country'            => 'US',
    'platform'           => 'ios',
    'appVersion'         => '2.5',
    'sdkVersion'         => '1.4',
    'experimentVariants' => ['exp-1' => 'treatment'],    // `experiment_variant` conditions
]);
```

Conditions that can only be answered by the database (`segment`, `cohort`, `survey_answer`,
`event_fired_in_window`, `group_property`) are never guessed at: local evaluation returns
`FlagVerdict::Cold` and the SDK falls back to the server for that flag.

## Tests

```bash
composer install && ./vendor/bin/phpunit
```

Covers: (1) 10 captures → one batched POST of 10 events, (2) identify/group/alias shapes,
(3) the 12 canonical murmurhash3 vectors + rollout/targeting/multivariate evaluation,
(4) 503-then-200 retry with backoff against a local stub, (5) survey round-trip +
messaging dispatch + LLM trace payload shapes, (6) **conformance**: bucketing and targeting
asserted against `tests/fixtures/flag-*.json` — vectors generated FROM the backend evaluator,
never from a sibling SDK.

## BillDog Auth

Session-token verification is **not available in this SDK yet**.
[`billdogeng-node`](https://www.npmjs.com/package/billdogeng-node) is the only
server SDK that can verify a BillDog Auth token offline today.

Until it lands here, either verify in a Node service, or call the
`auth-introspect` endpoint over HTTP — that costs a network round-trip per
check, and in exchange tells you whether the session is live *right now* rather
than only that the token was valid when it was issued.

## License

MIT
