<?php

namespace Tests\Feature\StaffScope;

use App\Exports\RefundRequestExport;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\BuildsStaffOrderWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The admin POS and the refund screens for a restricted employee (GCC-14c, slice 2). The POS offers, prices and sells the products of their countries only, and an order is
 * never placed with a product outside them. A refund request follows the order line it is about: the Kuwaiti line of an order that also holds a Saudi one is Kuwait's.
 *
 * The requests go through the real route table, the real sign-in check and module permission, the real controllers and repositories; only the markup of the pages is a test
 * stand-in. Employee 7 holds orders and the POS and works with Kuwait only. Products: 1 SA, 2 KW, 3 KW, 4 AE.
 */
class StaffPosAndRefundScreensTest extends TestCase
{
    use BuildsStaffOrderWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        Event::fake();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffOrderTables();
        $this->answerNoCustomers();
        $this->teachSqliteWhatMysqlKnows();
        $this->primeTaxSystemCache();
        Cache::put('tax_system_type_vendor_with_vat', ['SystemTaxVat' => ['tax_ids' => [], 'additionalData' => []], 'SystemTaxVatType' => 'order_wise', 'is_included' => 1, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => []]);
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        $this->insertStaffOrder(id: 101, products: [1]);
        $this->insertStaffOrder(id: 102, products: [2]);
        $this->insertStaffOrder(id: 104, products: [1, 2]);
        $this->insertStaffOrder(id: 105, products: []);
        $this->insertStaffOrder(id: 109, products: [4]);

        $this->insertStaffRefund(id: 1, orderId: 101, aboutProduct: 1);
        $this->insertStaffRefund(id: 2, orderId: 102, aboutProduct: 2);
        $this->insertStaffRefund(id: 3, orderId: 104, aboutProduct: 2);
        $this->insertStaffRefund(id: 4, orderId: 104, aboutProduct: 1);
        $this->insertStaffRefund(id: 5, orderId: 109, aboutProduct: 4, extra: ['status' => 'approved']);
        $this->insertStaffRefund(id: 6, orderId: 105, aboutProduct: null);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function asKuwaitEmployee(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW']);

        return $this->signInAsStaff(adminId: 7);
    }

    /** @return list<int> */
    private function idsPrinted(TestResponse|string $page, string $pattern): array
    {
        if ($page instanceof TestResponse) {
            $page->assertOk();
            $page = (string) $page->getContent();
        }
        preg_match_all($pattern, $page, $matches);

        return array_map('intval', $matches[1]);
    }

    private function cartItem(int $productId): array
    {
        return ['id' => $productId, 'customerId' => 0, 'customerOnHold' => false, 'quantity' => 1, 'price' => 10, 'name' => "P{$productId}", 'productType' => 'physical', 'image' => [], 'discount' => 0, 'variant' => null, 'variations' => []];
    }

    /** @param list<int> $productIds */
    private function putCart(array $productIds): void
    {
        $this->withSession(['current_user' => 'walk-in-customer-1', 'walk-in-customer-1' => [...array_map(fn (int $id): array => $this->cartItem($id), $productIds), 'add_to_cart_time' => now()]]);
    }

    /**
     * What a client is told: the status and the body, without what APP_DEBUG adds to an error answer.
     *
     * @return array{0: int, 1: mixed}
     */
    private function answer(TestResponse $response): array
    {
        $body = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($body) ? Arr::except($body, ['exception', 'file', 'line', 'trace']) : $response->getContent()];
    }

    // ---- the POS: products ---------------------------------------------------------------------------------------------------------

    public function test_the_pos_search_offers_only_the_products_of_the_country(): void
    {
        $response = $this->asKuwaitEmployee()->getJson('/admin/pos/search-product?name=Product')->assertOk();

        $this->assertEqualsCanonicalizing([2, 3], $this->idsPrinted($response->json('result'), '/\[product:(\d+)\]/'));
        $this->assertSame(2, $response->json('count'));
    }

    public function test_the_pos_search_with_the_name_of_a_product_of_another_country_finds_nothing_while_the_master_admin_finds_it(): void
    {
        $this->asKuwaitEmployee();

        $restricted = $this->getJson('/admin/pos/search-product?name=Product 1 of SA')->assertOk();
        $master = $this->signInAsStaff(adminId: 1)->getJson('/admin/pos/search-product?name=Product 1 of SA')->assertOk();

        $this->assertSame([], $this->idsPrinted($restricted->json('result'), '/\[product:(\d+)\]/'));
        $this->assertSame([1], $this->idsPrinted($master->json('result'), '/\[product:(\d+)\]/'), 'the control: the product is there to be found');
    }

    public function test_a_product_of_another_country_is_not_added_priced_viewed_or_counted_in_the_pos_cart(): void
    {
        $this->asKuwaitEmployee();
        $this->putCart([2]);
        $before = session('walk-in-customer-1');

        foreach ([
            ['POST', '/admin/pos/add-to-cart', ['id' => 1, 'quantity' => 1, 'quantity_in_cart' => 1]],
            ['POST', '/admin/pos/get-variant-price', ['id' => 1, 'quantity' => 1]],
            ['POST', '/admin/pos/quantity-update', ['key' => 1, 'quantity' => 2]],
            ['GET', '/admin/pos/quick-view', ['product_id' => 1]],
        ] as [$method, $uri, $input]) {
            $foreign = $this->json($method, $uri, $input);
            $unknown = $this->json($method, $uri, array_map(static fn ($value) => $value === 1 ? 99 : $value, $input));

            $this->assertSame(404, $foreign->getStatusCode(), $method . ' ' . $uri);
            $this->assertSame($this->answer($unknown), $this->answer($foreign), $method . ' ' . $uri . ': the same bytes as a product that does not exist');
        }

        $this->assertSame($before, session('walk-in-customer-1'), 'the cart was not touched');
    }

    // ---- the POS: the order ----------------------------------------------------------------------------------------------------------

    public function test_an_order_is_not_placed_when_the_cart_holds_a_product_of_another_country(): void
    {
        $this->asKuwaitEmployee();
        $this->putCart([2, 1]);
        $orders = DB::table('orders')->count();
        $stock = DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->all();

        $this->postJson('/admin/pos/order-place', ['amount' => 20, 'type' => 'cash', 'paid_amount' => 20])->assertNotFound();

        $this->assertSame($orders, DB::table('orders')->count(), 'no order whose lines are outside their countries');
        $this->assertSame($stock, DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->all(), 'and no stock taken');
        $this->assertSame(0, DB::table('order_details')->where('order_id', '>', 100000)->count());
    }

    public function test_an_order_is_placed_with_the_products_of_the_country_and_is_the_employees_afterwards(): void
    {
        $this->insertStaffOrder(id: 100003, products: [1]);
        $this->asKuwaitEmployee();
        $this->putCart([2, 3]);
        $nextNumber = 100000 + DB::table('orders')->count() + 1;

        $this->postJson('/admin/pos/order-place', ['amount' => 20, 'type' => 'cash', 'paid_amount' => 20])->assertOk();

        $this->assertSame(1, DB::table('orders')->where('id', $nextNumber)->count(), 'the number counts every order, not only the ones the employee can see: order 100003 is Saudi and exists');
        $this->assertSame('POS', DB::table('orders')->where('id', $nextNumber)->value('order_type'));
        $this->assertEqualsCanonicalizing([2, 3], DB::table('order_details')->where('order_id', $nextNumber)->pluck('product_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertContains($nextNumber, $this->idsPrinted($this->get('/admin/orders/list/all'), '/\[order:(\d+)\]/'), 'it is theirs: every line is a product of their country');
        $this->assertSame(1, DB::table('orders')->where('id', 100003)->count());
    }

    public function test_an_order_placed_by_the_master_admin_with_products_of_two_countries_stays_possible_and_is_hidden_from_each_single_country(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 7, countries: ['KW']);
        $this->signInAsStaff(adminId: 1);
        $this->putCart([2, 1]);
        $nextNumber = 100000 + DB::table('orders')->count() + 1;

        $this->postJson('/admin/pos/order-place', ['amount' => 20, 'type' => 'cash', 'paid_amount' => 20])->assertOk();

        $this->assertSame(1, DB::table('orders')->where('id', $nextNumber)->count());
        $this->assertNotContains($nextNumber, $this->idsPrinted($this->signInAsStaff(adminId: 7)->get('/admin/orders/list/all'), '/\[order:(\d+)\]/'));
    }

    // ---- the refunds: lists and exports -----------------------------------------------------------------------------------------------

    public function test_the_refund_list_shows_the_requests_about_the_lines_of_the_country_with_the_order_each_is_about(): void
    {
        $response = $this->asKuwaitEmployee()->get('/admin/refund-section/refund/list/pending');

        $this->assertSame([3, 2], $this->idsPrinted($response, '/\[refund:(\d+) /'));
        $this->assertSame([104, 102], $this->idsPrinted($response, '/ order:(\d+)\]/'), 'the order of a Kuwaiti line of a mixed order is shown: the refund is Kuwait\'s and so is the screen');
    }

    public function test_the_refund_list_per_status_is_narrowed(): void
    {
        $this->asKuwaitEmployee();

        $this->assertSame([], $this->idsPrinted($this->get('/admin/refund-section/refund/list/approved'), '/\[refund:(\d+) /'), 'refund 5 is approved, and Emirati');
        $this->assertSame([5], $this->idsPrinted($this->signInAsStaff(adminId: 1)->get('/admin/refund-section/refund/list/approved'), '/\[refund:(\d+) /'));
    }

    public function test_the_refund_list_search_cannot_bring_back_a_request_of_another_country(): void
    {
        $this->asKuwaitEmployee();

        $this->assertSame([], $this->idsPrinted($this->get('/admin/refund-section/refund/list/pending?searchValue=101'), '/\[refund:(\d+) /'), 'the order number of a Saudi refund finds nothing');
        $this->assertSame([2], $this->idsPrinted($this->get('/admin/refund-section/refund/list/pending?searchValue=102'), '/\[refund:(\d+) /'));
    }

    public function test_the_refund_export_holds_only_the_requests_of_the_country(): void
    {
        Excel::fake();

        $this->asKuwaitEmployee()->get('/admin/refund-section/refund/export/pending')->assertOk();

        Excel::assertDownloaded('refund-request.xlsx', function (RefundRequestExport $export): bool {
            $this->assertSame([3, 2], $export->view()->getData()['data']['refundList']->pluck('id')->all());

            return true;
        });
    }

    // ---- the refunds: one request ------------------------------------------------------------------------------------------------------

    public function test_the_details_of_a_request_about_a_line_of_the_country_open_even_when_the_order_holds_another_country_too(): void
    {
        $this->asKuwaitEmployee();

        $this->assertSame([2], $this->idsPrinted($this->get('/admin/refund-section/refund/details/2'), '/\[refund:(\d+)\]/'));
        $this->assertSame([3], $this->idsPrinted($this->get('/admin/refund-section/refund/details/3'), '/\[refund:(\d+)\]/'));
        $this->assertSame([104], $this->idsPrinted($this->get('/admin/refund-section/refund/details/3'), '/\[order:(\d+)\]/'));
    }

    public function test_the_details_of_a_request_about_another_country_a_deleted_line_or_nothing_are_answered_like_an_unknown_request(): void
    {
        $this->asKuwaitEmployee();
        $unknown = $this->get('/admin/refund-section/refund/details/424242');

        foreach ([1, 4, 5, 6] as $refund) {
            $answer = $this->get('/admin/refund-section/refund/details/' . $refund);

            $this->assertSame(404, $answer->getStatusCode(), 'refund ' . $refund);
            $this->assertSame($this->answer($unknown), $this->answer($answer), 'refund ' . $refund);
        }
    }

    public function test_a_request_about_a_line_of_the_country_is_rejected_and_one_of_another_country_is_not_touched(): void
    {
        $this->asKuwaitEmployee();

        $this->postJson('/admin/refund-section/refund/refund-status-update', ['id' => 2, 'refund_status' => 'rejected', 'rejected_note' => 'Not covered'])->assertOk()->assertJsonMissing(['error']);
        $this->assertSame('rejected', DB::table('refund_requests')->where('id', 2)->value('status'));

        foreach ([1, 4, 5, 6, 424242] as $refund) {
            $this->postJson('/admin/refund-section/refund/refund-status-update', ['id' => $refund, 'refund_status' => 'rejected', 'rejected_note' => 'No'])->assertNotFound();
        }
        $this->assertSame(['pending', 'pending', 'approved', 'pending'], DB::table('refund_requests')->whereIn('id', [1, 4, 5, 6])->orderBy('id')->pluck('status')->all(), 'the others were not touched');
    }

    public function test_a_request_about_a_kuwaiti_line_of_a_mixed_order_is_decided_with_the_order_the_employee_cannot_open(): void
    {
        $this->asKuwaitEmployee();

        $this->postJson('/admin/refund-section/refund/refund-status-update', ['id' => 3, 'refund_status' => 'rejected', 'rejected_note' => 'Not covered'])->assertOk()->assertJsonMissing(['error']);

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', 3)->value('status'));
        $this->get('/admin/orders/details/104')->assertNotFound();
    }

    public function test_the_master_admin_and_an_employee_with_no_country_see_and_decide_every_request(): void
    {
        $this->switchStaffScopesOn();

        $this->assertSame([6, 4, 3, 2, 1], $this->idsPrinted($this->signInAsStaff(adminId: 1)->get('/admin/refund-section/refund/list/pending'), '/\[refund:(\d+) /'), 'refund 6 is about no line and is listed: the master admin sees what exists');
        $this->assertSame([6, 4, 3, 2, 1], $this->idsPrinted($this->signInAsStaff(adminId: 7)->get('/admin/refund-section/refund/list/pending'), '/\[refund:(\d+) /'));
    }

    public function test_with_the_switch_off_an_employee_who_has_countries_sees_every_request(): void
    {
        $this->giveCountries(adminId: 7, countries: ['KW']);

        $this->assertSame([6, 4, 3, 2, 1], $this->idsPrinted($this->signInAsStaff(adminId: 7)->get('/admin/refund-section/refund/list/pending'), '/\[refund:(\d+) /'));
    }
}
