<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontResolver;
use App\Utils\Helpers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * C-15 and E09-F10: the commission a seller is charged keeps the decimals of the currency the order is priced in.
 *
 * A commission is a share of an amount of money, so it is an amount of money. `Helpers::seller_sales_commission()` rounded every one of
 * them to two decimals, which is right in Riyadh and wrong in Kuwait City: a tenth of a 12.345 dinar order is 1.2345, which is 1.235 charged to
 * the fils, and two decimals make it 1.23 — four fils of every dinar the platform is owed. `CommissionEngine`, which prices each line once rules are configured, already
 * keeps four decimals, and the column holds twenty — this one function was the only place cutting the figure down.
 *
 * The decimals come from the currency and never from `decimal_point_settings`: that is an operator's display preference, and an operator
 * who prints no decimals must not thereby change what a seller is charged. Two is the floor, which is what keeps every Saudi figure the
 * figure it is today — the first test here is the Saudi one, and it is the one that must never move.
 */
class CommissionKeepsItsCurrencysDecimalsTest extends TestCase
{
    private const SAUDI_RIYAL_ID = '2';

    private const SELLER_ID = 7;

    /** @var array<string, array{id: int, rate: float}> */
    private const CURRENCIES = [
        'USD' => ['id' => 1, 'rate' => 1.0],
        'SAR' => ['id' => 2, 'rate' => 3.75],
        'KWD' => ['id' => 4, 'rate' => 0.307],
        'BHD' => ['id' => 6, 'rate' => 0.376],
        'OMR' => ['id' => 7, 'rate' => 0.385],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMoneyAndSellerTables();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('sellers');

        parent::tearDown();
    }

    /** The commission of an order, as the two callers of the helper consume it: the separator stripped, read as a number. */
    private function commissionOf(float $orderTotal): float
    {
        return (float) str_replace(',', '', (string) Helpers::seller_sales_commission('seller', self::SELLER_ID, $orderTotal));
    }

    private function inStorefront(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }

    /** The figure every Saudi order has always produced, and the one nothing in this work may move. */
    public function test_a_riyal_commission_is_the_figure_it_has_always_been(): void
    {
        $this->assertSame(10.0, $this->commissionOf(100.0), 'a tenth of a hundred');
        $this->assertSame(10.06, $this->commissionOf(100.555), 'and a half-halala share is rounded to the halala, as before');
        $this->assertSame(1000.0, $this->commissionOf(10000.0), 'a four-figure commission still reads as a number for its callers');
    }

    /** @return array<string, array{string, float}> market, and the fils its commission keeps */
    public static function thousandthMarkets(): array
    {
        return [
            'Kuwait' => ['KW', 1.235],
            'Bahrain' => ['BH', 1.235],
            'Oman' => ['OM', 1.235],
        ];
    }

    #[DataProvider('thousandthMarkets')]
    public function test_a_commission_of_a_thousandth_currency_keeps_its_third_decimal(string $country, float $expected): void
    {
        $charged = $this->inStorefront($country, fn (): float => $this->commissionOf(12.345));

        $this->assertSame($expected, $charged, 'a tenth of 12.345 is 1.2345, charged to the fils, which is the smallest coin a seller can be charged in');
    }

    /** The same market with the currency switch off is the platform's own two decimals, because nothing is in force but the platform. */
    public function test_a_market_whose_currency_switch_is_off_keeps_the_platforms_two_decimals(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertSame(1.23, $this->inStorefront('KW', fn (): float => $this->commissionOf(12.345)));
    }

    /** An operator's display preference is not the currency's smallest coin, in either direction. */
    public function test_an_operators_display_preference_does_not_change_what_a_seller_is_charged(): void
    {
        foreach (['0', '1', '2'] as $preference) {
            DB::table('business_settings')->where('type', 'decimal_point_settings')->update(['value' => $preference]);
            clearWebConfigCacheKeys();

            $this->assertSame(10.06, $this->commissionOf(100.555), "a shop printing {$preference} decimals still charges the halala");
            $this->assertSame(1.235, $this->inStorefront('KW', fn (): float => $this->commissionOf(12.345)), 'and the fils in Kuwait');
        }
    }

    /** An in-house order is not a seller's order and carries no commission, which this work does not touch. */
    public function test_an_in_house_order_carries_no_commission(): void
    {
        $this->assertSame(0, Helpers::seller_sales_commission('admin', self::SELLER_ID, 100.0));
    }

    /** The seller's own percentage wins over the platform's, in their own market's decimals. */
    public function test_the_sellers_own_percentage_is_used_in_its_markets_decimals(): void
    {
        DB::table('sellers')->where('id', self::SELLER_ID)->update(['sales_commission_percentage' => 7.5]);

        $this->assertSame(0.926, $this->inStorefront('KW', fn (): float => $this->commissionOf(12.345)), '7.5% of 12.345 is 0.925875, charged to the fils');
        $this->assertSame(0.93, $this->commissionOf(12.345), 'and to the halala on the Saudi storefront');
    }

    private function buildMoneyAndSellerTables(): void
    {
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('sellers');

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->decimal('sales_commission_percentage', 40, 20)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (self::CURRENCIES as $code => $currency) {
            DB::table('currencies')->insert(['id' => $currency['id'], 'name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $currency['rate'], 'status' => 1]);
        }

        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => 'multi_currency'],
            ['type' => 'system_default_currency', 'value' => self::SAUDI_RIYAL_ID],
            ['type' => 'decimal_point_settings', 'value' => '2'],
            ['type' => 'sales_commission', 'value' => '10'],
        ]);
        DB::table('sellers')->insert(['id' => self::SELLER_ID, 'f_name' => 'A seller', 'sales_commission_percentage' => null]);

        clearWebConfigCacheKeys();
    }
}
