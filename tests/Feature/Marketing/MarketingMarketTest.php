<?php

namespace Tests\Feature\Marketing;

use App\Services\Address\AddressCountry;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\MarketingMarket;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Turning the country text an address holds into the market a consent is filed under.
 *
 * This is the layer that decides WHOSE consent is asked about, so it is tested directly as well as through the
 * senders: a resolver that quietly answered "Saudi Arabia" for everybody would let a Kuwaiti customer be judged by a
 * Saudi consent, and every customer in a Saudi-only fixture would hide it.
 */
class MarketingMarketTest extends MarketingConsentTestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function gccSpellings(): array
    {
        return [
            'Saudi, English' => ['Saudi Arabia', 'SA'],
            'Saudi, code' => ['SA', 'SA'],
            'Saudi, KSA' => ['K.S.A.', 'SA'],
            'Saudi, Arabic' => ['المملكة العربية السعودية', 'SA'],
            'Emirates, English' => ['United Arab Emirates', 'AE'],
            'Emirates, code' => ['AE', 'AE'],
            'Emirates, Arabic' => ['الإمارات العربية المتحدة', 'AE'],
            'Kuwait, English' => ['Kuwait', 'KW'],
            'Kuwait, Arabic' => ['الكويت', 'KW'],
            'Qatar, English' => ['Qatar', 'QA'],
            'Qatar, Arabic' => ['قطر', 'QA'],
            'Bahrain, English' => ['Bahrain', 'BH'],
            'Bahrain, Arabic' => ['مملكة البحرين', 'BH'],
            'Oman, English' => ['Sultanate of Oman', 'OM'],
            'Oman, Arabic' => ['سلطنة عمان', 'OM'],
        ];
    }

    #[DataProvider('gccSpellings')]
    public function test_every_spelling_of_a_gcc_country_resolves_to_its_iso_code(string $written, string $expected): void
    {
        $this->assertSame($expected, AddressCountry::isoCode($written), "'{$written}' did not resolve to {$expected}");
    }

    public function test_a_country_outside_the_six_markets_resolves_to_nothing(): void
    {
        foreach (['Egypt', 'Jordan', 'United Kingdom', 'EG', '', '   '] as $outside) {
            $this->assertNull(AddressCountry::isoCode($outside), "'{$outside}' resolved to a market it is not.");
        }

        $this->assertNull(AddressCountry::isoCode(null));
    }

    /** The existing answer must not have moved: isSaudi() is read by the address book in four places. */
    public function test_adding_the_iso_codes_did_not_change_what_counts_as_saudi(): void
    {
        foreach (['Saudi Arabia', 'SA', 'ksa', 'K.S.A.', 'المملكة العربية السعودية', 'السعودية'] as $saudi) {
            $this->assertTrue(AddressCountry::isSaudi($saudi), "'{$saudi}' stopped counting as Saudi.");
            $this->assertSame('SA', AddressCountry::isoCode($saudi));
        }

        foreach (['Kuwait', 'KW', 'United Arab Emirates', 'Egypt', '', null] as $notSaudi) {
            $this->assertFalse(AddressCountry::isSaudi($notSaudi), "'{$notSaudi}' started counting as Saudi.");
        }
    }

    public function test_a_customer_is_filed_under_the_market_of_their_own_address(): void
    {
        $saudi = $this->makeCustomer(email: 'market-sa@example.test', country: 'Saudi Arabia');
        $kuwaiti = $this->makeCustomer(email: 'market-kw@example.test', country: 'Kuwait');
        $emirati = $this->makeCustomer(email: 'market-ae@example.test', country: 'الإمارات العربية المتحدة');

        $this->assertSame('SA', MarketingMarket::forCustomer(customerId: (int) $saudi->id));
        $this->assertSame('KW', MarketingMarket::forCustomer(customerId: (int) $kuwaiti->id));
        $this->assertSame('AE', MarketingMarket::forCustomer(customerId: (int) $emirati->id));
    }

    public function test_the_newest_address_decides_when_a_customer_has_moved(): void
    {
        $customer = $this->makeCustomer(email: 'moved@example.test', country: 'Saudi Arabia');
        DB::table('shipping_addresses')->insert([
            'customer_id' => (string) $customer->id,
            'is_guest' => 0,
            'contact_person_name' => 'Moved',
            'country' => 'Qatar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('QA', MarketingMarket::forCustomer(customerId: (int) $customer->id));
    }

    /** An address naming a country outside the six markets is passed over for one that does name a market. */
    public function test_an_address_outside_the_six_markets_is_passed_over(): void
    {
        $customer = $this->makeCustomer(email: 'outside@example.test', country: 'Bahrain');
        DB::table('shipping_addresses')->insert([
            'customer_id' => (string) $customer->id,
            'is_guest' => 0,
            'contact_person_name' => 'Abroad',
            'country' => 'United Kingdom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('BH', MarketingMarket::forCustomer(customerId: (int) $customer->id));
    }

    public function test_a_customer_with_no_address_at_all_is_filed_under_the_home_market(): void
    {
        $customer = $this->makeCustomer(email: 'no-address@example.test', country: null);

        $this->assertSame('SA', MarketingMarket::forCustomer(customerId: (int) $customer->id));
        $this->assertSame('SA', MarketingMarket::forCustomer(customerId: null));
        $this->assertSame('SA', MarketingMarket::homeMarket());
    }

    /** A guest's address is not a customer's address: the two are kept apart by `is_guest`. */
    public function test_a_guests_address_is_not_read_for_a_registered_customer(): void
    {
        $customer = $this->makeCustomer(email: 'registered@example.test', country: 'Saudi Arabia');
        DB::table('shipping_addresses')->insert([
            'customer_id' => (string) $customer->id,
            'is_guest' => 1,
            'contact_person_name' => 'Same id, a guest',
            'country' => 'Oman',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('SA', MarketingMarket::forCustomer(customerId: (int) $customer->id));
        $this->assertSame('OM', MarketingMarket::forCustomer(customerId: (int) $customer->id, isGuest: true));
    }

    /**
     * The six markets are the six the address book knows, and a constant cannot compute itself.
     *
     * MarketingConsent::MARKETS has to be the five AddressCountry::GCC_SPELLINGS keys plus Saudi Arabia, which
     * AddressCountry answers from its own SAUDI list. If a seventh market is added to one place and not the other, a
     * consent would be recorded for a market the gate refuses to recognise — or the reverse.
     */
    public function test_the_six_markets_are_the_markets_the_address_book_knows(): void
    {
        $fromTheAddressBook = array_merge(['SA'], array_keys(AddressCountry::GCC_SPELLINGS));
        sort($fromTheAddressBook);

        $markets = MarketingConsent::MARKETS;
        sort($markets);

        $this->assertSame($fromTheAddressBook, $markets);

        foreach (MarketingConsent::MARKETS as $market) {
            $this->assertTrue(MarketingConsent::isMarket($market));
            $this->assertTrue(MarketingConsent::isMarket(mb_strtolower($market)), 'A market has to be recognised however it is spelled.');
        }
    }

    /**
     * A HOME MARKET THAT IS NOT A MARKET IS IGNORED, and this is the setting that made it matter.
     *
     * `market` is char(2). An independent review set GCC_MARKETING_CONSENT_HOME_MARKET to 'Saudi Arabia' — the exact
     * spelling AddressCountry::LEGACY_NAME and the shipping_addresses.country column both use — and measured the same
     * public unsubscribe route behaving three different ways: on SQLite it worked; on a non-strict MariaDB the value
     * was asked about as 'SAUDI ARABIA', SILENTLY STORED as 'SA', never matched again, so the person saw the "you have
     * been unsubscribed" page while the gate kept allowing the send and every click wrote another row; under the
     * strict-mode job it raised SQLSTATE 22001. One typed setting is not allowed to do any of that.
     */
    public function test_a_home_market_that_is_not_one_of_the_six_is_ignored(): void
    {
        foreach (['Saudi Arabia', 'SAU', 'FR', '', '  ', 'Kuwait'] as $misconfigured) {
            Config::set('marketing.consent.home_market', $misconfigured);
            $this->assertSame('SA', MarketingMarket::homeMarket(), "A home market of '{$misconfigured}' must not reach the column.");
        }

        // A real market, spelled either way, is still honoured.
        Config::set('marketing.consent.home_market', 'ae');
        $this->assertSame('AE', MarketingMarket::homeMarket());
    }

    /**
     * A CUSTOMER OUTSIDE THE SIX MARKETS INHERITS THE HOME MARKET'S REGIME. Recorded here because it is a consequence
     * nothing stated, not because it is settled: the owner's answer to Discovery Q-15 is per market, and if Saudi
     * Arabia becomes opt_out then a customer in France becomes opt_out with it. That is an OWNER DECISION — see the
     * pull request's open list.
     */
    public function test_a_customer_outside_the_six_markets_is_filed_under_the_home_market(): void
    {
        $customer = $this->makeCustomer(email: 'france@example.test', country: 'France');

        $this->assertSame('SA', MarketingMarket::forCustomer(customerId: (int) $customer->id));
    }
}
