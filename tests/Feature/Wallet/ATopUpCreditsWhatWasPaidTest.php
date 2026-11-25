<?php

namespace Tests\Feature\Wallet;

use App\Events\AddFundToWalletEvent;
use App\Utils\Convert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsWalletTopUpWorld;
use Tests\TestCase;

/**
 * E-V1: a gateway top-up credits the wallet the amount the gateway took, to the minor unit.
 *
 * It did not. `add_fund_to_wallet_success` puts the payment through three conversions, and the middle one -- `usdToDefaultCurrency` --
 * ended in `floor($value * $factor) / $factor`. `floor()` of a binary double is not idempotent: the double nearest 0.58 sits a shade
 * below it, so `0.58 * 100` is 57.99999999999999289 and the floor made it 0.57. A top-up could therefore credit a minor unit LESS than
 * the customer was charged, on a single-currency platform too, and nothing anywhere said so.
 *
 * The repair -- rounding at six digits below the minor unit before flooring, the grain `PosSaleTotal` already uses -- is in place. This
 * is the measurement of it, and it is a measurement rather than a claim: the sweep counts how many figures the bare floor loses and
 * then asserts the function in force loses none of them, and the hook itself is driven on the figures the bare floor lost so the
 * caller, not only the helper, is what the credit is read from.
 *
 * `multi_currency` is a different and LARGER defect, measured and pinned at the foot of this file rather than fixed: a 100 riyal
 * top-up stores 100 and reads back as 375 riyals. Nothing here changes it, because the function that does it is shared with every
 * order payment on the platform and moving it is the owner's call.
 */
class ATopUpCreditsWhatWasPaidTest extends TestCase
{
    use BuildsWalletTopUpWorld;

    /** The sweep the axis card measured its figures over: every exact two-decimal amount from 0.01 to 50.00. */
    private const SWEEP_UNITS = 5000;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->customer = $this->aCustomer(balance: 0.0);
        Event::fake([AddFundToWalletEvent::class]);
    }

    /** @return list<float> the exact minor-unit amounts a bare `floor($amount * 100) / 100` does not return unchanged */
    private function figuresTheBareFloorLoses(): array
    {
        $lost = [];
        for ($units = 1; $units <= self::SWEEP_UNITS; $units++) {
            // (float), because `100 / 100` is the INTEGER 1 in PHP and `1 !== 1.0`: without the cast the sweep would flag every whole
            // riyal as lost by a floor that returned it exactly.
            $amount = (float) ($units / 100);
            if (floor($amount * 100) / 100 !== $amount) {
                $lost[] = $amount;
            }
        }

        return $lost;
    }

    public function test_the_bare_floor_this_conversion_used_to_end_in_really_does_lose_minor_units(): void
    {
        $lost = $this->figuresTheBareFloorLoses();

        $this->assertNotSame([], $lost, 'if this is empty the sweep is measuring nothing and the test below proves nothing');
        $this->assertGreaterThan(
            100,
            count($lost),
            'the arithmetic the conversion used to end in loses ' . count($lost) . ' of the ' . self::SWEEP_UNITS . ' exact two-decimal amounts up to 50.00',
        );
        $this->assertContains(0.58, $lost, 'the figure the repair was found on');
        $this->assertContains(4.35, $lost, 'and the one the coupon form was found on');
    }

    public function test_the_conversion_in_force_returns_every_exact_minor_unit_amount_it_is_handed(): void
    {
        $lost = [];
        foreach ($this->figuresTheBareFloorLoses() as $amount) {
            if (usdToDefaultCurrency(amount: $amount) !== $amount) {
                $lost[] = $amount;
            }
        }

        $this->assertSame([], $lost, 'these amounts come back smaller than they went in');
    }

    public function test_an_amount_finer_than_the_currency_holds_is_still_cut_down_and_never_rounded_up(): void
    {
        $this->assertSame(4.99, usdToDefaultCurrency(amount: 4.999), 'a genuinely fractional figure still truncates, as the platform has always truncated it');
        $this->assertSame(272.84, usdToDefaultCurrency(amount: 272.849));
    }

    public function test_the_hook_credits_the_wallet_the_whole_of_every_amount_the_old_arithmetic_cut(): void
    {
        $credited = [];
        $paid = array_slice($this->figuresTheBareFloorLoses(), 0, 12);

        foreach ($paid as $amount) {
            $customer = $this->aCustomerFor($amount);
            add_fund_to_wallet_success($this->aPaidTopUp(customer: $customer, amount: $amount));
            $credited[] = round((float) DB::table('users')->where('id', $customer)->value('wallet_balance'), 2);
        }

        $this->assertSame($paid, $credited, 'the wallet was credited exactly what the gateway took, for every amount');
    }

    public function test_the_ledger_row_of_a_top_up_carries_the_amount_that_was_paid(): void
    {
        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 0.58));

        $row = DB::table('wallet_transactions')->where('user_id', $this->customer)->first();
        $this->assertSame(0.58, round((float) $row->credit, 2), 'the row credits what was paid, not a minor unit less');
        $this->assertSame(0.58, round((float) $row->balance, 2));
    }

    /**
     * NOT ENDORSED, and the reason this criterion is not closed by the repair above.
     *
     * Under `multi_currency` the three conversions of a top-up stop being identities, and the first of them --
     * `Convert::usdPaymentModule($amount, $currency)` -- reads a payment made in the store's OWN default currency as though it were
     * already dollars: with the riyal at 3.75 and the dollar at 1, `default / current` is 1, so 100 riyals in is 100 "dollars" out.
     * The wallet then stores 100 where 26.67 is what 100 riyals is worth, and every reader that puts a stored amount back into the
     * currency in force multiplies by 3.75 again: the customer paid 100 and the account is worth 375.
     *
     * It is pinned and not fixed on purpose. `usdPaymentModule` is the conversion EVERY gateway payment on this platform goes
     * through, not only a top-up, so changing it moves money on paths nobody has asked about -- the lesson of the `shipping_method`
     * default in docs/gcc/EXECUTION_STATE.md. Today's platform is single-currency, where the figures above are exact; this test is
     * what will fail, loudly and with both figures, on the day a market is switched to `multi_currency`.
     */
    public function test_current_behaviour_under_multi_currency_a_riyal_top_up_is_stored_as_though_the_riyals_were_dollars(): void
    {
        DB::table('currencies')->where('code', 'SAR')->update(['exchange_rate' => 3.75]);
        DB::table('currencies')->insert([
            'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business_settings')->updateOrInsert(['type' => 'currency_model'], ['value' => 'multi_currency', 'updated_at' => now()]);
        Cache::flush();
        session()->flush();

        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 100.0));

        $stored = round((float) DB::table('users')->where('id', $this->customer)->value('wallet_balance'), 2);
        $this->assertSame(100.0, $stored, 'the account stores the riyals it was paid as though they were dollars');
        $this->assertSame(26.67, round(100.0 / 3.75, 2), 'and 26.67 is what 100 riyals is worth in the money the account is kept in');
        $this->assertSame(375.0, round((float) Convert::default($stored), 2), 'so putting the stored figure back into riyals gives 375 for a 100 riyal payment');
    }

    /**
     * NOT ENDORSED, and a defect of its own that nothing had recorded: the credit of a top-up is cut to the number of decimals
     * the operator chose to DISPLAY amounts with.
     *
     * The hook converts the payment into the default currency and the writer converts it back (`usdToDefaultCurrency` then
     * `Convert::usd` inside `create_wallet_transaction`), which is the double conversion E04-F05 names. The pair is an exact
     * identity at `decimal_point_settings` of 2, because `payment_requests.payment_amount` is `decimal(24,2)` and there is
     * nothing finer to lose -- which is why this has never shown. The admin field's minimum is 0
     * (resources/views/admin-views/business-settings/website-info.blade.php:388), and at 0 the floor in the middle of the
     * pair takes the halalas off every top-up: a customer charged 100.50 riyals is credited 100.
     *
     * It is measured and not fixed here. The figure the writer rounds is the same figure both point-of-sale tills, the admin
     * panel and the checkout debit hand it, `amountInUsd` changes what it rounds and what it compares the balance at, and
     * what a stored unit is worth is open financial decision 1. A repair that moves one of those four writers and not the
     * others would be worse than the defect.
     */
    public function test_current_behaviour_a_top_up_loses_the_halalas_when_the_operator_displays_no_decimals(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'decimal_point_settings'], ['value' => '0', 'updated_at' => now()]);
        Cache::flush();
        session()->flush();

        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 100.5));

        $this->assertSame(
            100.0,
            round((float) DB::table('users')->where('id', $this->customer)->value('wallet_balance'), 2),
            'the customer was charged 100.50 and the account holds 100: the display setting took fifty halalas',
        );
    }

    /** The same payment at the two decimals the platform runs on, which is the control: nothing is lost. */
    public function test_at_two_decimals_the_same_payment_keeps_its_halalas(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'decimal_point_settings'], ['value' => '2', 'updated_at' => now()]);
        Cache::flush();
        session()->flush();

        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 100.5));

        $this->assertSame(100.5, round((float) DB::table('users')->where('id', $this->customer)->value('wallet_balance'), 2));
    }

    /** A customer of their own per amount, so each credit is read from an account that started at nothing. */
    private function aCustomerFor(float $amount): int
    {
        return DB::table('users')->insertGetId([
            'f_name' => 'Payer', 'l_name' => (string) $amount, 'email' => 'payer-' . str_replace('.', '-', (string) $amount) . '@example.test',
            'wallet_balance' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
