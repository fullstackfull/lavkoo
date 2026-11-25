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
 *
 * ## The topic is GLOBAL, and that is a decision rather than an oversight (I1-05)
 *
 * One publish wakes every market's apps, because every customer app subscribes to this one name at
 * startup. Scoping the name per market would silence a market until its own app build subscribed to
 * its own topic, which is an app release away (A1-17, Q-18, Q-32) — so a suffix here would turn a
 * wasted wake into a market whose phones never hear a publish at all. The wasted wake is the whole
 * cost, and two things keep it to that:
 *
 *   - **The payload carries no theme content.** A woken client asks `/theme/version` for ITS OWN
 *     storefront and is answered that storefront's revision
 *     (`StorefrontThemeServingTest::test_the_app_is_told_the_revision_of_the_theme_of_the_storefront_it_asks_for`),
 *     so no market can be handed another market's page by this beacon, whatever it was woken by.
 *   - **The payload names the market the publish was for**, while storefronts are on, so a client
 *     can ignore a beacon that is not its own instead of comparing a revision that belongs to
 *     another market against its own. Additive and absent while storefronts are off: an app that
 *     has never heard of the key is unaffected either way, which is the same shape as the market a
 *     push about an order carries ({@see \App\Services\Storefront\OrderNotificationContext::marketOf}).
 *
 * The maintenance beacons are global for a stronger reason, recorded here beside this one because
 * the two are asked about together: the maintenance they announce IS platform-wide. One
 * `system_maintenance_mode` entry stops the whole platform and the switch chooses which APP is
 * told, never which market, so a per-market maintenance topic would announce a per-market state
 * that does not exist.
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

    /**
     * Announce that a new revision is live. Must never be able to fail a publish.
     *
     * @param list<string>|null $storefronts the markets this publish is for, or null when it is for every one of them
     */
    public function announce(int $revision, ?array $storefronts = null): void
    {
        try {
            $this->sendNotificationToHttp($this->payload(revision: $revision, storefronts: $storefronts));
        } catch (\Throwable) {
            // No FCM credentials, no network, a malformed key — all mean "clients find out on
            // their next resume", which is the working state this feature started from.
        }
    }

    /**
     * The FCM v1 message, exposed for tests: the contract is "data-only and silent", and that is
     * a property of this array, not of the HTTP call.
     *
     * @param list<string>|null $storefronts the markets this publish is for, or null for every one of them
     * @return array<string, mixed>
     */
    public function payload(int $revision, ?array $storefronts = null): array
    {
        return [
            'message' => [
                'topic' => self::TOPIC,
                'data' => [
                    'type' => self::TYPE,
                    'revision' => (string) $revision,
                ] + $this->marketOfThePublish($storefronts),
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

    /**
     * The one market this publish is for, as a key to add to the payload, or nothing at all.
     *
     * Nothing while storefronts are off, and nothing for a publish that really is for every market
     * — the default storefront's theme, which every storefront without one of its own falls back to
     * — because a key naming one market there would tell the others to ignore a publish that
     * changes their page.
     *
     * @param list<string>|null $storefronts
     * @return array<string, string>
     */
    private function marketOfThePublish(?array $storefronts): array
    {
        if (!config('gcc.storefronts.enabled') || $storefronts === null || count($storefronts) !== 1) {
            return [];
        }

        $market = strtoupper(trim((string) $storefronts[0]));

        return preg_match('/^[A-Z]{2}$/', $market) === 1 ? ['storefront' => $market] : [];
    }
}
