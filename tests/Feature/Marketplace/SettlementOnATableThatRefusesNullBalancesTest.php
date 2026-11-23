<?php

namespace Tests\Feature\Marketplace;

use App\Models\VendorSettlement;
use App\Services\Marketplace\SettlementEngine;
use Illuminate\Support\Facades\Artisan;
use App\Services\Marketplace\ABalanceCannotBeLeftUnknown;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\WritesSellerLedger;
use Tests\TestCase;

/**
 * A settlement drawn in one currency from a ledger that mixes currencies records no opening and no closing balance (E-17.1,
 * SettlementEngine), which `vendor_settlements` has to be able to hold. There is no migration for that table in this
 * repository, so whether its `opening_balance` and `closing_balance` accept NULL in an installation is not known. Here the
 * table is built so that they do not, which is one of the things an installation may hold.
 *
 * What then happens, with `gcc.settlements.one_currency` on: a seller whose ledger is in ONE currency is settled exactly as
 * before, because no balance of theirs is unknown. A seller whose ledger MIXES currencies is refused, by name, and nothing of
 * theirs is claimed. A run over every seller settles the first kind, leaves the second, says which sellers it left, and ends
 * in failure, so that a night which did not finish its work is not reported as one that did. With the switch off the two
 * columns are always given a number, so nothing changes at all.
 */
class SettlementOnATableThatRefusesNullBalancesTest extends TestCase
{
    use BuildsWalletAndVendorLedgerSchema;
    use WritesSellerLedger;

    private SettlementEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildVendorSettlementTables(balancesAcceptNull: false);
        $this->useDefaultCurrency(code: 'SAR');
        $this->engine = app(SettlementEngine::class);
    }

    /**
     * The first currency of a mixed ledger is settled with exact balances: up to its last entry the ledger is in one currency.
     * It is the currency AFTER it whose balances are unknown, so one draw of one settlement is not refused at all.
     */
    public function test_with_the_switch_on_the_first_currency_of_a_mixed_ledger_is_still_settled_with_its_balances(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(amount: 100.5, currency: 'SAR');
        $kuwaitiEntry = $this->earning(amount: 12.345, currency: 'KWD');

        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(['SAR', 0.0, 100.5, 100.5, 1], [$settlement->currency, $settlement->opening_balance, $settlement->net_amount, $settlement->closing_balance, $settlement->entry_count]);
        $this->assertSame([$kuwaitiEntry->id], $this->unclaimedEntries(), 'the other currency is left for a settlement of its own');
    }

    /** Asking for the second currency of that ledger is refused, by name, and claims nothing. */
    public function test_with_the_switch_on_a_currency_whose_balances_are_unknown_is_refused_by_name(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');

        try {
            $this->engine->calculateEachCurrency(sellerId: self::SELLER);
            $this->fail('a settlement was drawn although a balance of it could not be left unknown');
        } catch (ABalanceCannotBeLeftUnknown $refusal) {
            $this->assertSame(self::SELLER, $refusal->sellerId);
            $this->assertStringContainsString('do not accept NULL', $refusal->getMessage());
            $this->assertStringContainsString('gcc.settlements.one_currency', $refusal->getMessage());
        }

        $this->assertSame(0, VendorSettlement::count(), 'the settlement of the first currency is taken back with the refusal of the second');
        $this->assertCount(2, $this->unclaimedEntries());
    }

    /** The common case: a shop that sells in one currency settles as it always did, whatever its columns allow. */
    public function test_with_the_switch_on_a_seller_whose_ledger_is_in_one_currency_is_settled_as_before(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 20.25, currency: 'SAR');

        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(['SAR', 0.0, 120.75, 120.75, 2], [$settlement->currency, $settlement->opening_balance, $settlement->net_amount, $settlement->closing_balance, $settlement->entry_count]);
        $this->assertSame([], $this->unclaimedEntries());
    }

    /** One seller who needs a column changed must not leave every seller after them unsettled. */
    public function test_with_the_switch_on_a_run_settles_the_sellers_it_can_and_names_the_ones_it_leaves(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->earning(amount: 60.0, currency: 'SAR', sellerId: self::ANOTHER_SELLER);

        $settlements = $this->engine->calculateForAll();

        $this->assertSame([self::ANOTHER_SELLER], array_map(static fn ($s): int => (int) $s->seller_id, $settlements));
        $left = $this->engine->sellersWhoseBalanceCannotBeLeftUnknown();
        $this->assertCount(1, $left);
        $this->assertSame(self::SELLER, $left[0]->sellerId);
        $this->assertCount(2, $this->unclaimedEntries(sellerId: self::SELLER), 'nothing of the seller that was left is claimed');
    }

    public function test_the_engine_says_the_table_cannot_leave_a_balance_unknown_and_still_reports_what_is_waiting(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');

        $this->assertFalse($this->engine->canLeaveABalanceUnknown());
        $this->assertCount(2, $this->engine->waiting());
    }

    public function test_with_the_switch_on_the_dry_run_says_the_run_would_refuse_and_fails(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');

        $exitCode = Artisan::call('marketplace:settle', ['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Dry run: the run would refuse to draw. opening_balance and closing_balance of vendor_settlements must accept NULL first. Nothing was written.', Artisan::output());
        $this->assertSame(0, VendorSettlement::count());
    }

    public function test_with_the_switch_on_the_run_ends_in_failure_and_names_the_seller_it_left(): void
    {
        config(['gcc.settlements.one_currency' => true]);
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.345, currency: 'KWD');
        $this->earning(amount: 60.0, currency: 'SAR', sellerId: self::ANOTHER_SELLER);

        $exitCode = Artisan::call('marketplace:settle');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('1 seller(s) were left unsettled', $output);
        $this->assertStringContainsString((string) self::SELLER, $output);
        $this->assertSame(1, VendorSettlement::count(), 'the seller whose ledger is in one currency was settled all the same');
    }

    public function test_with_the_switch_off_the_engine_draws_as_before_because_it_always_writes_a_number(): void
    {
        $this->earning(amount: 100.5, currency: 'SAR');
        $this->earning(amount: 12.25, currency: 'KWD');

        $settlement = $this->engine->calculate(sellerId: self::SELLER);

        $this->assertSame(['SAR', 0.0, 112.75, 112.75, 2], [$settlement->currency, $settlement->opening_balance, $settlement->net_amount, $settlement->closing_balance, $settlement->entry_count]);
        $this->assertSame(0, Artisan::call('marketplace:settle', ['--dry-run' => true]));
    }
}
