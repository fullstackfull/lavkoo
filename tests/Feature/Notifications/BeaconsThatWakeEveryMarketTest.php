<?php

namespace Tests\Feature\Notifications;

use App\Models\Theme;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ThemeSyncBeacon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\FakesThePushService;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * I1-05: a maintenance or theme-update beacon of one market does not wake another market's apps, **or is documented as global**.
 *
 * Both of these are global, and this is where that is documented and where its harmlessness is measured rather than asserted in prose.
 *
 * **The theme beacon.** Every customer app subscribes to `theme_updates_user_app` at startup, before login, so a suffix per market would
 * silence a market until its own app build subscribed to its own topic -- an app release away (A1-17, Q-18, Q-32). A wasted wake is a far
 * smaller cost than a market whose phones never hear a publish, so the topic stays one name. What makes the wake harmless is that the beacon
 * carries no theme content: a woken client asks `/theme/version` for ITS OWN storefront and is answered that storefront's revision. The
 * publish of a Kuwaiti theme therefore cannot change what a Saudi app is served, and that is asserted here through the real publish and the
 * real endpoint rather than inferred from the payload.
 *
 * Beside that, the payload now NAMES the market a publish was for, so a client can ignore a beacon that is not its own instead of comparing
 * another market's revision number against its own. Additive, and absent while storefronts are off.
 *
 * **The maintenance beacons.** Global for a stronger reason: the maintenance they announce IS platform-wide. One `system_maintenance_mode`
 * entry stops the whole platform, and the switch chooses which APP is told (customer, vendor, delivery man), never which market -- so a
 * per-market maintenance topic would announce a per-market state that does not exist. That is asserted from the switch's own code rather than
 * from its docblock.
 */
class BeaconsThatWakeEveryMarketTest extends TestCase
{
    use BuildsThemeWorld;
    use FakesThePushService;
    use RestoresLanguageFiles;

    /** The one file that turns maintenance on and names the topics it announces on. */
    private const MAINTENANCE_SWITCH = 'app/Http/Controllers/Admin/Settings/BusinessSettingsController.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->useAThrowawayServiceAccountKey();
        $this->buildTheSettingsTable();
        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->giveStorefrontTheme(name: 'Saudi', country: null, revision: 3, spacerHeight: 41, page: 'saudi-offers', active: true);
        $this->giveStorefrontTheme(name: 'Kuwaiti', country: 'KW', revision: 7, spacerHeight: 42, page: 'kuwaiti-offers', active: true);
        $this->configureThePushService();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    public function test_a_publish_of_one_markets_theme_is_announced_once_on_the_one_global_topic(): void
    {
        $this->publishTheKuwaitiTheme();

        $beacons = $this->beaconsSent();

        $this->assertCount(1, $beacons, 'one publish, one announcement');
        $this->assertSame(ThemeSyncBeacon::TOPIC, $beacons[0]['message']['topic']);
    }

    /** The cost of the global topic, stated: a Saudi app is woken, and what it then asks for is unchanged. */
    public function test_a_saudi_app_woken_by_a_kuwaiti_publish_is_still_told_the_saudi_revision(): void
    {
        $saudiBefore = $this->getJson('/api/v1/theme/version', ['X-Storefront' => 'SA'])->assertOk()->json();

        $this->publishTheKuwaitiTheme();

        $this->assertCount(1, $this->beaconsSent(), 'the Saudi app is woken by it');
        $this->assertSame($saudiBefore, $this->getJson('/api/v1/theme/version', ['X-Storefront' => 'SA'])->assertOk()->json(), 'and is told exactly what it was told before');
        $this->assertSame(3, $saudiBefore['revision']);
    }

    public function test_the_kuwaiti_app_is_told_the_new_revision_by_the_same_beacon(): void
    {
        $this->publishTheKuwaitiTheme();

        $announced = (int) $this->beaconsSent()[0]['message']['data']['revision'];

        $this->assertGreaterThan(7, $announced, 'the publish moved the revision on');
        $this->assertSame($announced, $this->getJson('/api/v1/theme/version', ['X-Storefront' => 'KW'])->assertOk()->json('revision'), 'and it is the revision the Kuwaiti app is told');
    }

    /** So a client can ignore a beacon that is not its own, rather than reading a revision that belongs to another market. */
    public function test_the_beacon_names_the_market_the_publish_was_for(): void
    {
        $this->publishTheKuwaitiTheme();

        $this->assertSame('KW', $this->beaconsSent()[0]['message']['data']['storefront'] ?? null);
    }

    /** A publish of the default storefront's theme reaches every market, because every market without one of its own falls back to it. */
    public function test_a_publish_that_is_for_every_market_names_none(): void
    {
        $this->publishTheTheme(named: 'Saudi');

        $this->assertArrayNotHasKey('storefront', $this->beaconsSent()[0]['message']['data']);
    }

    /** The first test of the switch: with storefronts off the payload is byte for byte the payload the platform sends today. */
    public function test_while_storefronts_are_off_the_payload_is_the_one_the_platform_sends_today(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $payload = app(ThemeSyncBeacon::class)->payload(revision: 9, storefronts: ['KW']);

        $this->assertSame(
            ['type' => ThemeSyncBeacon::TYPE, 'revision' => '9'],
            $payload['message']['data'],
            'no key an older app has never heard of',
        );
    }

    /** The beacon still carries no theme content, whatever market it names: it is a ping, and that is what keeps the global topic harmless. */
    public function test_the_beacon_carries_no_theme_content(): void
    {
        $data = app(ThemeSyncBeacon::class)->payload(revision: 9, storefronts: ['KW'])['message']['data'];

        $this->assertSame(['type', 'revision', 'storefront'], array_keys($data));
        $this->assertArrayNotHasKey('notification', app(ThemeSyncBeacon::class)->payload(revision: 9), 'and it is still silent');
    }

    /**
     * The maintenance beacons are global because the state they announce is. Read from the switch's own code: it writes ONE platform-wide
     * entry and chooses which APP is told, and names no market anywhere.
     */
    public function test_the_maintenance_switch_is_platform_wide_so_its_topic_must_be(): void
    {
        $code = (string) file_get_contents(base_path(self::MAINTENANCE_SWITCH));
        $switch = $this->theUpdateSystemModeMethod($code);

        $this->assertStringContainsString("Cache::put('system_maintenance_mode'", $switch, 'one entry stops the whole platform');
        $this->assertStringContainsString("Cache::forget('system_maintenance_mode')", $switch);

        foreach (['storefront', 'storefront_country', 'StorefrontCatalog', 'StorefrontContext'] as $market) {
            $this->assertStringNotContainsString($market, $switch, "the maintenance switch names no market ({$market})");
        }

        foreach (['maintenance_mode_start_user_app', 'maintenance_mode_start_vendor', 'maintenance_mode_start_deliveryman'] as $topic) {
            $this->assertStringContainsString("topic: '{$topic}'", $switch, 'and its topics are named per app, not per market');
        }
    }

    /** The one settings table the push service and the theme endpoints read. It is created by the installation dump, not by a migration. */
    private function buildTheSettingsTable(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('business_settings');
        \Illuminate\Support\Facades\Schema::create('business_settings', static function ($table): void {
            $table->id();
            $table->string('type');
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    /** The push service signs its token request with this key; the repository ships none, so a throwaway pair is made once per run. */
    private static ?string $serviceAccountKey = null;

    private function useAThrowawayServiceAccountKey(): void
    {
        if (self::$serviceAccountKey === null) {
            $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($pair, $private);
            self::$serviceAccountKey = $private;
        }

        config(['passport.private_key' => self::$serviceAccountKey]);
    }

    /** @return string the source of updateSystemMode(), which is the method the maintenance switch lives in */
    private function theUpdateSystemModeMethod(string $code): string
    {
        $start = strpos($code, 'function updateSystemMode');
        $this->assertNotFalse($start, 'the maintenance switch is still updateSystemMode()');

        return substr($code, $start, 6000);
    }

    private function publishTheKuwaitiTheme(): void
    {
        $this->publishTheTheme(named: 'Kuwaiti');
    }

    /** Publishes a new version of one storefront's theme through the real publisher, which is what announces. */
    private function publishTheTheme(string $named): void
    {
        $theme = Theme::query()->where('name', $named)->firstOrFail();
        $published = $theme->publishedVersion;
        $draft = $published->replicate();
        $draft->status = \App\Models\ThemeVersion::STATUS_DRAFT;
        $draft->revision = $published->revision + 1;
        $draft->label = $named . ' next';
        $draft->save();

        Cache::flush();
        app(ThemeManager::class)->publish($draft);
    }

    /** @return list<array<string, mixed>> the body of every beacon that left the server */
    private function beaconsSent(): array
    {
        return Http::recorded(static fn ($request): bool => str_starts_with($request->url(), 'https://fcm.googleapis.com/')
                && ($request->data()['message']['data']['type'] ?? null) === ThemeSyncBeacon::TYPE)
            ->map(static fn (array $exchange): array => $exchange[0]->data())
            ->values()
            ->all();
    }
}
