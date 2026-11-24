<?php

namespace Tests\Feature\Loyalty;

use App\Services\Loyalty\WhatAPointIsWorth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Loyalty\LoyaltyTables;
use Tests\Support\Refund\RefundTables;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * E-14 / LF-1 prepared, not adopted: the two readings of `loyalty_point_exchange_rate` both live in one place, and the platform still
 * answers exactly what it answered before.
 *
 * The apps credit `rate` points as one US dollar and the website credits them as one unit of the currency in force, so on a Saudi store at
 * 3.75 to the dollar the app pays **3.75 times** what the website pays for the same points. Which reading is right is the owner's decision
 * (`docs/gcc/OPEN-FINANCIAL-DECISIONS.md` section 1), and until it is made the default keeps both formulas exactly where they were -- which
 * is what the first test here measures, in the same world and with the same figures as
 * {@see PointsToWalletFormulaCharacterizationTest}.
 *
 * The other tests are the two candidate answers, reachable only by setting `gcc.loyalty.point_value`, so the owner can see what each one
 * costs before adopting it. No path in the application sets that value; it is configuration, and a value it does not recognise is read as
 * the default, because a typo must not move money.
 */
class WhatAPointIsWorthTest extends TestCase
{
    use RestoresLanguageFiles;

    private const POINTS = 100.0;

    private const SAR_PER_USD = 3.75;

    /**
     * The two answers as THIS class gives them, which is in the currency in force, before `WalletPosting::converted()` takes the figure
     * to the unit the balance is stored in. One dollar is 3.75 riyals; stored, it is the 1.00 that
     * PointsToWalletFormulaCharacterizationTest measures at the end of the API path.
     */
    private const ONE_DOLLAR = self::SAR_PER_USD;

    /** And one unit of the currency in force is one riyal, which is stored as 1 / 3.75 -- the website's figure in that same test. */
    private const ONE_RIYAL_IN_DOLLARS = 1.0;

    private const DELTA = 0.0005;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        WalletTables::create();
        LoyaltyTables::create();
        RefundTables::create();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('system_default_currency', '2');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
        LoyaltyTables::enableLoyaltyPoints();
        RefundTables::seedCurrency(id: 1, code: 'USD', exchangeRate: 1.0);
        RefundTables::seedCurrency(id: 2, code: 'SAR', exchangeRate: self::SAR_PER_USD);
        DB::table('business_settings')->updateOrInsert(['type' => 'currency_model'], ['value' => 'multi_currency']);
        session(['currency_code' => 'SAR', 'currency_exchange_rate' => self::SAR_PER_USD]);
        clearWebConfigCacheKeys();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function creditOf(string $path): float
    {
        return WhatAPointIsWorth::creditFor(self::POINTS, $path);
    }

    // ---- what ships: each path keeps its own answer ---------------------------------------------------------------------------

    public function test_by_default_each_path_credits_exactly_what_it_credited_before(): void
    {
        $this->assertSame(WhatAPointIsWorth::AS_EACH_PATH_DOES_TODAY, WhatAPointIsWorth::rule());

        $this->assertEqualsWithDelta(self::ONE_DOLLAR, $this->creditOf(WhatAPointIsWorth::API), self::DELTA, 'the apps credit one dollar for a hundred points, which is 3.75 riyals');
        $this->assertEqualsWithDelta(self::ONE_RIYAL_IN_DOLLARS, $this->creditOf(WhatAPointIsWorth::WEB), self::DELTA, 'the website credits one riyal');
    }

    /** The size of the open question, measured rather than described: the app pays the dollar rate of the store's currency times more. */
    public function test_the_two_answers_differ_by_the_dollar_rate_of_the_stores_own_currency(): void
    {
        $this->assertEqualsWithDelta(
            self::SAR_PER_USD,
            $this->creditOf(WhatAPointIsWorth::API) / $this->creditOf(WhatAPointIsWorth::WEB),
            0.01,
            'which is why this is the owner\'s decision and not a rounding question',
        );
    }

    // ---- the two candidate answers, reachable only by configuration ------------------------------------------------------------

    /** @return array<string, array{string, float}> */
    public static function theOwnersOptions(): array
    {
        return [
            'option A, the rate counts points to a dollar' => [WhatAPointIsWorth::ONE_DOLLAR_PER_RATE, self::ONE_DOLLAR],
            'option B, the rate counts points to a unit in force' => [WhatAPointIsWorth::ONE_UNIT_IN_FORCE_PER_RATE, self::ONE_RIYAL_IN_DOLLARS],
            // both figures are in the currency in force; stored, they are 1.00 and 0.267
        ];
    }

    #[DataProvider('theOwnersOptions')]
    public function test_an_option_the_owner_adopts_makes_both_paths_credit_the_same(string $rule, float $credit): void
    {
        config(['gcc.loyalty.point_value' => $rule]);

        $this->assertSame($rule, WhatAPointIsWorth::rule());
        $this->assertEqualsWithDelta($credit, $this->creditOf(WhatAPointIsWorth::API), self::DELTA);
        $this->assertEqualsWithDelta($credit, $this->creditOf(WhatAPointIsWorth::WEB), self::DELTA, 'which is the whole point of answering it');
    }

    /** A value nobody recognises is today's behaviour, not a third answer: a typo in a deploy must not move money. */
    public function test_a_value_the_configuration_does_not_recognise_is_read_as_todays_behaviour(): void
    {
        foreach (['', 'one_dollar', 'ONE_DOLLAR_PER_RATE', 'whatever', null] as $typo) {
            config(['gcc.loyalty.point_value' => $typo]);

            $this->assertSame(WhatAPointIsWorth::AS_EACH_PATH_DOES_TODAY, WhatAPointIsWorth::rule());
            $this->assertEqualsWithDelta(self::ONE_DOLLAR, $this->creditOf(WhatAPointIsWorth::API), self::DELTA);
            $this->assertEqualsWithDelta(self::ONE_RIYAL_IN_DOLLARS, $this->creditOf(WhatAPointIsWorth::WEB), self::DELTA);
        }
    }

    /** Whatever is adopted, a hundred points are worth more than nothing and less than the platform: the arithmetic is bounded. */
    public function test_no_option_credits_nothing_or_something_absurd(): void
    {
        foreach ([WhatAPointIsWorth::AS_EACH_PATH_DOES_TODAY, WhatAPointIsWorth::ONE_DOLLAR_PER_RATE, WhatAPointIsWorth::ONE_UNIT_IN_FORCE_PER_RATE] as $rule) {
            config(['gcc.loyalty.point_value' => $rule]);

            foreach ([WhatAPointIsWorth::WEB, WhatAPointIsWorth::API] as $path) {
                $credit = $this->creditOf($path);

                $this->assertGreaterThan(0.0, $credit, "[{$rule}/{$path}] credits nothing for a hundred points");
                $this->assertLessThanOrEqual(self::POINTS, $credit, "[{$rule}/{$path}] credits more than a point a unit");
            }
        }
    }
}
