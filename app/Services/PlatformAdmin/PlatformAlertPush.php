<?php

namespace App\Services\PlatformAdmin;

/**
 * Turning one alert into one push, for the devices of the admins who may see that market (L-16).
 *
 * **What a push carries, and nothing else.** The market, the alert's code, and a count. No message, no deep link to an entity, no name, no
 * amount. A notification arrives on a lock screen, is read by whoever is holding the phone, and is kept by the push service on the way; the
 * sentence an alert carries is in the API's answer, which the app fetches when the notification is OPENED and which re-checks the grant and the
 * market on the server as every request of this API does. So a phone that is handed to somebody, and a token that is stolen, show a code and a
 * number, and the notification is useless without a session that may read the rest.
 *
 * **Deduplicated.** The key is the alert's own id -- itself the alert's code and market -- with the day, so the same alert is one notification
 * however many times a minute the figures are recomputed, and a new day's alert is a new notification.
 *
 * **Sent only to a topic the market belongs to.** A restricted viewer's device is subscribed to their markets alone
 * (PlatformAdminTopics::forPrincipal), so a market they may not see cannot reach their phone even by a channel that arrives without a request.
 */
final class PlatformAlertPush
{
    /** The alert fields that may be sent. Anything else of an alert stays on the server. */
    public const SENT = ['market', 'code', 'count'];

    public function __construct(private readonly PlatformAdminTopics $topics, private readonly PlatformAdminPushTransport $transport)
    {
    }

    /**
     * What a push of this alert carries.
     *
     * @param array<string, mixed> $alert one row of PlatformAlertService::forMarkets()
     *
     * @return array{market: string, code: string, count: int}
     */
    public function payloadFor(array $alert, int $count = 1): array
    {
        return [
            'market' => strtoupper(trim((string) ($alert['market'] ?? ''))),
            'code' => (string) ($alert['code'] ?? ''),
            'count' => $count,
        ];
    }

    /** The key the same alert is dropped a second time by: the alert's own id, and the day. */
    public function dedupKeyFor(array $alert): string
    {
        return substr(sha1(((string) ($alert['id'] ?? '')) . '|' . now()->toDateString()), 0, 24);
    }

    /**
     * Sends one alert to the topic of its market. Nothing is sent for an alert of no market -- an alert nobody attributed belongs to no topic --
     * and nothing is sent anywhere while the transport is the one that sends nothing, which is what ships.
     *
     * @param array<string, mixed> $alert
     */
    public function notify(array $alert, int $count = 1): ?string
    {
        $payload = $this->payloadFor(alert: $alert, count: $count);

        if ($payload['market'] === '' || $payload['code'] === '') {
            return null;
        }

        $topic = PlatformAdminTopics::PREFIX . strtolower($payload['market']);

        if (!$this->topics->isOurs(topic: $topic)) {
            return null;
        }

        $this->transport->send($topic, $payload, $this->dedupKeyFor(alert: $alert));

        return $topic;
    }
}
