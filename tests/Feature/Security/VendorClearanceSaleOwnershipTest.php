<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The vendor panel's clearance sale pages reach a product, and a clearance entry, by the product id the page sends. Any seller who may run
 * promotions could put another seller's product on their own clearance sale (which discounts it on the storefront), and reprice, switch off
 * or delete another seller's clearance entry, or the in-house shop's, whose admin user_id equals seller 1's id. The discount update even read
 * the admin's clearance setup instead of the seller's own.
 *
 * Each handler must answer only for the seller's own products and the seller's own vendor entries (added_by 'vendor', user_id the signed-in
 * seller): another seller's and the in-house shop's are not found, and nothing changes.
 */
class VendorClearanceSaleOwnershipTest extends VendorPanelTestCase
{
    private const SECOND_OWN_PRODUCT = 11;

    private const OWN_SETUP = 1;
    private const OTHER_SELLERS_SETUP = 2;
    private const IN_HOUSE_SETUP = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->insertProduct(id: self::SECOND_OWN_PRODUCT, userId: self::SELLER, addedBy: 'seller', name: 'Second own product');

        $this->insertSetup(id: self::OWN_SETUP, setupBy: 'vendor', userId: self::SELLER, shopId: self::SELLERS_SHOP);
        $this->insertSetup(id: self::OTHER_SELLERS_SETUP, setupBy: 'vendor', userId: self::OTHER_SELLER, shopId: self::OTHER_SELLERS_SHOP);
        $this->insertSetup(id: self::IN_HOUSE_SETUP, setupBy: 'admin', userId: self::SELLER, shopId: 0);

        $this->insertEntry(productId: self::OWN_PRODUCT, addedBy: 'vendor', userId: self::SELLER, setupId: self::OWN_SETUP);
        $this->insertEntry(productId: self::OTHER_SELLERS_PRODUCT, addedBy: 'vendor', userId: self::OTHER_SELLER, setupId: self::OTHER_SELLERS_SETUP);
        $this->insertEntry(productId: self::IN_HOUSE_PRODUCT, addedBy: 'admin', userId: self::SELLER, setupId: self::IN_HOUSE_SETUP);
    }

    /** @return array<string, array{int}> the two products a seller reaches by id that are not theirs: another seller's, and the in-house product sharing their id */
    public static function productsThatAreNotTheSellers(): array
    {
        return [
            'another seller product' => [self::OTHER_SELLERS_PRODUCT],
            'in-house product sharing the seller id' => [self::IN_HOUSE_PRODUCT],
        ];
    }

    // ---- putting products on the sale ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_put_a_product_that_is_not_theirs_on_their_clearance_sale(int $productId): void
    {
        DB::table('stock_clearance_products')->where('product_id', $productId)->delete();

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/clearance-sale/add-clearance-product', $this->addForm(productIds: [$productId]));

        $response->assertOk()->assertJson(['status' => false]);
        $this->assertSame(0, DB::table('stock_clearance_products')->where('product_id', $productId)->count());
    }

    public function test_a_request_naming_one_product_that_is_not_theirs_puts_none_of_them_on_the_sale(): void
    {
        $response = $this->signInAs(self::SELLER)->postJson('/vendor/clearance-sale/add-clearance-product', $this->addForm(productIds: [self::SECOND_OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT]));

        $response->assertOk()->assertJson(['status' => false]);
        $this->assertSame(0, DB::table('stock_clearance_products')->where('product_id', self::SECOND_OWN_PRODUCT)->count());
    }

    public function test_a_seller_can_still_put_their_own_product_on_their_clearance_sale(): void
    {
        $response = $this->signInAs(self::SELLER)->postJson('/vendor/clearance-sale/add-clearance-product', $this->addForm(productIds: [self::SECOND_OWN_PRODUCT]));

        $response->assertOk()->assertJson(['status' => true]);
        $entry = DB::table('stock_clearance_products')->where('product_id', self::SECOND_OWN_PRODUCT)->first();
        $this->assertSame(
            ['vendor', self::SELLER, self::OWN_SETUP, self::SELLERS_SHOP, 'percentage', 15.0, 1],
            [$entry->added_by, (int) $entry->user_id, (int) $entry->setup_id, (int) $entry->shop_id, $entry->discount_type, (float) $entry->discount_amount, (int) $entry->is_active],
        );
    }

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_the_product_cards_of_the_sale_do_not_list_a_product_that_is_not_the_sellers(int $productId): void
    {
        $response = $this->signInAs(self::SELLER)->getJson('/vendor/clearance-sale/multiple-product-details?' . http_build_query(['productIds' => [$productId, self::OWN_PRODUCT]]));

        $view = $response->assertOk()->json('result');
        $this->assertStringContainsString('selected-product:Own product;', $view);
        $this->assertSame(1, substr_count($view, 'selected-product:'), 'only the seller\'s own product is listed');
    }

    // ---- repricing, switching off and removing an entry ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_reprice_a_clearance_entry_that_is_not_theirs(int $productId): void
    {
        $before = $this->entryOf($productId);

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/clearance-sale/update-discount', ['product_id' => $productId, 'discount_type' => 'percentage', 'discount_amount' => 99]);

        $response->assertOk()->assertJson(['status' => false]);
        $this->assertSame($before, $this->entryOf($productId));
    }

    public function test_a_seller_can_still_reprice_their_own_clearance_entry(): void
    {
        $response = $this->signInAs(self::SELLER)->postJson('/vendor/clearance-sale/update-discount', ['product_id' => self::OWN_PRODUCT, 'discount_type' => 'percentage', 'discount_amount' => 25]);

        $response->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['percentage', 25.0], array_slice($this->entryOf(self::OWN_PRODUCT), 0, 2));
    }

    public function test_the_discount_of_an_entry_is_checked_against_the_sellers_own_setup_not_the_admins(): void
    {
        DB::table('stock_clearance_setups')->where('id', self::IN_HOUSE_SETUP)->update(['discount_type' => 'flat']);

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/clearance-sale/update-discount', ['product_id' => self::OWN_PRODUCT, 'discount_type' => 'percentage', 'discount_amount' => 500]);

        $response->assertOk()->assertJson(['status' => false]);
        $this->assertSame(['percentage', 10.0, 1], $this->entryOf(self::OWN_PRODUCT));
    }

    public function test_a_seller_can_still_reprice_their_own_entry_when_the_admin_has_no_clearance_setup(): void
    {
        DB::table('stock_clearance_setups')->where('setup_by', 'admin')->delete();

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/clearance-sale/update-discount', ['product_id' => self::OWN_PRODUCT, 'discount_type' => 'percentage', 'discount_amount' => 25]);

        $response->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['percentage', 25.0], array_slice($this->entryOf(self::OWN_PRODUCT), 0, 2));
    }

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_switch_off_a_clearance_entry_that_is_not_theirs(int $productId): void
    {
        $before = $this->entryOf($productId);

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/clearance-sale/clearance-product-status-update', ['product_id' => $productId, 'status' => 0]);

        $response->assertOk()->assertJson(['status' => 0]);
        $this->assertSame($before, $this->entryOf($productId));
    }

    public function test_a_seller_can_still_switch_their_own_clearance_entry_off_and_on(): void
    {
        $this->signInAs(self::SELLER);

        $this->postJson('/vendor/clearance-sale/clearance-product-status-update', ['product_id' => self::OWN_PRODUCT, 'status' => 0])->assertOk()->assertJson(['status' => 1]);
        $this->assertSame(0, $this->entryOf(self::OWN_PRODUCT)[2]);

        $this->postJson('/vendor/clearance-sale/clearance-product-status-update', ['product_id' => self::OWN_PRODUCT, 'status' => 1])->assertOk()->assertJson(['status' => 1]);
        $this->assertSame(1, $this->entryOf(self::OWN_PRODUCT)[2]);
    }

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_remove_a_clearance_entry_that_is_not_theirs(int $productId): void
    {
        $this->signInAs(self::SELLER)->delete('/vendor/clearance-sale/clearance-delete/' . $productId)->assertRedirect();

        $this->assertSame(1, DB::table('stock_clearance_products')->where('product_id', $productId)->count());
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_a_seller_can_still_remove_their_own_clearance_entry(): void
    {
        $this->signInAs(self::SELLER)->delete('/vendor/clearance-sale/clearance-delete/' . self::OWN_PRODUCT)->assertRedirect();

        $this->assertSame(0, DB::table('stock_clearance_products')->where('product_id', self::OWN_PRODUCT)->count());
        $this->assertSame(2, DB::table('stock_clearance_products')->count(), 'the other entries stay');
        $this->assertContains('success', $this->toastTypes());
    }

    private function insertSetup(int $id, string $setupBy, int $userId, int $shopId): void
    {
        DB::table('stock_clearance_setups')->insert([
            'id' => $id, 'setup_by' => $setupBy, 'user_id' => $userId, 'shop_id' => $shopId, 'is_active' => 1, 'discount_type' => 'product_wise',
            'discount_amount' => 0, 'offer_active_time' => 'always', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertEntry(int $productId, string $addedBy, int $userId, int $setupId): void
    {
        DB::table('stock_clearance_products')->insert([
            'added_by' => $addedBy, 'product_id' => $productId, 'setup_id' => $setupId, 'user_id' => $userId, 'shop_id' => 0, 'is_active' => 1,
            'discount_type' => 'percentage', 'discount_amount' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{string, float, int} the discount type, the amount and the switch of the entry of a product */
    private function entryOf(int $productId): array
    {
        $entry = DB::table('stock_clearance_products')->where('product_id', $productId)->first();

        return [$entry->discount_type, (float) $entry->discount_amount, (int) $entry->is_active];
    }

    /**
     * @param list<int> $productIds
     * @return array<string, mixed> what the clearance page posts to put products on the sale: 15% on each
     */
    private function addForm(array $productIds): array
    {
        return [
            'productIds' => $productIds,
            'discount_type' => array_fill_keys($productIds, 'percentage'),
            'discount_amount' => array_fill_keys($productIds, 15),
        ];
    }
}
