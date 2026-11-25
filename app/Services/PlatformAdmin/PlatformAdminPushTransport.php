<?php

namespace App\Services\PlatformAdmin;

/**
 * What actually speaks to the push service, behind an interface so that nothing of this API depends on which service that is (L-16).
 *
 * The owner has not said whether push is wanted at all, nor which project to send through (contract s.10 item 9), so what ships is
 * {@see PlatformAdminPushIsNotConfigured}: every call succeeds and sends nothing. A device can therefore be registered, its topics named and
 * its row removed when the session ends -- all of which is testable -- without this platform sending a single notification to anybody before
 * that decision is taken.
 */
interface PlatformAdminPushTransport
{
    /**
     * Subscribes a device's push token to the topics the server named for it.
     *
     * @param list<string> $topics
     */
    public function subscribe(string $pushToken, array $topics): void;

    /**
     * Unsubscribes a device, and invalidates the token as far as this platform is concerned: nothing is sent to it again until it registers
     * afresh.
     *
     * @param list<string> $topics
     */
    public function unsubscribe(string $pushToken, array $topics): void;

    /**
     * Sends one alert to a topic. The payload is ids and counts only (PlatformAlertPush builds it), and `dedupKey` is what lets the service,
     * and the phone, drop the same alert twice.
     *
     * @param array<string, mixed> $payload
     */
    public function send(string $topic, array $payload, string $dedupKey): void;
}
