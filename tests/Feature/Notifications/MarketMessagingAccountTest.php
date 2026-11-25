<?php

namespace Tests\Feature\Notifications;

use App\Services\ChatwootService;
use App\Services\Notification\NotificationProfiles;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\ZenderOtpService;
use App\Utils\SMSModule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsNotificationProfiles;
use Tests\TestCase;

/**
 * I1-02: a market's one-time codes and WhatsApp messages go through that market's own account, and the readiness report says what is
 * missing before the market opens.
 *
 * One SMS gateway chain, one Zender account and one Chatwoot inbox served the whole installation, so a Kuwaiti customer's one-time code went
 * out through the Saudi gateway -- on a sender number registered to another company, in another country, which for a code that proves who
 * somebody is matters more than for most messages. A WhatsApp TEMPLATE is approved per sender number at the provider, so a market with its
 * own number also has its own approved template names, and sending its messages through another market's inbox is not merely wrong but
 * impossible for any template that number has not had approved.
 *
 * **A market's own account is the whole answer for its channel.** A gateway that market does not name answers nothing, and the shared
 * configuration is not read: a Kuwaiti code may not leave through whichever gateway the Saudi account happens to have switched on, which is
 * exactly what a per-gateway fallback would do. Falling back is a decision about a MARKET.
 */
class MarketMessagingAccountTest extends TestCase
{
    use BuildsNotificationProfiles;

    private const SHARED_ZENDER = 'https://zender.shared.example';

    private const KUWAITI_ZENDER = 'https://zender.kw.example';

    private const KUWAITI_SECRET = 'kuwait-zender-secret-never-logged';

    private const EMIRATI_ZENDER = 'https://zender.ae.example';

    private const EMIRATI_SECRET = 'emirates-zender-secret-never-logged';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNotificationProfilesTable();
        $this->buildTheSharedSmsSettings();
        $this->buildTheSharedChatwootIntegration();
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->dropNotificationProfilesTable();
        Schema::dropIfExists('addon_settings');
        Schema::dropIfExists('integration_settings');
        Schema::dropIfExists('zender_settings');

        parent::tearDown();
    }

    /** The first test of the switch: with it off, a market's row is not read and every gateway configuration is the shared one. */
    public function test_while_the_switch_is_off_the_shared_gateway_configuration_is_what_every_market_reads(): void
    {
        $this->giveKuwaitItsOwnSmsAccount();
        $this->serveMarket('KW');

        $this->assertSame('shared-twilio-sid', SMSModule::get_settings('twilio')['sid']);
    }

    public function test_a_kuwaiti_code_reads_the_kuwaiti_gateway_and_an_emirati_the_emiratis(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnSmsAccount();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::SMS,
            country: 'AE',
            settings: ['gateways' => ['twilio' => ['status' => 1, 'sid' => 'ae-twilio-sid', 'otp_template' => 'Your code is #OTP#']]],
            credentials: ['gateways' => ['twilio' => ['token' => 'ae-twilio-token']]],
        );

        $this->serveMarket('KW');
        $kuwait = SMSModule::get_settings('twilio');
        $this->serveMarket('AE');
        $emirates = SMSModule::get_settings('twilio');

        $this->assertSame(['kw-twilio-sid', 'kw-twilio-token'], [$kuwait['sid'], $kuwait['token']]);
        $this->assertSame(['ae-twilio-sid', 'ae-twilio-token'], [$emirates['sid'], $emirates['token']]);
    }

    public function test_a_market_with_no_account_of_its_own_reads_the_shared_gateway(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnSmsAccount();
        $this->serveMarket('AE');

        $this->assertSame('shared-twilio-sid', SMSModule::get_settings('twilio')['sid']);
    }

    /**
     * The isolation this requirement exists for, asserted without a network call: the Kuwaiti account names only `twilio`, the SHARED
     * account has `nexmo` switched on, and a Kuwaiti code finds no gateway at all rather than going out through the Saudi company's nexmo.
     */
    public function test_a_gateway_the_markets_own_account_does_not_name_is_not_taken_from_the_shared_one(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnSmsAccount();
        $this->serveMarket('KW');

        $this->assertNull(SMSModule::get_settings('nexmo'), 'the shared nexmo account is not the Kuwaiti market\'s');

        // And the shared account really does have one, so the null above is the market's own answer and not an empty platform.
        $this->serveMarket('AE');
        $this->assertSame('shared-nexmo-key', SMSModule::get_settings('nexmo')['api_key']);
    }

    public function test_a_market_whose_own_account_has_every_gateway_switched_off_sends_nothing(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::SMS,
            country: 'KW',
            settings: ['gateways' => ['twilio' => ['status' => 0]]],
        );
        $this->serveMarket('KW');

        $this->assertSame('not_found', SMSModule::send('+96550000000', '123456'), 'and not a code sent through the shared gateway');
    }

    public function test_a_kuwaiti_one_time_code_leaves_through_the_kuwaiti_zender_account(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnSmsAccount();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::SMS,
            country: 'AE',
            settings: ['gateways' => [], 'zender' => $this->zenderAccount(self::EMIRATI_ZENDER)],
            credentials: ['zender' => ['secret' => self::EMIRATI_SECRET]],
        );
        Http::fake(['*' => Http::response(['status' => 200])]);

        $this->serveMarket('KW');
        app(ZenderOtpService::class)->send('+96550000000', '123456');
        $this->serveMarket('AE');
        app(ZenderOtpService::class)->send('+97150000000', '654321');

        $sent = $this->zenderRequests();
        $this->assertSame(self::KUWAITI_ZENDER . '/api/send/sms', $sent[0]->url());
        $this->assertSame(self::KUWAITI_SECRET, $sent[0]['secret']);
        $this->assertSame(self::EMIRATI_ZENDER . '/api/send/sms', $sent[1]->url());
        $this->assertSame(self::EMIRATI_SECRET, $sent[1]['secret']);
    }

    public function test_a_market_with_no_zender_account_of_its_own_uses_the_shared_one(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveTheSharedZenderAccount();
        Http::fake(['*' => Http::response(['status' => 200])]);

        $this->serveMarket('AE');
        app(ZenderOtpService::class)->send('+97150000000', '654321');

        $this->assertSame(self::SHARED_ZENDER . '/api/send/sms', $this->zenderRequests()[0]->url());
    }

    /** A market that has an SMS account but no Zender in it sends no code rather than sending through the shared Zender account. */
    public function test_a_market_whose_account_names_no_zender_does_not_use_the_shared_one(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveTheSharedZenderAccount();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::SMS,
            country: 'KW',
            settings: ['gateways' => ['twilio' => ['status' => 0]]],
        );
        Http::fake(['*' => Http::response(['status' => 200])]);

        $this->serveMarket('KW');

        $this->assertFalse(app(ZenderOtpService::class)->send('+96550000000', '123456'));
        $this->assertSame([], $this->zenderRequests());
    }

    public function test_a_whatsapp_message_of_a_market_goes_to_that_markets_inbox(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::WHATSAPP,
            country: 'KW',
            settings: ['base_url' => 'https://chat.kw.example', 'account_id' => 71, 'inbox_id' => 404],
            credentials: ['api_access_token' => 'kuwait-chatwoot-token'],
        );
        Http::fake(['*' => Http::response(['id' => 404])]);
        $this->serveMarket('KW');

        app(ChatwootService::class)->getInbox();

        $asked = Http::recorded()[0][0];
        $this->assertSame('https://chat.kw.example/api/v1/accounts/71/inboxes/404', $asked->url());
        $this->assertSame('kuwait-chatwoot-token', $asked->header('api_access_token')[0]);
    }

    public function test_a_market_with_no_whatsapp_account_of_its_own_uses_the_shared_inbox(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::WHATSAPP,
            country: 'KW',
            settings: ['base_url' => 'https://chat.kw.example', 'account_id' => 71, 'inbox_id' => 404],
            credentials: ['api_access_token' => 'kuwait-chatwoot-token'],
        );
        Http::fake(['*' => Http::response(['id' => 9])]);
        $this->serveMarket('AE');

        app(ChatwootService::class)->getInbox();

        $this->assertSame('https://chat.shared.example/api/v1/accounts/1/inboxes/9', Http::recorded()[0][0]->url());
    }

    /**
     * The "and template" half of the criterion. A WhatsApp template is approved per SENDER NUMBER at the provider, and the approved list
     * lives on the inbox, so reading the market's own inbox is what makes the templates the market's own: a template the Saudi number has
     * had approved is not offered for a Kuwaiti message, and vice versa.
     */
    public function test_the_templates_offered_for_a_markets_message_are_the_ones_its_own_inbox_has_approved(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::WHATSAPP,
            country: 'KW',
            settings: ['base_url' => 'https://chat.kw.example', 'account_id' => 71, 'inbox_id' => 404],
            credentials: ['api_access_token' => 'kuwait-chatwoot-token'],
        );
        Http::fake([
            'chat.kw.example/*' => Http::response(['message_templates' => [['name' => 'kw_cart_reminder', 'status' => 'APPROVED', 'language' => 'ar']]]),
            'chat.shared.example/*' => Http::response(['message_templates' => [['name' => 'sa_cart_reminder', 'status' => 'APPROVED', 'language' => 'ar']]]),
        ]);

        $this->serveMarket('KW');
        $kuwait = app(ChatwootService::class)->getWhatsAppTemplates();
        $this->serveMarket('AE');
        $shared = app(ChatwootService::class)->getWhatsAppTemplates();

        $this->assertSame(['kw_cart_reminder'], array_column($kuwait, 'name'));
        $this->assertSame(['sa_cart_reminder'], array_column($shared, 'name'));
        $this->assertNull(app(ChatwootService::class)->getWhatsAppTemplate('kw_cart_reminder'), 'the Kuwaiti template is not offered for another market');
    }

    /** A market's WhatsApp account that cannot address a conversation refuses rather than falling through to the shared inbox. */
    public function test_an_incomplete_whatsapp_account_refuses_rather_than_using_another_markets(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::WHATSAPP,
            country: 'KW',
            settings: ['base_url' => 'https://chat.kw.example', 'account_id' => 71],
            credentials: ['api_access_token' => 'kuwait-chatwoot-token'],
        );
        $this->serveMarket('KW');

        $this->expectException(RuntimeException::class);
        app(ChatwootService::class);
    }

    public function test_the_readiness_report_says_nothing_is_read_while_the_switch_is_off(): void
    {
        $this->artisan('gcc:notification-readiness')
            ->expectsOutputToContain('Notification accounts per market: off')
            ->assertExitCode(0);
    }

    public function test_the_readiness_report_names_the_value_a_market_cannot_send_without(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::WHATSAPP,
            country: 'KW',
            settings: ['base_url' => 'https://chat.kw.example', 'account_id' => 71],
            credentials: ['api_access_token' => 'kuwait-chatwoot-token'],
        );

        $this->artisan('gcc:notification-readiness')
            ->expectsOutputToContain('MISSING: inbox_id')
            ->assertExitCode(1);
    }

    public function test_the_readiness_report_prints_no_credential_value(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnSmsAccount();
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::MAIL,
            country: 'KW',
            settings: ['host' => 'smtp.kw.example', 'from_address' => 'orders@kw.example'],
            credentials: ['password' => self::KUWAITI_SECRET],
        );

        $report = $this->reportOf('gcc:notification-readiness');

        $this->assertStringNotContainsString(self::KUWAITI_SECRET, $report);
        $this->assertStringContainsString('credentials present: password', $report, 'the operator is told a password is set, by name');
    }

    public function test_the_readiness_report_names_the_markets_that_have_an_account_of_their_own(): void
    {
        $this->switchNotificationProfilesOn();
        $this->giveKuwaitItsOwnSmsAccount();

        $report = $this->reportOf('gcc:notification-readiness');

        $this->assertStringContainsString('markets with their own: KW', $report);
        $this->assertStringContainsString('mail      no shared account', $report);
    }

    /** Every market reads the platform settings while no row exists, so the report is a page of "as today" and exits clean. */
    public function test_the_readiness_report_of_an_empty_table_says_every_market_reads_the_platform_settings(): void
    {
        $this->switchNotificationProfilesOn();

        $report = $this->reportOf('gcc:notification-readiness');

        foreach (StorefrontResolver::fromConfig()->configuredCountries() as $country) {
            $this->assertStringContainsString($country . '   mail      the platform settings, as today.', $report);
        }
    }

    private function reportOf(string $command): string
    {
        Artisan::call($command);

        return Artisan::output();
    }

    /** @return list<Request> every request that went to a Zender host, in the order it left */
    private function zenderRequests(): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), 'zender'))
            ->map(static fn (array $exchange): Request => $exchange[0])
            ->values()
            ->all();
    }

    private function serveMarket(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
        app(NotificationProfiles::class)->flush();
    }

    private function giveKuwaitItsOwnSmsAccount(): void
    {
        $this->giveMarketItsOwnAccount(
            channel: NotificationProfiles::SMS,
            country: 'KW',
            settings: [
                'gateways' => ['twilio' => ['status' => 1, 'sid' => 'kw-twilio-sid', 'messaging_service_sid' => 'kw-service', 'otp_template' => 'Your code is #OTP#']],
                'zender' => $this->zenderAccount(self::KUWAITI_ZENDER),
            ],
            credentials: ['gateways' => ['twilio' => ['token' => 'kw-twilio-token']], 'zender' => ['secret' => self::KUWAITI_SECRET]],
            label: 'Kuwait gateways',
        );
    }

    /** @return array<string, mixed> */
    private function zenderAccount(string $baseUrl): array
    {
        return ['enabled' => true, 'base_url' => $baseUrl, 'type' => 'sms', 'mode' => 'credits', 'gateway' => '1', 'message' => 'Your code is {{otp}}'];
    }

    private function giveTheSharedZenderAccount(): void
    {
        Schema::create('zender_settings', static function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('base_url')->nullable();
            $table->text('secret')->nullable();
            $table->string('type')->nullable();
            $table->text('message')->nullable();
            $table->integer('expire')->nullable();
            $table->integer('priority')->nullable();
            $table->string('account')->nullable();
            $table->string('mode')->nullable();
            $table->string('device')->nullable();
            $table->string('gateway')->nullable();
            $table->integer('sim')->nullable();
            $table->timestamps();
        });
        \App\Models\ZenderSetting::create($this->zenderAccount(self::SHARED_ZENDER) + ['secret' => 'shared-zender-secret']);
    }

    /** The one SMS configuration every installation has: twilio and nexmo, both switched on. */
    private function buildTheSharedSmsSettings(): void
    {
        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', static function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('test');
            $table->integer('is_active')->default(0);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });

        foreach ([
            'twilio' => ['status' => 1, 'sid' => 'shared-twilio-sid', 'token' => 'shared-twilio-token', 'messaging_service_sid' => 'shared-service', 'otp_template' => 'Your code is #OTP#'],
            'nexmo' => ['status' => 1, 'api_key' => 'shared-nexmo-key', 'api_secret' => 'shared-nexmo-secret', 'from' => 'Platform', 'otp_template' => 'Your code is #OTP#'],
        ] as $gateway => $values) {
            DB::table('addon_settings')->insert([
                'id' => $gateway, 'key_name' => $gateway, 'settings_type' => 'sms_config',
                'live_values' => json_encode($values, JSON_THROW_ON_ERROR), 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** The one Chatwoot integration every installation has, stored the way the application stores it. */
    private function buildTheSharedChatwootIntegration(): void
    {
        Schema::dropIfExists('integration_settings');
        Schema::create('integration_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('name')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('base_url')->nullable();
            $table->text('credentials')->nullable();
            $table->text('settings')->nullable();
            $table->timestamps();
        });

        \App\Models\IntegrationSetting::create([
            'provider' => 'chatwoot', 'name' => 'Chatwoot', 'enabled' => true, 'base_url' => 'https://chat.shared.example',
            'credentials' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['api_access_token' => 'shared-chatwoot-token'], JSON_THROW_ON_ERROR)),
            'settings' => ['account_id' => 1, 'inbox_id' => 9],
        ]);
    }
}
