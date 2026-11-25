<?php

namespace Tests\Feature\Payments;

use App\Events\AddFundToWalletEvent;
use App\Models\Currency;
use App\Services\Payments\WhatAPaymentIsWorth;
use App\Utils\Convert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BuildsWalletTopUpWorld;
use Tests\TestCase;

/**
 * E-V1 prepared, not adopted: what a gateway payment is worth in the unit this platform stores money in, both readings in
 * one place, and the platform still answering exactly what it answered before.
 *
 * `Convert::usdPaymentModule()` converts a payment into the store's DEFAULT currency and stops. Its name says dollars and
 * the stored unit IS the dollar -- `usdToDefaultCurrency()` multiplies a stored figure by `default_rate / usd_rate` to show
 * it -- so on a store whose default is the riyal its answer is 3.75 times the figure that should be stored. The end of that
 * is measured in {@see \Tests\Feature\Wallet\ATopUpCreditsWhatWasPaidTest}: a customer pays 100 riyals into the wallet, 100
 * is stored, and every screen reads the account back as 375 riyals.
 *
 * Correcting it changes what a stored unit is worth, which is the same question as what a loyalty point is worth, and it
 * reaches every caller of that function -- the top-up's credit, the amount a top-up hands the gateway, the minimum and
 * maximum top-up checks, and the catalogue's price-range filter on the website and in the seller API. So the correction is a
 * setting, it ships OFF, and the first test here is the proof that OFF is the same platform: a matrix of amounts, paid
 * currencies and default currencies, each compared against this file's own frozen copy of the arithmetic as it stood, with
 * `assertSame` on the floats so a figure that differs in its last bit fails.
 *
 * Nothing in the application sets that value; it is configuration, and a value it does not recognise is read as today's
 * behaviour, because a typo must not move money. `docs/gcc/OPEN-FINANCIAL-DECISIONS.md` section 4 is how it gets answered.
 */
class WhatAPaymentIsWorthTest extends TestCase
{
    use BuildsWalletTopUpWorld;

    private const SAR_PER_USD = 3.75;

    private const AED_PER_USD = 3.6725;

    /** A three-decimal Gulf currency, and the one whose rate is BELOW the dollar's, which is the other side of every `>=` in the old code. */
    private const KWD_PER_USD = 0.307;

    /** Every amount the matrix is walked with: a whole figure, the two the floor defect was found on, and a large one. */
    private const AMOUNTS = [1.0, 100.0, 0.58, 4.35, 12345.67];

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->customer = $this->aCustomer(balance: 0.0);
        Event::fake([AddFundToWalletEvent::class]);

        DB::table('currencies')->where('code', 'SAR')->update(['exchange_rate' => self::SAR_PER_USD]);
        $this->aCurrency(code: 'USD', rate: 1.0);
        $this->aCurrency(code: 'AED', rate: self::AED_PER_USD);
        $this->aCurrency(code: 'KWD', rate: self::KWD_PER_USD);
    }

    public function test_as_it_ships_every_figure_in_the_matrix_is_what_the_module_has_always_answered(): void
    {
        $this->assertSame(WhatAPaymentIsWorth::AS_TODAY, WhatAPaymentIsWorth::rule(), 'the setting must ship as today, or nothing below means anything');

        $differences = [];
        foreach ($this->matrix() as $case) {
            [$default, $paid, $amount] = $case;
            $this->theDefaultCurrencyIs($default);

            $inForce = Convert::usdPaymentModule($amount, $paid);
            $asItStood = $this->theArithmeticAsItStood($amount, $paid);

            if ($inForce !== $asItStood) {
                $differences[] = "default {$default}, paid {$amount} {$paid}: " . var_export($inForce, true) . ' not ' . var_export($asItStood, true);
            }
        }

        $this->assertSame([], $differences, 'the setting is off and one of these figures moved');
    }

    public function test_the_matrix_really_separates_the_two_readings(): void
    {
        $disagreements = 0;
        $agreedWithoutTheDollar = [];

        foreach ($this->matrix() as $case) {
            [$default, $paid, $amount] = $case;
            $this->theDefaultCurrencyIs($default);

            if ($this->theArithmeticAsItStood($amount, $paid) !== $this->theCorrection($amount, $paid)) {
                $disagreements++;

                continue;
            }

            // The two readings agree in exactly two situations, and both are the dollar: the store's default currency IS the
            // dollar, in which case the old code's own early branch is the correction; or the payment was taken in dollars, in
            // which case there is nothing to convert. Anywhere else the old reading is `default_rate` times the correction, so
            // an agreement outside those two would mean this matrix is not exercising what it claims to.
            if ($default !== 'USD' && $paid !== 'USD') {
                $agreedWithoutTheDollar[] = "default {$default}, paid {$amount} {$paid}";
            }
        }

        $this->assertSame([], $agreedWithoutTheDollar, 'these cases should differ by the rate of the default currency and do not');
        $this->assertGreaterThan(
            25,
            $disagreements,
            'if the two readings agreed almost everywhere the matrix above would prove nothing; they disagree on ' . $disagreements . ' of ' . count($this->matrix()) . ' cases',
        );
    }

    public function test_the_whole_matrix_is_the_identity_on_a_single_currency_platform_under_either_reading(): void
    {
        $this->setting(type: 'currency_model', value: 'single_currency');
        $this->forgetEverythingCached();

        foreach ([WhatAPaymentIsWorth::AS_TODAY, WhatAPaymentIsWorth::THE_RATE_OF_THE_CURRENCY_PAID] as $rule) {
            config(['gcc.payments.what_a_payment_is_worth' => $rule]);

            foreach ($this->matrix() as [$default, $paid, $amount]) {
                $this->assertSame(
                    $amount,
                    Convert::usdPaymentModule($amount, $paid),
                    "under {$rule} a single-currency platform converts nothing, so {$amount} {$paid} stays {$amount}",
                );
            }
        }
    }

    public function test_the_correction_is_the_exact_inverse_of_the_conversion_that_charged_the_gateway(): void
    {
        config(['gcc.payments.what_a_payment_is_worth' => WhatAPaymentIsWorth::THE_RATE_OF_THE_CURRENCY_PAID]);

        foreach (['SAR', 'AED', 'KWD', 'USD'] as $code) {
            $this->theDefaultCurrencyIs('SAR');
            $stored = Convert::usdPaymentModule(100.0, $code);

            $this->assertSame(
                100.0,
                round((float) usdToAnotherCurrencyConverter(currencyCode: $code, amount: $stored), 2),
                "100 {$code} stored and charged again is 100 {$code}; today's reading is not this inverse for any default but the dollar",
            );
        }
    }

    public function test_as_it_ships_a_hundred_riyals_paid_into_the_wallet_reads_back_as_three_hundred_and_seventy_five(): void
    {
        $this->theDefaultCurrencyIs('SAR');

        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 100.0));

        $stored = round((float) DB::table('users')->where('id', $this->customer)->value('wallet_balance'), 2);
        $this->assertSame(100.0, $stored, 'the riyals are stored as though they were dollars');
        $this->assertSame(375.0, round((float) Convert::default($stored), 2), 'which every screen reads back as 375 riyals for a 100 riyal payment');
    }

    public function test_with_the_correction_on_a_hundred_riyals_paid_into_the_wallet_reads_back_as_a_hundred(): void
    {
        $this->theDefaultCurrencyIs('SAR');
        config(['gcc.payments.what_a_payment_is_worth' => WhatAPaymentIsWorth::THE_RATE_OF_THE_CURRENCY_PAID]);

        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 100.0));

        // The raw column, not a figure rounded to two decimals first: 100 / 3.75 is 26.666667 and the balance holds six
        // decimals, so cutting it to two before converting it back would lose a halala and a quarter and read 100.01.
        $stored = (float) DB::table('users')->where('id', $this->customer)->value('wallet_balance');
        $this->assertSame(26.67, round($stored, 2), '100 riyals at 3.75 is 26.67 of the unit the balance is kept in');
        $this->assertSame(100.0, round((float) Convert::default($stored), 2), 'and the customer who paid 100 riyals has an account worth 100 riyals');
    }

    public function test_a_value_the_setting_does_not_recognise_is_read_as_todays_behaviour(): void
    {
        $this->theDefaultCurrencyIs('SAR');

        foreach (['', 'the_rate_of_the_currency_pait', 'true', '1', 'correct'] as $typo) {
            config(['gcc.payments.what_a_payment_is_worth' => $typo]);

            $this->assertSame(WhatAPaymentIsWorth::AS_TODAY, WhatAPaymentIsWorth::rule(), "'{$typo}' must not move money");
            $this->assertSame(100.0, Convert::usdPaymentModule(100.0, 'SAR'), "'{$typo}' must not move money");
        }
    }

    /**
     * The arithmetic of `Convert::usdPaymentModule` as it stood before the seam, copied here character for character.
     *
     * It is a frozen record and not code to improve: the `>=` / `<=` pairs are how the function decided which way round to
     * divide, and simplifying them here would make the comparison above compare two of my own readings instead of the
     * platform's.
     */
    private function theArithmeticAsItStood(float $amount, string $currency): float|int|null
    {
        $currency = strtoupper(trim($currency));
        $currencyModel = getWebConfig(name: 'currency_model');
        if ($currencyModel == 'multi_currency') {
            if ($currency == 'USD') {
                return floatval($amount);
            }

            $default = Currency::find(getWebConfig(name: 'system_default_currency'));
            $currentCurrency = Currency::where(['code' => $currency])->first()->exchange_rate ?? 1;

            if ($default['code'] == 'USD') {
                return floatval($amount) / $currentCurrency;
            }
            $rate = $default['exchange_rate'] >= $currentCurrency ? ($default['exchange_rate'] / $currentCurrency) : ($currentCurrency / $default['exchange_rate']);
            $defaultAmount = $default['exchange_rate'] <= $currentCurrency ? floatval($amount) / floatval($rate) : floatval($amount) * floatval($rate);
            $usdRate = Currency::where(['code' => 'USD'])->first()->exchange_rate;

            $value = floatval($default['exchange_rate']) >= floatval($usdRate) ? floatval($defaultAmount) * floatval($usdRate) : floatval($defaultAmount) / floatval($usdRate);
        } else {
            $value = floatval($amount);
        }

        return $value;
    }

    /** The correction, written out here so the matrix does not ask the class under test what the class under test should say. */
    private function theCorrection(float $amount, string $currency): float
    {
        $usd = (float) Currency::where('code', 'USD')->value('exchange_rate');
        $paid = (float) Currency::where('code', strtoupper(trim($currency)))->value('exchange_rate');

        return $amount * $usd / $paid;
    }

    /** @return list<array{0: string, 1: string, 2: float}> every default currency against every paid currency against every amount */
    private function matrix(): array
    {
        $cases = [];
        foreach (['SAR', 'USD', 'KWD'] as $default) {
            foreach (['SAR', 'USD', 'AED', 'KWD'] as $paid) {
                foreach (self::AMOUNTS as $amount) {
                    $cases[] = [$default, $paid, $amount];
                }
            }
        }

        return $cases;
    }

    private function theDefaultCurrencyIs(string $code): void
    {
        $this->setting(type: 'currency_model', value: 'multi_currency');
        $this->setting(type: 'system_default_currency', value: (string) DB::table('currencies')->where('code', $code)->value('id'));
        $this->setting(type: 'decimal_point_settings', value: '2');
        $this->forgetEverythingCached();
    }

    private function aCurrency(string $code, float $rate): void
    {
        DB::table('currencies')->insert([
            'name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The settings cache AND the session, because the rate of the default currency is memoised in both. */
    private function forgetEverythingCached(): void
    {
        Cache::flush();
        clearWebConfigCacheKeys();
        session()->forget(['default', 'usd', 'system_default_currency_info', 'currency_code', 'currency_symbol', 'currency_exchange_rate']);
    }
}
