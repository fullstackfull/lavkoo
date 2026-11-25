<?php

namespace Tests\Feature\Checkout;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsTaxModuleWorld;

/**
 * D1-07: what a TAX-INCLUDED order records of the tax inside its price.
 *
 * On this platform a store running the tax module in `is_included` mode records no tax on its orders at all -- `total_tax_amount` 0, every
 * line's `tax` 0, not one row in `order_taxes` -- while the order's own `tax_model` says `include`. The money is right and the record is a
 * claim that cannot be true of a taxed sale, and nothing can answer what VAT an order of that store collected.
 *
 * The tax inside the price is now worked out and written into two columns of its own, behind `gcc.tax.record_included_amount`, which ships
 * off. The first three tests are the off case, to the digit, including the columns staying NULL while they exist. The rest are what is
 * recorded when the question is answered, and every one of them re-asserts that no figure the customer was charged moved.
 */
class IncludedTaxRecordedOnTheOrderTest extends ApiAddressTestCase
{
    use BuildsTaxModuleWorld;

    private const COD = '/api/v1/customer/order/place';

    /** 15 (VAT) + 5 (municipality fee) on a gross of 100: 100 * 20 / 120, and not the 20 a net 100 would attract. */
    private const INSIDE_A_HUNDRED = 16.67;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->createTaxModuleTables();
        $this->seedTheTaxRates();
        $this->runTheIncludedTaxMigration();
    }

    /** The columns exactly as production gets them: the real migration, never a hand-built copy of it. */
    private function runTheIncludedTaxMigration(): void
    {
        (require database_path('migrations/2026_12_27_100000_add_included_tax_to_orders_and_order_details.php'))->up();
    }

    private function recordTheIncludedTax(): void
    {
        config(['gcc.tax.record_included_amount' => true]);
    }

    /** Places a cash-on-delivery order of one product priced 100. */
    private function placeTheOrder(): int
    {
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        return (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');
    }

    /** Places a cash-on-delivery order of two lines of the one seller: product 1 at 100 and product 2 at 50. */
    private function placeTwoLines(array $shippingOfTheLines = []): int
    {
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->insert(['id' => 2, 'name' => 'Ibuprofen', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 50, 'current_stock' => 10]);
        DB::table('carts')->insert([
            'customer_id' => $customer->id, 'is_guest' => false, 'cart_group_id' => 'group-' . $customer->id . 'c', 'product_id' => 2, 'quantity' => 1,
            'price' => 50, 'seller_id' => 1, 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('carts')->update(['product_type' => 'physical', 'is_checked' => 1]);
        foreach ($shippingOfTheLines as $productId => $cost) {
            DB::table('products')->where('id', $productId)->update(['shipping_cost' => $cost]);
        }

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        return (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');
    }

    /** @return array{model: ?string, charged_tax: float, amount: float, included_tax: float|null} */
    private function taxOfOrder(int $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();

        return [
            'model' => $order->tax_model,
            'charged_tax' => round((float) $order->total_tax_amount, 2),
            'amount' => round((float) $order->order_amount, 2),
            'included_tax' => $order->included_tax_amount === null ? null : round((float) $order->included_tax_amount, 2),
        ];
    }

    /** @return array<int, array{tax: float, included: float|null}> what each line of the order keeps, by product */
    private function taxOfTheLines(int $orderId): array
    {
        return DB::table('order_details')->where('order_id', $orderId)->orderBy('product_id')->get()
            ->mapWithKeys(fn (object $line): array => [(int) $line->product_id => [
                'tax' => round((float) $line->tax, 2),
                'included' => $line->included_tax === null ? null : round((float) $line->included_tax, 2),
            ]])->all();
    }

    public function test_with_the_setting_off_a_tax_included_order_records_no_tax_at_all_and_both_columns_stay_null(): void
    {
        $this->openTheTaxSetup(included: true);

        $orderId = $this->placeTheOrder();

        $this->assertSame(['model' => 'include', 'charged_tax' => 0.0, 'amount' => 100.0, 'included_tax' => null], $this->taxOfOrder($orderId));
        $this->assertSame([1 => ['tax' => 0.0, 'included' => null]], $this->taxOfTheLines($orderId));
        $this->assertSame(0, DB::table('order_taxes')->count(), 'the off case writes no tax row either');
    }

    public function test_with_the_setting_on_an_excluded_tax_order_is_untouched_because_nothing_is_inside_its_price(): void
    {
        $this->recordTheIncludedTax();
        $this->openTheTaxSetup();

        $orderId = $this->placeTheOrder();

        $this->assertSame(['model' => 'exclude', 'charged_tax' => 20.0, 'amount' => 120.0, 'included_tax' => null], $this->taxOfOrder($orderId));
        $this->assertSame([1 => ['tax' => 20.0, 'included' => null]], $this->taxOfTheLines($orderId));
        $this->assertSame(2, DB::table('order_taxes')->count(), 'and its per-rate rows are still written');
    }

    public function test_a_store_whose_database_has_not_run_the_migration_places_its_order_exactly_as_before(): void
    {
        $this->dropTheIncludedTaxColumns();
        $this->recordTheIncludedTax();
        $this->openTheTaxSetup(included: true);

        $orderId = $this->placeTheOrder();

        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertSame('include', $order->tax_model);
        $this->assertSame(0.0, round((float) $order->total_tax_amount, 2));
        $this->assertSame(100.0, round((float) $order->order_amount, 2));
        $this->assertSame(0.0, round((float) DB::table('order_details')->where('order_id', $orderId)->value('tax'), 2));
    }

    public function test_the_tax_inside_the_price_is_recorded_on_the_order_and_its_line_and_no_charged_figure_moves(): void
    {
        $this->recordTheIncludedTax();
        $this->openTheTaxSetup(included: true);

        $orderId = $this->placeTheOrder();

        $this->assertSame(
            ['model' => 'include', 'charged_tax' => 0.0, 'amount' => 100.0, 'included_tax' => self::INSIDE_A_HUNDRED],
            $this->taxOfOrder($orderId),
            'the customer still pays 100 and the order still charges no tax on top: only the record of the tax inside the 100 is new',
        );
        $this->assertSame([1 => ['tax' => 0.0, 'included' => self::INSIDE_A_HUNDRED]], $this->taxOfTheLines($orderId));
        $this->assertSame(0, DB::table('order_taxes')->count(), 'the per-rate rows of an included order are a separate question and are not written');
    }

    public function test_the_recorded_amount_keeps_more_than_two_decimals_so_a_third_decimal_currency_survives(): void
    {
        $this->recordTheIncludedTax();
        $this->openTheTaxSetup(included: true);

        $orderId = $this->placeTheOrder();

        $this->assertSame(
            16.666667,
            round((float) DB::table('orders')->where('id', $orderId)->value('included_tax_amount'), 6),
            'rounding belongs to the currency of the order, which the column does not know, so nothing is rounded on the way in',
        );
    }

    public function test_each_line_of_a_cart_records_the_tax_inside_its_own_price_and_the_order_records_their_sum(): void
    {
        $this->recordTheIncludedTax();
        $this->openTheTaxSetup(included: true);

        $orderId = $this->placeTwoLines();

        $this->assertSame(['model' => 'include', 'charged_tax' => 0.0, 'amount' => 150.0, 'included_tax' => 25.0], $this->taxOfOrder($orderId));
        $this->assertSame(
            [1 => ['tax' => 0.0, 'included' => self::INSIDE_A_HUNDRED], 2 => ['tax' => 0.0, 'included' => 8.33]],
            $this->taxOfTheLines($orderId),
        );
    }

    public function test_the_tax_inside_the_shipping_charge_is_on_the_order_and_not_on_the_lines(): void
    {
        $this->recordTheIncludedTax();
        $setup = $this->openTheTaxSetup(included: true);
        DB::table('tax_additional_setups')->insert(['name' => 'shipping', 'system_tax_setup_id' => $setup->id, 'tax_ids' => json_encode([self::VAT]), 'is_active' => 1]);
        DB::table('shipping_types')->insert(['seller_id' => 0, 'shipping_type' => 'product_wise']);

        $orderId = $this->placeTwoLines(shippingOfTheLines: [1 => 10, 2 => 20]);

        // 20 and 10 of the 30 charged for shipping, each holding 15 / 115 of itself: 2.6087 and 1.3043 on top of the 25 inside the goods.
        $this->assertSame(['model' => 'include', 'charged_tax' => 0.0, 'amount' => 180.0, 'included_tax' => 28.91], $this->taxOfOrder($orderId));
        $this->assertSame(
            [1 => ['tax' => 0.0, 'included' => self::INSIDE_A_HUNDRED], 2 => ['tax' => 0.0, 'included' => 8.33]],
            $this->taxOfTheLines($orderId),
            "a line's column mirrors `tax`, which is the goods only: the shipping tax is the order's",
        );
    }

    public function test_a_discount_lowers_the_tax_inside_the_line_because_the_gross_is_what_the_customer_pays(): void
    {
        $this->recordTheIncludedTax();
        $this->openTheTaxSetup(included: true);
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->where('id', 1)->update(['discount' => 20, 'discount_type' => 'flat']);

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        // 80 is what the customer pays, so 80 is the gross the tax is inside: 80 * 20 / 120, not 100 * 20 / 120.
        $orderId = (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');
        $this->assertSame(['model' => 'include', 'charged_tax' => 0.0, 'amount' => 80.0, 'included_tax' => 13.33], $this->taxOfOrder($orderId));
        $this->assertSame([1 => ['tax' => 0.0, 'included' => 13.33]], $this->taxOfTheLines($orderId));
    }

    private function dropTheIncludedTaxColumns(): void
    {
        if (Schema::hasColumn('orders', 'included_tax_amount')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('included_tax_amount'));
        }

        if (Schema::hasColumn('order_details', 'included_tax')) {
            Schema::table('order_details', fn (Blueprint $table) => $table->dropColumn('included_tax'));
        }
    }
}
