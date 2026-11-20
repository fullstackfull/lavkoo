<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\LedgerChain;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * E-17.2 of the master plan: a running balance on the seller ledger belongs to one seller, one selling company and one currency, and a settlement is drawn per chain.
 *
 * Everything in the first group runs with `gcc.ledgers.keyed_by_entity_and_currency` on. The second group runs with it off and pins the shipped platform, where a seller has
 * one chain over everything — which is the live Saudi position and has to stay exactly as it is. The third group is an installation whose `vendor_ledger_entries` has no
 * company column at all, which is every installation that has not run a migration for one: `vendor_ledger_entries` has no migration in this repository, so the live table came
 * from the installation dump and the tables here are built from the columns the code writes.
 *
 * NOT COVERED: a payout. PayoutService still draws on `withdrawable()` for the seller as a whole, so a seller with money in two chains can still be paid out of the sum of
 * them; a payout has no currency to be drawn in yet. Nothing here runs several processes at once either — the lock is pinned only as "a second entry of the same chain reads
 * the balance the first one wrote", which is what the single-process test can see; tests/Feature/Concurrency holds the ones that need a real engine and several processes.
 */
class LedgerChainTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private const COMPANY_SA = 11;

    private const COMPANY_AE = 12;

    private VendorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorSettlementTables();
        $this->useDefaultCurrency('SAR');
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);
        $this->ledger = app(VendorLedger::class);
    }

    private function chain(?int $company, ?string $currency, int $sellerId = self::SELLER): LedgerChain
    {
        return $this->ledger->chain(sellerId: $sellerId, legalEntityId: $company, currency: $currency);
    }

    private function balanceOf(?int $company, ?string $currency): float
    {
        return $this->ledger->balances(sellerId: self::SELLER, chain: $this->chain(company: $company, currency: $currency))['balance'];
    }

    public function test_money_of_two_currencies_runs_in_two_balances_of_its_own(): void
    {
        $riyals = $this->earning(100, 'SAR');
        $dirhams = $this->earning(40, 'AED');
        $moreRiyals = $this->earning(25, 'SAR');

        $this->assertSame(100.0, $riyals->balance_after);
        $this->assertSame(40.0, $dirhams->balance_after, 'the first dirham entry opens a balance of its own and does not read the riyal one');
        $this->assertSame(125.0, $moreRiyals->balance_after, 'the second riyal entry reads the riyal balance, not the sum of everything');
    }

    public function test_money_of_two_selling_companies_runs_in_two_balances_of_its_own(): void
    {
        $saudi = $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $emirati = $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertSame(100.0, $saudi->balance_after);
        $this->assertSame(60.0, $emirati->balance_after, 'the same currency sold by another company is another chain');
    }

    public function test_an_entry_that_names_no_currency_belongs_to_the_base_currency_chain(): void
    {
        $unlabelled = $this->withoutACurrency($this->earning(100, null));
        $riyals = $this->earning(30, 'SAR');

        $this->assertNull($unlabelled->currency);
        $this->assertSame(130.0, $riyals->balance_after, 'years of unlabelled history stay in one piece with the base currency');
        $this->assertSame(130.0, $this->balanceOf(company: null, currency: 'SAR'));
        $this->assertSame(130.0, $this->balanceOf(company: null, currency: null), 'the chain of no currency is the chain of the base currency');
    }

    public function test_a_currency_written_in_another_case_or_with_spaces_is_the_same_chain(): void
    {
        $this->withoutACurrency($this->earning(100, 'SAR'), ' sar ');
        $next = $this->earning(10, 'SAR');

        $this->assertSame(110.0, $next->balance_after);
    }

    public function test_a_blank_currency_belongs_to_the_base_currency_chain(): void
    {
        $this->withoutACurrency($this->earning(100, 'SAR'), '   ');
        $next = $this->earning(10, 'SAR');

        $this->assertSame(110.0, $next->balance_after);
    }

    public function test_another_sellers_money_is_never_in_this_sellers_chain(): void
    {
        $this->earning(100, 'SAR');
        $other = $this->earning(500, 'SAR', sellerId: self::ANOTHER_SELLER);

        $this->assertSame(500.0, $other->balance_after);
        $this->assertSame(100.0, $this->balanceOf(company: null, currency: 'SAR'));
    }

    public function test_a_chain_answers_for_its_own_money_only(): void
    {
        $this->earning(100, 'SAR');
        $this->charge(30, 'SAR');
        $this->earning(40, 'AED');

        $this->assertSame(70.0, $this->balanceOf(company: null, currency: 'SAR'));
        $this->assertSame(40.0, $this->balanceOf(company: null, currency: 'AED'));
        $this->assertSame(0.0, $this->balanceOf(company: null, currency: 'KWD'), 'a chain with nothing in it is empty, not the seller total');
    }

    public function test_a_reader_that_names_no_chain_still_gets_the_sellers_whole_position(): void
    {
        $this->earning(100, 'SAR');
        $this->earning(40, 'AED');

        $this->assertSame(140.0, $this->ledger->balances(self::SELLER)['balance'], 'the screens that show a seller everything they have are not narrowed by this change');
        $this->assertSame(140.0, $this->ledger->withdrawable(self::SELLER));
    }

    public function test_what_can_be_withdrawn_out_of_one_chain_excludes_the_other_chains(): void
    {
        $this->earning(100, 'SAR');
        $this->earning(40, 'AED');
        $this->earning(70, 'SAR', ['status' => VendorLedgerEntry::STATUS_PENDING]);

        $this->assertSame(
            100.0,
            $this->ledger->withdrawable(sellerId: self::SELLER, chain: $this->chain(company: null, currency: 'SAR')),
            'the riyal chain is its own available money less its own pending money',
        );
        $this->assertSame(40.0, $this->ledger->withdrawable(sellerId: self::SELLER, chain: $this->chain(company: null, currency: 'AED')));
    }

    public function test_an_entry_records_the_company_that_sold_it(): void
    {
        $entry = $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertSame(self::COMPANY_AE, $entry->refresh()->legal_entity_id);
    }

    public function test_a_settlement_never_nets_two_companies_together(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(2, $settlements, 'one settlement for each company, not one of 160');
        $this->assertSame(
            [self::COMPANY_SA => 100.0, self::COMPANY_AE => 60.0],
            collect($settlements)->mapWithKeys(fn ($settlement): array => [(int) $settlement->legal_entity_id => (float) $settlement->net_amount])->all(),
        );
    }

    public function test_the_waiting_report_names_a_line_for_each_company_and_currency(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);
        $this->earning(40, 'AED', ['legalEntityId' => self::COMPANY_AE]);

        $lines = collect(app(SettlementEngine::class)->waiting(sellerId: self::SELLER))
            ->map(fn (array $line): array => [$line['legal_entity_id'], $line['currency'], $line['net']])
            ->sortBy([1, 0])
            ->values()
            ->all();

        $this->assertSame([
            [self::COMPANY_AE, 'AED', 40.0],
            [self::COMPANY_SA, 'SAR', 100.0],
            [self::COMPANY_AE, 'SAR', 60.0],
        ], $lines);
    }

    /**
     * History decides whether a settlement can report balances, and it decides it per settlement.
     *
     * These two entries were written before the chains were told apart, so `balance_after` ran 100 then
     * 160 over both companies. The Saudi settlement's last entry is the first one, and up to there the
     * ledger really was one chain, so its 0 and 100 are its own opening and closing balance. The Emirati
     * settlement's last entry is the second, and by then the running balance had both companies in it,
     * so it is not that settlement's closing balance and the settlement records neither.
     */
    public function test_a_settlement_drawn_after_the_running_balance_mixed_companies_reports_no_balances(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => false, 'gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);
        config(['gcc.ledgers.keyed_by_entity_and_currency' => true]);

        $balances = collect(app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER))
            ->mapWithKeys(fn ($settlement): array => [
                (int) $settlement->legal_entity_id => [$settlement->opening_balance, $settlement->closing_balance],
            ])
            ->all();

        $this->assertSame([self::COMPANY_SA => [0.0, 100.0], self::COMPANY_AE => [null, null]], $balances);
    }

    public function test_the_company_is_recorded_even_while_the_chains_are_not_told_apart(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => false]);

        $entry = $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertSame(self::COMPANY_AE, $entry->refresh()->legal_entity_id, 'the column is filled before the switch is thrown, or there would be no history to split');
    }

    public function test_with_the_switch_off_a_seller_has_the_one_chain_the_platform_has_always_had(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => false]);

        $riyals = $this->earning(100, 'SAR');
        $dirhams = $this->earning(40, 'AED');
        $emirati = $this->earning(10, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertSame(100.0, $riyals->balance_after);
        $this->assertSame(140.0, $dirhams->balance_after, 'off means the balance runs over everything, which is the platform of today');
        $this->assertSame(150.0, $emirati->balance_after);
    }

    public function test_with_the_switch_off_one_settlement_takes_both_companies(): void
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => false, 'gcc.settlements.one_currency' => true]);
        $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);

        $settlements = app(SettlementEngine::class)->calculateEachCurrency(sellerId: self::SELLER);

        $this->assertCount(1, $settlements);
        $this->assertSame(160.0, (float) $settlements[0]->net_amount);
    }

    public function test_a_ledger_with_no_company_column_is_keyed_by_seller_and_currency_alone(): void
    {
        Schema::table('vendor_ledger_entries', fn ($table) => $table->dropColumn(LedgerChain::ENTITY_COLUMN));

        $saudi = $this->earning(100, 'SAR', ['legalEntityId' => self::COMPANY_SA]);
        $emirati = $this->earning(60, 'SAR', ['legalEntityId' => self::COMPANY_AE]);
        $dirhams = $this->earning(40, 'AED', ['legalEntityId' => self::COMPANY_AE]);

        $this->assertFalse(LedgerChain::entityIsRecorded());
        $this->assertSame(100.0, $saudi->balance_after);
        $this->assertSame(160.0, $emirati->balance_after, 'with nowhere to record the company, the currency is the whole of the key it can use');
        $this->assertSame(40.0, $dirhams->balance_after, 'the currency still tells one chain from another');
    }
}
