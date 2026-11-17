<?php

namespace Tests\Feature\StaffScope;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\RefundRequest;
use App\Models\Scopes\StaffCountryOrderScope;
use App\Models\Scopes\StaffCountryRefundScope;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsStaffOrderWorld;
use Tests\TestCase;

/**
 * What an order and a refund request are to a restricted employee (GCC-14c, slice 2), at the level of the models: every query of a request that is narrowed to orders
 * reaches the orders whose every line is a product of the employee's countries, and nothing else.
 *
 * An order carries no country: it follows from its lines. It is visible only when it has at least one line and EVERY line's product exists and belongs to one of the
 * employee's countries, so an order that holds two countries needs an employee who holds both, and an order with no line, a deleted product or a product without a country is
 * unattributed and stays hidden from a restricted employee (it fails closed). A refund request follows the country of the order line it is about.
 *
 * Products: 1 SA, 2 KW, 3 KW, 4 AE, 5 without a country, 99 does not exist.
 */
class StaffOrderScopeTest extends TestCase
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

        $this->insertStaffOrder(id: 101, products: [1]);
        $this->insertStaffOrder(id: 102, products: [2]);
        $this->insertStaffOrder(id: 103, products: [2, 3]);
        $this->insertStaffOrder(id: 104, products: [1, 2]);
        $this->insertStaffOrder(id: 105, products: []);
        $this->insertStaffOrder(id: 106, products: [99]);
        $this->insertStaffOrder(id: 107, products: [2, 99]);
        $this->insertStaffOrder(id: 108, products: [5]);
        $this->insertStaffOrder(id: 109, products: [4]);
    }

    /** @param list<string> $subjects */
    private function narrowRequestFor(int $adminId, array $subjects = [StaffCountryScope::SUBJECT_ORDERS, StaffCountryScope::SUBJECT_REFUNDS]): void
    {
        $request = Request::create('/admin/orders/list/all');
        $this->app->instance('request', $request);
        $scope = app(StaffCountryScope::class);
        $scope->bind(request: $request, admin: Admin::query()->findOrFail($adminId));
        $scope->narrowTo(request: $request, subjects: $subjects);
    }

    private function employeeWith(array $countries, int $adminId = 7): void
    {
        $this->switchStaffScopesOn();
        DB::table('admin_country_scopes')->where('admin_id', $adminId)->delete();
        $this->giveCountries(adminId: $adminId, countries: $countries);
        $this->narrowRequestFor(adminId: $adminId);
    }

    /** @return list<int> */
    private function visibleOrders(): array
    {
        return Order::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    // ---- who sees which order ---------------------------------------------------------------------------------------------------------

    public function test_an_order_is_visible_when_every_line_is_a_product_of_the_employees_countries(): void
    {
        $this->employeeWith(['KW']);

        $this->assertSame([102, 103], $this->visibleOrders());
    }

    public function test_an_order_of_two_countries_needs_an_employee_who_holds_both(): void
    {
        $this->employeeWith(['SA']);
        $this->assertSame([101], $this->visibleOrders(), 'Saudi only: the order that holds a Kuwaiti line too is not theirs');

        $this->employeeWith(['KW', 'SA']);
        $this->assertSame([101, 102, 103, 104], $this->visibleOrders(), 'both: the mixed order is theirs');
    }

    public function test_an_order_with_no_line_a_deleted_product_or_a_product_without_a_country_is_hidden_from_a_restricted_employee(): void
    {
        $this->employeeWith(['SA', 'KW', 'AE']);

        $this->assertSame([101, 102, 103, 104, 109], $this->visibleOrders(), '105 has no line, 106 and 107 hold a deleted product, 108 a product without a country');
    }

    public function test_an_employee_restricted_to_nothing_sees_no_order_at_all(): void
    {
        $this->employeeWith(['zz']);

        $this->assertSame([], $this->visibleOrders());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_every_kind_of_query_of_the_model_is_narrowed(): void
    {
        $this->employeeWith(['KW']);

        $this->assertSame(2, Order::query()->count());
        $this->assertNull(Order::query()->find(101));
        $this->assertSame(102, Order::query()->find(102)->id);
        $this->assertSame([102], Order::query()->whereIn('id', [101, 102, 104, 106])->pluck('id')->all());
        $this->assertFalse(Order::query()->where('id', 104)->exists());
        $this->assertSame(0, Order::query()->where('order_status', 'pending')->where('id', 101)->count());
        $this->assertSame(['pending' => 2], Order::query()->selectRaw('order_status, COUNT(*) as total')->groupBy('order_status')->pluck('total', 'order_status')->all());
        $this->assertSame([103, 102], Order::query()->orderByDesc('id')->get()->pluck('id')->all());
        $this->assertSame(2, Order::query()->paginate(10)->total());
    }

    public function test_a_search_that_ends_in_an_or_condition_cannot_let_another_countrys_order_through(): void
    {
        $this->employeeWith(['KW']);
        DB::table('orders')->where('id', 101)->update(['transaction_ref' => 'NEEDLE']);

        $found = Order::query()->where(fn ($query) => $query->where('id', 'like', '%NEEDLE%')->orWhere('transaction_ref', 'like', '%NEEDLE%'))->pluck('id')->all();

        $this->assertSame([], $found);
    }

    public function test_an_update_through_the_model_only_reaches_the_orders_the_employee_sees(): void
    {
        $this->employeeWith(['KW']);

        $updated = Order::query()->where('checked', 0)->update(['checked' => 1]);

        $this->assertSame(2, $updated);
        $this->assertSame([102, 103], DB::table('orders')->where('checked', 1)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    public function test_a_relation_that_goes_through_the_orders_is_narrowed(): void
    {
        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2);
        $this->employeeWith(['KW']);

        $this->assertSame([2], RefundRequest::withoutGlobalScope(StaffCountryRefundScope::class)->whereHas('order')->pluck('id')->all());
        $this->assertSame(['order' => null], RefundRequest::withoutGlobalScope(StaffCountryRefundScope::class)->with('order')->find(1)->only('order'));
    }

    // ---- who sees which refund --------------------------------------------------------------------------------------------------------

    private function insertRefunds(): void
    {
        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2);
        $this->insertStaffRefund(id: 3, orderId: 104, aboutProduct: 2);
        $this->insertStaffRefund(id: 4, orderId: 104, aboutProduct: 1);
        $this->insertStaffRefund(id: 5, orderId: 106, aboutProduct: 99);
        $this->insertStaffRefund(id: 6, orderId: 108, aboutProduct: 5);
        $this->insertStaffRefund(id: 7, orderId: 105, aboutProduct: null);
        $this->insertStaffRefund(id: 8, orderId: 109, aboutProduct: 4);
        $this->insertStaffRefund(id: 9, orderId: 102, aboutProduct: 2, extra: ['order_details_id' => 424242]);
    }

    /** @return list<int> */
    private function visibleRefunds(): array
    {
        return RefundRequest::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function test_a_refund_follows_the_country_of_the_order_line_it_is_about(): void
    {
        $this->insertRefunds();

        $this->employeeWith(['KW']);
        $this->assertSame([2, 3], $this->visibleRefunds(), 'the Kuwaiti line of a mixed order is Kuwait\'s, although the order is not');

        $this->employeeWith(['SA']);
        $this->assertSame([1, 4], $this->visibleRefunds());

        $this->employeeWith(['AE']);
        $this->assertSame([8], $this->visibleRefunds());

        $this->employeeWith(['KW', 'SA']);
        $this->assertSame([1, 2, 3, 4], $this->visibleRefunds());
    }

    public function test_a_refund_about_a_deleted_product_a_product_without_a_country_no_line_or_a_line_that_is_gone_is_hidden(): void
    {
        $this->insertRefunds();
        $this->employeeWith(['SA', 'KW', 'AE']);

        $this->assertSame([1, 2, 3, 4, 8], $this->visibleRefunds(), '5 is about a deleted product, 6 a product without a country, 7 about no line, 9 about a line that is gone');
    }

    public function test_an_employee_restricted_to_nothing_sees_no_refund(): void
    {
        $this->insertRefunds();
        $this->employeeWith(['zz']);

        $this->assertSame([], $this->visibleRefunds());
    }

    // ---- a request that is not narrowed -----------------------------------------------------------------------------------------------

    public function test_a_request_that_is_not_narrowed_sees_every_order_and_refund(): void
    {
        $this->insertRefunds();

        $this->assertSame([101, 102, 103, 104, 105, 106, 107, 108, 109], $this->visibleOrders());
        $this->assertCount(9, $this->visibleRefunds());
    }

    public function test_a_bound_request_is_narrowed_only_for_the_subjects_it_asked_for(): void
    {
        $this->insertRefunds();
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW']);

        $this->narrowRequestFor(adminId: 7, subjects: []);
        $this->assertCount(9, $this->visibleOrders(), 'bound, but nothing asked for: the business logic of an action sees every order');
        $this->assertCount(9, $this->visibleRefunds());

        $this->narrowRequestFor(adminId: 7, subjects: [StaffCountryScope::SUBJECT_ORDERS]);
        $this->assertSame([102, 103], $this->visibleOrders());
        $this->assertCount(9, $this->visibleRefunds());

        $this->narrowRequestFor(adminId: 7, subjects: [StaffCountryScope::SUBJECT_REFUNDS]);
        $this->assertCount(9, $this->visibleOrders());
        $this->assertSame([2, 3], $this->visibleRefunds());
    }

    public function test_the_master_admin_and_an_employee_with_no_country_are_never_narrowed(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);

        $this->narrowRequestFor(adminId: 1);
        $this->assertCount(9, $this->visibleOrders());

        $this->narrowRequestFor(adminId: 7);
        $this->assertCount(9, $this->visibleOrders(), 'no row, so no restriction');
    }

    public function test_with_the_switch_off_an_employee_with_countries_sees_everything(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);

        $this->narrowRequestFor(adminId: 7);

        $this->assertCount(9, $this->visibleOrders());
    }

    public function test_the_narrowing_belongs_to_the_request_so_a_later_request_starts_unnarrowed(): void
    {
        $this->employeeWith(['KW']);
        $this->assertCount(2, $this->visibleOrders());

        $this->app->instance('request', Request::create('/'));

        $this->assertCount(9, $this->visibleOrders());
    }

    public function test_the_scope_can_be_set_aside_by_name_for_the_one_query_that_must_see_every_order(): void
    {
        $this->employeeWith(['KW']);

        $this->assertCount(9, Order::withoutGlobalScope(StaffCountryOrderScope::class)->get());
        $this->assertSame(2, OrderDetail::query()->where('product_id', 1)->count(), 'the lines are not narrowed by themselves: they are reached through their order');
    }

    // ---- the SQL ----------------------------------------------------------------------------------------------------------------------

    public function test_a_request_that_is_not_narrowed_runs_exactly_the_query_it_always_did(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->narrowRequestFor(adminId: 7, subjects: []);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Order::query()->where('order_status', 'pending')->count();
        $boundButNotNarrowed = array_column(DB::getQueryLog(), 'query');

        $this->app->instance('request', Request::create('/'));
        DB::flushQueryLog();
        Order::query()->where('order_status', 'pending')->count();

        $this->assertCount(1, $boundButNotNarrowed);
        $this->assertSame(array_column(DB::getQueryLog(), 'query'), $boundButNotNarrowed);
    }

    public function test_a_narrowed_list_is_one_query_whatever_the_number_of_orders(): void
    {
        $this->employeeWith(['KW']);
        $counted = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Order::query()->orderBy('id')->get();

            return count(DB::getQueryLog());
        };
        $few = $counted();

        for ($id = 200; $id < 260; $id++) {
            $this->insertStaffOrder(id: $id, products: [2, 3]);
        }

        $this->assertSame(1, $few);
        $this->assertSame($few, $counted(), 'no query per row');
        $this->assertCount(62, $this->visibleOrders());
    }

    public function test_the_narrowing_is_made_of_correlated_conditions_and_carries_nothing_but_the_countries(): void
    {
        $this->employeeWith(['KW', 'SA']);

        $query = Order::query()->toBase();

        $this->assertStringContainsString('exists', $query->toSql());
        $this->assertStringContainsString('not exists', $query->toSql());
        $this->assertSame(['KW', 'SA'], $query->getBindings(), 'no id of an order, a line or a product is in the statement: it holds for any number of orders');
    }

    // ---- the checks of an id ----------------------------------------------------------------------------------------------------------

    public function test_the_ids_a_request_names_are_covered_only_when_every_one_is_visible(): void
    {
        $this->insertRefunds();
        $this->employeeWith(['KW']);
        $scope = app(StaffCountryScope::class);

        $this->assertTrue($scope->coversEveryOrder(orderIds: [102]));
        $this->assertTrue($scope->coversEveryOrder(orderIds: [102, 103, 102]), 'a repeat is one order');
        $this->assertFalse($scope->coversEveryOrder(orderIds: [102, 104]), 'a mixed order');
        $this->assertFalse($scope->coversEveryOrder(orderIds: [102, 105]), 'an order with no line');
        $this->assertFalse($scope->coversEveryOrder(orderIds: [102, 424242]), 'an order that does not exist');
        $this->assertTrue($scope->coversEveryOrder(orderIds: []), 'it names nothing');

        $this->assertTrue($scope->coversEveryRefund(refundIds: [2, 3]));
        $this->assertFalse($scope->coversEveryRefund(refundIds: [2, 1]));
        $this->assertFalse($scope->coversEveryRefund(refundIds: [2, 424242]));
        $this->assertTrue($scope->coversEveryRefund(refundIds: []));

        $lines = [102 => $this->staffOrderLineIds(102)[0], 104 => $this->staffOrderLineIds(104)[0], 105 => 424242];
        $this->assertTrue($scope->coversEveryOrderLine(lineIds: [$lines[102]]));
        $this->assertFalse($scope->coversEveryOrderLine(lineIds: [$lines[104]]), 'the line of a mixed order is reached through its order');
        $this->assertFalse($scope->coversEveryOrderLine(lineIds: [$lines[105]]));
        $this->assertFalse($scope->coversEveryOrderLine(lineIds: [$lines[102], $lines[104]]));
    }

    public function test_a_request_that_is_not_narrowed_covers_every_id_and_asks_the_database_nothing(): void
    {
        $scope = app(StaffCountryScope::class);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($scope->coversEveryOrder(orderIds: [101, 424242]));
        $this->assertTrue($scope->coversEveryRefund(refundIds: [1]));
        $this->assertTrue($scope->coversEveryOrderLine(lineIds: [1]));

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_an_empty_scope_covers_no_id_and_a_scope_of_nothing_asks_for_no_subject_it_was_not_given(): void
    {
        $this->employeeWith(['zz']);
        $scope = app(StaffCountryScope::class);

        $this->assertFalse($scope->coversEveryOrder(orderIds: [102]));
        $this->assertSame([], $scope->narrowedCountries(subject: StaffCountryScope::SUBJECT_ORDERS));
        $this->assertNull($scope->narrowedCountries(subject: 'something else'));
    }

    public function test_the_subjects_a_request_is_narrowed_for_are_the_ones_it_was_given_and_only_while_it_is_bound(): void
    {
        $scope = app(StaffCountryScope::class);
        $request = Request::create('/admin/orders/list/all');
        $this->app->instance('request', $request);

        $scope->narrowTo(request: $request, subjects: [StaffCountryScope::SUBJECT_ORDERS]);
        $this->assertFalse($scope->narrows(subject: StaffCountryScope::SUBJECT_ORDERS), 'a request that was never bound is not narrowed');

        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $scope->bind(request: $request, admin: Admin::query()->findOrFail(7));
        $this->assertFalse($scope->narrows(subject: StaffCountryScope::SUBJECT_ORDERS));

        $scope->narrowTo(request: $request, subjects: [StaffCountryScope::SUBJECT_ORDERS]);
        $scope->narrowTo(request: $request, subjects: [StaffCountryScope::SUBJECT_REFUNDS]);
        $this->assertTrue($scope->narrows(subject: StaffCountryScope::SUBJECT_ORDERS));
        $this->assertTrue($scope->narrows(subject: StaffCountryScope::SUBJECT_REFUNDS), 'asking again adds, it does not replace');
        $this->assertSame(['KW'], $scope->narrowedCountries(subject: StaffCountryScope::SUBJECT_ORDERS));
    }
}
