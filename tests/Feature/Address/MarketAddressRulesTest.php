<?php

namespace Tests\Feature\Address;

use App\Enums\Address\AddressChannel;
use App\Services\Address\AddressCatalog;
use App\Services\Address\AddressSchema;
use App\Services\Address\AddressText;
use App\Services\ShippingAddressService;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Two rules a six-market address system needs, and the one thing both must not do (A2-06, A2-12).
 *
 * **The LIST belongs to the market being served.** A region carries its own `country_code` and a city takes its region's, and
 * nothing read them: the Kuwaiti storefront's checkout offered the Saudi regions, so a customer could have given Riyadh as their
 * Kuwaiti city and the order would have looked perfectly well formed. The market comes from `StorefrontCatalog::country()`, the
 * one reader that already knows which requests belong to a storefront and which work across every country.
 *
 * **The PHONE rule belongs to the address's own market.** The only rule was the Saudi one, and it was applied only to Saudi
 * addresses -- so an address in the other five markets was asked for no number at all. A Kuwaiti mobile is eight digits and
 * could never have matched a Saudi pattern, so leaving the check out was the only thing that did not refuse every Kuwaiti
 * address. Each market's rule is now read from the one table of per-market mobile facts the platform already keeps, which is
 * the table fulfilment holds a shipment on, so what a customer may save and what a courier may be given cannot drift apart.
 *
 * **What neither does is narrow a place looked up by its ID.** A storefront may deliver to another country and an order keeps
 * the place it was placed with for good, so an id that names a real place resolves whichever market is asking. The last case
 * here is exactly that, because it is the thing a later "tidy-up" would quietly break.
 *
 * Both are dormant: with storefronts off every list is the list this store has always had, and with the address mode off no
 * phone rule applies at all.
 */
class MarketAddressRulesTest extends AddressTestCase
{
    /** @var array<string, int> */
    private array $saudi;

    private int $kuwaitiRegion;

    private int $kuwaitCity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = $this->seedAddressCatalog();
        [$this->kuwaitiRegion, $this->kuwaitCity] = $this->seedKuwait();
    }

    private function catalog(): AddressCatalog
    {
        return app(AddressCatalog::class);
    }

    // ---- the lists ---------------------------------------------------------------------------------------------------

    public function test_each_storefront_is_offered_the_regions_of_its_own_country(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame(
            ['Riyadh', 'Makkah', 'Eastern Province'],
            $this->regionNamesIn('SA'),
            'the Saudi storefront is offered exactly the regions it has always been offered',
        );
        $this->assertSame(['Al Asimah'], $this->regionNamesIn('KW'), 'and the Kuwaiti storefront only Kuwait\'s');
    }

    public function test_each_storefront_searches_the_cities_of_its_own_country(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame(['Al Khobar'], $this->cityNamesIn('SA', 'khobar'), 'the Saudi storefront finds the Saudi city');
        $this->assertSame([], $this->cityNamesIn('KW', 'khobar'), 'and the Kuwaiti storefront does not, because Khobar is not in Kuwait');
        $this->assertSame(['Kuwait City'], $this->cityNamesIn('KW', 'kuwait'), 'it finds its own');
        $this->assertSame([], $this->cityNamesIn('SA', 'kuwait'), 'which the Saudi storefront does not');
    }

    public function test_a_list_asked_for_with_no_search_text_is_narrowed_too(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame(['Kuwait City'], $this->cityNamesIn('KW', null), 'the whole list of a market is that market\'s');
        $this->assertCount(11, $this->contextOf('SA')->serve(fn () => $this->catalog()->searchCities(limit: 100)), 'the Saudi list is every Saudi city, as before');
    }

    public function test_with_storefronts_off_every_list_is_the_list_this_store_has_always_had(): void
    {
        $this->assertSame(
            ['Riyadh', 'Makkah', 'Eastern Province', 'Al Asimah'],
            array_map(static fn (object $region): string => (string) $region->name_en, $this->catalog()->regions()->all()),
            'dormant: nothing is narrowed and the imported rows are all there is',
        );
        $this->assertSame(['Al Khobar'], array_map(static fn (object $city): string => (string) $city->name_en, $this->catalog()->searchCities(query: 'khobar')->all()));
    }

    public function test_a_request_that_belongs_to_no_one_market_reads_every_country(): void
    {
        $this->switchStorefrontsOn();
        $this->app->instance('request', Request::create('/admin/address/cities'));

        $this->assertSame(
            ['Riyadh', 'Makkah', 'Eastern Province', 'Al Asimah'],
            array_map(static fn (object $region): string => (string) $region->name_en, $this->catalog()->regions(onlyActive: false)->all()),
            'the panel manages every market\'s list, so it is never narrowed to one',
        );
    }

    public function test_a_place_looked_up_by_its_id_is_found_from_any_market(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame(
            ['Kuwait City', 'Al Khobar'],
            [
                (string) $this->contextOf('SA')->serve(fn () => $this->catalog()->city($this->kuwaitCity))?->name_en,
                (string) $this->contextOf('KW')->serve(fn () => $this->catalog()->city($this->saudi['khobar']))?->name_en,
            ],
            'an order keeps the place it was placed with, so an id resolves whichever market is asking',
        );
        $this->assertNotNull(
            $this->contextOf('SA')->serve(fn () => $this->catalog()->activeCityInRegion($this->kuwaitCity, $this->kuwaitiRegion)),
            'and the pair a client submits is judged against the master, not against the storefront it was submitted from',
        );
    }

    // ---- the phone rule ----------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function numbersOfTheSixMarkets(): array
    {
        return [
            'a Saudi mobile' => ['Saudi Arabia', '0501234567', true],
            'a Saudi mobile written internationally' => ['Saudi Arabia', '+966501234567', true],
            'a Kuwaiti number in a Saudi address' => ['Saudi Arabia', '51234567', false],
            'a Kuwaiti mobile' => ['Kuwait', '51234567', true],
            'a Kuwaiti mobile written internationally' => ['Kuwait', '+96551234567', true],
            'a Saudi number in a Kuwaiti address' => ['Kuwait', '0501234567', false],
            'an Emirati mobile' => ['United Arab Emirates', '0501234567', true],
            'a Qatari mobile' => ['Qatar', '33123456', true],
            'a Qatari address given a nine-digit number' => ['Qatar', '331234567', false],
            'a Bahraini mobile' => ['Bahrain', '36123456', true],
            'an Omani mobile' => ['Oman', '91234567', true],
            'an Omani address given six digits' => ['Oman', '912345', false],
        ];
    }

    #[DataProvider('numbersOfTheSixMarkets')]
    public function test_each_market_judges_a_mobile_number_by_its_own_rule(string $country, string $phone, bool $accepted): void
    {
        $this->useAddressMode(AddressSchema::MODE_STRICT);

        $refused = $this->refusalFor(country: $country, phone: $phone);

        $this->assertSame($accepted, $refused === null, $refused ?? 'accepted');
    }

    public function test_a_country_outside_the_six_markets_is_judged_by_nobody(): void
    {
        $this->useAddressMode(AddressSchema::MODE_STRICT);

        $this->assertNull(
            $this->refusalFor(country: 'Egypt', phone: '1001234567'),
            'the platform has no numbering plan for a country it does not sell in, and does not invent one',
        );
    }

    public function test_with_the_address_mode_off_no_market_asks_for_a_number_at_all(): void
    {
        foreach (['Saudi Arabia', 'Kuwait'] as $country) {
            $this->assertNull($this->refusalFor(country: $country, phone: 'not a phone number at all'), $country);
        }
    }

    public function test_a_client_that_may_still_send_the_old_fields_is_asked_for_nothing_new(): void
    {
        $this->useAddressMode(AddressSchema::MODE_SOFT);

        $this->assertNull(
            $this->refusalFor(country: 'Kuwait', phone: '0501234567', channel: AddressChannel::Api),
            'the mobile app may keep sending what it sends until the store is strict',
        );
        $this->assertNotNull(
            $this->refusalFor(country: 'Kuwait', phone: '0501234567'),
            'while the website, which must give the structured address, is held to the market\'s rule',
        );
    }

    private function refusalFor(string $country, string $phone, AddressChannel $channel = AddressChannel::Web): ?string
    {
        try {
            app(ShippingAddressService::class)->assertPhone(phone: $phone, country: $country, channel: $channel);
        } catch (ValidationException $exception) {
            return implode(' ', $exception->errors()['phone'] ?? ['refused']);
        }

        return null;
    }

    /** @return list<string> */
    private function regionNamesIn(string $market): array
    {
        return array_map(
            static fn (object $region): string => (string) $region->name_en,
            $this->contextOf($market)->serve(fn () => $this->catalog()->regions())->all(),
        );
    }

    /** @return list<string> */
    private function cityNamesIn(string $market, ?string $query): array
    {
        return array_map(
            static fn (object $city): string => (string) $city->name_en,
            $this->contextOf($market)->serve(fn () => $this->catalog()->searchCities(query: $query))->all(),
        );
    }

    private function contextOf(string $market): StorefrontContext
    {
        return StorefrontResolver::fromConfig()->contextOfRecordedCountry($market);
    }

    private function switchStorefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
    }

    private function useAddressMode(string $mode): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => AddressSchema::SETTING], ['value' => $mode]);
        AddressSchema::flush();
    }

    /** One Kuwaiti region and one Kuwaiti city, which is what a second market's imported list looks like. @return array{0: int, 1: int} */
    private function seedKuwait(): array
    {
        $region = DB::table('address_regions')->insertGetId([
            'country_code' => 'KW', 'code' => 'KW-KU', 'name_ar' => 'محافظة العاصمة', 'name_en' => 'Al Asimah',
            'normalized_ar' => AddressText::key('محافظة العاصمة'), 'normalized_en' => AddressText::key('Al Asimah'),
            'sort_order' => 4, 'is_active' => true, 'source' => 'test', 'source_ref' => 'kw-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $city = DB::table('address_cities')->insertGetId([
            'region_id' => $region, 'name_ar' => 'مدينة الكويت', 'name_en' => 'Kuwait City',
            'normalized_ar' => AddressText::key('مدينة الكويت'), 'normalized_en' => AddressText::key('Kuwait City'),
            'sort_order' => 50, 'is_active' => true, 'source' => 'test', 'source_ref' => 'kw-city-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        AddressSchema::flush();

        return [$region, $city];
    }
}
