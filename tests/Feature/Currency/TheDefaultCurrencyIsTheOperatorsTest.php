<?php

namespace Tests\Feature\Currency;

use App\Models\Currency;
use App\Traits\CacheManagerTrait;
use App\Utils\Helpers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The backend half of the Syrian-pound question (J-05, recorded in docs/gcc/tracker/coverage-gaps.md).
 *
 * The customer app shows Syrian pounds on the default storefront whatever the operator set as the system default
 * currency: `lib/features/splash/controllers/splash_controller.dart:289-299` walks `currencyList` and breaks on
 * `code == 'SYP'` **or** the entry whose id matches `systemDefaultCurrency`, whichever comes FIRST in the list. That is
 * the app's defect and the app's to fix. This file asks the separate question the backend owns: does the BACKEND's own
 * currency resolution have the same shape -- a hardcoded preference for one currency, or a list whose order decides the
 * default -- and does the backend hand the app a list whose order it never states?
 *
 * The world is the worst case for the question: Syrian pounds sit at id 1, BEFORE the riyal the operator chose, and
 * both are active. Every figure below was measured by running this file, not reasoned about.
 */
class TheDefaultCurrencyIsTheOperatorsTest extends TestCase
{
    use CacheManagerTrait;

    private const SYP = 1;

    private const SAR = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('currencies');
        Schema::dropIfExists('business_settings');

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        // The order is the point: the leftover of the product this one was forked from sits in front of the riyal.
        DB::table('currencies')->insert([
            ['id' => self::SYP, 'name' => 'Syrian pound', 'symbol' => 'LS', 'code' => 'SYP', 'exchange_rate' => 13000, 'status' => 1],
            ['id' => self::SAR, 'name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 3.75, 'status' => 1],
            ['id' => 3, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1, 'status' => 1],
        ]);
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => 'multi_currency'],
            ['type' => 'system_default_currency', 'value' => (string) self::SAR],
            ['type' => 'decimal_point_settings', 'value' => '2'],
        ]);

        $this->forgetEverythingCached();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('business_settings');

        parent::tearDown();
    }

    public function test_the_backends_own_resolution_answers_the_currency_the_operator_chose_and_not_the_first_row(): void
    {
        $this->assertSame('SAR', getCurrencyCode(), 'the platform default a panel and an invoice read');
        $this->assertSame('SAR', getCurrencyCode(type: 'web'), 'the code the website prints');
        $this->assertSame('SAR', getCurrencySymbol(), 'the sign beside every amount');
        // \App\Utils\currency_symbol(), not currency_symbol(): the functions at the foot of app/Utils/Helpers.php sit
        // INSIDE its `namespace App\Utils`, while their own `function_exists('currency_symbol')` guard asks after the
        // global name -- so the guard guards nothing and only a caller already in that namespace reaches them.
        $this->assertSame('SAR', \App\Utils\currency_symbol(), "the admin panel's own copy of the same question");
        $this->assertSame(3.75, round(defaultCurrencyRate(), 4), 'the rate every stored figure is shown at');
        $this->assertSame(self::SAR, (int) getWebConfig(name: 'system_default_currency'), 'the id the config endpoint sends the apps');
    }

    public function test_the_currency_list_the_apps_are_sent_leads_with_the_currency_the_operator_chose(): void
    {
        $codes = $this->cacheCurrencyTable()->pluck('code')->all();

        $this->assertSame(
            ['SAR', 'SYP', 'USD'],
            $codes,
            "the operator's own default leads the list, and every other row keeps the order it had -- so the customer app's first-priority loop reaches the default before it can reach its SYP clause",
        );
    }

    public function test_the_list_is_still_a_json_array_and_not_an_object(): void
    {
        $encoded = json_decode(json_encode(['currency_list' => $this->cacheCurrencyTable()]), true);

        $this->assertSame([0, 1, 2], array_keys($encoded['currency_list']), 'a sorted collection keeps its original keys and would encode as an object, which every client reading this field would break on');
    }

    public function test_changing_the_default_currency_reorders_the_list_at_once_and_is_not_held_by_the_three_hour_cache(): void
    {
        $this->assertSame('SAR', $this->cacheCurrencyTable()->first()->code, 'the list is now cached');

        DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => '3']);
        clearWebConfigCacheKeys();

        $this->assertSame(
            'USD',
            $this->cacheCurrencyTable()->first()->code,
            'nothing clears the currency cache when the default currency setting changes, so the order has to be computed outside it',
        );
    }

    public function test_an_operator_default_that_names_no_row_leaves_the_list_exactly_as_it_was(): void
    {
        DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => '99']);
        clearWebConfigCacheKeys();

        $this->assertSame(['SYP', 'SAR', 'USD'], $this->cacheCurrencyTable()->pluck('code')->all(), 'no row matches, so nothing is promoted and the list is what it always was');
    }

    public function test_the_panels_fallback_when_the_chosen_currency_is_gone_is_whatever_sits_at_id_one(): void
    {
        DB::table('currencies')->where('id', self::SAR)->delete();
        $this->forgetEverythingCached();

        Helpers::currency_load();

        $this->assertSame(
            'SYP',
            session('system_default_currency_info')->code,
            'app/Utils/Helpers.php:342 falls back to Currency::find(1), so a deleted default currency silently becomes whatever row happens to be first -- a hardcoded positional preference, which on this fork is the Syrian pound',
        );
    }

    public function test_the_storefront_copy_of_the_same_routine_has_no_fallback_at_all(): void
    {
        DB::table('currencies')->where('id', self::SAR)->delete();
        $this->forgetEverythingCached();

        $this->expectException(\Throwable::class);

        // app/Utils/currency.php:31-34 -- Currency::find($id) with no null check, so the website is a 500 where the panel
        // silently shows another currency. Two copies of one routine, two different answers to one missing row.
        loadCurrency();
    }

    public function test_the_dead_copy_of_the_code_question_resolves_by_symbol_and_can_answer_the_wrong_currency(): void
    {
        DB::table('currencies')->insert([
            ['id' => 4, 'name' => 'Canadian Dollar', 'symbol' => '$', 'code' => 'CAD', 'exchange_rate' => 1.37, 'status' => 1],
        ]);
        DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => '4']);
        $this->forgetEverythingCached();

        // Helpers::currency_code() asks `Currency::where(['symbol' => $symbol])->first()->code` (app/Utils/Helpers.php:577):
        // the dollar sign belongs to several currencies, so the code it answers is whichever row the engine returns first and
        // not the currency in force. Nothing in the application calls it -- grep says its only other definition is
        // BackEndHelper::currency_code() -- so it is recorded here rather than changed.
        session(['currency_symbol' => '$']);
        $this->assertSame('USD', Helpers::currency_code(), 'the operator chose the Canadian dollar and this answers the US one');
    }

    /** The settings cache AND the session, because the currency in force is memoised in both. */
    private function forgetEverythingCached(): void
    {
        Cache::flush();
        clearWebConfigCacheKeys();
        session()->flush();
    }
}
