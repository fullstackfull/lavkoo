<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Inventory\InventoryLocationMarket;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * A place stock lives is in a country, and says so (G1-05, E11-F19).
 *
 * `inventory_locations` carried `seller_id`, a type and a provider and nothing else, so the only country a stock screen could be asked
 * about was the PRODUCT's -- which answers for the units on the shelf and never for the shelf. That is why the staff country scope stopped
 * at the stock screens: a restricted employee of Kuwait could be shown the Saudi warehouse itself, with its code, its name and its figures.
 *
 * The first test is the one that protects what exists: with storefronts off nothing is narrowed, nothing is stamped, and the three
 * locations every installation has are the three it has always had.
 */
class InventoryLocationMarketTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const SAUDI = 'SA';

    private const KUWAIT = 'KW';

    private const EMIRATES = 'AE';

    private const MIGRATION = '2026_12_27_100000_add_market_and_company_to_inventory_locations.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        app(InventoryLocationMarket::class)->forget();
    }

    public function test_the_migration_adds_a_nullable_market_and_company_and_leaves_every_location_unmarked(): void
    {
        $this->assertTrue(Schema::hasColumn('inventory_locations', InventoryLocationMarket::COLUMN));
        $this->assertTrue(Schema::hasColumn('inventory_locations', InventoryLocationMarket::COMPANY_COLUMN));

        app(InventoryLocationService::class)->ensureDefaults();

        $this->assertSame(
            [null, null, null],
            DB::table('inventory_locations')->orderBy('id')->pluck(InventoryLocationMarket::COLUMN)->all(),
            'the three platform locations are the default storefront\'s and nothing writes a country nobody declared',
        );
    }

    /**
     * The market is compared BYTE FOR BYTE, as every other market column of this platform is.
     *
     * The column is declared `ascii_bin` on MySQL and MariaDB, where a `char(2)` otherwise takes the server's own case-INSENSITIVE
     * collation and `kw` would match `KW`. SQLite compares bytes anyway, so this test only proves something on a real engine -- which is
     * exactly why it is here and not reasoned about: the same mistake cost CI run 664 one failure.
     */
    public function test_a_market_written_in_lower_case_is_not_the_market(): void
    {
        $ids = $this->locationsOf(['kuwait-city' => 'kw']);

        $this->assertSame(
            [],
            DB::table('inventory_locations')->where('id', $ids['kuwait-city'])->where(InventoryLocationMarket::COLUMN, self::KUWAIT)->pluck('code')->all(),
            "'kw' is not 'KW': the column compares bytes, so what is written must be upper case",
        );

        // And the row IS there: without this the assertion above would pass for an empty table, which is how the first version of this
        // test passed on SQLite while asking for a column named `key` -- SQLite reads an unknown double-quoted name as a string.
        $this->assertSame(
            ['kuwait-city'],
            DB::table('inventory_locations')->where('id', $ids['kuwait-city'])->where(InventoryLocationMarket::COLUMN, 'kw')->pluck('code')->all(),
        );
    }

    /**
     * Rolling back would forget which country a warehouse is in, which cannot be worked out again from what is left, so it is refused
     * while any location names one. The table is put back afterwards, because every class that runs after this one in the same process
     * needs it.
     */
    public function test_the_rollback_is_refused_while_a_location_names_a_country(): void
    {
        $this->locationsOf(['kuwait-city' => self::KUWAIT]);
        $migration = require database_path('migrations/' . self::MIGRATION);

        try {
            $migration->down();
            $this->fail('the rollback was allowed and the country was forgotten');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('country', $refused->getMessage());
        }

        DB::table('inventory_locations')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('inventory_locations', InventoryLocationMarket::COLUMN), 'with no country named, the rollback goes through');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('inventory_locations', InventoryLocationMarket::COLUMN), 'and the table is put back for whatever runs next');
        $this->assertTrue(Schema::hasColumn('inventory_locations', InventoryLocationMarket::COMPANY_COLUMN));
    }

    public function test_with_storefronts_off_nothing_is_narrowed_and_nothing_is_stamped(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->locationsOf(['riyadh' => self::SAUDI, 'kuwait-city' => self::KUWAIT, 'legacy' => null]);

        $this->assertFalse(app(InventoryLocationMarket::class)->applies());
        $this->assertSame(['kuwait-city', 'legacy', 'riyadh'], $this->listed(self::SAUDI));
        $this->assertNull(app(InventoryLocationMarket::class)->stampForAccount($this->account(self::EMIRATES)));
    }

    public function test_a_storefront_lists_its_own_locations_and_the_unmarked_ones_belong_to_the_default(): void
    {
        $this->storefrontsOn();
        $this->locationsOf(['riyadh' => self::SAUDI, 'kuwait-city' => self::KUWAIT, 'legacy' => null]);

        $this->assertSame(['legacy', 'riyadh'], $this->listed(self::SAUDI), 'the default storefront keeps the rows that name no market');
        $this->assertSame(['kuwait-city'], $this->listed(self::KUWAIT), 'and another market inherits none of them');
    }

    public function test_an_fba_location_is_filed_in_the_country_its_account_sells_in(): void
    {
        $this->storefrontsOn();

        $emirati = app(InventoryLocationService::class)->ensureFbaLocation($this->account(self::EMIRATES));

        $this->assertSame(self::EMIRATES, DB::table('inventory_locations')->where('id', $emirati->id)->value(InventoryLocationMarket::COLUMN));
    }

    /** An account whose settings name no country -- or two -- gives none, and the location stays the default storefront's. */
    public function test_an_account_that_names_no_country_leaves_its_location_unmarked(): void
    {
        $this->storefrontsOn();

        $unknown = app(InventoryLocationService::class)->ensureFbaLocation($this->account(''));

        $this->assertNull(DB::table('inventory_locations')->where('id', $unknown->id)->value(InventoryLocationMarket::COLUMN));
    }

    public function test_a_restricted_employee_is_covered_only_for_the_locations_of_their_own_countries(): void
    {
        $this->storefrontsOn();
        $ids = $this->locationsOf(['riyadh' => self::SAUDI, 'kuwait-city' => self::KUWAIT, 'legacy' => null]);
        $market = app(InventoryLocationMarket::class);

        $this->assertTrue($market->allLocationsBelongTo([$ids['riyadh'], $ids['legacy']], [self::SAUDI]));
        $this->assertFalse($market->allLocationsBelongTo([$ids['riyadh'], $ids['kuwait-city']], [self::SAUDI]));
        $this->assertTrue($market->allLocationsBelongTo([$ids['kuwait-city']], [self::KUWAIT]));
        $this->assertFalse($market->allLocationsBelongTo([$ids['legacy']], [self::KUWAIT]), 'an unmarked location is the default storefront\'s, not everyone\'s');
        $this->assertFalse($market->allLocationsBelongTo([$ids['riyadh'], 777777], [self::SAUDI]), 'a location that does not exist is not covered');
    }

    public function test_the_staff_scope_asks_the_same_question_through_its_own_door(): void
    {
        $this->storefrontsOn();
        $ids = $this->locationsOf(['riyadh' => self::SAUDI, 'kuwait-city' => self::KUWAIT]);
        $scope = app(StaffCountryScope::class);

        $this->assertTrue($scope->coversEveryInventoryLocation([$ids['kuwait-city']]), 'an unnarrowed request may work with every location');

        $this->narrowTo([self::KUWAIT]);

        $this->assertTrue($scope->coversEveryInventoryLocation([$ids['kuwait-city']]));
        $this->assertFalse($scope->coversEveryInventoryLocation([$ids['riyadh']]));
        $this->assertFalse($scope->coversEveryInventoryLocation([$ids['kuwait-city'], $ids['riyadh']]));
    }

    public function test_a_listing_narrowed_to_an_employees_countries_shows_only_theirs(): void
    {
        $this->storefrontsOn();
        $this->locationsOf(['riyadh' => self::SAUDI, 'kuwait-city' => self::KUWAIT, 'legacy' => null]);
        $market = app(InventoryLocationMarket::class);

        $this->assertSame(['kuwait-city'], $this->codes($market->narrowToCountries(InventoryLocation::query(), [self::KUWAIT])));
        $this->assertSame(['legacy', 'riyadh'], $this->codes($market->narrowToCountries(InventoryLocation::query(), [self::SAUDI])));
        $this->assertSame([], $this->codes($market->narrowToCountries(InventoryLocation::query(), [])), 'an employee restricted to nothing sees nothing');
    }

    /** @param array<string, string|null> $markets code => the market it is in */
    private function locationsOf(array $markets): array
    {
        $ids = [];
        foreach ($markets as $code => $country) {
            $ids[$code] = (int) DB::table('inventory_locations')->insertGetId([
                'code' => $code,
                'name' => $code,
                'seller_id' => 0,
                'type' => InventoryLocation::TYPE_OWN,
                'source_of_truth' => InventoryLocation::TRUTH_MANUAL,
                'storefront_sellable' => true,
                'is_active' => true,
                InventoryLocationMarket::COLUMN => $country,
            ]);
        }

        return $ids;
    }

    /** @return list<string> the codes a storefront lists, in id order */
    private function listed(string $country): array
    {
        return $this->codes(app(InventoryLocationMarket::class)->narrow(InventoryLocation::query(), $country));
    }

    /** @param \Illuminate\Database\Eloquent\Builder<InventoryLocation> $query */
    private function codes($query): array
    {
        return $query->orderBy('code')->pluck('code')->all();
    }

    /** An Amazon account of one Gulf marketplace, which is how ChannelAccountCountry knows which country it sells in. */
    private function account(string $marketplaceCountry): SalesChannelAccount
    {
        $marketplaces = ['SA' => 'A17E79C6D8DWNP', 'AE' => 'A2VIGQ35RCS4UG'];
        $suffix = $marketplaceCountry === '' ? 'none' : strtolower($marketplaceCountry);

        $id = DB::table('sales_channel_accounts')->insertGetId([
            'channel' => 'amazon',
            'name' => 'Amazon ' . $suffix,
            'seller_id' => 0,
            'environment' => 'production',
            'external_account_id' => 'acct-' . $suffix,
            'marketplace_ids' => json_encode(isset($marketplaces[$marketplaceCountry]) ? [$marketplaces[$marketplaceCountry]] : []),
            'currency' => 'SAR',
            'settings' => json_encode(['region' => 'eu']),
            'credentials' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SalesChannelAccount::findOrFail($id);
    }

    private function storefrontsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        app()->instance(StorefrontContext::class, new StorefrontContext(country: self::SAUDI, currency: 'SAR', storefrontsEnabled: true));
        app(InventoryLocationMarket::class)->forget();
    }

    /** @param list<string> $countries */
    private function narrowTo(array $countries): void
    {
        config(['gcc.staff.country_scopes' => true]);
        $request = Request::create('/admin/inventory');
        $request->attributes->set('staff_country_scope', $countries);
        $request->attributes->set('staff_country_scope_subjects', ['inventory_locations']);
        app()->instance('request', $request);
    }
}
