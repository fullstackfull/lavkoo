<?php

namespace Tests\Feature\Wallet;

use App\Services\Customer\WalletMinorUnit;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Wallet\WalletTables;
use Tests\Support\Wallet\WalletWriters;
use Tests\TestCase;

/**
 * One credit is one amount, whichever of the five writers of a wallet balance is asked (E-16).
 *
 * Each writer computed its credit where it stood and none of them rounded it, so a refund and a referral reward of one amount reached the
 * ledger as whatever float the path's own conversion produced -- and in Kuwait, Bahrain and Oman that can be a figure with more places
 * than the currency has coins for. `WalletMinorUnit::credited()` is the one helper all five now round through.
 *
 * The figure is **10.0155**: 10.02 at two places and 10.016 at three, so every assertion reads a number that could only have come from one
 * of the two units. The ledger columns here are `decimal(40, 20)`, the shipped precision, so a stored figure is the figure the writer
 * computed and not the column's rounding of it.
 *
 * `loyalty_point` is deliberately NOT one of the types below: the five writers convert points by formulas that disagree (LF-1 and LF-2,
 * open financial decision 1), so what they credit for a number of points differs for a reason this switch does not touch and must not
 * appear to settle. What is asserted of that type is only that it lands ON the unit, which is this switch's whole claim.
 */
class EveryWriterCreditsTheSameAmountTest extends TestCase
{
    private const CUSTOMER_ID = 1;

    /** 10.02 at two places, 10.016 at three. */
    private const AMOUNT = 10.0155;

    private const AT_TWO_PLACES = 10.02;

    protected function setUp(): void
    {
        parent::setUp();

        WalletTables::create();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
        WalletTables::seedCustomer(self::CUSTOMER_ID, 0.0);
    }

    /**
     * The two credit types every one of the five writers credits the amount itself for: a refund and the type a referral reward is paid
     * under. `return_order_amount_by_admin` is not here because two of the five do not recognise it and credit nothing, which is a
     * disagreement of its own and older than this.
     *
     * @return array<string, array{path: string, transactionType: string}>
     */
    public static function everyWriterAndCreditType(): array
    {
        $cases = [];
        foreach (WalletWriters::PATHS as $path) {
            foreach (['order_refund', 'add_fund_by_admin'] as $transactionType) {
                $cases["{$path}, {$transactionType}"] = ['path' => $path, 'transactionType' => $transactionType];
            }
        }

        return $cases;
    }

    // ---- the switch is off: every writer stores what it has always stored ------------------------------------------------

    /**
     * Off, a credit is handed back untouched -- not rounded at two places, which is the whole difference between this switch and the debit
     * side's. The stored figure carries all four of its places.
     */
    #[DataProvider('everyWriterAndCreditType')]
    public function test_with_the_switch_off_every_writer_stores_the_credit_unrounded(string $path, string $transactionType): void
    {
        $this->assertFalse((bool) config(WalletMinorUnit::CREDITS_SWITCH), 'the credit unit ships off');

        WalletWriters::write($path, (string) self::CUSTOMER_ID, $transactionType, 'one-credit', self::AMOUNT);

        $this->assertSame(self::AMOUNT, (float) WalletTables::ledgerRow()->credit, "{$path} must store the credit it always stored");
        $this->assertSame(self::AMOUNT, WalletTables::walletBalance(self::CUSTOMER_ID), 'and move the balance by it');
    }

    /** Off, the helper is not asked anything: the amount comes back as the same float, places and all, for every market. */
    public function test_with_the_switch_off_the_helper_hands_the_amount_straight_back(): void
    {
        $this->assertSame(self::AMOUNT, WalletMinorUnit::credited(self::AMOUNT));
        $this->assertSame(0.0005, WalletMinorUnit::credited(0.0005), 'a figure below the smallest coin is not rounded away');
    }

    // ---- the switch is on: one unit, every writer ------------------------------------------------------------------------

    #[DataProvider('everyWriterAndCreditType')]
    public function test_with_the_switch_on_every_writer_stores_the_same_credit(string $path, string $transactionType): void
    {
        config([WalletMinorUnit::CREDITS_SWITCH => true]);

        WalletWriters::write($path, (string) self::CUSTOMER_ID, $transactionType, 'one-credit', self::AMOUNT);

        $this->assertSame(self::AT_TWO_PLACES, (float) WalletTables::ledgerRow()->credit, "{$path} must round through the one helper");
        $this->assertSame(self::AT_TWO_PLACES, WalletTables::walletBalance(self::CUSTOMER_ID), 'the balance moves by the rounded credit');
    }

    /**
     * The claim that makes the switch worth having: the same reward, paid through five different writers, is five identical figures.
     *
     * Run as one case rather than per writer, because "they agree" is a statement about the set and a per-writer assertion cannot make it.
     */
    public function test_with_the_switch_on_the_same_reward_through_every_writer_is_one_figure(): void
    {
        config([WalletMinorUnit::CREDITS_SWITCH => true]);

        foreach (WalletWriters::PATHS as $path) {
            WalletWriters::write($path, (string) self::CUSTOMER_ID, 'add_fund_by_admin', 'referral-' . $path, self::AMOUNT);
        }

        $credits = DB::table('wallet_transactions')->orderBy('id')->pluck('credit')->map(fn (mixed $credit): float => (float) $credit)->all();

        $this->assertCount(count(WalletWriters::PATHS), $credits, 'every writer wrote its row');
        $this->assertSame([self::AT_TWO_PLACES], array_values(array_unique($credits)), 'five writers, one figure');
    }

    /**
     * The points conversion lands on the unit without its formula being touched. The five writers answer different figures for 1000.0155
     * points -- that is LF-1 and LF-2 -- and every one of those figures has at most two places once the switch is on.
     */
    public function test_with_the_switch_on_a_points_conversion_lands_on_the_unit_whatever_the_formula_answers(): void
    {
        config([WalletMinorUnit::CREDITS_SWITCH => true]);
        // The web formula divides by the visitor's own rate (localToDefaultCurrency), which a test with no visitor does not have. One is
        // the identity, so the figures stay the figures the other four writers' formulas answer and the disagreement is not papered over.
        session(['currency_exchange_rate' => 1]);

        foreach (WalletWriters::PATHS as $path) {
            WalletWriters::write($path, (string) self::CUSTOMER_ID, 'loyalty_point', 'points-' . $path, 1000.0155);
        }

        foreach (DB::table('wallet_transactions')->orderBy('id')->pluck('credit') as $credit) {
            $this->assertSame(round((float) $credit, WalletMinorUnit::TWO_PLACES), (float) $credit, 'a points credit is counted in whole cents');
        }
    }
}
