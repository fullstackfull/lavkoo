<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\IntegrationSetting;
use App\Models\ProviderCity;
use App\Services\Fulfillment\ProviderCityCountry;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Providers\TorodPoint\TorodCityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\Fulfillment\FakeGulfAdapter;

/**
 * H1-11's last criterion: the same carrier code runs for two countries, with separate credentials and separate city lists.
 *
 * It could not. `integration_settings` held one row per provider code, so a second country had nowhere to keep its own account, and
 * `provider_cities` was unique on (provider, external_id), so the second country's city list collided with the first on every city whose id
 * the two share -- which, for a carrier numbering its cities from 1 in each country, is most of them. An operator could configure the second
 * country only by overwriting the first.
 *
 * Both tables now carry a `country`, and `''` is the PLATFORM-WIDE row: the account and the list every installation has today. Everything
 * falls back to it, so the first test here is the one that protects what exists -- a platform with one account answers exactly as it did,
 * whatever country is asked about.
 *
 * **Why the empty string and not NULL.** MySQL and MariaDB count two NULLs as different in a unique key, so `(provider, NULL, external_id)`
 * could be stored twice and the key that stops a city being imported twice would have quietly stopped biting. The test that proves the key
 * still bites is the point of that choice, and it is in here.
 */
class TwoCountriesOneCarrierTest extends FulfillmentTestCase
{
    private const CODE = 'fake_gulf';

    private const TOROD = ProviderCode::TorodPoint;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fulfillment.providers.' . self::CODE => [
                'label' => 'Fake Gulf Express',
                'reference_code' => 'FG',
                'adapter' => FakeGulfAdapter::class,
                'routes' => ['SA:AE', 'KW:KW'],
                'secret_keys' => ['api_key', 'webhook_secret'],
            ],
        ]);

        $this->migration()->up();
        app(ProviderCityCountry::class)->forget();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_12_23_100000_add_country_to_provider_credentials_and_cities.php');
    }

    private function connections(): ProviderConnectionManager
    {
        // A fresh manager each time: it remembers whether the rows carry a country, which a test changes mid-run.
        app()->forgetInstance(ProviderConnectionManager::class);

        return app(ProviderConnectionManager::class);
    }

    /** The only account a platform has today, read for every country, because none of them has one of its own. */
    public function test_one_account_answers_for_every_country_exactly_as_it_did(): void
    {
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: 'https://one.test', settings: [], secrets: ['fulfillment_username' => 'the-one-account']);

        foreach ([null, 'SA', 'KW', 'AE'] as $country) {
            $config = $this->connections()->config(self::TOROD, $country);

            $this->assertTrue($config->enabled, 'the platform-wide account answers for ' . ($country ?? 'no country named'));
            $this->assertSame('https://one.test', $config->baseUrl);
            $this->assertSame('the-one-account', $config->secret('fulfillment_username'));
        }

        $this->assertSame([], $this->connections()->countriesWithTheirOwnAccount(self::TOROD), 'and it is not a country of its own');
    }

    public function test_a_second_country_keeps_its_own_credentials_and_the_first_is_untouched(): void
    {
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: 'https://sa.test', settings: [], secrets: ['fulfillment_username' => 'saudi-account']);
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: 'https://kw.test', settings: [], secrets: ['fulfillment_username' => 'kuwaiti-account'], country: 'KW');

        $kuwait = $this->connections()->config(self::TOROD, 'KW');
        $platform = $this->connections()->config(self::TOROD);

        $this->assertSame('kuwaiti-account', $kuwait->secret('fulfillment_username'));
        $this->assertSame('https://kw.test', $kuwait->baseUrl);
        $this->assertSame('saudi-account', $platform->secret('fulfillment_username'), 'the account that was there is untouched');
        $this->assertSame('https://sa.test', $platform->baseUrl);
        $this->assertSame(['KW'], $this->connections()->countriesWithTheirOwnAccount(self::TOROD));
        $this->assertSame(2, IntegrationSetting::where('provider', self::TOROD->code())->count(), 'two rows, one code');
    }

    /** A country with no account of its own still reads the platform-wide one, which is what makes the second account additive. */
    public function test_a_country_without_an_account_of_its_own_reads_the_platform_wide_one(): void
    {
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: 'https://sa.test', settings: [], secrets: ['fulfillment_username' => 'saudi-account']);
        $this->connections()->save(provider: self::TOROD, enabled: false, baseUrl: 'https://kw.test', settings: [], secrets: ['fulfillment_username' => 'kuwaiti-account'], country: 'KW');

        $bahrain = $this->connections()->config(self::TOROD, 'BH');

        $this->assertSame('saudi-account', $bahrain->secret('fulfillment_username'));
        $this->assertTrue($bahrain->enabled, 'and it reads that account\'s switch, not the Kuwaiti one');
        $this->assertFalse($this->connections()->canCall(self::TOROD, 'KW'), 'while the Kuwaiti account is switched off on its own');
        $this->assertTrue($this->connections()->canCall(self::TOROD, 'BH'));
    }

    /** A second account starts from the carrier's defaults and not from the first account's settings. */
    public function test_a_second_account_does_not_inherit_the_first_accounts_settings(): void
    {
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: ['company_id' => '714'], secrets: []);
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: [], secrets: [], country: 'KW');

        $this->assertSame('714', $this->connections()->config(self::TOROD)->get('company_id'));
        $this->assertNotSame(
            '714',
            $this->connections()->config(self::TOROD, 'KW')->get('company_id'),
            'the Kuwaiti account must not be handed the Saudi company id',
        );
    }

    /**
     * Nor its go-live moment. `go_live_at` is what stops orders placed before automatic dispatch went live from being shipped
     * retroactively, so a second account inheriting the first's would sweep out every order of its own country that had been waiting.
     */
    public function test_a_second_account_does_not_inherit_the_first_accounts_go_live_moment(): void
    {
        $live = ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false];
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: $live, secrets: []);

        $this->assertNotNull($this->connections()->config(self::TOROD)->get('go_live_at'), 'the first account is live');

        // The second is made in the controlled phase: enabled, but still a dry run, so it is NOT live and has no go-live moment at all.
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => true], secrets: [], country: 'KW');

        $this->assertNull(
            $this->connections()->config(self::TOROD, 'KW')->get('go_live_at'),
            'a second account that is not live has no go-live moment, whatever the first account did',
        );
        $this->assertNotNull($this->connections()->config(self::TOROD)->get('go_live_at'), 'and the first account keeps its own');
    }

    /**
     * And when the second account DOES go live, it gets its OWN moment.
     *
     * This is the case the before-state has to be read from the right row for. Read from the account a reader would fall back to, the
     * platform-wide one is already live, so going live would look like no change at all and no moment would be stamped -- leaving the second
     * account live with nothing to stop every order of its country that had been waiting from being swept out at once.
     */
    public function test_a_second_account_going_live_gets_its_own_moment(): void
    {
        $live = ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false];
        $this->travelTo('2026-11-01 09:00:00');
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: $live, secrets: []);

        $this->travelTo('2026-11-20 09:00:00');
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: $live, secrets: [], country: 'KW');

        $this->assertSame('2026-11-20 09:00:00', $this->connections()->config(self::TOROD, 'KW')->get('go_live_at'), 'the day IT went live');
        $this->assertSame('2026-11-01 09:00:00', $this->connections()->config(self::TOROD)->get('go_live_at'), 'and the first account keeps the day it did');
    }

    /** A token the system obtains is written to the account it was obtained for. */
    public function test_a_runtime_token_is_written_to_the_account_it_belongs_to(): void
    {
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: [], secrets: []);
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: [], secrets: [], country: 'KW');

        $this->connections()->putRuntimeSecret(self::TOROD, 'access_token', 'kuwaiti-token', country: 'KW');

        $this->assertSame('kuwaiti-token', $this->connections()->config(self::TOROD, 'KW')->secret('access_token'));
        $this->assertNull($this->connections()->config(self::TOROD)->secret('access_token'), 'the platform-wide account did not get it');
    }

    /** The country is compared byte for byte, as the column is declared, and what this class writes is upper case. */
    public function test_the_country_is_compared_byte_for_byte_and_what_is_written_is_upper_case(): void
    {
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: [], secrets: ['fulfillment_username' => 'platform-wide']);
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: [], secrets: ['fulfillment_username' => 'kuwaiti'], country: ' kw ');

        $this->assertSame('KW', IntegrationSetting::where('provider', self::TOROD->code())->where('country', '<>', '')->value('country'));
        $this->assertSame('kuwaiti', $this->connections()->config(self::TOROD, 'KW')->secret('fulfillment_username'));
        $this->assertSame('kuwaiti', $this->connections()->config(self::TOROD, 'kw')->secret('fulfillment_username'), 'the reader upper-cases what it is asked too');
    }

    /** A country that is not two letters is not a country: it reads the platform-wide account rather than creating a row of nonsense. */
    public function test_a_country_that_is_not_a_country_reads_the_platform_wide_account(): void
    {
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: [], secrets: ['fulfillment_username' => 'platform-wide']);
        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: [], secrets: ['fulfillment_username' => 'nonsense'], country: 'not-a-country');

        $this->assertSame(1, IntegrationSetting::where('provider', self::TOROD->code())->count(), 'no second row was made');
        $this->assertSame('nonsense', $this->connections()->config(self::TOROD)->secret('fulfillment_username'), 'it wrote the platform-wide row');
        $this->assertSame([], $this->connections()->countriesWithTheirOwnAccount(self::TOROD));
    }

    /** Two countries can both list a city numbered 1, which is what the old unique key made impossible. */
    public function test_two_countries_can_both_list_a_city_with_the_same_external_id(): void
    {
        $this->cityOf(country: ProviderCityCountry::PLATFORM_WIDE, externalId: '1', nameEn: 'Riyadh');
        $this->cityOf(country: 'KW', externalId: '1', nameEn: 'Kuwait City');

        $this->assertSame(2, ProviderCity::where('provider', self::TOROD->code())->where('external_id', '1')->count());
    }

    /** And the key still bites inside one list, which is the whole reason the column is `''` rather than NULL. */
    public function test_one_country_cannot_list_the_same_city_twice(): void
    {
        $this->cityOf(country: ProviderCityCountry::PLATFORM_WIDE, externalId: '1', nameEn: 'Riyadh');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->cityOf(country: ProviderCityCountry::PLATFORM_WIDE, externalId: '1', nameEn: 'Riyadh again');
    }

    /** A lookup reads ONE list: the country's own when it has imported one. */
    public function test_a_country_with_its_own_list_is_resolved_from_that_list_alone(): void
    {
        $this->cityOf(country: ProviderCityCountry::PLATFORM_WIDE, externalId: '1', nameEn: 'Riyadh');
        $this->cityOf(country: 'KW', externalId: '1', nameEn: 'Kuwait City');
        $this->cityOf(country: 'KW', externalId: '2', nameEn: 'Hawalli');
        app(ProviderCityCountry::class)->forget();

        $resolver = app(TorodCityResolver::class);

        $this->assertSame('Kuwait City', $resolver->resolve('Kuwait City', country: 'KW')?->name_en);
        $this->assertNull($resolver->resolve('Riyadh', country: 'KW'), 'the Saudi list is not read for a Kuwaiti parcel');
        $this->assertSame('Riyadh', $resolver->resolve('Riyadh')?->name_en, 'and a lookup that names no country reads the platform-wide list');
    }

    /** A country that has imported no list of its own reads the platform-wide one, which is every country today. */
    public function test_a_country_with_no_list_of_its_own_reads_the_platform_wide_list(): void
    {
        $this->cityOf(country: ProviderCityCountry::PLATFORM_WIDE, externalId: '1', nameEn: 'Riyadh');
        app(ProviderCityCountry::class)->forget();

        $this->assertSame('Riyadh', app(TorodCityResolver::class)->resolve('Riyadh', country: 'BH')?->name_en);
        $this->assertSame(ProviderCityCountry::PLATFORM_WIDE, app(ProviderCityCountry::class)->listFor(provider: self::TOROD->code(), country: 'BH'));
        $this->assertSame(
            ProviderCityCountry::PLATFORM_WIDE,
            app(ProviderCityCountry::class)->listFor(provider: self::TOROD->code(), country: 'KW'),
            'Kuwait has imported nothing, so it reads the platform-wide list too',
        );

        $this->cityOf(country: 'KW', externalId: '9', nameEn: 'Hawalli');
        app(ProviderCityCountry::class)->forget();

        $this->assertSame('KW', app(ProviderCityCountry::class)->listFor(provider: self::TOROD->code(), country: 'KW'), 'and its own list once it has one');
    }

    /** Before the migration there is no country to read, and every answer is the one this platform has always given. */
    public function test_before_the_migration_nothing_is_narrowed(): void
    {
        $this->migration()->down();
        app(ProviderCityCountry::class)->forget();

        $this->assertFalse(Schema::hasColumn('provider_cities', ProviderCityCountry::COLUMN));
        $this->assertFalse(app(ProviderCityCountry::class)->applies());

        DB::table('provider_cities')->insert(['provider' => self::TOROD->code(), 'external_id' => '1', 'name_en' => 'Riyadh', 'normalized_en' => 'riyadh', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('Riyadh', app(TorodCityResolver::class)->resolve('Riyadh', country: 'KW')?->name_en, 'nothing is narrowed, so the one list answers');

        $this->connections()->save(provider: self::TOROD, enabled: true, baseUrl: null, settings: [], secrets: ['fulfillment_username' => 'the-one-account'], country: 'KW');
        $this->assertSame('the-one-account', $this->connections()->config(self::TOROD, 'KW')->secret('fulfillment_username'));
        $this->assertSame('the-one-account', $this->connections()->config(self::TOROD)->secret('fulfillment_username'), 'one row, whatever country was named');
    }

    /** Rolling back is refused once a row names a country, because the country cannot be worked out again. */
    public function test_the_rollback_is_refused_once_a_row_names_a_country(): void
    {
        $this->cityOf(country: 'KW', externalId: '1', nameEn: 'Kuwait City');

        try {
            $this->migration()->down();
            $this->fail('The rollback must be refused while a row names a country.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Rolling this migration back would forget', $refusal->getMessage());
        }
    }

    /** And it runs twice without complaint, as every migration of this plan must. */
    public function test_the_migration_runs_twice_without_complaint(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('integration_settings', 'country'));
        $this->assertTrue(Schema::hasColumn('provider_cities', ProviderCityCountry::COLUMN));
    }

    private function cityOf(string $country, string $externalId, string $nameEn): void
    {
        DB::table('provider_cities')->insert([
            'provider' => self::TOROD->code(),
            ProviderCityCountry::COLUMN => $country,
            'external_id' => $externalId,
            'name_en' => $nameEn,
            'normalized_en' => mb_strtolower($nameEn),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
