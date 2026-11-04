<?php

namespace App\Services\Theme;

use App\Traits\PushNotificationTrait;

/**
 * A publish, announced — so an open app updates in seconds instead of on its next resume.
 *
 * This is deliberately NOT the theme: the payload is a data-only ping carrying the new revision
 * and nothing else. A client that receives it runs the exact same sync it runs on resume — ask
 * /theme/version, download only if newer — so the beacon can be lost, duplicated or delayed
 * without any client ever holding a wrong page. Push is an accelerator here, never the mechanism
 * (spec §17: the system must not depend on it).
 *
 * Data-only also means silent: no notification block, and the APNs headers mark it a background
 * push. A merchant reordering their home page must never make every customer's phone buzz.
 */
class ThemeSyncBeacon
{
    use PushNotificationTrait;

    /**
     * The topic every customer app subscribes to at startup — alongside the maintenance topic,
     * before login, so guests hear the beacon too.
     */
    public const TOPIC = 'theme_updates_user_app';

    public const TYPE = 'theme_version_changed';

    /** Announce that a new revision is live. Must never be able to fail a publish. */
    public function announce(int $revision): void
    {
        try {
            $this->sendNotificationToHttp($this->payload($revision));
        } catch (\Throwable) {
            // No FCM credentials, no network, a malformed key — all mean "clients find out on
            // their next resume", which is the working state this feature started from.
        }
    }

    /**
     * The FCM v1 message, exposed for tests: the contract is "data-only and silent", and that is
     * a property of this array, not of the HTTP call.
     *
     * @return array<string, mixed>
     */
    public function payload(int $revision): array
    {
        return [
            'message' => [
                'topic' => self::TOPIC,
                'data' => [
                    'type' => self::TYPE,
                    'revision' => (string) $revision,
                ],
                // Background delivery on iOS: content-available with no alert, priority 5 as
                // Apple requires for background pushes. No `notification` block anywhere.
                'apns' => [
                    'headers' => [
                        'apns-priority' => '5',
                        'apns-push-type' => 'background',
                    ],
                    'payload' => [
                        'aps' => ['content-available' => 1],
                    ],
                ],
            ],
        ];
    }
}
