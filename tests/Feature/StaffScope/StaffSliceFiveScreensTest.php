<?php

namespace Tests\Feature\StaffScope;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Marketplace\CommissionEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsSliceFiveMarketplaceSchema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The rest of slice 5 through the real route table (GCC-14c; G-09): the reviews, the returns queue, the pick-pack-ship queue, the
 * commission rules, and the three screens the slice settles by a refusal -- the seller scorecard, the settlements and the brand registry.
 *
 * Every one of these is made of ORDERS rather than of products, which is why they waited for a rule of their own, and each of them is now
 * followed to the thing that can carry a market: a review to its product, a return to the product or order it names, a fulfilment to the
 * order it ships, a commission rule to the product it charges for.
 *
 * **Every refusal here is asserted twice**: once for the restricted employee, who must be answered like for something that does not exist,
 * and once for an employee who is not restricted, who must still be served. A 404 on its own cannot tell a guard from a URL that was never
 * there, and that is how a dead guard shipped on the audit centre and how a site-map test passed on a URL that did not exist.
 *
 * The world. Products: 1 is Saudi, 2 and 3 are Kuwaiti. Orders: 101 holds the Saudi product, 102 the Kuwaiti one, 103 holds BOTH and so
 * belongs to whoever holds both markets, 104 has no line at all. Reviews 11 (Saudi product) and 12 (Kuwaiti). Returns 21 (of order 102),
 * 22 (of order 101), 23 (of the mixed order 103), 24 (of nothing: no product and no order). Fulfilments 31 (order 102), 32 (order 101),
 * 33 (order 103). Commission rules 41 (the Kuwaiti product), 42 (the Saudi product), 43 (global). Employee 92 holds the marketplace and
 * people modules and is restricted to Kuwait; employee 93 holds the same modules and is restricted to nothing, so it stands for the
 * unrestricted twin of every refusal.
 */
class StaffSliceFiveScreensTest extends TestCase
{
    use BuildsSliceFiveMarketplaceSchema;
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const KUWAIT_HAND = 92;

    private const UNRESTRICTED_HAND = 93;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        // The promotion builder is what creates `orders` in this world; these screens read orders, not deals.
        $this->buildStaffPromotionTables();
        $this->buildSliceFiveMarketplaceSchema();
        $this->runStaffScopeMigration();
        $this->primeTaxSystemCache();

        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');

        $this->insertStaffOrderOfProduct(orderId: 101, productId: 1);
        $this->insertStaffOrderOfProduct(orderId: 102, productId: 2);
        $this->insertStaffOrderOfProduct(orderId: 103, productId: 1);
        DB::table('order_details')->insert(['order_id' => 103, 'product_id' => 2]);
        DB::table('orders')->insert(['id' => 104, 'seller_is' => 'admin', 'seller_id' => 1, 'order_status' => 'delivered']);

        DB::table('reviews')->insert([
            ['id' => 11, 'product_id' => 1, 'customer_id' => 1, 'order_id' => 101, 'rating' => 5, 'status' => 0],
            ['id' => 12, 'product_id' => 2, 'customer_id' => 1, 'order_id' => 102, 'rating' => 4, 'status' => 0],
        ]);

        // The NEWEST return is one a Kuwait employee cannot see, and it holds the highest reference of the month: the sequence of the next
        // reference is read by id, UNNARROWED, and a narrowed read would hand a Kuwaiti return a reference that is already taken.
        DB::table('return_shipments')->insert([
            ['id' => 21, 'reference' => 'RMA-' . date('Ym') . '-0003', 'order_id' => 102, 'product_id' => 2, 'qty' => 1, 'status' => 'authorized'],
            ['id' => 22, 'reference' => 'RMA-' . date('Ym') . '-0005', 'order_id' => 101, 'product_id' => 1, 'qty' => 1, 'status' => 'authorized'],
            ['id' => 23, 'reference' => 'RMA-' . date('Ym') . '-0004', 'order_id' => 103, 'product_id' => null, 'qty' => 1, 'status' => 'authorized'],
            ['id' => 24, 'reference' => 'RMA-' . date('Ym') . '-0009', 'order_id' => null, 'product_id' => null, 'qty' => 1, 'status' => 'authorized'],
        ]);

        DB::table('order_fulfillments')->insert([
            ['id' => 31, 'reference' => 'FUL-1', 'order_id' => 102, 'status' => 'pending'],
            ['id' => 32, 'reference' => 'FUL-2', 'order_id' => 101, 'status' => 'pending'],
            ['id' => 33, 'reference' => 'FUL-3', 'order_id' => 103, 'status' => 'pending'],
        ]);

        DB::table('commission_rules')->insert([
            ['id' => 41, 'scope_type' => CommissionEngine::SCOPE_PRODUCT, 'scope_id' => 2, 'rate_type' => 'percentage', 'percentage' => 5, 'priority' => 400, 'is_active' => 1],
            ['id' => 42, 'scope_type' => CommissionEngine::SCOPE_PRODUCT, 'scope_id' => 1, 'rate_type' => 'percentage', 'percentage' => 7, 'priority' => 400, 'is_active' => 1],
            ['id' => 43, 'scope_type' => CommissionEngine::SCOPE_GLOBAL, 'scope_id' => null, 'rate_type' => 'percentage', 'percentage' => 3, 'priority' => 100, 'is_active' => 1],
        ]);

        DB::table('vendor_settlements')->insert(['id' => 51, 'reference' => 'SET-1', 'seller_id' => 1, 'seller_is' => 'seller', 'status' => 'calculated']);
        DB::table('brand_claims')->insert(['id' => 61, 'brand_id' => 1, 'seller_id' => 1, 'status' => 'submitted']);

        DB::table('admin_roles')->insert(['id' => 64, 'name' => 'Marketplace and people', 'module_access' => json_encode(['marketplace', 'people']), 'status' => 1]);
        DB::table('admins')->insert([
            ['id' => self::KUWAIT_HAND, 'name' => 'Kuwait hand', 'email' => 'ninetytwo@example.test', 'admin_role_id' => 64],
            ['id' => self::UNRESTRICTED_HAND, 'name' => 'Every market', 'email' => 'ninetythree@example.test', 'admin_role_id' => 64],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->dropSliceFiveMarketplaceSchema();

        parent::tearDown();
    }

    private function asKuwaitHand(): static
    {
        $this->switchStaffScopesOn();
        $this->ensureCountries(adminId: self::KUWAIT_HAND, countries: ['KW']);

        return $this->signInAsStaff(adminId: self::KUWAIT_HAND);
    }

    /** The grant table is unique on (admin, country), and a test signs in as the same employee more than once. */
    private function ensureCountries(int $adminId, array $countries): void
    {
        $held = DB::table('admin_country_scopes')->where('admin_id', $adminId)->pluck('country')->all();

        $this->giveCountries(adminId: $adminId, countries: array_values(array_diff($countries, $held)));
    }

    /** The same switch, the same role, no country: the employee every refusal above is measured against. */
    private function asUnrestrictedHand(): static
    {
        $this->switchStaffScopesOn();

        return $this->signInAsStaff(adminId: self::UNRESTRICTED_HAND);
    }

    private function asHandOfBothMarkets(): static
    {
        $this->switchStaffScopesOn();
        $this->ensureCountries(adminId: self::KUWAIT_HAND, countries: ['KW', 'SA']);

        return $this->signInAsStaff(adminId: self::KUWAIT_HAND);
    }

    // ---- the reviews ------------------------------------------------------------------------------------------------------------------

    /**
     * The reviews screen cannot be rendered on SQLite by anybody: its customer picker is
     * `CustomerRepository::getCustomerNameList()`, which selects `IF(id <> "0", CONCAT(...), CONCAT(...))` -- MySQL's `IF`, which SQLite has
     * no function for, so the page answers 500 there whatever the scope says. The narrowing of this screen is therefore proven on a real
     * engine only, and that is stated rather than hidden behind a passing SQLite run.
     */
    private function requireTheEngineTheReviewsScreenNeeds(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('the reviews screen selects MySQL\'s IF(): it answers 500 on SQLite for every employee');
        }
    }

    public function test_the_reviews_list_and_its_export_hold_only_the_employees_products(): void
    {
        $this->requireTheEngineTheReviewsScreenNeeds();

        $page = $this->asKuwaitHand()->get('/admin/reviews/list');

        $page->assertOk();
        $this->assertStringContainsString('[review:12 product:2]', (string) $page->getContent());
        $this->assertStringNotContainsString('[review:11 ', (string) $page->getContent(), 'the Saudi product\'s review is not on a Kuwait employee\'s list');

        $everyMarket = $this->asUnrestrictedHand()->get('/admin/reviews/list');
        $everyMarket->assertOk();
        $this->assertStringContainsString('[review:11 product:1]', (string) $everyMarket->getContent(), 'and the same screen still shows it to an employee who is not restricted');
        $this->assertStringContainsString('[review:12 product:2]', (string) $everyMarket->getContent());
    }

    public function test_the_review_list_filtered_by_another_markets_product_is_answered_like_an_unknown_one(): void
    {
        $this->asKuwaitHand()->get('/admin/reviews/list?product_id=1')->assertNotFound();
        $this->asKuwaitHand()->get('/admin/reviews/export?product_id=1')->assertNotFound();

        $this->requireTheEngineTheReviewsScreenNeeds();
        $this->asKuwaitHand()->get('/admin/reviews/list?product_id=2')->assertOk('their own product still filters the list');
        $this->asUnrestrictedHand()->get('/admin/reviews/list?product_id=1')->assertOk('the same URL serves an employee who is not restricted');
    }

    public function test_a_review_of_another_markets_product_cannot_be_published_or_replied_to(): void
    {
        $this->asKuwaitHand();

        $this->post('/admin/reviews/status', ['id' => 11, 'status' => 1])->assertNotFound();
        $this->post('/admin/reviews/status', ['id' => 999, 'status' => 1])->assertNotFound('an unknown review is answered the same way');
        $this->post('/admin/reviews/add-review-reply', ['review_id' => 11, 'reply_text' => 'no'])->assertNotFound();

        $this->assertSame(0, (int) DB::table('reviews')->where('id', 11)->value('status'), 'the Saudi review was not published');
        $this->assertSame(0, DB::table('review_replies')->count('id'));

        $this->post('/admin/reviews/status', ['id' => 12, 'status' => 1])->assertRedirect();
        $this->assertSame(1, (int) DB::table('reviews')->where('id', 12)->value('status'), 'their own review still publishes');

        $this->asUnrestrictedHand()->post('/admin/reviews/status', ['id' => 11, 'status' => 1])->assertRedirect();
        $this->assertSame(1, (int) DB::table('reviews')->where('id', 11)->value('status'), 'and an unrestricted employee still publishes the Saudi one');
    }

    // ---- the returns queue ------------------------------------------------------------------------------------------------------------

    public function test_the_returns_queue_holds_only_the_returns_of_the_employees_orders(): void
    {
        $page = $this->asKuwaitHand()->get('/admin/marketplace/returns');

        $page->assertOk();
        $this->assertStringContainsString('[return:21 order:102 product:2]', (string) $page->getContent());
        foreach (['[return:22 ', '[return:23 ', '[return:24 '] as $hidden) {
            $this->assertStringNotContainsString($hidden, (string) $page->getContent(), $hidden . 'is not theirs');
        }

        $everyMarket = $this->asUnrestrictedHand()->get('/admin/marketplace/returns');
        $everyMarket->assertOk();
        foreach (['[return:21 ', '[return:22 ', '[return:23 ', '[return:24 '] as $shown) {
            $this->assertStringContainsString($shown, (string) $everyMarket->getContent(), 'an unrestricted employee sees the whole queue');
        }
    }

    public function test_a_return_of_another_markets_order_cannot_be_advanced_received_or_rejected(): void
    {
        $this->asKuwaitHand();

        foreach (['transit', 'receive', 'reject'] as $action) {
            $this->post('/admin/marketplace/returns/22/' . $action)->assertNotFound();
            $this->post('/admin/marketplace/returns/999/' . $action)->assertNotFound('an unknown return is answered the same way');
        }
        $this->assertSame('authorized', DB::table('return_shipments')->where('id', 22)->value('status'), 'the Saudi return did not move');

        $this->post('/admin/marketplace/returns/21/transit', ['carrier' => 'Aramex'])->assertRedirect();
        $this->assertSame('in_transit', DB::table('return_shipments')->where('id', 21)->value('status'), 'their own return still moves');

        $this->asUnrestrictedHand()->post('/admin/marketplace/returns/22/transit', ['carrier' => 'Aramex'])->assertRedirect();
        $this->assertSame('in_transit', DB::table('return_shipments')->where('id', 22)->value('status'), 'and an unrestricted employee still moves the Saudi one');
    }

    /** A return that names neither a product nor an order belongs to no market: it fails closed, as an order with no line does. */
    public function test_a_return_that_names_nothing_and_one_of_a_mixed_order_are_refused_to_an_employee_of_one_market(): void
    {
        $this->asKuwaitHand();

        $this->post('/admin/marketplace/returns/24/transit')->assertNotFound('a return of nothing belongs to no market');
        $this->post('/admin/marketplace/returns/23/transit')->assertNotFound('a return of an order that holds two markets needs both');

        $this->asHandOfBothMarkets()->post('/admin/marketplace/returns/23/transit')->assertRedirect();
        $this->assertSame('in_transit', DB::table('return_shipments')->where('id', 23)->value('status'));
    }

    public function test_authorising_a_return_is_judged_by_what_the_form_names(): void
    {
        $this->asKuwaitHand();

        $this->post('/admin/marketplace/returns', ['product_id' => 1, 'qty' => 1])->assertNotFound('another market\'s product');
        $this->post('/admin/marketplace/returns', ['order_id' => 101, 'qty' => 1])->assertNotFound('another market\'s order');
        $this->post('/admin/marketplace/returns', ['order_id' => 104, 'qty' => 1])->assertNotFound('an order with no line belongs to no market');
        $this->post('/admin/marketplace/returns', ['qty' => 1])->assertNotFound('a form that names nothing names no market');
        $this->assertSame(4, DB::table('return_shipments')->count('id'), 'nothing was authorised');

        $this->post('/admin/marketplace/returns', ['product_id' => 2, 'qty' => 1])->assertRedirect();
        $this->assertSame(5, DB::table('return_shipments')->count('id'));
    }

    /**
     * The sequence of a new return's reference is read without the narrowing, which is why only the LISTING actions ask for it. Narrow the
     * write too and a Kuwaiti return takes a reference the Saudi one already holds.
     */
    public function test_a_new_returns_reference_continues_after_every_markets_returns(): void
    {
        $this->asKuwaitHand()->post('/admin/marketplace/returns', ['product_id' => 2, 'qty' => 1])->assertRedirect();

        $this->assertSame(
            'RMA-' . date('Ym') . '-0010',
            DB::table('return_shipments')->orderByDesc('id')->value('reference'),
            'the next reference follows 0009, which belongs to a return the employee cannot see',
        );
    }

    // ---- the pick, pack and ship queue ------------------------------------------------------------------------------------------------

    public function test_the_fulfilment_queue_holds_only_the_shipments_of_the_employees_orders(): void
    {
        $page = $this->asKuwaitHand()->get('/admin/marketplace/fulfillments');

        $page->assertOk();
        $this->assertStringContainsString('[fulfillment:31 order:102]', (string) $page->getContent());
        $this->assertStringNotContainsString('[fulfillment:32 ', (string) $page->getContent());
        $this->assertStringNotContainsString('[fulfillment:33 ', (string) $page->getContent(), 'the mixed order is not theirs either');

        $everyMarket = $this->asUnrestrictedHand()->get('/admin/marketplace/fulfillments');
        $everyMarket->assertOk();
        $this->assertStringContainsString('[fulfillment:32 order:101]', (string) $everyMarket->getContent());
    }

    public function test_a_fulfilment_of_another_markets_order_cannot_be_advanced_or_cancelled(): void
    {
        $this->asKuwaitHand();

        $this->post('/admin/marketplace/fulfillments/32/advance', ['to_status' => 'picking'])->assertNotFound();
        $this->post('/admin/marketplace/fulfillments/32/cancel')->assertNotFound();
        $this->post('/admin/marketplace/fulfillments/999/cancel')->assertNotFound();
        $this->assertSame('pending', DB::table('order_fulfillments')->where('id', 32)->value('status'));

        $this->post('/admin/marketplace/fulfillments/31/advance', ['to_status' => 'picking'])->assertRedirect();
        $this->assertSame('picking', DB::table('order_fulfillments')->where('id', 31)->value('status'), 'their own fulfilment still advances');

        $this->asUnrestrictedHand()->post('/admin/marketplace/fulfillments/32/cancel')->assertRedirect();
        $this->assertSame('canceled', DB::table('order_fulfillments')->where('id', 32)->value('status'));
    }

    public function test_opening_a_fulfilment_is_judged_by_the_order_it_would_ship(): void
    {
        $this->asKuwaitHand();

        $this->post('/admin/marketplace/fulfillments', ['order_id' => 101])->assertNotFound();
        $this->post('/admin/marketplace/fulfillments', ['order_id' => 103])->assertNotFound('an order of two markets needs both');
        $this->post('/admin/marketplace/fulfillments', ['order_id' => 104])->assertNotFound('an order with no line belongs to no market');
        $this->assertSame(3, DB::table('order_fulfillments')->count('id'));

        // Their own order, which already has an open fulfilment: the service's duplicate check reads the queue UNNARROWED on a write, and
        // the answer is the refusal of the service rather than of the scope -- the row is written either way, so the count is what shows it.
        $this->post('/admin/marketplace/fulfillments', ['order_id' => 102])->assertRedirect();
        $this->assertSame(3, DB::table('order_fulfillments')->count('id'), 'an open fulfilment already exists for order 102');
    }

    // ---- the commission rules ---------------------------------------------------------------------------------------------------------

    public function test_the_rule_list_holds_the_employees_product_rules_and_every_rule_that_names_no_product(): void
    {
        $page = $this->asKuwaitHand()->get('/admin/marketplace/commission-rules');

        $page->assertOk();
        $this->assertStringContainsString('[rule:41 scope:product:2]', (string) $page->getContent());
        $this->assertStringContainsString('[rule:43 scope:global:]', (string) $page->getContent(), 'a global rule has no market to be hidden by');
        $this->assertStringNotContainsString('[rule:42 ', (string) $page->getContent(), 'the Saudi product\'s rule is hidden');

        $everyMarket = $this->asUnrestrictedHand()->get('/admin/marketplace/commission-rules');
        $everyMarket->assertOk();
        $this->assertStringContainsString('[rule:42 scope:product:1]', (string) $everyMarket->getContent());
    }

    public function test_only_a_rule_that_names_one_of_the_employees_products_can_be_written(): void
    {
        $this->asKuwaitHand();

        foreach ([42, 43, 999] as $refused) {
            $this->post('/admin/marketplace/commission-rules/' . $refused . '/toggle')->assertNotFound();
            $this->delete('/admin/marketplace/commission-rules/' . $refused)->assertNotFound();
        }
        $this->assertSame(1, (int) DB::table('commission_rules')->where('id', 42)->value('is_active'), 'the Saudi rule is untouched');
        $this->assertSame(1, (int) DB::table('commission_rules')->where('id', 43)->value('is_active'), 'and so is the global one');
        $this->assertSame(3, DB::table('commission_rules')->count('id'));

        $this->post('/admin/marketplace/commission-rules/41/toggle')->assertRedirect();
        $this->assertSame(0, (int) DB::table('commission_rules')->where('id', 41)->value('is_active'), 'their own rule still switches off');

        $this->asUnrestrictedHand()->post('/admin/marketplace/commission-rules/43/toggle')->assertRedirect();
        $this->assertSame(0, (int) DB::table('commission_rules')->where('id', 43)->value('is_active'), 'an unrestricted employee still switches the global rule');
    }

    public function test_creating_a_rule_that_charges_every_market_is_refused_and_one_for_their_own_product_is_not(): void
    {
        $this->asKuwaitHand();

        $global = ['scope_type' => 'global', 'rate_type' => 'percentage', 'percentage' => 9];
        $this->post('/admin/marketplace/commission-rules', $global)->assertNotFound('a global rule charges the sellers of every market');
        $this->post('/admin/marketplace/commission-rules', ['scope_type' => 'vendor', 'scope_id' => 1, 'rate_type' => 'percentage', 'percentage' => 9])->assertNotFound();
        $this->post('/admin/marketplace/commission-rules', ['scope_type' => 'product', 'scope_id' => 1, 'rate_type' => 'percentage', 'percentage' => 9])->assertNotFound('another market\'s product');
        $this->assertSame(3, DB::table('commission_rules')->count('id'));

        $this->post('/admin/marketplace/commission-rules', ['scope_type' => 'product', 'scope_id' => 3, 'rate_type' => 'percentage', 'percentage' => 9])->assertRedirect();
        $this->assertSame(4, DB::table('commission_rules')->count('id'));

        $this->asUnrestrictedHand()->post('/admin/marketplace/commission-rules', $global)->assertRedirect();
        $this->assertSame(5, DB::table('commission_rules')->count('id'));
    }

    public function test_the_preview_beside_the_rule_list_prices_only_the_employees_own_product(): void
    {
        $this->asKuwaitHand()->get('/admin/marketplace/commission-rules?preview_amount=100&preview_product_id=1')->assertNotFound();

        $own = $this->asKuwaitHand()->get('/admin/marketplace/commission-rules?preview_amount=100&preview_product_id=2&preview_seller_id=1');
        $own->assertOk();
        // The preview itself resolves no rule for anybody: `CommissionEngine::calculate()` reads `seller_is` out of the line and the preview
        // form posts none, so it takes the `admin` branch, which charges nothing. That is a defect of the screen and not of the scope, and it
        // is recorded here rather than asserted away: what this test can prove is that the employee's own product is priced and another
        // market's is not reachable at all.
        $this->assertStringContainsString('[preview-rule:none]', (string) $own->getContent());

        $this->asUnrestrictedHand()->get('/admin/marketplace/commission-rules?preview_amount=100&preview_product_id=1')->assertOk();
    }

    // ---- the three screens the slice settles by a refusal -----------------------------------------------------------------------------

    public function test_the_scorecard_the_settlements_and_the_brand_registry_are_unavailable_to_a_restricted_employee(): void
    {
        $screens = [
            '/admin/marketplace/seller-scorecard',
            '/admin/marketplace/settlements',
            '/admin/marketplace/settlements/51',
            '/admin/marketplace/ledger/1',
            '/admin/marketplace/brand-registry',
        ];

        $this->asKuwaitHand();
        foreach ($screens as $screen) {
            $this->get($screen)->assertNotFound($screen . ' cannot be told apart by country');
        }

        $this->asUnrestrictedHand();
        foreach ($screens as $screen) {
            $this->get($screen)->assertSuccessful($screen . ' is a screen that exists: an unrestricted employee is served it');
        }
    }

    public function test_the_money_actions_of_a_settlement_and_the_platform_controls_on_its_page_are_refused(): void
    {
        $this->asKuwaitHand();

        foreach ([
            ['post', '/admin/marketplace/settlements/calculate'],
            ['post', '/admin/marketplace/settlements/51/approve'],
            ['post', '/admin/marketplace/settlements/51/mark-paid'],
            ['post', '/admin/marketplace/settlements/51/cancel'],
            ['post', '/admin/marketplace/settlements/toggle-maker-checker'],
            ['post', '/admin/marketplace/settlements/terms'],
            ['post', '/admin/marketplace/brand-registry/approve'],
            ['post', '/admin/marketplace/brand-registry/reject'],
            ['post', '/admin/marketplace/brand-registry/revoke'],
            ['post', '/admin/marketplace/brand-registry/enforcement'],
        ] as [$verb, $url]) {
            $this->{$verb}($url, ['claim_id' => 61, 'status' => 1])->assertNotFound($url);
        }

        $this->assertSame('calculated', DB::table('vendor_settlements')->where('id', 51)->value('status'), 'the settlement did not move');
        $this->assertSame('submitted', DB::table('brand_claims')->where('id', 61)->value('status'), 'the brand claim was not decided');
    }

    // ---- what the scope must NOT change -----------------------------------------------------------------------------------------------

    public function test_with_the_switch_off_every_screen_of_the_slice_answers_as_it_always_did(): void
    {
        config(['gcc.staff.country_scopes' => false]);
        $this->giveCountries(adminId: self::KUWAIT_HAND, countries: ['KW']);
        $this->signInAsStaff(adminId: self::KUWAIT_HAND);

        $page = $this->get('/admin/marketplace/returns');
        $page->assertOk();
        foreach (['[return:21 ', '[return:22 ', '[return:23 ', '[return:24 '] as $shown) {
            $this->assertStringContainsString($shown, (string) $page->getContent(), 'the whole queue, as before the slice');
        }

        $this->get('/admin/marketplace/seller-scorecard')->assertSuccessful();
        $this->get('/admin/marketplace/settlements')->assertSuccessful();
        $this->post('/admin/marketplace/returns/22/transit', ['carrier' => 'Aramex'])->assertRedirect();
        $this->assertSame('in_transit', DB::table('return_shipments')->where('id', 22)->value('status'));
    }

    /** The master admin is never restricted, whatever rows it is given. */
    public function test_the_master_admin_reaches_every_market_on_every_screen_of_the_slice(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);
        $this->signInAsStaff(adminId: 1);

        $returns = $this->get('/admin/marketplace/returns');
        $returns->assertOk();
        $this->assertStringContainsString('[return:22 ', (string) $returns->getContent());

        $rules = $this->get('/admin/marketplace/commission-rules');
        $rules->assertOk();
        $this->assertStringContainsString('[rule:42 ', (string) $rules->getContent());
    }
}
