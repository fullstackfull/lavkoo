<?php

namespace App\Services\PlatformAdmin;

use App\Models\PlatformAdminDevice;
use Illuminate\Support\Facades\Schema;

/**
 * The devices of the platform admins: registering one, taking one away, and taking away every device of a session that has ended (L-16).
 *
 * The topics are the SERVER's: a device sends its push token and says which platform it is, and is told which topics it was subscribed to. It
 * cannot ask for one, so it cannot ask for a market its grant does not allow, nor for a topic belonging to the seller app.
 *
 * A device belongs to the session that registered it. When that session ends -- signed out, revoked by another device, evicted to make room --
 * the row is deleted and the push token unsubscribed, in that order, so a device that cannot be unsubscribed is still gone from the table. A
 * session that merely went idle has no moment to hook, so the sending path asks for devices of OPEN sessions (see the model's scope) rather
 * than trusting the table alone.
 *
 * The table may not exist yet: the whole of this API answers 503 until its own tables are there (PlatformAdminGate), but the session-ending paths
 * run from console commands too, so the schema is asked before a device is looked for.
 */
final class PlatformAdminDevices
{
    public function __construct(
        private readonly PlatformAdminTopics $topics,
        private readonly PlatformAdminPushTransport $transport,
        private readonly PlatformAdminSettings $settings,
    ) {
    }

    public function tableReady(): bool
    {
        return Schema::hasTable('platform_admin_devices');
    }

    /** The platform a device says it is, or null when it is not one this platform sends to. */
    public function platformOf(mixed $given): ?string
    {
        $platform = is_string($given) ? strtolower(trim($given)) : '';

        return in_array($platform, PlatformAdminDevice::PLATFORMS, true) ? $platform : null;
    }

    /**
     * Registers a device of this session, or updates the one that is already registered for it, and subscribes it to the topics the grant
     * allows. Registering the same phone again in the same session replaces its topics, which is what a grant that has changed since needs.
     */
    public function register(PlatformAdminPrincipal $principal, string $platform, string $pushToken): PlatformAdminDevice
    {
        $topics = $this->topics->forPrincipal(principal: $principal);

        $device = PlatformAdminDevice::query()->updateOrCreate(
            ['token_id' => (int) $principal->token->getKey(), 'push_token_hash' => PlatformAdminDevice::hashOf(pushToken: $pushToken)],
            ['admin_id' => $principal->adminId(), 'platform' => $platform, 'push_token' => $pushToken, 'topics' => $topics],
        );

        $this->transport->subscribe($pushToken, $topics);

        return $device;
    }

    /** The devices of this session, newest first. The push token is never in what this returns. */
    public function ofSession(PlatformAdminPrincipal $principal): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        return PlatformAdminDevice::query()
            ->where('token_id', (int) $principal->token->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(static fn (PlatformAdminDevice $device): array => [
                'id' => (int) $device->getKey(),
                'platform' => (string) $device->platform,
                'topics' => $device->topicList(),
                'registered_at' => PlatformAdminPresenter::timestamp(moment: $device->created_at),
            ])
            ->all();
    }

    /** Takes one device of this session away. Returns false when this session has no device with that id: another session's device is not found either. */
    public function forget(PlatformAdminPrincipal $principal, int $deviceId): bool
    {
        if (!$this->tableReady()) {
            return false;
        }

        $device = PlatformAdminDevice::query()->where('token_id', (int) $principal->token->getKey())->whereKey($deviceId)->first();

        return $device !== null && $this->remove(device: $device);
    }

    /**
     * Every device of these sessions, gone: the rows first, then the unsubscribe. Called wherever a session ends.
     *
     * @param list<int> $tokenIds
     *
     * @return int how many devices were removed
     */
    public function forgetSessions(array $tokenIds): int
    {
        if ($tokenIds === [] || !$this->tableReady()) {
            return 0;
        }

        $removed = 0;

        foreach (PlatformAdminDevice::query()->whereIn('token_id', $tokenIds)->get() as $device) {
            $removed += $this->remove(device: $device) ? 1 : 0;
        }

        return $removed;
    }

    /** The devices that may be sent to: those of sessions that are still open, which leaves out a session that went idle. */
    public function sendable(): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        return PlatformAdminDevice::query()->ofOpenSessions($this->settings->sessionIdleMinutes())->get()->all();
    }

    /**
     * The row goes before the unsubscribe, deliberately: a push service that cannot be reached must not leave a device registered here, because
     * the table is what the sending path reads. The unsubscribe is a courtesy to the service and never part of ending a session.
     */
    private function remove(PlatformAdminDevice $device): bool
    {
        $pushToken = $device->pushToken();
        $topics = $device->topicList();
        $device->delete();

        if ($pushToken !== null) {
            $this->transport->unsubscribe($pushToken, $topics);
        }

        return true;
    }
}
