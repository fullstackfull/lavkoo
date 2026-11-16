<?php

namespace Tests\Feature\Security;

use App\Services\SellerCenter\Lists\InventoryList;
use App\Services\SellerCenter\Revenue;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\Producers\FinanceIntegrityProducer;
use App\Services\SellerIntelligence\SellerInsightEngine;
use App\Services\SellerIntelligence\Severity\SellerBaselineProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSellerApiOrders;
use Tests\TestCase;

/**
 * The figures the seller apps show about the shop (home, briefing, scorecard, seller info) are counted by the seller's id. The in-house
 * shop's orders, lines, products and reviews carry that same id 1, so a count that does not also say "a seller's own" shows seller 1 the
 * in-house shop's revenue, rating and refunds, and lets the in-house shop's refunds, reviews and strikes change seller 1's tier.
 *
 * Each test puts in-house rows next to seller 1's own and expects only the own ones to be counted.
 */
class SellerApiFiguresScopeTest extends TestCase
{
    use BuildsSellerApiOrders;

    private const OWN_PRODUCT = 10;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createSellerApiOrderTables();
        $this->createFigureTables();
        $this->seedSellerApiOrders();
        $this->seedProductsReviewsAndStrikes();
        $this->deliverTheLinesToday();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- revenue ----

    public function test_the_revenue_counts_only_the_sellers_own_delivered_lines(): void
    {
        $this->assertEqualsWithDelta(200.0, Revenue::total(sellerId: self::SELLER), 0.001);
        $this->assertSame(2, Revenue::units(sellerId: self::SELLER));
    }

    public function test_the_briefing_counts_only_the_sellers_own_delivered_lines_as_revenue(): void
    {
        $response = $this->sellerApi('GET', 'seller-center/control-tower/briefing');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsWithDelta(200.0, (float) $response->json('today.revenue'), 0.001);
    }

    public function test_the_revenue_of_the_other_seller_is_still_counted(): void
    {
        $this->assertEqualsWithDelta(480.0 * 2, Revenue::total(sellerId: self::OTHER_SELLER), 0.001);
    }

    // ---- the scorecard ----

    public function test_the_scorecard_counts_only_the_sellers_own_lines_refunds_reviews_and_strikes(): void
    {
        $response = $this->sellerApi('GET', 'seller-center/scorecard');

        $figures = ['orders_total', 'items_total', 'items_refunded', 'refund_rate', 'avg_rating', 'review_count', 'moderation_strikes', 'tier'];
        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals(
            ['orders_total' => 1, 'items_total' => 1, 'items_refunded' => 0, 'refund_rate' => 0, 'avg_rating' => 5, 'review_count' => 1, 'moderation_strikes' => 1, 'tier' => 'watch'],
            array_intersect_key($response->json(), array_flip($figures)),
        );
    }

    public function test_the_in_house_refunds_do_not_change_the_tier_of_the_seller_who_shares_its_id(): void
    {
        DB::table('product_moderation_events')->delete();
        DB::table('order_details')->insert([
            ['order_id' => self::IN_HOUSE_ORDER, 'seller_id' => self::IN_HOUSE_ID, 'product_id' => self::IN_HOUSE_PRODUCT, 'refund_request' => 1],
            ['order_id' => self::IN_HOUSE_ORDER, 'seller_id' => self::IN_HOUSE_ID, 'product_id' => self::IN_HOUSE_PRODUCT, 'refund_request' => 1],
        ]);

        $response = $this->sellerApi('GET', 'seller-center/scorecard');

        $this->assertEquals(0, $response->json('refund_rate'));
        $this->assertSame('good', $response->json('tier'));
    }

    public function test_the_in_house_strikes_do_not_change_the_tier_of_the_seller_who_shares_its_id(): void
    {
        DB::table('product_moderation_events')->delete();
        DB::table('product_moderation_events')->insert(array_fill(0, 3, ['product_id' => self::IN_HOUSE_PRODUCT, 'action' => 'suspended']));
        DB::table('order_details')->update(['refund_request' => 0]);

        $response = $this->sellerApi('GET', 'seller-center/scorecard');

        $this->assertSame(0, $response->json('moderation_strikes'));
        $this->assertSame('good', $response->json('tier'));
    }

    public function test_the_overview_reports_the_same_performance_as_the_scorecard(): void
    {
        $response = $this->sellerApi('GET', 'seller-center/overview');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->json('performance.items_total'));
        $this->assertSame(1, $response->json('performance.review_count'));
    }

    // ---- seller info of the older app ----

    public function test_v2_the_seller_info_counts_only_the_sellers_own_orders(): void
    {
        $response = $this->sellerApiV2('GET', 'seller-info');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->json('orders_count'));
    }

    public function test_v2_the_seller_info_of_the_other_seller_counts_that_sellers_orders(): void
    {
        $response = $this->sellerApiV2('GET', 'seller-info', as: self::OTHER_SELLER);

        $this->assertSame(1, $response->json('orders_count'));
    }

    // ---- the finance check ----

    public function test_the_finance_check_counts_only_the_sellers_own_delivered_lines_that_have_no_earning(): void
    {
        $this->createTheFinanceCheckTables();
        $this->deliverTheLinesLongAgo();

        $drafts = $this->financeFindings(sellerId: self::SELLER);

        $this->assertCount(1, $drafts);
        $this->assertSame(1.0, $drafts[0]->metric);
        $this->assertSame([self::OWN_ORDER], $drafts[0]->actionParams['order_ids']);
        $this->assertEqualsWithDelta(200.0, $drafts[0]->impact, 0.001);
    }

    public function test_the_finance_check_finds_nothing_for_a_seller_whose_only_delivered_lines_are_the_in_house_shops(): void
    {
        $this->createTheFinanceCheckTables();
        $this->deliverTheLinesLongAgo();
        DB::table('order_details')->where('id', self::OWN_LINE)->delete();

        $this->assertSame([], $this->financeFindings(sellerId: self::SELLER));
    }

    public function test_the_finance_check_of_the_other_seller_still_finds_that_sellers_own_line(): void
    {
        $this->createTheFinanceCheckTables();
        $this->deliverTheLinesLongAgo();

        $drafts = $this->financeFindings(sellerId: self::OTHER_SELLER);

        $this->assertCount(1, $drafts);
        $this->assertSame([self::OTHER_SELLERS_ORDER], $drafts[0]->actionParams['order_ids']);
    }

    public function test_the_action_center_of_a_seller_does_not_show_the_in_house_shops_orders_as_money_owed_to_them(): void
    {
        $this->createTheFinanceCheckTables();
        $this->createSellerApiTable('seller_insights', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id');
            foreach (['type', 'category', 'severity', 'status', 'title', 'entity_type', 'entity_id', 'action_key', 'fingerprint', 'resolution_type', 'resolution_message'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('body')->nullable();
            foreach (['metric', 'impact'] as $column) {
                $table->decimal($column, 16, 2)->nullable();
            }
            foreach (['impact_score', 'affected_count'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->integer('detection_count')->default(0);
            $table->integer('escalation_level')->default(0);
            $table->text('action_params')->nullable();
            $table->text('metadata')->nullable();
            $table->unsignedBigInteger('assigned_staff_id')->nullable();
            foreach (['expires_at', 'due_at', 'first_detected_at', 'last_detected_at', 'dismissed_at', 'resolved_at'] as $column) {
                $table->dateTime($column)->nullable();
            }
            $table->timestamps();
        });
        $this->deliverTheLinesLongAgo();
        DB::table('order_details')->where('id', self::OWN_LINE)->delete();

        app(SellerInsightEngine::class)->refresh(self::SELLER, ['FINANCE_INTEGRITY']);
        $response = $this->sellerApi('GET', 'seller-center/action-center');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->json('insights'));
        $this->assertSame(0, $response->json('counts.total'));
    }

    // ---- reserved units ----

    public function test_the_reserved_units_are_the_sellers_own_accepted_orders_and_not_the_in_house_shops(): void
    {
        DB::table('orders')->where('id', self::OWN_ORDER)->update(['order_status' => 'confirmed']);
        DB::table('orders')->where('id', self::IN_HOUSE_ORDER)->update(['order_status' => 'processing']);
        DB::table('order_details')->where('id', self::IN_HOUSE_LINE)->update(['qty' => 7]);

        $inventory = app(InventoryList::class);

        $this->assertSame(2, $inventory->reservedTotal(self::SELLER));
    }

    public function test_the_reserved_units_of_the_other_seller_are_still_counted(): void
    {
        DB::table('orders')->where('id', self::OTHER_SELLERS_ORDER)->update(['order_status' => 'out_for_delivery']);

        $this->assertSame(2, app(InventoryList::class)->reservedTotal(self::OTHER_SELLER));
    }

    // ---- the size of the business every insight is scored against ----

    public function test_the_recent_revenue_every_insight_is_scored_against_is_the_sellers_own_delivered_lines_only(): void
    {
        $this->deliverTheLinesLongAgo();

        $baselines = app(SellerBaselineProvider::class);

        $this->assertEqualsWithDelta(200.0, $baselines->for(self::SELLER)->recentRevenue, 0.001);
        $this->assertEqualsWithDelta(960.0, $baselines->for(self::OTHER_SELLER)->recentRevenue, 0.001);
    }

    // ---- helpers ----

    /** @return list<InsightDraft> */
    private function financeFindings(int $sellerId): array
    {
        return iterator_to_array(app(FinanceIntegrityProducer::class)->produce($sellerId), preserve_keys: false);
    }

    /** The finance check only looks at lines older than its grace window, and at the ledger. */
    private function createTheFinanceCheckTables(): void
    {
        $this->createSellerApiTable('vendor_ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            foreach (['seller_is', 'entry_type', 'reference_type', 'status', 'currency', 'description'] as $column) {
                $table->string($column)->nullable();
            }
            $table->unsignedBigInteger('reference_id')->nullable();
            foreach (['debit', 'credit', 'balance_after'] as $column) {
                $table->decimal($column, 16, 2)->default(0);
            }
            $table->timestamps();
        });
    }

    private function deliverTheLinesLongAgo(): void
    {
        DB::table('order_details')->update(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);
    }

    /** Seller 1 owns product 10 and its delivered line; the in-house shop owns product 30, a refunded delivered line, a 1-star review and a suspension. */
    private function seedProductsReviewsAndStrikes(): void
    {
        $product = fn (int $id, int $userId, string $addedBy) => DB::table('products')->insert(['id' => $id, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => 'Product ' . $id]);
        $product(self::OWN_PRODUCT, self::SELLER, 'seller');
        $product(self::OTHER_SELLERS_PRODUCT, self::OTHER_SELLER, 'seller');
        $product(self::IN_HOUSE_PRODUCT, self::IN_HOUSE_ID, 'admin');

        DB::table('reviews')->insert([
            ['product_id' => self::OWN_PRODUCT, 'rating' => 5, 'status' => 1],
            ['product_id' => self::IN_HOUSE_PRODUCT, 'rating' => 1, 'status' => 1],
        ]);
        DB::table('product_moderation_events')->insert([
            ['product_id' => self::OWN_PRODUCT, 'action' => 'rejected'],
            ['product_id' => self::IN_HOUSE_PRODUCT, 'action' => 'suspended'],
        ]);
        DB::table('order_details')->where('id', self::OWN_LINE)->update(['product_id' => self::OWN_PRODUCT]);
        DB::table('order_details')->where('id', self::IN_HOUSE_LINE)->update(['product_id' => self::IN_HOUSE_PRODUCT, 'refund_request' => 1]);
    }

    /** Revenue counts delivered lines in a window: all three shops' lines are delivered now. */
    private function deliverTheLinesToday(): void
    {
        DB::table('order_details')->update(['delivery_status' => 'delivered', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->update(['order_status' => 'delivered', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function createFigureTables(): void
    {
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->integer('current_stock')->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('product_moderation_events', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->string('action')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('seller_wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 16, 2)->default(0);
            $table->decimal('pending_withdraw', 16, 2)->default(0);
            $table->timestamps();
        });
    }
}
