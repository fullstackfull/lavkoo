<?php

namespace Tests\Feature\StaffScope;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderTransaction;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsStaffOrderWorld;
use Tests\TestCase;

/**
 * The rows that hang off an order follow the order (GCC-14c, slice 3): the lines of an order, the money records of an order and the money records of a refund are narrowed by the
 * same rule as the orders and the refund requests, each under a subject of its own, so a screen says which of them it reads and the screens of slice 2 keep their statements.
 *
 * Products: 1 SA, 2 KW, 3 KW, 4 AE, 5 without a country, 99 does not exist. Orders: 101 SA, 102 KW, 103 KW (two lines), 104 SA and KW, 105 no line, 106 a deleted product, 107 KW
 * and a deleted product, 108 a product without a country, 109 AE. Every order has one money record (its id plus 1000), and there are two that name no order that can be seen: one
 * with no order, one with an order that does not exist. Refund transactions follow the refund requests of the scope test: 1 a Saudi line, 2 and 3 Kuwaiti lines, 4 a Saudi line
 * of the mixed order, 5 a deleted product, 6 a product without a country, 7 no line, 8 an Emirati line, 9 a line that is gone.
 */
class StaffOrderRowScopesTest extends TestCase
{
    use BuildsStaffOrderWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffOrderTables();
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        $this->insertStaffProduct(id: 5, country: '');

        foreach ([101 => [1], 102 => [2], 103 => [2, 3], 104 => [1, 2], 105 => [], 106 => [99], 107 => [2, 99], 108 => [5], 109 => [4]] as $order => $products) {
            $this->insertStaffOrder(id: $order, products: $products);
            $this->insertStaffOrderTransaction(id: $order + 1000, orderId: $order);
        }
        $this->insertStaffOrderTransaction(id: 2000, orderId: 0, extra: ['order_id' => null]);
        $this->insertStaffOrderTransaction(id: 2001, orderId: 424242);

        $this->insertStaffRefundTransaction(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefundTransaction(id: 2, orderId: 102, aboutProduct: 2);
        $this->insertStaffRefundTransaction(id: 3, orderId: 104, aboutProduct: 2);
        $this->insertStaffRefundTransaction(id: 4, orderId: 104, aboutProduct: 1);
        $this->insertStaffRefundTransaction(id: 5, orderId: 106, aboutProduct: 99);
        $this->insertStaffRefundTransaction(id: 6, orderId: 108, aboutProduct: 5);
        $this->insertStaffRefundTransaction(id: 7, orderId: 105, aboutProduct: null);
        $this->insertStaffRefundTransaction(id: 8, orderId: 109, aboutProduct: 4);
        $this->insertStaffRefundTransaction(id: 9, orderId: 102, aboutProduct: 2, extra: ['order_details_id' => 424242]);
    }

    private const ORDERS_WITH_A_LINE = [101, 102, 103, 104, 106, 107, 108, 109];

    /** @param list<string> $countries @param list<string> $subjects */
    private function narrowTo(array $countries, array $subjects, int $adminId = 7): void
    {
        $this->switchStaffScopesOn();
        DB::table('admin_country_scopes')->where('admin_id', $adminId)->delete();
        $this->giveCountries(adminId: $adminId, countries: $countries);
        $request = Request::create('/admin/dashboard');
        $this->app->instance('request', $request);
        $scope = app(StaffCountryScope::class);
        $scope->bind(request: $request, admin: Admin::query()->findOrFail($adminId));
        $scope->narrowTo(request: $request, subjects: $subjects);
    }

    /** @return list<int> the orders whose lines a query reaches, once each */
    private function ordersOfTheLines(): array
    {
        return OrderDetail::query()->orderBy('order_id')->pluck('order_id')->unique()->values()->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function transactions(): array
    {
        return OrderTransaction::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function refundTransactions(): array
    {
        return RefundTransaction::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    // ---- the lines ---------------------------------------------------------------------------------------------------------------

    public function test_a_line_is_reached_when_its_order_is_visible_to_the_employee(): void
    {
        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_ORDER_LINES]);

        $this->assertSame([102, 103], $this->ordersOfTheLines());
        $this->assertSame(3, OrderDetail::query()->count(), '102 has one line, 103 two: the lines of the mixed order 104 and of 107 are not theirs');
    }

    public function test_the_lines_of_a_mixed_order_need_an_employee_who_holds_both_countries(): void
    {
        $this->narrowTo(['SA'], [StaffCountryScope::SUBJECT_ORDER_LINES]);
        $this->assertSame([101], $this->ordersOfTheLines());

        $this->narrowTo(['SA', 'KW'], [StaffCountryScope::SUBJECT_ORDER_LINES]);
        $this->assertSame([101, 102, 103, 104], $this->ordersOfTheLines());
    }

    public function test_a_line_whose_order_cannot_be_attributed_is_hidden_from_a_restricted_employee(): void
    {
        DB::table('order_details')->insert(['order_id' => null, 'product_id' => 2]);
        DB::table('order_details')->insert(['order_id' => 424242, 'product_id' => 99]);
        $this->narrowTo(['SA', 'KW', 'AE'], [StaffCountryScope::SUBJECT_ORDER_LINES]);

        $this->assertSame([101, 102, 103, 104, 109], $this->ordersOfTheLines(), 'no line of 106, 107 or 108, of an order that is gone, and none with no order at all');
    }

    public function test_an_employee_restricted_to_nothing_reaches_no_line_no_transaction_and_no_refund_transaction(): void
    {
        $this->narrowTo(['zz'], [StaffCountryScope::SUBJECT_ORDER_LINES, StaffCountryScope::SUBJECT_ORDER_TRANSACTIONS, StaffCountryScope::SUBJECT_REFUND_TRANSACTIONS]);

        $this->assertSame([[], [], []], [$this->ordersOfTheLines(), $this->transactions(), $this->refundTransactions()]);
    }

    public function test_the_lines_a_relation_or_a_count_reads_are_the_visible_ones(): void
    {
        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_ORDER_LINES]);

        $this->assertSame(2, Order::query()->withCount('details')->find(103)->details_count);
        $this->assertSame(2, Product::query()->withCount('orderDetails')->find(2)->order_details_count, 'the product 2 is on a line of 102, 103, 104 and 107, and only 102 and 103 are theirs');
    }

    // ---- the money records of orders ----------------------------------------------------------------------------------------------

    public function test_a_money_record_is_reached_when_its_order_is_visible(): void
    {
        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_ORDER_TRANSACTIONS]);

        $this->assertSame([1102, 1103], $this->transactions());
        $this->assertSame(200.0, (float) OrderTransaction::query()->sum('order_amount'));
    }

    public function test_a_money_record_of_an_unattributable_order_is_hidden_whatever_the_countries(): void
    {
        $this->narrowTo(['SA', 'KW', 'AE'], [StaffCountryScope::SUBJECT_ORDER_TRANSACTIONS]);

        $this->assertSame([1101, 1102, 1103, 1104, 1109], $this->transactions(), 'not the ones of 105, 106, 107, 108, of no order and of an order that is gone');
    }

    // ---- the money records of refunds ---------------------------------------------------------------------------------------------

    public function test_a_refund_money_record_follows_the_line_it_is_about_like_the_refund_request_does(): void
    {
        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_REFUND_TRANSACTIONS]);
        $this->assertSame([2, 3], $this->refundTransactions());

        $this->narrowTo(['SA'], [StaffCountryScope::SUBJECT_REFUND_TRANSACTIONS]);
        $this->assertSame([1, 4], $this->refundTransactions());

        $this->narrowTo(['AE'], [StaffCountryScope::SUBJECT_REFUND_TRANSACTIONS]);
        $this->assertSame([8], $this->refundTransactions());
    }

    public function test_a_refund_money_record_about_no_line_a_gone_line_or_a_product_without_a_country_is_hidden(): void
    {
        $this->narrowTo(['SA', 'KW', 'AE'], [StaffCountryScope::SUBJECT_REFUND_TRANSACTIONS]);

        $this->assertSame([1, 2, 3, 4, 8], $this->refundTransactions());
    }

    // ---- each subject on its own --------------------------------------------------------------------------------------------------

    public function test_a_request_narrowed_to_orders_alone_reaches_every_line_and_every_money_record_so_the_screens_of_slice_2_ask_what_they_always_asked(): void
    {
        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_ORDERS, StaffCountryScope::SUBJECT_REFUNDS]);

        $this->assertSame([102, 103], Order::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(self::ORDERS_WITH_A_LINE, $this->ordersOfTheLines());
        $this->assertCount(11, $this->transactions());
        $this->assertCount(9, $this->refundTransactions());
    }

    public function test_each_subject_narrows_its_own_rows_and_no_other(): void
    {
        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_ORDER_LINES]);
        $this->assertSame([[102, 103], 11, 9], [$this->ordersOfTheLines(), count($this->transactions()), count($this->refundTransactions())]);

        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_ORDER_TRANSACTIONS]);
        $this->assertSame([self::ORDERS_WITH_A_LINE, [1102, 1103], 9], [$this->ordersOfTheLines(), $this->transactions(), count($this->refundTransactions())]);

        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_REFUND_TRANSACTIONS]);
        $this->assertSame([self::ORDERS_WITH_A_LINE, 11, [2, 3]], [$this->ordersOfTheLines(), count($this->transactions()), $this->refundTransactions()]);
    }

    // ---- a request that is not narrowed ---------------------------------------------------------------------------------------------

    public function test_a_request_that_is_not_narrowed_reaches_every_row(): void
    {
        $this->assertSame(self::ORDERS_WITH_A_LINE, $this->ordersOfTheLines());
        $this->assertCount(11, $this->transactions());
        $this->assertCount(9, $this->refundTransactions());
    }

    public function test_the_master_admin_and_an_employee_with_no_country_reach_every_row_even_when_a_screen_asks_for_the_subjects(): void
    {
        foreach ([1, 8] as $adminId) {
            $this->narrowTo([], [StaffCountryScope::SUBJECT_ORDER_LINES, StaffCountryScope::SUBJECT_ORDER_TRANSACTIONS, StaffCountryScope::SUBJECT_REFUND_TRANSACTIONS], adminId: $adminId);

            $this->assertSame([self::ORDERS_WITH_A_LINE, 11, 9], [$this->ordersOfTheLines(), count($this->transactions()), count($this->refundTransactions())], 'employee ' . $adminId);
        }
    }

    public function test_the_statements_are_the_ones_of_before_for_a_request_that_is_not_narrowed_for_the_subject(): void
    {
        $unbound = [OrderDetail::query()->toSql(), OrderTransaction::query()->toSql(), RefundTransaction::query()->toSql()];
        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_ORDERS, StaffCountryScope::SUBJECT_REFUNDS]);

        $this->assertSame($unbound, [OrderDetail::query()->toSql(), OrderTransaction::query()->toSql(), RefundTransaction::query()->toSql()]);
        $this->assertStringNotContainsString('visible_lines', OrderDetail::query()->toSql());
    }

    public function test_a_narrowed_read_of_the_rows_is_one_statement_whatever_the_number_of_orders(): void
    {
        $this->narrowTo(['KW'], [StaffCountryScope::SUBJECT_ORDER_LINES, StaffCountryScope::SUBJECT_ORDER_TRANSACTIONS]);
        $counted = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            OrderDetail::query()->get();
            OrderTransaction::query()->get();

            return count(DB::getQueryLog());
        };
        $few = $counted();

        for ($id = 300; $id < 340; $id++) {
            $this->insertStaffOrder(id: $id, products: [2, 3]);
            $this->insertStaffOrderTransaction(id: $id + 1000, orderId: $id);
        }

        $this->assertSame($few, $counted(), 'a subquery for the whole read, never a query per order: only the lines and the records, and what the line model loads beside them');
        $this->assertSame(3 + 80, OrderDetail::query()->count());
    }

    public function test_the_scopes_of_the_rows_carry_nothing_but_the_countries(): void
    {
        $this->narrowTo(['KW', 'SA'], [StaffCountryScope::SUBJECT_ORDER_LINES, StaffCountryScope::SUBJECT_ORDER_TRANSACTIONS, StaffCountryScope::SUBJECT_REFUND_TRANSACTIONS]);

        foreach ([OrderDetail::query(), OrderTransaction::query(), RefundTransaction::query()] as $query) {
            $this->assertSame(['KW', 'SA'], $query->toBase()->getBindings(), get_class($query->getModel()));
        }
    }
}
