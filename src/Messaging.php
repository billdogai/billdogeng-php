<?php

declare(strict_types=1);

namespace BillDogEng;

/**
 * Messaging dispatch (BillDog advantage over PostHog) — server-side delivery
 * across push / email / sms / in-app / live-activity.
 *
 * Note: `messaging-dispatch` authenticates with a Supabase session **Bearer
 * JWT** + project membership, not the `X-BillDog-API-Key` key used by the other
 * modules. Pass the JWT as `accessToken` per call.
 */
final class Messaging
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Dispatch a message. Returns `{ sent, failed }` (or `{ success:false, ... }`
     * when no recipients match).
     *
     * @param array<string,mixed> $params {
     *   projectId: string, channel: string, content: array, accessToken: string,
     *   targeting?: array, scheduling?: array, templateId?: string
     * }
     */
    public function dispatch(array $params): mixed
    {
        $body = [
            'action' => 'send',
            'project_id' => $params['projectId'],
            'channel' => $params['channel'],
            'content' => (object) ($params['content'] ?? []),
        ];
        if (isset($params['targeting'])) {
            $body['targeting'] = (object) $params['targeting'];
        }
        if (isset($params['scheduling'])) {
            $body['scheduling'] = (object) $params['scheduling'];
        }
        if (isset($params['templateId'])) {
            $body['template_id'] = $params['templateId'];
        }

        return $this->transport->request(
            '/messaging-dispatch',
            $body,
            ['Authorization' => 'Bearer ' . $params['accessToken']],
            gzip: false
        );
    }
}
