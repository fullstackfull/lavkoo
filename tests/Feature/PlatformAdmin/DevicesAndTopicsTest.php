<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\PlatformAdminDevice;
use App\Services\PlatformAdmin\PlatformAdminPushTransport;
use App\Services\PlatformAdmin\PlatformAdminTopics;
use App\Services\PlatformAdmin\PlatformAlertPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The devices a platform admin is alerted on, and the topics they are subscribed to (L-16).
 *
 * The push itself sends nothing: whether the owner wants it at all is their open decision, so the transport that ships is the one that sends
 * nothing and a fake records what WOULD have been sent. What is proved here is the part that must be right before a single notification is sent:
 * the table is the admin's own and not the shop's, the topics are the SERVER's and are never the seller app's, a device is subscribed only to
 * markets its grant allows, a payload carries a code and a count and no sentence, and a session that ends takes its devices with it.
 */
class DevicesAndTopicsTest extends PlatformAdminTestCase
{
    private const DEVICES = self::PREFIX . '/devices';

    private const PUSH_TOKEN = 'fGq8-a-real-looking-fcm-registration-token-value-0123456789';

    private _RecordingPushTransport $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->push = new _RecordingPushTransport();
        $this->app->instance(PlatformAdminPushTransport::class, $this->push);
    }

    public function test_a_device_registers_on_its_own_table_and_never_on_the_shops(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $shopsBefore = DB::table('sellers')->orderBy('id')->get()->toJson();

        $response = $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => self::PUSH_TOKEN], $this->bearer($token));

        $response->assertOk()->assertJsonPath('data.platform', 'android');
        $this->assertSame(1, DB::table('platform_admin_devices')->count());
        $this->assertSame($shopsBefore, DB::table('sellers')->orderBy('id')->get()->toJson(), 'nothing of the shop is written by this API: a seller\'s own push token is a column of theirs and is not touched');
        $this->assertSame([self::PUSH_TOKEN], $this->push->subscribed, 'the device was subscribed once, with its own token');
    }

    public function test_the_push_token_is_kept_encrypted_and_is_never_answered_back(): void
    {
        $token = $this->mintToken($this->platformAdmin());

        $response = $this->postJson(self::DEVICES, ['platform' => 'ios', 'push_token' => self::PUSH_TOKEN], $this->bearer($token));

        $this->assertStringNotContainsString(self::PUSH_TOKEN, (string) $response->getContent(), 'a credential for a phone is not answered back');
        $stored = (string) DB::table('platform_admin_devices')->value('push_token');
        $this->assertNotSame(self::PUSH_TOKEN, $stored, 'a dump of this table is not a list of push tokens');
        $this->assertSame(self::PUSH_TOKEN, PlatformAdminDevice::query()->first()?->pushToken(), 'and it can still be read back to send with');
        $this->assertSame(hash('sha256', self::PUSH_TOKEN), (string) DB::table('platform_admin_devices')->value('push_token_hash'));
        $this->assertStringNotContainsString(self::PUSH_TOKEN, (string) json_encode($this->getJson(self::DEVICES, $this->bearer($token))->json()));
    }

    public function test_the_server_names_the_topics_and_a_device_cannot_ask_for_one(): void
    {
        $token = $this->mintToken($this->platformAdmin());

        $answered = $this->postJson(
            self::DEVICES,
            ['platform' => 'android', 'push_token' => self::PUSH_TOKEN, 'topics' => ['lavkoo_seller', 'lavkoo_pa_zz']],
            $this->bearer($token),
        )->assertOk()->json('data.topics');

        $this->assertSame(['lavkoo_pa_level_1', 'lavkoo_pa_sa', 'lavkoo_pa_ae', 'lavkoo_pa_kw', 'lavkoo_pa_qa', 'lavkoo_pa_bh', 'lavkoo_pa_om'], $answered);
        $this->assertNotContains('lavkoo_seller', $answered, 'what the device asked for is not what it was given');
        $this->assertSame($answered, $this->push->topics, 'and the service was asked for exactly those');
    }

    public function test_a_restricted_viewer_is_subscribed_to_their_own_markets_and_to_no_other(): void
    {
        $token = $this->mintToken($this->platformAdmin(countries: ['KW', 'AE']));

        $topics = $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => self::PUSH_TOKEN], $this->bearer($token))->assertOk()->json('data.topics');

        $this->assertSame(['lavkoo_pa_level_1', 'lavkoo_pa_ae', 'lavkoo_pa_kw'], $topics, 'a market they may not see cannot reach their phone even by push');
    }

    public function test_no_admin_topic_is_a_topic_of_the_seller_app_the_customers_or_marketing(): void
    {
        $ours = app(PlatformAdminTopics::class)->all();

        $this->assertNotEmpty($ours);
        $this->assertSame([], array_values(array_intersect($ours, PlatformAdminTopics::NOT_OURS)), 'the two sets are disjoint');
        foreach ($ours as $topic) {
            $this->assertStringStartsWith('lavkoo_pa_', $topic, $topic);
        }
        foreach (PlatformAdminTopics::NOT_OURS as $theirs) {
            $this->assertFalse(app(PlatformAdminTopics::class)->isOurs(topic: $theirs), $theirs);
        }
    }

    public function test_a_push_carries_the_market_the_code_and_a_count_and_no_sentence(): void
    {
        $alert = ['id' => 'abc123', 'severity' => 'critical', 'market' => 'KW', 'code' => 'stock_zero', 'message' => '12 live products in KW are out of stock.', 'deep_link' => 'markets/KW'];

        $push = app(PlatformAlertPush::class);
        $topic = $push->notify(alert: $alert, count: 12);

        $this->assertSame('lavkoo_pa_kw', $topic);
        $this->assertSame(['market' => 'KW', 'code' => 'stock_zero', 'count' => 12], $this->push->sent[0]['payload']);
        $this->assertSame(PlatformAlertPush::SENT, array_keys($this->push->sent[0]['payload']));
        $this->assertStringNotContainsString('out of stock', (string) json_encode($this->push->sent[0]), 'the sentence stays on the server, where opening the notification re-checks the grant');
    }

    public function test_the_same_alert_is_one_notification_and_a_new_day_is_another(): void
    {
        $alert = ['id' => 'abc123', 'market' => 'KW', 'code' => 'stock_zero'];
        $push = app(PlatformAlertPush::class);

        $push->notify(alert: $alert);
        $push->notify(alert: $alert);

        $this->assertSame($this->push->sent[0]['dedup'], $this->push->sent[1]['dedup'], 'the same alert twice carries the same key, so the service and the phone can drop one');

        $this->travel(1)->day();
        $push->notify(alert: $alert);

        $this->assertNotSame($this->push->sent[0]['dedup'], $this->push->sent[2]['dedup'], "tomorrow's is a new notification");
    }

    public function test_an_alert_of_no_market_is_sent_nowhere(): void
    {
        $push = app(PlatformAlertPush::class);

        $this->assertNull($push->notify(alert: ['id' => 'x', 'market' => null, 'code' => 'stock_zero']));
        $this->assertNull($push->notify(alert: ['id' => 'x', 'market' => 'ZZ', 'code' => 'stock_zero']));
        $this->assertSame([], $this->push->sent);
    }

    public function test_signing_out_takes_the_device_with_it_and_unsubscribes_the_token(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => self::PUSH_TOKEN], $this->bearer($token))->assertOk();

        $this->postJson(self::PREFIX . '/auth/logout', [], $this->bearer($token))->assertOk();

        $this->assertSame(0, DB::table('platform_admin_devices')->count());
        $this->assertSame([self::PUSH_TOKEN], $this->push->unsubscribed);
    }

    public function test_ending_the_other_sessions_takes_their_devices_and_leaves_this_ones(): void
    {
        $admin = $this->platformAdmin(grantOverrides: ['capabilities' => json_encode(['sessions', 'alerts'])]);
        $other = $this->mintToken($admin);
        $mine = $this->mintToken($admin);
        $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => 'the-other-phone-0123456789012345'], $this->bearer($other))->assertOk();
        $this->postJson(self::DEVICES, ['platform' => 'ios', 'push_token' => self::PUSH_TOKEN], $this->bearer($mine))->assertOk();

        $this->deleteJson(self::PREFIX . '/sessions', [], $this->bearer($mine))->assertOk();

        $this->assertSame([self::PUSH_TOKEN], PlatformAdminDevice::query()->get()->map(fn (PlatformAdminDevice $device): ?string => $device->pushToken())->all());
        $this->assertSame(['the-other-phone-0123456789012345'], $this->push->unsubscribed);
    }

    public function test_a_session_that_merely_went_idle_is_sent_nothing_even_before_anything_removes_its_row(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->mintToken($admin);
        $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => self::PUSH_TOKEN], $this->bearer($token))->assertOk();
        $this->assertCount(1, app(\App\Services\PlatformAdmin\PlatformAdminDevices::class)->sendable());

        // Nothing runs when a session goes idle, so the sending path is what has to notice.
        config(['gcc.platform_admin.session.idle_minutes' => 60]);
        DB::table('platform_admin_tokens')->update(['last_used_at' => now()->subHours(3), 'created_at' => now()->subHours(3)]);

        $this->assertSame([], app(\App\Services\PlatformAdmin\PlatformAdminDevices::class)->sendable());
        $this->assertSame(1, DB::table('platform_admin_devices')->count(), 'the row is still there; what changed is that it is not sendable');
    }

    public function test_a_device_of_another_session_cannot_be_forgotten_and_registering_again_replaces_the_topics(): void
    {
        $admin = $this->platformAdmin();
        $mine = $this->mintToken($admin);
        $other = $this->mintToken($admin);
        $id = (int) $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => self::PUSH_TOKEN], $this->bearer($mine))->json('data.id');

        $this->deleteJson(self::DEVICES . '/' . $id, [], $this->bearer($other))->assertStatus(404)->assertJsonPath('errors.0.code', 'device_not_found');

        $again = $this->postJson(self::DEVICES, ['platform' => 'ios', 'push_token' => self::PUSH_TOKEN], $this->bearer($mine))->assertOk();
        $this->assertSame($id, (int) $again->json('data.id'), 'the same phone in the same session is the same device');
        $this->assertSame(1, DB::table('platform_admin_devices')->count());

        $this->deleteJson(self::DEVICES . '/' . $id, [], $this->bearer($mine))->assertOk()->assertExactJson(['data' => ['removed' => true]]);
        $this->assertSame(0, DB::table('platform_admin_devices')->count());
    }

    public function test_a_platform_this_api_does_not_send_to_and_a_push_token_that_is_not_one_are_refused(): void
    {
        $token = $this->mintToken($this->platformAdmin());

        $this->postJson(self::DEVICES, ['platform' => 'web', 'push_token' => self::PUSH_TOKEN], $this->bearer($token))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_device_platform');
        $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => '   '], $this->bearer($token))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_push_token');
        $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => str_repeat('x', 513)], $this->bearer($token))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_push_token');

        $this->assertSame(0, DB::table('platform_admin_devices')->count());
        $this->assertSame([], $this->push->subscribed, 'a refused registration speaks to the push service about nothing');
    }

    public function test_registering_a_device_is_written_to_the_trail_with_its_topics_and_not_its_token(): void
    {
        $token = $this->mintToken($this->platformAdmin());

        $this->postJson(self::DEVICES, ['platform' => 'android', 'push_token' => self::PUSH_TOKEN], $this->bearer($token))->assertOk();

        $line = DB::table('platform_admin_audit_logs')->where('action', 'devices.register')->orderByDesc('id')->first();

        $this->assertNotNull($line);
        $this->assertStringNotContainsString(self::PUSH_TOKEN, (string) $line->context);
        $context = json_decode((string) $line->context, true);
        $this->assertSame(200, $context['status']);
        $this->assertSame('android', $context['platform']);
        $this->assertContains('lavkoo_pa_sa', $context['topics']);
    }

    public function test_the_api_answers_before_its_own_table_exists_rather_than_failing(): void
    {
        Schema::drop('platform_admin_devices');
        $token = $this->mintToken($this->platformAdmin());

        // The gate's 503 covers the tables of level 1; this one came later, so the read answers an empty list rather than a 500 and nothing is
        // sent to a table that is not there. An installation mid-deployment must not have its monitoring fall over for want of a device.
        $this->getJson(self::DEVICES, $this->bearer($token))->assertOk()->assertJsonPath('data.devices', []);
        $this->assertSame([], app(\App\Services\PlatformAdmin\PlatformAdminDevices::class)->sendable());

        (require database_path('migrations/2026_12_31_100100_create_platform_admin_devices_table.php'))->up();
    }

    public function test_the_transport_that_ships_sends_nothing(): void
    {
        $this->app->forgetInstance(PlatformAdminPushTransport::class);

        $this->assertInstanceOf(\App\Services\PlatformAdmin\PlatformAdminPushIsNotConfigured::class, app(PlatformAdminPushTransport::class), 'push is an open owner decision, so what ships sends nothing');
    }
}

/** A push service that records what it was asked to do and speaks to nobody. */
final class _RecordingPushTransport implements PlatformAdminPushTransport
{
    /** @var list<string> */
    public array $subscribed = [];

    /** @var list<string> */
    public array $unsubscribed = [];

    /** @var list<string> the topics of the last subscribe */
    public array $topics = [];

    /** @var list<array{topic: string, payload: array<string, mixed>, dedup: string}> */
    public array $sent = [];

    public function subscribe(string $pushToken, array $topics): void
    {
        $this->subscribed[] = $pushToken;
        $this->topics = $topics;
    }

    public function unsubscribe(string $pushToken, array $topics): void
    {
        $this->unsubscribed[] = $pushToken;
    }

    public function send(string $topic, array $payload, string $dedupKey): void
    {
        $this->sent[] = ['topic' => $topic, 'payload' => $payload, 'dedup' => $dedupKey];
    }
}
