<?php

namespace Tests\Feature\Address;

use App\Models\AddressCity;
use App\Models\AddressCityAlias;
use App\Services\Address\AddressText;
use App\Services\Address\CityAliasNamespace;
use App\Services\Address\LegacyCityMatcher;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\RestoresLanguageFiles;

/**
 * Two same-named cities in two countries can both be aliased, and neither country is given the other's spelling (H1-10).
 *
 * `address_city_aliases.normalized_alias` was unique across the whole table. For one country that is exactly right -- one spelling points
 * at one city. For six it is wrong twice over: the second country to record a shared spelling of its OWN city is refused, and until it is
 * refused the legacy matcher resolves a customer's typed city to the FIRST country's city, which is how a parcel crosses a border with
 * nothing on the order to say so.
 *
 * `''` is the default storefront's namespace, which is where every alias on this platform already is, and the default storefront's own
 * code normalises back to `''` so that one market can never hold two namespaces and the unique key stop biting.
 */
class CityAliasNamespaceTest extends AddressTestCase
{
    use RestoresLanguageFiles;

    private const MIGRATION = '2026_12_28_100000_add_country_to_address_city_aliases.php';

    private const SHARED_SPELLING = 'Al Khobar';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        app(CityAliasNamespace::class)->forget();
    }

    public function test_the_default_storefronts_namespace_is_the_empty_one_that_every_alias_is_already_in(): void
    {
        $namespaces = app(CityAliasNamespace::class);

        $this->assertTrue($namespaces->applies());
        $this->assertSame(CityAliasNamespace::DEFAULT_STOREFRONT, $namespaces->of('SA'), 'the default market normalises back, so it never has two namespaces');
        $this->assertSame(CityAliasNamespace::DEFAULT_STOREFRONT, $namespaces->of(null));
        $this->assertSame(CityAliasNamespace::DEFAULT_STOREFRONT, $namespaces->of('ZZ'), 'a country we have no storefront for has nowhere else to go');
        $this->assertSame('KW', $namespaces->of('kw'), 'and a market of our own keeps its own, upper case');
    }

    /** Before the migration there is one namespace, which is the same answer: nothing is narrowed and nothing is stamped. */
    public function test_before_the_migration_nothing_is_narrowed_or_stamped(): void
    {
        $migration = require database_path('migrations/' . self::MIGRATION);
        $migration->down();
        app(CityAliasNamespace::class)->forget();

        $namespaces = app(CityAliasNamespace::class);
        $this->assertFalse($namespaces->applies());
        $this->assertSame([], $namespaces->stamp('KW'), 'an insert written then is the insert this platform has always written');
        $this->assertSame(CityAliasNamespace::DEFAULT_STOREFRONT, $namespaces->of('KW'));

        $migration->up();
        app(CityAliasNamespace::class)->forget();
        $this->assertTrue(Schema::hasColumn('address_city_aliases', CityAliasNamespace::COLUMN), 'and the table is put back for whatever runs next');
    }

    public function test_two_countries_can_both_record_the_same_spelling_of_their_own_city(): void
    {
        [$saudi, $kuwaiti] = $this->twoCitiesNamedTheSame();
        $namespaces = app(CityAliasNamespace::class);

        $this->aliasFor($saudi);
        $this->aliasFor($kuwaiti);

        $this->assertSame(
            [CityAliasNamespace::DEFAULT_STOREFRONT => $saudi->id, 'KW' => $kuwaiti->id],
            DB::table('address_city_aliases')->pluck('city_id', CityAliasNamespace::COLUMN)->map(static fn ($id): int => (int) $id)->all(),
        );
        $this->assertSame(CityAliasNamespace::DEFAULT_STOREFRONT, $namespaces->forCity($saudi));
        $this->assertSame('KW', $namespaces->forCity($kuwaiti));
    }

    /** Within ONE namespace the old rule still holds, and it is the database that holds it: one spelling, one city. */
    public function test_within_one_country_a_spelling_still_points_at_one_city_only(): void
    {
        [$saudi] = $this->twoCitiesNamedTheSame();
        $anotherSaudiCity = $this->cityIn($this->regionIn('SA', 'SA-EAS2', 'Eastern Two'), 'الخبر الثانية', 'Khobar Two');

        $this->aliasFor($saudi);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->aliasFor($anotherSaudiCity);
    }

    /**
     * And the reader: the legacy matcher resolves a typed city through the namespace of the storefront being served, so a spelling
     * confirmed for Kuwait's city is not what a Saudi order's text resolves to.
     */
    public function test_a_spelling_confirmed_for_one_country_does_not_resolve_another_countrys_typed_city(): void
    {
        [$saudi, $kuwaiti] = $this->twoCitiesNamedTheSame();
        $this->aliasFor($kuwaiti);

        $this->serving('SA');
        $this->assertNotSame($kuwaiti->id, app(LegacyCityMatcher::class)->match(self::SHARED_SPELLING)->city?->id, 'the Saudi storefront is not given Kuwait\'s spelling');

        $this->serving('KW');
        $this->assertSame($kuwaiti->id, app(LegacyCityMatcher::class)->match(self::SHARED_SPELLING)->city?->id, 'and Kuwait is given its own');

        $this->serving('SA');
        $this->aliasFor($saudi);
        $this->assertSame($saudi->id, app(LegacyCityMatcher::class)->match(self::SHARED_SPELLING)->city?->id);
    }

    /** Rolling back cannot decide which of two countries' identical spellings to throw away, so it is refused while one names a country. */
    public function test_the_rollback_is_refused_while_an_alias_names_a_country(): void
    {
        [, $kuwaiti] = $this->twoCitiesNamedTheSame();
        $this->aliasFor($kuwaiti);
        $migration = require database_path('migrations/' . self::MIGRATION);

        try {
            $migration->down();
            $this->fail('the rollback was allowed and one country\'s spelling would have been thrown away');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('country', $refused->getMessage());
        }

        DB::table('address_city_aliases')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('address_city_aliases', CityAliasNamespace::COLUMN));

        $migration->up();
        app(CityAliasNamespace::class)->forget();
        $this->assertTrue(Schema::hasColumn('address_city_aliases', CityAliasNamespace::COLUMN), 'and the table is put back for whatever runs next');
    }

    /** @return array{0: AddressCity, 1: AddressCity} the same city name in Saudi Arabia and in Kuwait */
    private function twoCitiesNamedTheSame(): array
    {
        return [
            $this->cityIn($this->regionIn('SA', 'SA-EAS', 'Eastern Province'), 'الخبر', 'Al Khobar'),
            $this->cityIn($this->regionIn('KW', 'KW-KU', 'Kuwait'), 'الخبر', 'Al Khobar'),
        ];
    }

    private function aliasFor(AddressCity $city): AddressCityAlias
    {
        $namespaces = app(CityAliasNamespace::class);
        $key = AddressText::key(self::SHARED_SPELLING);

        return AddressCityAlias::create($namespaces->stamp($namespaces->forCity($city)) + [
            'city_id' => $city->id,
            'alias' => self::SHARED_SPELLING,
            'normalized_alias' => $key,
            'source' => 'admin',
        ]);
    }

    private function regionIn(string $country, string $code, string $english): int
    {
        return (int) DB::table('address_regions')->insertGetId([
            'country_code' => $country, 'code' => $code, 'name_ar' => $english, 'name_en' => $english,
            'normalized_ar' => AddressText::key($english), 'normalized_en' => AddressText::key($english),
            'sort_order' => 1, 'is_active' => true, 'source' => 'test', 'source_ref' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cityIn(int $regionId, string $arabic, string $english): AddressCity
    {
        $id = DB::table('address_cities')->insertGetId([
            'region_id' => $regionId, 'name_ar' => $arabic, 'name_en' => $english,
            'normalized_ar' => AddressText::key($arabic), 'normalized_en' => AddressText::key($english),
            'sort_order' => 50, 'is_active' => true, 'source' => 'test', 'source_ref' => $regionId . '-' . $english,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return AddressCity::query()->with('region')->findOrFail($id);
    }

    private function serving(string $country): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        app()->instance(StorefrontContext::class, new StorefrontContext(country: $country, currency: $country === 'KW' ? 'KWD' : 'SAR', storefrontsEnabled: true));
    }
}
