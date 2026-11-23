<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The world of the storefront money tests: a platform that runs the multi-currency model with the Saudi riyal as its default and whose amount
 * settings are typed in riyals, the six storefronts switched on, and the currency rows of the five that trade in their own. Reading an amount
 * as a customer of a storefront does (inStorefront) shows whether it is the platform's number or the storefront's own.
 */
trait BuildsStorefrontMoneyWorld
{
    /** @var array<string, array{id: int, symbol: string, rate: float}> */
    private const MONEY_CURRENCIES = [
        'USD' => ['id' => 1, 'symbol' => '$', 'rate' => 1.0],
        'SAR' => ['id' => 2, 'symbol' => 'SAR', 'rate' => 3.75],
        'AED' => ['id' => 3, 'symbol' => 'AED', 'rate' => 3.6725],
        'KWD' => ['id' => 4, 'symbol' => 'KD', 'rate' => 0.307],
        'QAR' => ['id' => 5, 'symbol' => 'QR', 'rate' => 3.64],
        'BHD' => ['id' => 6, 'symbol' => 'BD', 'rate' => 0.376],
        'OMR' => ['id' => 7, 'symbol' => 'RO', 'rate' => 0.385],
    ];

    /** What the platform's settings hold: amounts as the Saudi panel stores them. */
    private const PLATFORM_AMOUNTS = [
        'minimum_order_amount' => 100,
        'free_delivery_over_amount' => 500,
        'free_delivery_over_amount_seller' => 300,
        'minimum_add_fund_amount' => 10,
        'maximum_add_fund_amount' => 1000,
    ];

    protected function buildStorefrontMoneyWorld(): void
    {
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

        foreach (self::MONEY_CURRENCIES as $code => $currency) {
            DB::table('currencies')->insert(['id' => $currency['id'], 'name' => $code, 'symbol' => $currency['symbol'], 'code' => $code, 'exchange_rate' => $currency['rate'], 'status' => 1]);
        }

        DB::table('business_settings')->insert(array_merge(
            [
                ['type' => 'currency_model', 'value' => 'multi_currency'],
                ['type' => 'system_default_currency', 'value' => '2'],
                ['type' => 'decimal_point_settings', 'value' => '2'],
            ],
            array_map(
                static fn (string $type, int $value): array => ['type' => $type, 'value' => (string) $value],
                array_keys(self::PLATFORM_AMOUNTS),
                array_values(self::PLATFORM_AMOUNTS),
            ),
        ));

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);
        Cache::forget('active_currency_list');
        clearWebConfigCacheKeys();
    }

    protected function dropStorefrontMoneyWorld(): void
    {
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('business_settings');
    }

    /** Serves the callback as a customer of the storefront does, with its money in force while the switches are on. */
    protected function inStorefront(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }

    /** What an operator gave a storefront, written as gcc:storefront:money writes it. */
    protected function giveStorefrontAmounts(string $country, string $currency, array $amounts): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => 'storefront_money_' . strtolower($country)],
            ['value' => json_encode(['currency' => $currency] + $amounts)],
        );
        clearWebConfigCacheKeys();
    }

    /** @return array<string, array{0: string, 1: string}> the storefronts that trade in their own currency and the currency of each */
    public static function storefrontsWithTheirOwnCurrency(): array
    {
        return [
            'United Arab Emirates' => ['AE', 'AED'],
            'Kuwait' => ['KW', 'KWD'],
            'Qatar' => ['QA', 'QAR'],
            'Bahrain' => ['BH', 'BHD'],
            'Oman' => ['OM', 'OMR'],
        ];
    }
}
