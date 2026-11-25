<?php

namespace Tests\Feature\Storefront;

use App\Console\Commands\StorefrontBrandCommand;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\StorefrontBrand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F-13: LAVKOO's identity is global, and a market may show its own marketing name, logos and favicon.
 *
 * The owner's decision is recorded and it is a FALLBACK, not a second store of brand values: a field a market has not overridden is the one
 * global `business_settings` value it has always been, and a platform with no override rows is the platform of today, field for field. That
 * is what the first test asserts and what every other test is measured against.
 *
 * Two things about the shape of this are load-bearing, and each is a defect that would otherwise have shipped.
 *
 * **The override is applied at `getWebConfig()`, the one accessor the hundred-odd readers share.** The name is asked for by the website's
 * head and footer, the app's configuration, every e-mail template and the SEO tags; an override wired reader by reader would differ between
 * two of them the first time one was missed. The legal identity is deliberately NOT among these fields: an invoice prints the selling
 * company (F-14) from `invoice_settings` and `company_web_logo_png`, neither of which a market may override.
 *
 * **An override answers with the SAME SHAPE the global setting has.** `getWebConfig()` runs a logo or the favicon through
 * `setWebConfigCache()`, which turns a stored file name into a storage link with a `path` key, and the website's head reads
 * `$web_config['fav_icon']['path']`. A market's record therefore stores what the global row would store and is shaped by the same function;
 * an override that answered a bare file name would take the favicon off every page of that market.
 */
class StorefrontBrandTest extends TestCase
{
    private const PLATFORM_NAME = 'Lavkoo';

    private const KUWAITI_NAME = 'Lavkoo Kuwait';

    private const PLATFORM_LOGO = 'platform-logo.png';

    private const KUWAITI_LOGO = 'kw-logo.png';

    private const KUWAITI_FAVICON = 'kw-favicon.png';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        $this->setGlobally('company_name', self::PLATFORM_NAME);
        $this->setGlobally('company_web_logo', self::PLATFORM_LOGO);
        $this->setGlobally('company_fav_icon', 'platform-favicon.png');
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $this->forget();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('business_settings');

        parent::tearDown();
    }

    /** The first test of the switch: with storefronts off a stored override is not read and every reader answers the platform's identity. */
    public function test_while_storefronts_are_off_an_override_is_not_read(): void
    {
        $this->giveKuwaitItsOwnBrand();
        config(['gcc.storefronts.enabled' => false]);
        $this->forget();

        $this->servingMarket('KW', function (): void {
            $this->assertSame(self::PLATFORM_NAME, getWebConfig(name: 'company_name'));
            $this->assertSame([], app(StorefrontBrand::class)->shapedOverridesOf());
        });
    }

    public function test_a_market_with_an_override_shows_its_own_name_and_no_other_market_does(): void
    {
        $this->giveKuwaitItsOwnBrand();

        $this->servingMarket('KW', fn (): mixed => $this->assertSame(self::KUWAITI_NAME, getWebConfig(name: 'company_name')));
        $this->servingMarket('AE', fn (): mixed => $this->assertSame(self::PLATFORM_NAME, getWebConfig(name: 'company_name')));
        $this->servingMarket('SA', fn (): mixed => $this->assertSame(self::PLATFORM_NAME, getWebConfig(name: 'company_name')));
    }

    /** The second criterion on its own: a field the market has not overridden is the platform's, in the same breath as one it has. */
    public function test_a_field_without_an_override_falls_back_to_the_global_value(): void
    {
        $this->giveKuwaitItsOwnBrand(fields: ['company_name' => self::KUWAITI_NAME]);

        $this->servingMarket('KW', function (): void {
            $this->assertSame(self::KUWAITI_NAME, getWebConfig(name: 'company_name'), 'the field the market overrode');
            $this->assertStringContainsString(self::PLATFORM_LOGO, $this->fileOf(getWebConfig(name: 'company_web_logo')), 'the field it did not');
        });
    }

    /** The trap: a logo or the favicon has to come back shaped as the global one is, or the website's head loses it. */
    public function test_an_overridden_logo_answers_with_the_same_shape_the_global_one_has(): void
    {
        $this->giveKuwaitItsOwnBrand(fields: ['company_fav_icon' => self::KUWAITI_FAVICON]);

        $platformShape = array_keys((array) getWebConfig(name: 'company_fav_icon'));

        $this->servingMarket('KW', function () use ($platformShape): void {
            $kuwaiti = getWebConfig(name: 'company_fav_icon');

            $this->assertSame($platformShape, array_keys((array) $kuwaiti), 'the market\'s favicon has a different shape from the platform\'s');
            $this->assertStringContainsString(self::KUWAITI_FAVICON, $this->fileOf($kuwaiti), 'and it is the market\'s own file');
        });
    }

    /** The website's shared settings array, which is built at boot and so has to be re-dressed when a view is rendered. */
    public function test_the_websites_settings_array_is_dressed_in_the_markets_brand(): void
    {
        $this->giveKuwaitItsOwnBrand();
        $webConfig = [
            'company_name' => self::PLATFORM_NAME,
            'name' => self::PLATFORM_NAME,
            'meta_title' => self::PLATFORM_NAME . ' Online Shopping | ' . self::PLATFORM_NAME . ' ecommerce',
            'web_logo' => getWebConfig(name: 'company_web_logo'),
            'fav_icon' => getWebConfig(name: 'company_fav_icon'),
            'primary_color' => '#112233',
        ];

        $this->servingMarket('KW', function () use ($webConfig): void {
            $dressed = app(StorefrontBrand::class)->dress(webConfig: $webConfig);

            $this->assertSame(self::KUWAITI_NAME, $dressed['company_name']);
            $this->assertSame(self::KUWAITI_NAME, $dressed['name'], 'the second key the website reads the name under');
            $this->assertStringContainsString(self::KUWAITI_NAME, $dressed['meta_title'], 'the title built from the name');
            $this->assertStringNotContainsString(self::PLATFORM_NAME . ' Online', $dressed['meta_title'], 'and not half the platform\'s and half the market\'s');
            $this->assertStringContainsString(self::KUWAITI_LOGO, $this->fileOf($dressed['web_logo']));
            $this->assertStringContainsString(self::KUWAITI_FAVICON, $this->fileOf($dressed['fav_icon']));
            $this->assertSame('#112233', $dressed['primary_color'], 'a setting that is not a brand field is untouched');
        });
    }

    /** And a market with no brand of its own gets the array back exactly as it was: the platform of today, key for key. */
    public function test_a_market_with_no_override_gets_the_settings_array_back_unchanged(): void
    {
        $webConfig = ['company_name' => self::PLATFORM_NAME, 'name' => self::PLATFORM_NAME, 'web_logo' => 'x'];

        foreach (['SA', 'KW', 'AE'] as $market) {
            $this->servingMarket($market, function () use ($webConfig, $market): void {
                $this->assertSame($webConfig, app(StorefrontBrand::class)->dress(webConfig: $webConfig), $market);
                $this->assertFalse(app(StorefrontBrand::class)->overridesAnything(), $market);
            });
        }
    }

    /** The app is answered the whole brand, resolved: it never has to merge two payloads to learn its own name. */
    public function test_the_app_is_answered_the_brand_of_the_storefront_it_names(): void
    {
        $this->giveKuwaitItsOwnBrand(fields: ['company_name' => self::KUWAITI_NAME]);
        $brand = app(StorefrontBrand::class);

        $this->servingMarket('KW', function () use ($brand): void {
            $answer = [];
            foreach (StorefrontBrand::FIELDS as $field) {
                $answer[$field] = $brand->of(field: $field);
            }

            $this->assertSame(StorefrontBrand::FIELDS, array_keys($answer), 'every field is answered, overridden or not');
            $this->assertSame(self::KUWAITI_NAME, $answer['company_name']);
            $this->assertStringContainsString(self::PLATFORM_LOGO, $this->fileOf($answer['company_web_logo']));
        });
    }

    /** And over HTTP, where the market comes from the header the app sends and nothing else. */
    public function test_the_brand_endpoint_answers_the_market_the_header_names(): void
    {
        $this->giveKuwaitItsOwnBrand(fields: ['company_name' => self::KUWAITI_NAME]);

        $kuwaiti = $this->withHeader(StorefrontResolver::HEADER, 'KW')->getJson('/api/v1/theme/brand')->assertOk()->json();
        $saudi = $this->withHeader(StorefrontResolver::HEADER, 'SA')->getJson('/api/v1/theme/brand')->assertOk()->json();

        $this->assertSame(StorefrontBrand::FIELDS, array_keys($kuwaiti), 'every field is answered, overridden or not');
        $this->assertSame(self::KUWAITI_NAME, $kuwaiti['company_name']);
        $this->assertSame(self::PLATFORM_NAME, $saudi['company_name'], 'the default market is answered the platform\'s identity');
        $this->assertSame(self::PLATFORM_LOGO, $this->fileOf($kuwaiti['company_web_logo']), 'a field Kuwait did not override');
    }

    /** The answer varies by the header, so a shared cache or a proxy cannot serve one market's brand to another. */
    public function test_the_brand_endpoint_says_it_varies_by_the_storefront_header(): void
    {
        $response = $this->withHeader(StorefrontResolver::HEADER, 'KW')->getJson('/api/v1/theme/brand')->assertOk();

        $this->assertStringContainsString(StorefrontResolver::HEADER, (string) $response->headers->get('Vary'));
    }

    public function test_the_command_writes_a_markets_brand_clears_one_field_and_refuses_the_default_market(): void
    {
        $this->assertSame(0, $this->runCommand(['country' => 'KW', 'field' => 'company_name', 'value' => self::KUWAITI_NAME]));
        $this->forget();
        $this->servingMarket('KW', fn (): mixed => $this->assertSame(self::KUWAITI_NAME, getWebConfig(name: 'company_name')));

        $this->assertSame(0, $this->runCommand(['country' => 'KW', 'field' => 'company_name']));
        $this->forget();
        $this->servingMarket('KW', fn (): mixed => $this->assertSame(self::PLATFORM_NAME, getWebConfig(name: 'company_name'), 'a cleared field is the platform\'s again'));

        $this->assertNotSame(0, $this->runCommand(['country' => 'SA', 'field' => 'company_name', 'value' => 'Another']), 'the default market was given a record of its own');
        $this->assertNotSame(0, $this->runCommand(['country' => 'ZZ', 'field' => 'company_name', 'value' => 'Another']), 'a market the platform does not configure was written');
        $this->assertNotSame(0, $this->runCommand(['country' => 'KW', 'field' => 'company_reliability', 'value' => 'Another']), 'a setting that is not a brand field was written');
    }

    /**
     * A record for the DEFAULT market is ignored even if one is written straight into the table.
     *
     * The global settings ARE the default market's brand -- that is what they have always been -- so a record for it would be a second
     * writer of one field with no rule for which of them wins, and whichever lost would do so silently. The command refuses to write one;
     * this asserts the resolver would not read one either, because a refusal at the only writer is not the same as a rule.
     */
    public function test_a_record_for_the_default_market_is_ignored(): void
    {
        DB::table('business_settings')->insert([
            'type' => StorefrontBrand::SETTING_PREFIX . 'SA',
            'value' => json_encode(['company_name' => 'Written straight into the table']),
        ]);
        $this->forget();

        $this->servingMarket('SA', function (): void {
            $this->assertSame(self::PLATFORM_NAME, getWebConfig(name: 'company_name'));
            $this->assertSame([], app(StorefrontBrand::class)->shapedOverridesOf());
        });
    }

    /** A record written with something that is not JSON leaves the market showing the platform's identity rather than nothing at all. */
    public function test_an_unreadable_record_is_treated_as_no_override(): void
    {
        DB::table('business_settings')->insert(['type' => StorefrontBrand::SETTING_PREFIX . 'KW', 'value' => '{not json']);
        $this->forget();

        $this->servingMarket('KW', fn (): mixed => $this->assertSame(self::PLATFORM_NAME, getWebConfig(name: 'company_name')));
    }

    /** @param array<string, string> $arguments */
    private function runCommand(array $arguments): int
    {
        return Artisan::call(StorefrontBrandCommand::class, $arguments);
    }

    /** @param array<string, string>|null $fields */
    private function giveKuwaitItsOwnBrand(?array $fields = null): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => StorefrontBrand::SETTING_PREFIX . 'KW'],
            ['value' => json_encode($fields ?? [
                'company_name' => self::KUWAITI_NAME,
                'company_web_logo' => self::KUWAITI_LOGO,
                'company_fav_icon' => self::KUWAITI_FAVICON,
            ])],
        );
        $this->forget();
    }

    private function setGlobally(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value]);
        $this->forget();
    }

    private function servingMarket(string $country, callable $callback): void
    {
        $context = new StorefrontContext(country: $country, currency: 'SAR', storefrontsEnabled: (bool) config('gcc.storefronts.enabled', false));
        $context->serve(function () use ($callback): void {
            app(StorefrontBrand::class)->flush();
            $callback();
        });
        app(StorefrontBrand::class)->flush();
    }

    /**
     * The file a logo setting names. `key` rather than `path`, because `storageLink()` answers `path: null, status: 404` for a file that is
     * not on this disk and every file named here is made up -- and the question these tests ask is WHICH FILE the setting names, which is
     * the half an override controls.
     */
    private function fileOf(mixed $logo): string
    {
        if (is_array($logo)) {
            return (string) ($logo['key'] ?? $logo['path'] ?? '');
        }

        return is_object($logo) ? (string) ($logo->key ?? $logo->path ?? '') : (string) $logo;
    }

    private function forget(): void
    {
        Cache::flush();
        clearWebConfigCacheKeys();
        app(StorefrontBrand::class)->flush();
    }
}
