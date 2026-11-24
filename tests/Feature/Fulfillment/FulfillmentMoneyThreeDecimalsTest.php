<?php

namespace Tests\Feature\Fulfillment;

use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\CodAllocator;
use App\Services\Fulfillment\Support\CurrencyCode;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\Fulfillment\Support\ProviderCurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * C-15: fulfilment carries the third decimal of the three Gulf currencies that have one.
 *
 * Kuwait, Bahrain and Oman price in fils and baisa: 1 KWD is 1,000 fils, and the smallest coin a courier can collect is 0.001. Fulfilment
 * was built for Saudi Arabia, where the smallest coin is a halala, so every amount it carried was rounded to two decimals: the cash a
 * provider collects was split in hundredths, the columns holding it were `decimal(14, 2)`, the model formatted them to two decimals and the
 * admin screens printed two. A 12.345 cash-on-delivery order asked its courier for 12.350 — five fils the customer does not owe — and a
 * single fils between two collectors reached neither.
 *
 * It now works in the smallest coin of the currency the money is in, which is **two decimals for a riyal and always was**: the Saudi cases
 * below are the same figures with the same rule, and the three Gulf cases are the ones that changed. Where the number of decimals comes from
 * is one place for the whole platform, `gcc.storefronts.currency.decimals`, which answers two for every currency it is not told about.
 *
 * What is NOT settled here, and is the owner's decision: WHICH currency a provider is paid in for a non-Saudi order (the configuration's
 * one, as today, or the order's storefront currency). This requirement's first criterion waits on that; how many decimals a given currency
 * has does not.
 */
class FulfillmentMoneyThreeDecimalsTest extends TestCase
{
    private const CREATE_MIGRATION = 'migrations/2026_11_05_100000_create_provider_fulfillment_tables.php';

    private const WIDEN_MIGRATION = 'migrations/2026_12_14_100000_carry_three_decimals_on_provider_fulfillment_money.php';

    /** The three Gulf currencies whose smallest coin is a thousandth, with the fils figure each one's first shipment would carry. */
    public static function thousandthCurrencies(): array
    {
        return [
            'Kuwaiti dinar' => ['KWD', 12.345],
            'Bahraini dinar' => ['BHD', 12.345],
            'Omani rial' => ['OMR', 12.345],
        ];
    }

    private function allocator(): CodAllocator
    {
        return app(CodAllocator::class);
    }

    /** The decimals a provider's money is counted in, as the platform answers it with this currency configured. */
    private function decimalsPaidIn(string $currency): int
    {
        config(['fulfillment.currency' => $currency]);

        return app(ProviderCurrency::class)->decimals();
    }

    #[DataProvider('thousandthCurrencies')]
    public function test_the_cash_a_courier_is_asked_to_collect_keeps_its_third_decimal(string $currency, float $owed): void
    {
        $allocated = $this->allocator()->allocate($owed, ['torod_point' => 100.0], $this->decimalsPaidIn($currency));

        $this->assertSame(['torod_point' => 12.345], $allocated, "in {$currency} the courier is asked for exactly what the order owes");
        $this->assertSame($owed, array_sum($allocated), 'nothing is created and nothing is lost');
    }

    /** A single fils between two collectors reaches one of them -- the one shipping the most, which is the rule the allocator states. */
    public function test_one_fils_between_two_collectors_reaches_one_of_them(): void
    {
        $allocated = $this->allocator()->allocate(0.001, ['torod_point' => 200.0, 'm5azn' => 100.0], $this->decimalsPaidIn('KWD'));

        $this->assertSame(['torod_point' => 0.001, 'm5azn' => 0.0], $allocated);
        $this->assertSame(0.001, array_sum($allocated));
    }

    /** A fils split between two equal shipments: the odd fils goes to one of them and the two still sum to the amount owed. */
    public function test_a_fils_amount_is_split_without_a_remainder_going_missing(): void
    {
        $allocated = $this->allocator()->allocate(12.345, ['torod_point' => 100.0, 'm5azn' => 100.0], $this->decimalsPaidIn('KWD'));

        $this->assertSame([6.173, 6.172], array_values($allocated));
        $this->assertSame(12345, $this->inFils($allocated), 'the parts add up to the amount owed, to the fils');
    }

    /**
     * The Saudi split is what it has always been: hundredths, half a halala rounded up, the odd halala to the largest shipment.
     *
     * These are the cases the allocator shipped with, run the way every Saudi caller runs it -- without naming a currency, which is two
     * decimals. If any of them moves, the riyal has changed, and no part of this requirement is allowed to change it.
     */
    public function test_a_riyal_split_is_what_it_has_always_been(): void
    {
        $this->assertSame(['torod_point' => 0.01], $this->allocator()->allocate(0.005, ['torod_point' => 1.0]), 'half a halala rounds up');

        $exact = $this->allocator()->allocate(12.34, ['torod_point' => 100.0, 'm5azn' => 100.0]);
        $this->assertSame(['torod_point' => 6.17, 'm5azn' => 6.17], $exact);
        $this->assertSame(12.34, array_sum($exact));

        $odd = $this->allocator()->allocate(0.03, ['torod_point' => 200.0, 'm5azn' => 100.0]);
        $this->assertSame(['torod_point' => 0.02, 'm5azn' => 0.01], $odd, 'the odd halala goes to the largest shipment');

        $this->assertSame(2, $this->decimalsPaidIn('SAR'), 'and a riyal is counted in hundredths');
    }

    public function test_the_decimals_come_from_the_currency_the_money_is_in(): void
    {
        foreach (['SAR' => 2, 'AED' => 2, 'QAR' => 2, 'KWD' => 3, 'BHD' => 3, 'OMR' => 3] as $currency => $decimals) {
            $this->assertSame($decimals, $this->decimalsPaidIn($currency), "{$currency} is counted in " . $decimals . ' decimals');
        }

        $this->assertSame(2, $this->decimalsPaidIn('XTS'), 'a currency the configuration does not name is counted in hundredths');
    }

    /** An operator's display preference is not the currency's smallest coin: setting the site to print no decimals cannot change what a courier collects. */
    public function test_the_operators_display_preference_does_not_decide_what_a_courier_collects(): void
    {
        $this->rateSourceAnswering(model: 'single_currency', defaultCode: 'KWD', displayDecimals: 0);

        $this->assertSame(12.345, app(ProviderCurrency::class)->fromStored(12.345));
    }

    /** The amount converted for a provider keeps its fils, and a riyal amount is still rounded to the halala. */
    public function test_an_amount_converted_for_a_provider_is_rounded_to_the_smallest_coin_of_its_currency(): void
    {
        $this->rateSourceAnswering(model: 'single_currency', defaultCode: 'KWD');
        $this->assertSame(12.346, app(ProviderCurrency::class)->fromStored(12.3456), 'a fils amount keeps three decimals');

        $this->rateSourceAnswering(model: 'single_currency', defaultCode: 'SAR');
        $this->assertSame(12.35, app(ProviderCurrency::class)->fromStored(12.345), 'and a riyal amount is rounded to the halala, as always');
    }

    /** The columns make room for the third decimal, and a fils figure survives the round trip through the model. */
    public function test_a_fils_figure_survives_the_round_trip_through_the_columns(): void
    {
        $this->buildTheFulfillmentTables();

        DB::table('provider_fulfillments')->insert([
            'id' => 1, 'order_id' => 1, 'reference' => 'SHIP-1', 'idempotency_key' => 'KEY-1', 'provider' => 'torod_point', 'status' => 'pending',
            'cod_amount' => 12.345, 'items_total' => 12.345, 'shipping_allocation' => 0.001, 'total_amount' => 12.346,
            'currency' => 'KWD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $shipment = ProviderFulfillment::find(1);

        $this->assertSame('12.345', $shipment->cod_amount, 'the fils reached the column and came back');
        $this->assertSame('0.001', $shipment->shipping_allocation);
        $this->assertSame(3, $shipment->money_decimals, 'and the screens print it in the decimals the currency has');
    }

    /** The same round trip for a riyal shipment, which is what every shipment recorded so far is: two decimals, unchanged. */
    public function test_a_riyal_shipment_reads_back_in_two_decimals(): void
    {
        $this->buildTheFulfillmentTables();

        DB::table('provider_fulfillments')->insert([
            'id' => 2, 'order_id' => 2, 'reference' => 'SHIP-2', 'idempotency_key' => 'KEY-2', 'provider' => 'torod_point', 'status' => 'pending',
            'cod_amount' => 12.34, 'currency' => 'SAR', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $shipment = ProviderFulfillment::find(2);

        $this->assertSame('12.34', $shipment->cod_amount);
        $this->assertSame(2, $shipment->money_decimals);
        $this->assertSame('SAR', CurrencyCode::provider(), 'which is the currency the configuration still names');
    }

    /** Every money column of a provider shipment is widened, and the migration says which: a column left out would round the fils away on the way in. */
    public function test_every_money_column_of_a_shipment_is_widened(): void
    {
        $widen = (string) file_get_contents(database_path(self::WIDEN_MIGRATION));

        foreach (['cod_amount', 'items_total', 'shipping_allocation', 'total_amount', 'provider_cost', 'line_total'] as $column) {
            $this->assertStringContainsString("'{$column}'", $widen, "{$column} holds a shipment's money and has to make room for a third decimal");
        }

        foreach (['provider_fulfillments', 'provider_fulfillment_items'] as $table) {
            $this->assertStringContainsString("Schema::table('{$table}'", $widen, 'the table is named in full, so the guard on customer tables can read which tables this migration touches');
        }

        $create = (string) file_get_contents(database_path(self::CREATE_MIGRATION));
        $this->assertStringContainsString("string('currency', 8)->default('SAR')", $create, 'a shipment is still stamped SAR unless the configuration says otherwise');
    }

    /**
     * The parts added up in the smallest coin, which is how an amount of money is added up.
     *
     * 6.173 + 6.172 is 12.344999999999999 in binary floating point, so a sum of the parts as floats says nothing about whether a fils went
     * missing. Counted in fils, both the parts and the amount owed are whole numbers and the question has an exact answer.
     *
     * @param array<string, float> $allocated
     */
    private function inFils(array $allocated): int
    {
        return array_sum(array_map(static fn (float $part): int => (int) round($part * 1000), $allocated));
    }

    private function buildTheFulfillmentTables(): void
    {
        (require database_path(self::CREATE_MIGRATION))->up();
        (require database_path(self::WIDEN_MIGRATION))->up();
    }

    /** A currency table that answers what this test needs, so the conversion is read without touching the store's own rows. */
    private function rateSourceAnswering(string $model, string $defaultCode, int $displayDecimals = 2): void
    {
        config(['fulfillment.currency' => $defaultCode]);

        $this->app->instance(CurrencyRateSource::class, new class($model, $defaultCode, $displayDecimals) implements CurrencyRateSource {
            public function __construct(
                private readonly string $model,
                private readonly string $defaultCode,
                private readonly int $displayDecimals,
            ) {
            }

            public function model(): string
            {
                return $this->model;
            }

            public function rate(string $code): ?float
            {
                return 1.0;
            }

            public function defaultCode(): ?string
            {
                return $this->defaultCode;
            }

            public function decimals(): int
            {
                return $this->displayDecimals;
            }
        });
    }
}
