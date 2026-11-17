<?php

namespace Tests\Feature\StaffScope;

use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\User;
use App\Packages\AdvanceSearch\AdvanceSearch;
use App\Services\Staff\StaffSearchHits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsStaffOrderWorld;
use Tests\TestCase;

/**
 * What the search of the admin header hands a restricted employee (GCC-14c, slice 3): an order, a refund request or a product of another country is not found by its number,
 * and the cache every employee shares is filled with every country's, so that one employee's scope never decides what another finds.
 *
 * Products: 1 SA, 2 KW, 3 KW, 4 AE. Orders: 101 SA, 102 KW, 103 KW, 104 SA and KW, 105 without a line, 109 AE. Refund requests: 1 about a Saudi line, 2 about a Kuwaiti line.
 */
class StaffSearchHitsTest extends TestCase
{
    use BuildsStaffOrderWorld;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->buildStaffOrderTables();
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        foreach ([101 => [1], 102 => [2], 103 => [2, 3], 104 => [1, 2], 105 => [], 109 => [4]] as $order => $products) {
            $this->insertStaffOrder(id: $order, products: $products);
        }
        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2);
    }

    /** @return list<int> */
    private function idsOf(\Illuminate\Support\Collection $hits): array
    {
        return $hits->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
    }

    // ---- the filter --------------------------------------------------------------------------------------------------------------------

    public function test_the_orders_found_are_the_ones_whose_every_line_is_a_product_of_the_employees_countries(): void
    {
        $hits = Order::query()->get();

        $this->assertSame([102, 103], $this->idsOf(app(StaffSearchHits::class)->keep(hits: $hits, model: Order::class, countries: ['KW'])));
        $this->assertSame([101, 102, 103, 104], $this->idsOf(app(StaffSearchHits::class)->keep(hits: $hits, model: Order::class, countries: ['KW', 'SA'])));
        $this->assertSame([], $this->idsOf(app(StaffSearchHits::class)->keep(hits: $hits, model: Order::class, countries: [])), 'restricted to nothing: no order');
    }

    public function test_the_refund_requests_and_the_products_found_are_the_employees(): void
    {
        $refunds = RefundRequest::query()->get();
        $products = Product::query()->get();

        $this->assertSame([2], $this->idsOf(app(StaffSearchHits::class)->keep(hits: $refunds, model: RefundRequest::class, countries: ['KW'])));
        $this->assertSame([2, 3], $this->idsOf(app(StaffSearchHits::class)->keep(hits: $products, model: Product::class, countries: ['KW'])));
    }

    public function test_what_is_not_an_order_a_refund_request_or_a_product_is_left_alone(): void
    {
        $customers = User::query()->get();

        $this->assertCount(1, app(StaffSearchHits::class)->keep(hits: $customers, model: User::class, countries: ['KW']));
    }

    public function test_an_employee_who_is_not_restricted_keeps_every_hit_and_nothing_is_asked_of_the_database(): void
    {
        $hits = Order::query()->get();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $kept = app(StaffSearchHits::class)->keep(hits: $hits, model: Order::class, countries: null);

        $this->assertCount(6, $kept);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_a_restricted_employee_costs_one_statement_for_the_hits_of_a_kind_whatever_their_number(): void
    {
        $hits = Order::query()->get();
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(StaffSearchHits::class)->keep(hits: $hits, model: Order::class, countries: ['KW']);

        $this->assertCount(1, DB::getQueryLog());
    }

    // ---- the search of the header --------------------------------------------------------------------------------------------------------

    /** The search of the header, over the three tables that name an order or a product, as an employee of the given countries (none: not restricted) would run it. */
    private function search(string $keyword, ?int $adminId): array
    {
        if ($adminId !== null) {
            $this->signInAsStaff(adminId: $adminId);
        }

        $search = new class ('admin', $keyword) extends AdvanceSearch {
            public function getModels(): array
            {
                return array_intersect_key(parent::getModels(), array_flip(['orders', 'refund_requests', 'products']));
            }
        };

        return array_column($search->searchModelList(), 'uri');
    }

    public function test_an_order_of_another_country_is_not_found_by_its_number_and_one_of_theirs_is(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 4, countries: ['KW']);

        $found = $this->search(keyword: '101', adminId: 4);
        $own = $this->search(keyword: '102', adminId: 4);

        $this->assertNotContains('admin/orders/details/101', $found);
        $this->assertContains('admin/orders/details/102', $own);
    }

    public function test_the_cache_everybody_shares_is_filled_with_every_country_so_one_employees_scope_never_decides_what_another_finds(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 4, countries: ['KW']);

        $this->assertNotContains('admin/orders/details/101', $this->search(keyword: '101', adminId: 4));
        $this->assertContains('admin/orders/details/101', $this->search(keyword: '101', adminId: 1), 'the master admin, after a restricted employee has filled the cache');
    }

    public function test_the_master_admin_an_employee_with_no_country_and_the_switch_off_find_every_order(): void
    {
        $this->giveCountries(adminId: 4, countries: ['KW']);
        $this->assertContains('admin/orders/details/101', $this->search(keyword: '101', adminId: 4), 'the switch is off');

        $this->switchStaffScopesOn();
        $this->assertContains('admin/orders/details/101', $this->search(keyword: '101', adminId: 1), 'the master admin');
        DB::table('admin_country_scopes')->delete();
        $this->assertContains('admin/orders/details/101', $this->search(keyword: '101', adminId: 4), 'no country, so no restriction');
    }

    public function test_the_search_of_the_header_narrows_what_it_hands_back_and_not_the_query_that_fills_the_cache(): void
    {
        $source = (string) file_get_contents(app_path('Packages/AdvanceSearch/AdvanceSearch.php'));

        $this->assertMatchesRegularExpression('/Cache::remember\(\$cache_key.*?\)->values\(\);\s*\$filteredItems = app\(StaffSearchHits::class\)->keep\(/s', $source);
    }
}
