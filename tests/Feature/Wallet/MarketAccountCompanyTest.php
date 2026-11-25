<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Models\LegalEntity;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Wallet\CustomerMarketBalances;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A customer's market account is stamped with the company that sells in THAT market (GCC-13, E-01).
 *
 * `CustomerMarketBalances` recorded the single platform-wide `gcc.storefronts.legal.legal_entity` on every
 * market's wallet and loyalty account, above a comment saying it would until per-market entities existed. They
 * exist: `legal_entities` holds one row per country, `country` is UNIQUE on it, and the platform-admin market
 * list, the health answer and the per-market reports all read it that way. The company on a money account is who
 * the customer's balance is a liability OF, so one company's name on six markets' accounts is a false statement
 * about whose money it is -- and a Kuwaiti balance labelled with the Saudi company is the kind of record a
 * regulator reads.
 *
 * The middle case here is the one worth reading: a market the registry does NOT name records NOTHING rather than
 * the platform-wide value. Labelling that market's account with another company would look correct and be wrong,
 * and the project's rule is that an absent answer beats a guessed one. The account still opens -- refusing a
 * customer's credit over a missing company record would move a bookkeeping gap onto their money.
 *
 * NOTHING IS BACK-FILLED and no case here rewrites a row: accounts already open keep the company they were
 * stamped with, and whether those are restated is the owner's. `companiesThatDoNotMatchTheirMarket()` counts them
 * without touching one, which is the figure that decision needs.
 */
/*
 * NAMED TO SORT WITH ITS SIBLINGS, DELIBERATELY. `BuildsMarketAccountSchema` calls `Schema::dropAllTables()`, which is why
 * tests/Feature/Wallet runs as a CI job of its own -- and why the ORDER inside the directory matters: the classes that run before the first
 * `MarketAccount*` class rely on tables an earlier class left behind. Added as `AMarketAccount…` this file ran FIRST and took the database
 * from four of them (24 errors, 10 failures, all "table doesn't exist"). As `MarketAccountCompanyTest` it sits among the siblings that already
 * drop everything, and the order every other class sees is the order it saw before. The real repair -- every class building what it needs --
 * is a harness job across the whole directory and not this change's.
 */
class MarketAccountCompanyTest extends TestCase
{
    use BuildsMarketAccountSchema;
    use RestoresLanguageFiles;

    private const CUSTOMER = 1;

    /** The one value every market's account used to carry. */
    private const PLATFORM_WIDE = 'Lavkoo Trading Co.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildMarketAccountSchema();
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->seedCustomer(self::CUSTOMER);
        $this->theLegalEntitiesTable();

        config(['gcc.storefronts.legal.legal_entity' => self::PLATFORM_WIDE]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('legal_entities');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The real migration, so `country` carries the unique key the registry relies on. */
    private function theLegalEntitiesTable(): void
    {
        Schema::dropIfExists('legal_entities');
        (require database_path('migrations/2026_11_27_100000_create_legal_entities_table.php'))->up();
    }

    private function theRegistryIsInForce(): void
    {
        config(['gcc.storefronts.legal_entities.enabled' => true]);
        app(LegalEntityRegistry::class)->flush();
    }

    private function aCompanyFor(string $country, string $legalName, bool $active = true): void
    {
        LegalEntity::query()->create([
            'country' => $country,
            'legal_name' => $legalName,
            'trade_name' => $legalName,
            'tax_registration_number' => '30000000000' . strlen($legalName),
            'commercial_registration_number' => '101010' . strlen($legalName),
            'status' => $active,
        ]);
        app(LegalEntityRegistry::class)->flush();
    }

    /** Opens the account the way a credit does, and answers the company it was stamped with. */
    private function theCompanyStampedOn(string $market, MarketBalanceKind $kind = MarketBalanceKind::Wallet): ?string
    {
        $change = app(CustomerMarketBalances::class)->credit(kind: $kind, customerId: self::CUSTOMER, market: $market, amount: 10.0);

        $this->assertTrue($change->applied, "the credit to {$market} was refused: " . ($change->refusal?->value ?? '-'));

        return DB::table($kind->accountsTable())->where(['customer_id' => self::CUSTOMER, 'market' => $market])->value('legal_entity');
    }

    // ---------------------------------------------------------------- today's platform

    /** The registry is off, so the one platform-wide value is still the answer and no Saudi account changes. */
    public function test_with_the_registry_off_the_one_platform_wide_company_is_still_recorded(): void
    {
        $this->assertSame(self::PLATFORM_WIDE, $this->theCompanyStampedOn('SA'));
    }

    public function test_with_the_registry_off_but_not_migrated_the_platform_wide_company_is_still_recorded(): void
    {
        Schema::dropIfExists('legal_entities');
        app(LegalEntityRegistry::class)->flush();

        $this->assertSame(self::PLATFORM_WIDE, $this->theCompanyStampedOn('SA'));
    }

    // ---------------------------------------------------------------- each market's own company

    /** THE CASE THE DEFECT WAS: two markets, two companies, two accounts, and the accounts say so. */
    public function test_two_markets_accounts_carry_two_different_companies(): void
    {
        $this->theRegistryIsInForce();
        $this->aCompanyFor('SA', 'Lavkoo Saudi Arabia');
        $this->aCompanyFor('KW', 'Lavkoo Kuwait');

        $saudi = $this->theCompanyStampedOn('SA');
        $kuwaiti = $this->theCompanyStampedOn('KW');

        $this->assertSame('Lavkoo Saudi Arabia', $saudi);
        $this->assertSame('Lavkoo Kuwait', $kuwaiti);
        $this->assertNotSame($saudi, $kuwaiti, 'both accounts carry one company again');
    }

    public function test_the_loyalty_account_is_stamped_the_same_way_as_the_wallet(): void
    {
        $this->theRegistryIsInForce();
        $this->aCompanyFor('KW', 'Lavkoo Kuwait');

        $this->assertSame('Lavkoo Kuwait', $this->theCompanyStampedOn('KW', MarketBalanceKind::Loyalty));
    }

    // ---------------------------------------------------------------- an absent answer, never a guessed one

    /**
     * A market the registry does not name records NOTHING, and the account still opens. The platform-wide value
     * is deliberately not used: it would look correct and be wrong about whose liability the balance is.
     */
    public function test_a_market_the_registry_does_not_name_records_no_company_at_all(): void
    {
        $this->theRegistryIsInForce();
        $this->aCompanyFor('SA', 'Lavkoo Saudi Arabia');

        $this->assertNull($this->theCompanyStampedOn('KW'), "the Saudi company's name was put on a Kuwaiti account");
        $this->assertNotNull(
            DB::table(MarketBalanceKind::Wallet->accountsTable())->where(['customer_id' => self::CUSTOMER, 'market' => 'KW'])->value('id'),
            'the account was not opened, so a bookkeeping gap refused a customer their money',
        );
    }

    /** A company whose row is switched off is not the company that sells there, so nothing is recorded. */
    public function test_a_company_that_is_switched_off_records_nothing(): void
    {
        $this->theRegistryIsInForce();
        $this->aCompanyFor('KW', 'Lavkoo Kuwait', active: false);

        $this->assertNull($this->theCompanyStampedOn('KW'));
    }

    /**
     * A truncated company name is a DIFFERENT COMPANY to any reader, and MySQL truncates silently while this
     * application's connection is not strict. The account column is 64 characters and `legal_entities.legal_name`
     * is 191, so the case is reachable: nothing is recorded and the length is logged.
     */
    public function test_a_company_name_too_long_for_the_column_records_nothing_rather_than_a_truncation(): void
    {
        $this->theRegistryIsInForce();
        $tooLong = str_repeat('Lavkoo Kuwait Trading and Distribution ', 3);
        $this->assertGreaterThan(CustomerMarketBalances::COMPANY_NAME_LIMIT, mb_strlen($tooLong));
        $this->aCompanyFor('KW', $tooLong);

        $this->assertNull($this->theCompanyStampedOn('KW'), 'a company name was truncated into a company that does not exist');
    }

    // ---------------------------------------------------------------- the count the owner needs

    /** Read only: it counts the rows opened before the registry was read and touches none of them. */
    public function test_the_rows_that_do_not_match_their_market_are_counted_and_not_rewritten(): void
    {
        // Two accounts opened the old way, both carrying the platform-wide company.
        $this->theCompanyStampedOn('SA');
        $this->theCompanyStampedOn('KW');

        $this->theRegistryIsInForce();
        $this->aCompanyFor('SA', self::PLATFORM_WIDE);
        $this->aCompanyFor('KW', 'Lavkoo Kuwait');

        $found = app(CustomerMarketBalances::class)->companiesThatDoNotMatchTheirMarket();

        $this->assertSame(1, $found['wallet']['KW']['mismatched'], "the Kuwaiti account carries the platform's company and is not counted");
        $this->assertSame(0, $found['wallet']['SA']['mismatched'], 'the Saudi account carries its own company and must not be counted');
        $this->assertSame('Lavkoo Kuwait', $found['wallet']['KW']['expected']);
        $this->assertSame(self::PLATFORM_WIDE, DB::table('customer_market_wallet_accounts')->where('market', 'KW')->value('legal_entity'), 'a row was rewritten');
    }

    /** An account whose market has no company at all is counted apart: nothing says what it should have carried. */
    public function test_an_account_whose_market_has_no_company_is_counted_apart(): void
    {
        $this->theCompanyStampedOn('KW');
        $this->theRegistryIsInForce();

        $found = app(CustomerMarketBalances::class)->companiesThatDoNotMatchTheirMarket();

        $this->assertTrue($found['wallet']['KW']['market_has_no_company']);
        $this->assertSame(0, $found['wallet']['KW']['mismatched'], 'a market with no company cannot have a mismatch');
        $this->assertSame(1, $found['wallet']['KW']['accounts']);
    }
}
