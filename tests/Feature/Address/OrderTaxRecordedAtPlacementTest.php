<?php

namespace Tests\Feature\Address;

use App\Models\Product;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Tests\Support\BuildsTaxModuleWorld;

/**
 * D1-07 of the master plan, as it is on `main`: the tax an order is placed under is read from the one default tax setup of the store, and what the order records of it
 * is its type, its model, the amount on the order and on each line, and one row per tax rate in `order_taxes`. Nothing here knows a country or a storefront: a GCC branch
 * that chooses the setup by the storefront's country, or records the tax of a tax-included price, must leave every case below unchanged while its switch is off.
 *
 * The tests do not say the tax rules of any country are right; they say what the placed order keeps, so that a change to it is a decision and not an accident.
 */
class OrderTaxRecordedAtPlacementTest extends ApiAddressTestCase
{
    use BuildsTaxModuleWorld;

    private const COD = '/api/v1/customer/order/place';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('DB_STRICT_TESTS') === '1' && !method_exists(OrderManager::class, 'countCouponUses')) {
            $this->markTestSkipped('The coupon count of the checkout is still a bare GROUP BY that a strict SQL server refuses, so the checkout of main answers 500 there (PR 89 fixes it).');
        }

        $this->createOrderPipelineTables();
        $this->createTaxModuleTables();
        $this->seedTheTaxRates();
    }

    private function placeTheOrder(int $quantityOfTheFirstProduct = 1): int
    {
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);
        DB::table('carts')->where('product_id', 1)->update(['quantity' => $quantityOfTheFirstProduct]);

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        return (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');
    }

    /** @return array<int, array<string, mixed>> */
    private function orderTaxRows(): array
    {
        return DB::table('order_taxes')->orderBy('id')->get()->map(fn (object $row): array => [
            'name' => $row->tax_name,
            'type' => $row->tax_type,
            'on' => $row->tax_on,
            'rate' => round((float) $row->tax_rate, 2),
            'amount' => round((float) $row->tax_amount, 2),
            'before' => round((float) $row->before_tax_amount, 2),
            'after' => round((float) $row->after_tax_amount, 2),
            'quantity' => (int) $row->quantity,
            'payer' => $row->tax_payer,
            'order_type' => $row->order_type,
            'seller_id' => (int) $row->seller_id,
            'seller_type' => $row->seller_type,
            'setup' => (int) $row->system_tax_setup_id,
            'for' => $row->tax_for,
        ])->all();
    }

    /** @return array{type: ?string, model: ?string, tax: float, amount: float} what the order keeps of its tax */
    private function taxOfOrder(int $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();

        return ['type' => $order->tax_type, 'model' => $order->tax_model, 'tax' => round((float) $order->total_tax_amount, 2), 'amount' => round((float) $order->order_amount, 2)];
    }

    public function test_an_order_wise_setup_records_the_sum_of_its_rates_on_the_order_and_its_line(): void
    {
        $setup = $this->openTheTaxSetup();

        $orderId = $this->placeTheOrder();

        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 20.0, 'amount' => 120.0], $this->taxOfOrder($orderId));
        $line = DB::table('order_details')->where('order_id', $orderId)->first();
        $this->assertEquals(20, $line->tax);
        $this->assertSame('exclude', $line->tax_model);
        $this->assertSame($setup->id, (int) DB::table('order_taxes')->value('system_tax_setup_id'));
    }

    public function test_it_writes_one_row_per_rate_with_the_amounts_of_that_rate(): void
    {
        $setup = $this->openTheTaxSetup();

        $this->placeTheOrder();

        $this->assertSame([
            ['name' => 'VAT', 'type' => 'order_wise', 'on' => 'basic', 'rate' => 15.0, 'amount' => 15.0, 'before' => 100.0, 'after' => 120.0, 'quantity' => 1, 'payer' => 'vendor', 'order_type' => 'order', 'seller_id' => 1, 'seller_type' => 'admin', 'setup' => $setup->id, 'for' => 'tax_for_orders'],
            ['name' => 'Municipality fee', 'type' => 'order_wise', 'on' => 'basic', 'rate' => 5.0, 'amount' => 5.0, 'before' => 100.0, 'after' => 120.0, 'quantity' => 1, 'payer' => 'vendor', 'order_type' => 'order', 'seller_id' => 1, 'seller_type' => 'admin', 'setup' => $setup->id, 'for' => 'tax_for_orders'],
        ], $this->orderTaxRows());
    }

    public function test_a_row_of_an_order_tax_is_attached_to_no_taxable_and_says_so_with_null(): void
    {
        $this->openTheTaxSetup();

        $this->placeTheOrder();

        $this->assertSame(2, DB::table('order_taxes')->count());
        $this->assertSame(0, DB::table('order_taxes')->whereNotNull('taxable_id')->count(), 'the column is an integer: an empty string in it is refused by a strict SQL server');
        $this->assertSame(0, DB::table('order_taxes')->whereNotNull('taxable_type')->count());
    }

    public function test_the_tax_follows_the_quantity_of_the_line(): void
    {
        $this->openTheTaxSetup();

        $orderId = $this->placeTheOrder(quantityOfTheFirstProduct: 3);

        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 60.0, 'amount' => 360.0], $this->taxOfOrder($orderId));
        $this->assertSame([[45.0, 300.0, 3], [15.0, 300.0, 3]], array_map(fn (array $row): array => [$row['amount'], $row['before'], $row['quantity']], $this->orderTaxRows()));
    }

    public function test_a_tax_that_is_switched_off_is_left_out_whatever_the_setup_lists(): void
    {
        $this->openTheTaxSetup(taxIds: [self::VAT, self::RETIRED_LEVY]);

        $orderId = $this->placeTheOrder();

        $this->assertSame(15.0, $this->taxOfOrder($orderId)['tax']);
        $this->assertSame(['VAT'], array_column($this->orderTaxRows(), 'name'));
    }

    /**
     * Finding for the owner, pinned and not changed here: the rows of a rate divide the tax of a line by the sum of the rates of every line of the seller's cart, so in a cart of
     * two lines each row keeps half of its line's tax. The order (30) and its lines (20 and 10) are right, the four rows add up to 15. A report that adds the rows up
     * under-reports the tax of every order of two lines or more. When it is corrected, change the four amounts below on purpose.
     */
    public function test_the_rows_of_a_cart_of_two_lines_keep_a_share_of_the_tax_of_their_line_and_the_order_and_its_lines_are_right(): void
    {
        $this->openTheTaxSetup();
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->insert(['id' => 2, 'name' => 'Ibuprofen', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 50, 'current_stock' => 10]);
        DB::table('carts')->insert([
            'customer_id' => $customer->id, 'is_guest' => false, 'cart_group_id' => 'group-' . $customer->id . 'c', 'product_id' => 2, 'quantity' => 1,
            'price' => 50, 'seller_id' => 1, 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        $orderId = (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');
        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 30.0, 'amount' => 180.0], $this->taxOfOrder($orderId));
        $taxByProduct = DB::table('order_details')->where('order_id', $orderId)->orderBy('product_id')->pluck('tax', 'product_id')->map(fn ($tax): float => round((float) $tax, 2))->all();
        $this->assertSame([1 => 20.0, 2 => 10.0], $taxByProduct);
        $rows = $this->orderTaxRows();
        $this->assertSame([[7.5, 100.0], [2.5, 100.0], [3.75, 50.0], [1.25, 50.0]], array_map(fn (array $row): array => [$row['amount'], $row['before']], $rows));
        $this->assertSame(15.0, round(array_sum(array_column($rows, 'amount')), 2));
    }

    public function test_a_product_wise_setup_taxes_the_products_that_carry_a_tax_and_no_other(): void
    {
        $setup = $this->openTheTaxSetup(type: 'product_wise', taxIds: []);
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->insert(['id' => 2, 'name' => 'Ibuprofen', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 50, 'current_stock' => 10]);
        DB::table('carts')->insert([
            'customer_id' => $customer->id, 'is_guest' => false, 'cart_group_id' => 'group-' . $customer->id . 'c', 'product_id' => 2, 'quantity' => 1,
            'price' => 50, 'seller_id' => 1, 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('taxables')->insert([
            'taxable_type' => Product::class, 'taxable_id' => 1, 'system_tax_setup_id' => $setup->id, 'tax_id' => self::VAT, 'tax_for' => 'tax_for_orders',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::flush();

        $this->asCustomer($customer)->getJson(self::COD)->assertOk();

        $orderId = (int) DB::table('orders')->where('customer_id', $customer->id)->value('id');
        $this->assertSame(['type' => 'product_wise', 'model' => 'exclude', 'tax' => 15.0, 'amount' => 165.0], $this->taxOfOrder($orderId));
        $taxByProduct = DB::table('order_details')->where('order_id', $orderId)->orderBy('product_id')->pluck('tax', 'product_id')->map(fn ($tax): float => round((float) $tax, 2))->all();
        $this->assertSame([1 => 15.0, 2 => 0.0], $taxByProduct);
        $this->assertSame(['VAT'], array_column($this->orderTaxRows(), 'name'));
    }

    public function test_a_tax_included_setup_computes_and_records_no_tax_at_all(): void
    {
        $this->openTheTaxSetup(included: true);

        $orderId = $this->placeTheOrder();

        $this->assertSame(['type' => 'order_wise', 'model' => 'include', 'tax' => 0.0, 'amount' => 100.0], $this->taxOfOrder($orderId));
        $this->assertSame('include', DB::table('order_details')->where('order_id', $orderId)->value('tax_model'));
        $this->assertSame([], $this->orderTaxRows());
    }

    public function test_a_rate_changed_after_the_order_does_not_move_what_the_order_kept(): void
    {
        $this->openTheTaxSetup();
        $orderId = $this->placeTheOrder();
        $before = [$this->taxOfOrder($orderId), $this->orderTaxRows(), (float) DB::table('order_details')->where('order_id', $orderId)->value('tax')];

        DB::table('taxes')->where('id', self::VAT)->update(['tax_rate' => 5]);
        SystemTaxSetup::query()->update(['is_included' => 1]);
        Cache::flush();

        $this->assertSame($before, [$this->taxOfOrder($orderId), $this->orderTaxRows(), (float) DB::table('order_details')->where('order_id', $orderId)->value('tax')]);
    }

    public function test_a_store_with_no_tax_setup_places_its_order_without_tax_and_the_checkout_makes_an_inactive_default_setup(): void
    {
        $orderId = $this->placeTheOrder();

        $this->assertSame(['type' => 'order_wise', 'model' => 'exclude', 'tax' => 0.0, 'amount' => 100.0], $this->taxOfOrder($orderId));
        $this->assertSame([], $this->orderTaxRows());
        $this->assertSame(1, SystemTaxSetup::query()->where('is_default', 1)->where('is_active', 0)->count(), 'a read of the tax setup of a store that has none writes one');
    }
}
