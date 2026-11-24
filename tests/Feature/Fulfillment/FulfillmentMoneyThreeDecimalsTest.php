<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\CodAllocator;
use App\Services\Fulfillment\Support\CurrencyCode;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * C-15, written down before it is fixed: what fulfilment does today with the three currencies of the Gulf that have a THOUSANDTH.
 *
 * Kuwait, Bahrain and Oman price in fils and baisa: 1 KWD is 1,000 fils, and the smallest coin a courier can collect is 0.001. Fulfilment
 * was built for Saudi Arabia, where the smallest unit is a halala, 0.01 — so every amount it carries is a two-decimal amount: the cash a
 * provider collects is split in **cents** (`CodAllocator`, which multiplies by 100 and rounds), the columns that hold it are
 * `decimal(14, 2)`, and the currency stamped on a shipment is the one the configuration names, never the market's.
 *
 * None of that is wrong for Saudi Arabia and none of it is a defect today: no non-Saudi provider account is enabled, so no fils amount has
 * ever reached it. It is a precondition of the first Kuwaiti, Bahraini or Omani shipment, and these tests are what make the fix visible when
 * it comes: each one asserts today's answer, so the day the third decimal is carried they fail and say so.
 *
 * Nothing here changes behaviour. The amounts are the ones the owner's question is about: a 12.345 cash-on-delivery order, and a split of one
 * fils between two collectors.
 */
class FulfillmentMoneyThreeDecimalsTest extends TestCase
{
    /** The three Gulf currencies whose smallest unit is a thousandth, with the fils figure each one's first shipment would carry. */
    public static function thousandthCurrencies(): array
    {
        return [
            'Kuwaiti dinar' => ['KWD', 12.345],
            'Bahraini dinar' => ['BHD', 12.345],
            'Omani rial' => ['OMR', 12.345],
        ];
    }

    #[DataProvider('thousandthCurrencies')]
    public function test_the_cash_a_courier_is_asked_to_collect_loses_its_third_decimal(string $currency, float $owed): void
    {
        $allocated = app(CodAllocator::class)->allocate($owed, ['torod_point' => 100.0]);

        $this->assertSame(
            ['torod_point' => 12.35],
            $allocated,
            "in {$currency} the courier is asked for 12.350, which is 5 fils more than the order owes",
        );
        $this->assertNotSame($owed, array_sum($allocated), 'the third decimal is not carried, so the sum is not the amount owed');
    }

    /** A thousandth cannot be split at all: one fils between two collectors is nothing to one of them and nothing to the other. */
    public function test_one_fils_between_two_collectors_reaches_neither(): void
    {
        $allocated = app(CodAllocator::class)->allocate(0.001, ['torod_point' => 100.0, 'm5azn' => 100.0]);

        $this->assertSame(['torod_point' => 0.0, 'm5azn' => 0.0], $allocated, 'rounded to zero cents before it is split');
    }

    /** And a half-halala amount, which Saudi Arabia never produces, is rounded up rather than held. */
    public function test_a_half_halala_amount_is_rounded_up_rather_than_refused(): void
    {
        $this->assertSame(['torod_point' => 0.01], app(CodAllocator::class)->allocate(0.005, ['torod_point' => 1.0]));
    }

    /** What two collectors get of a two-decimal amount, so the thousandth cases above are read against a case that is exact. */
    public function test_a_two_decimal_amount_is_split_exactly(): void
    {
        $allocated = app(CodAllocator::class)->allocate(12.34, ['torod_point' => 100.0, 'm5azn' => 100.0]);

        $this->assertSame(['torod_point' => 6.17, 'm5azn' => 6.17], $allocated);
        $this->assertSame(12.34, array_sum($allocated), 'nothing is created and nothing is lost');
    }

    /** The odd cent of a two-decimal amount goes to the largest shipment, which is the rule the allocator states. */
    public function test_the_odd_cent_goes_to_the_largest_shipment(): void
    {
        $allocated = app(CodAllocator::class)->allocate(0.03, ['torod_point' => 200.0, 'm5azn' => 100.0]);

        $this->assertSame(['torod_point' => 0.02, 'm5azn' => 0.01], $allocated);
        $this->assertSame(0.03, array_sum($allocated));
    }

    /**
     * The currency a shipment is stamped with is the configuration's, whatever market the order was sold in.
     *
     * This is the first criterion of C-15 and the reason the figures above are not a defect yet: with every shipment stamped SAR, a fils
     * amount cannot reach a provider at all. The day the stamp follows the order's market, it can.
     */
    #[DataProvider('thousandthCurrencies')]
    public function test_a_shipment_is_stamped_the_configured_currency_whatever_the_market(string $currency): void
    {
        config(['gcc.storefronts.enabled' => true, "gcc.storefronts.countries.{$this->countryOf($currency)}.active" => true]);

        $this->assertSame('SAR', CurrencyCode::provider(), 'the stamp reads the configuration, not the order');
        $this->assertSame($currency, CurrencyCode::canonical($currency), 'the code itself is understood; it is simply not asked for');
    }

    /** And the configuration is the only thing that moves it, which is a deploy. */
    public function test_the_configured_currency_is_what_a_shipment_carries(): void
    {
        config(['fulfillment.currency' => 'KWD']);

        $this->assertSame('KWD', CurrencyCode::provider());
    }

    /**
     * The columns that hold a shipment's money declare two decimals, so even an amount that reached them whole would be rounded on the way
     * in. Read from the migration's own source: how an engine then reports the type differs (SQLite says `numeric` and keeps whatever it is
     * given), and what has to be pinned is what the platform ASKS for, which is the same on every engine.
     */
    public function test_the_columns_that_hold_a_shipments_money_declare_two_decimals(): void
    {
        $migration = (string) file_get_contents(database_path('migrations/2026_11_05_100000_create_provider_fulfillment_tables.php'));

        foreach (['cod_amount', 'items_total', 'shipping_allocation', 'total_amount'] as $column) {
            $this->assertStringContainsString(
                "decimal('{$column}', 14, 2)",
                $migration,
                "{$column} is declared with two decimals, so a fils figure cannot be stored in it as it stands",
            );
        }

        $this->assertStringContainsString("string('currency', 8)->default('SAR')", $migration, 'and a shipment is stamped SAR unless the configuration says otherwise');
    }

    /**
     * What the column then does with a fils amount, on the engine this run is against. It is left as an observation rather than an
     * assertion of one answer: a decimal(14,2) column rounds on MySQL and MariaDB, and SQLite keeps what it is given, so the figure that
     * comes back says which of the two the suite is running on rather than anything about the platform.
     */
    public function test_what_the_column_does_with_a_fils_amount_is_recorded(): void
    {
        (require database_path('migrations/2026_11_05_100000_create_provider_fulfillment_tables.php'))->up();

        \Illuminate\Support\Facades\DB::table('provider_fulfillments')->insert([
            'id' => 1, 'order_id' => 1, 'reference' => 'SHIP-1', 'idempotency_key' => 'KEY-1', 'provider' => 'torod_point', 'status' => 'pending', 'cod_amount' => 12.345,
            'currency' => 'KWD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $stored = (float) \Illuminate\Support\Facades\DB::table('provider_fulfillments')->where('id', 1)->value('cod_amount');

        $this->assertContains($stored, [12.345, 12.35], 'either the engine kept the fils or it rounded them away; neither is a platform that carries them');
        $this->assertSame('KWD', \Illuminate\Support\Facades\DB::table('provider_fulfillments')->where('id', 1)->value('currency'), 'the column itself holds any code');
    }

    private function countryOf(string $currency): string
    {
        return ['KWD' => 'KW', 'BHD' => 'BH', 'OMR' => 'OM'][$currency];
    }
}
