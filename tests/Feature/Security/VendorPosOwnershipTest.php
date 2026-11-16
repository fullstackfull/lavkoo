<?php

namespace Tests\Feature\Security;

use App\Enums\SessionKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The vendor panel's point of sale looks a product up by the id the page sends and puts it in the session cart, and the order that follows sells
 * it and takes its stock. The lookup had no owner in it: a seller could add another seller's product, or the in-house shop's (whose admin
 * user_id equals seller 1's id), to the cart, read its price and variations, and sell it at their own till.
 *
 * The cart is also read back from the session by id, to price it and to place the order: that lookup is the seller's own products too, so a cart that
 * still holds another seller's product (one put there before the cart routes were scoped) sells no line of it and takes none of its stock.
 */
class VendorPosOwnershipTest extends VendorPanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('colors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        DB::table('products')->update(['discount_type' => 'percent', 'discount' => 0, 'tax' => 0]);
        DB::table('currencies')->insert(['id' => 1, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert([['type' => 'system_default_currency', 'value' => '1'], ['type' => 'currency_model', 'value' => 'single_currency']]);
        // The prices of the fixture include their tax, so the sale needs no tax setup.
        foreach (['with_vat', 'no_vat'] as $variant) {
            Cache::put("tax_system_type_vendor_{$variant}", [
                'SystemTaxVat' => ['is_active' => 0, 'tax_ids' => [], 'additionalData' => []], 'SystemTaxVatType' => 'order_wise', 'is_included' => 1,
                'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
            ], 3600);
        }
        $this->addTheColumnsAPosSaleWrites();
    }

    private const CART = 'walk-in-customer-1';

    private function addTheColumnsAPosSaleWrites(): void
    {
        Schema::table('order_details', static function (Blueprint $table): void {
            $table->string('discount_type')->nullable();
        });
        Schema::table('orders', static function (Blueprint $table): void {
            foreach (['customer_type', 'shipping_address', 'billing_address', 'billing_address_data', 'shipping_responsibility', 'verification_code', 'tax_type', 'tax_model',
                         'extra_discount_type', 'coupon_code', 'discount_type', 'coupon_discount_bearer'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['checked', 'total_tax_amount', 'extra_discount', 'discount_amount', 'init_order_amount'] as $column) {
                $table->decimal($column, 16, 2)->default(0);
            }
        });
    }

    /** @return array<string, array{int}> */
    public static function productsThatAreNotTheSellers(): array
    {
        return [
            'another seller product' => [self::OTHER_SELLERS_PRODUCT],
            'in-house product sharing the seller id' => [self::IN_HOUSE_PRODUCT],
            'a product that does not exist' => [999],
        ];
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function pointOfSaleRoutes(): array
    {
        return [
            'the variant price' => ['/vendor/pos/get-variant-price', ['quantity' => 1]],
            'the quantity of a cart row' => ['/vendor/pos/quantity-update', ['quantity' => 2]],
            'the add to cart' => ['/vendor/pos/add-to-cart', ['quantity' => 1, 'quantity_in_cart' => 1]],
        ];
    }

    /** @param array<string, mixed> $fields */
    private function postToPos(string $uri, array $fields, int $productId): \Illuminate\Testing\TestResponse
    {
        $idField = $uri === '/vendor/pos/quantity-update' ? 'key' : 'id';

        return $this->signInAs(self::SELLER)->withSession([SessionKey::CURRENT_USER => 'wf-test-cart'])->postJson($uri, [$idField => $productId] + $fields);
    }

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_product_that_is_not_the_sellers_is_not_found_by_any_pos_cart_route(int $productId): void
    {
        foreach (self::pointOfSaleRoutes() as [$uri, $fields]) {
            $response = $this->postToPos($uri, $fields, $productId);

            $response->assertNotFound()->assertJsonStructure(['message']);
        }
        $this->assertSame([], array_filter((array)session('wf-test-cart'), 'is_array'), 'a product that is not the seller\'s is in the cart');
        $this->assertStringNotContainsString('Another seller', json_encode(session()->all()));
    }

    public function test_the_sellers_own_product_is_still_found_and_priced(): void
    {
        $response = $this->postToPos('/vendor/pos/get-variant-price', ['quantity' => 1], self::OWN_PRODUCT);

        $response->assertOk()->assertJson(['price' => '100$', 'quantity' => 5]);
    }

    // ---- the cart read back from the session ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_cart_holding_a_product_that_is_not_the_sellers_sells_no_line_of_it_and_takes_none_of_its_stock(int $productId): void
    {
        $stockBefore = DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->all();

        $this->signInAs(self::SELLER)->withSession($this->cartHolding(productIds: [$productId]))->postJson('/vendor/pos/order-place', ['amount' => 200, 'type' => 'cash', 'paid_amount' => 200])->assertOk();

        $this->assertSame([], DB::table('order_details')->where('product_id', $productId)->get()->all(), 'a line of the product was sold');
        $this->assertSame($stockBefore, DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->all());
    }

    public function test_a_cart_holding_the_sellers_own_product_is_still_sold_and_takes_its_stock(): void
    {
        $this->signInAs(self::SELLER)->withSession($this->cartHolding(productIds: [self::OWN_PRODUCT]))->postJson('/vendor/pos/order-place', ['amount' => 200, 'type' => 'cash', 'paid_amount' => 200])->assertOk();

        $line = DB::table('order_details')->where('product_id', self::OWN_PRODUCT)->first();
        $this->assertSame([self::SELLER, 2], [(int)$line->seller_id, (int)$line->qty]);
        $this->assertSame(3, (int)DB::table('products')->where('id', self::OWN_PRODUCT)->value('current_stock'));
    }

    public function test_the_cart_summary_leaves_out_a_product_that_is_not_the_sellers(): void
    {
        $session = $this->cartHolding(productIds: [self::OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT]);

        $summary = $this->signInAs(self::SELLER)->withSession($session)->postJson('/vendor/pos/quantity-update', ['key' => self::OWN_PRODUCT, 'quantity' => 0])->assertOk()->json('view');

        $this->assertStringContainsString('Own product', $summary);
        $this->assertStringNotContainsString('Other seller', $summary);
        $this->assertStringNotContainsString('In-house pro', $summary);
    }

    public function test_the_discount_update_summary_leaves_out_a_product_that_is_not_the_sellers(): void
    {
        $session = $this->cartHolding(productIds: [self::OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT]);

        $summary = $this->signInAs(self::SELLER)->withSession($session)->postJson('/vendor/pos/update-discount', ['type' => 'flat', 'discount' => 1])->assertOk()->json('view');

        $this->assertStringContainsString('Own product', $summary);
        $this->assertStringNotContainsString('Other seller', $summary);
        $this->assertStringNotContainsString('In-house pro', $summary);
    }

    public function test_the_summary_of_the_cart_that_moves_to_another_customer_leaves_out_a_product_that_is_not_the_sellers(): void
    {
        $this->createTheTablesTheCustomerInfoReads();
        $session = $this->cartHolding(productIds: [self::OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT]);

        $response = $this->signInAs(self::SELLER)->withSession($session)->postJson('/vendor/pos/change-customer', ['user_id' => 0])->assertOk();

        $summary = (string) $response->json('view') . (string) $response->json('cart_view');
        $this->assertStringContainsString('Own product', $summary);
        $this->assertStringNotContainsString('Other seller', $summary);
        $this->assertStringNotContainsString('In-house pro', $summary);
    }

    public function test_the_hold_orders_summary_leaves_out_a_product_that_is_not_the_sellers(): void
    {
        $session = $this->cartHolding(productIds: [self::OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT], onHold: true);

        $summary = $this->signInAs(self::SELLER)->withSession($session)->postJson('/vendor/pos/view-hold-orders')->assertOk()->json('view');

        $this->assertStringContainsString('Own product', $summary);
        $this->assertStringNotContainsString('Other seller', $summary);
        $this->assertStringNotContainsString('In-house pro', $summary);
    }

    // ---- the order details route nothing linked to ----

    /** @return array<string, array{int}> */
    public static function ordersOfAnyShop(): array
    {
        return [
            'the sellers own order' => [100001],
            'another seller order' => [100002],
            'the in-house order sharing the seller id' => [100003],
            'an order that does not exist' => [999999],
        ];
    }

    #[DataProvider('ordersOfAnyShop')]
    public function test_the_point_of_sale_has_no_order_details_route_that_reads_an_order_by_its_id(int $orderId): void
    {
        DB::table('business_settings')->insert(['type' => 'seller_pos', 'value' => '1']);
        Cache::flush();
        DB::table('orders')->insert([
            ['id' => 100001, 'seller_is' => 'seller', 'seller_id' => self::SELLER, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 100002, 'seller_is' => 'seller', 'seller_id' => self::OTHER_SELLER, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 100003, 'seller_is' => 'admin', 'seller_id' => self::SELLER, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->signInAs(self::SELLER)->post('/vendor/pos/order-details/' . $orderId)->assertNotFound();
    }

    // ---- the quick view of a product ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_the_quick_view_of_a_product_that_is_not_the_sellers_is_not_found(int $productId): void
    {
        $this->createTheTablesTheQuickViewReads();

        $response = $this->signInAs(self::SELLER)->getJson('/vendor/pos/quick-view?product_id=' . $productId);

        $response->assertNotFound();
        $this->assertStringNotContainsString('Other sellers product', (string) $response->getContent());
        $this->assertStringNotContainsString('In-house product', (string) $response->getContent());
    }

    public function test_the_quick_view_of_the_sellers_own_product_still_opens(): void
    {
        $this->createTheTablesTheQuickViewReads();

        $response = $this->signInAs(self::SELLER)->getJson('/vendor/pos/quick-view?product_id=' . self::OWN_PRODUCT);

        $response->assertOk()->assertJson(['success' => 1]);
        $this->assertStringContainsString('Own product', $response->json('view'));
    }

    private function createTheTablesTheCustomerInfoReads(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->decimal('wallet_balance', 16, 2)->default(0);
            $table->timestamps();
        });
    }

    private function createTheTablesTheQuickViewReads(): void
    {
        foreach (['tags' => 'tag', 'authors' => 'name', 'publishing_houses' => 'name'] as $tableName => $column) {
            Schema::create($tableName, static function (Blueprint $table) use ($column): void {
                $table->id();
                $table->string($column)->nullable();
                $table->timestamps();
            });
        }
        Schema::create('product_tag', static function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
        });
        Schema::create('digital_product_authors', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('author_id');
            $table->timestamps();
        });
        Schema::create('digital_product_publishing_houses', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('publishing_house_id');
            $table->timestamps();
        });
    }

    /**
     * @param list<int> $productIds
     * @return array<string, mixed> a walk-in customer's session cart with two of each product, the way the add to cart route puts them there
     */
    private function cartHolding(array $productIds, bool $onHold = false): array
    {
        $names = [self::OWN_PRODUCT => 'Own product', self::OTHER_SELLERS_PRODUCT => 'Other sellers product', self::IN_HOUSE_PRODUCT => 'In-house product'];

        return [
            SessionKey::CURRENT_USER => self::CART,
            'cart_name' => [self::CART],
            self::CART => array_map(static fn (int $productId): array => [
                'id' => $productId, 'customerId' => 0, 'customerOnHold' => $onHold, 'quantity' => 2, 'price' => 100, 'name' => $names[$productId] ?? 'Unknown product',
                'productType' => 'physical', 'image' => 'thumbnail.png', 'discount' => 0, 'variant' => '', 'variations' => ['color' => null],
            ], $productIds),
        ];
    }
}
