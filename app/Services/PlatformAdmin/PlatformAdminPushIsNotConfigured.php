<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Support\Facades\Log;

/**
 * The transport this platform ships with: it sends nothing (L-16).
 *
 * Whether the owner wants push at all, for which severities, and through which project are open decisions (GCC-20 contract s.10 item 9). Until
 * they are taken, a device registers, the server names its topics, and the row goes when the session ends -- and no notification leaves this
 * platform. Each call is written to the log at debug level so that a staging server can show that the right thing WOULD have been sent.
 *
 * Nothing about this is a failure, so nothing throws: a push that cannot be sent must never be able to fail a request that was answered.
 */
final class PlatformAdminPushIsNotConfigured implements PlatformAdminPushTransport
{
    /** @param list<string> $topics */
    public function subscribe(string $pushToken, array $topics): void
    {
        $this->note(action: 'subscribe', topics: $topics);
    }

    /** @param list<string> $topics */
    public function unsubscribe(string $pushToken, array $topics): void
    {
        $this->note(action: 'unsubscribe', topics: $topics);
    }

    /** @param array<string, mixed> $payload */
    public function send(string $topic, array $payload, string $dedupKey): void
    {
        $this->note(action: 'send', topics: [$topic], extra: ['dedup' => $dedupKey]);
    }

    /**
     * The push token is NEVER written to the log: it is a credential for a particular phone, and a log line is the easiest place in this
     * platform to read one out of.
     *
     * @param list<string> $topics
     * @param array<string, mixed> $extra
     */
    private function note(string $action, array $topics, array $extra = []): void
    {
        Log::debug('platform admin push is not configured', ['would' => $action, 'topics' => $topics] + $extra);
    }
}
