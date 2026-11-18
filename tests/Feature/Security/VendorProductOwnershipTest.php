<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The vendor panel's product pages reach a product by the id in the URL or the form, scoped by user_id alone in several handlers. Admin
 * products carry the admin's id as user_id, so seller 1 collides with the in-house shop's products (added_by 'admin', user_id 1): a seller
 * could switch off, delete, print the barcode of and open the page of an in-house product, and the digital file, variation and stock lookups
 * took any product at all.
 *
 * Each handler must answer only for the seller's own product (added_by 'seller', user_id the signed-in seller): another seller's product
 * and an in-house product sharing the seller's id are not found, and nothing changes.
 */
class VendorProductOwnershipTest extends VendorPanelTestCase
{
    private const DIGITAL_FILE = 'sold-ebook.pdf';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('restock_products', static function (Blueprint $table): void {
            $table->id();
            $table->integer('product_id');
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_product_customers', static function (Blueprint $table): void {
            $table->id();
            $table->integer('restock_product_id');
            $table->integer('customer_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
    }

    /** @return array<string, array{int}> the two products a seller reaches by id that are not theirs: another seller's, and the in-house product sharing their id */
    public static function productsThatAreNotTheSellers(): array
    {
        return [
            'another seller product' => [self::OTHER_SELLERS_PRODUCT],
            'in-house product sharing the seller id' => [self::IN_HOUSE_PRODUCT],
        ];
    }

    // ---- status ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_switch_off_a_product_that_is_not_theirs(int $productId): void
    {
        $this->signInAs(self::SELLER)->postJson('/vendor/products/status-update', ['id' => $productId, 'status' => 0])
            ->assertOk()->assertJson(['success' => 0]);

        $this->assertSame(1, $this->statusOf($productId));
    }

    public function test_a_seller_can_still_switch_their_own_product_off_and_on(): void
    {
        $this->signInAs(self::SELLER);

        $this->postJson('/vendor/products/status-update', ['id' => self::OWN_PRODUCT, 'status' => 0])->assertOk()->assertJson(['success' => 1]);
        $this->assertSame(0, $this->statusOf(self::OWN_PRODUCT));

        $this->postJson('/vendor/products/status-update', ['id' => self::OWN_PRODUCT, 'status' => 1])->assertOk()->assertJson(['success' => 1]);
        $this->assertSame(1, $this->statusOf(self::OWN_PRODUCT));
    }

    // ---- delete ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_delete_a_product_that_is_not_theirs(int $productId): void
    {
        DB::table('wishlists')->insert(['product_id' => $productId]);

        $this->signInAs(self::SELLER)->delete('/vendor/products/delete/' . $productId)->assertRedirect();

        $this->assertTrue(DB::table('products')->where('id', $productId)->exists());
        $this->assertSame(1, DB::table('wishlists')->where('product_id', $productId)->count());
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_a_seller_can_still_delete_their_own_product_with_what_hangs_on_it(): void
    {
        DB::table('wishlists')->insert([['product_id' => self::OWN_PRODUCT], ['product_id' => self::OTHER_SELLERS_PRODUCT]]);

        $this->signInAs(self::SELLER)->delete('/vendor/products/delete/' . self::OWN_PRODUCT)->assertRedirect();

        $this->assertFalse(DB::table('products')->where('id', self::OWN_PRODUCT)->exists());
        $this->assertSame([self::OTHER_SELLERS_PRODUCT], DB::table('wishlists')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertContains('success', $this->toastTypes());
    }

    // ---- barcode and view ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_open_the_barcode_page_of_a_product_that_is_not_theirs(int $productId): void
    {
        $response = $this->signInAs(self::SELLER)->get('/vendor/products/barcode/' . $productId);

        $response->assertRedirect(route('vendor.products.list', ['type' => 'all']));
        $this->assertContains('error', $this->toastTypes());
    }

    public function test_a_seller_can_still_open_the_barcode_page_of_their_own_product(): void
    {
        $response = $this->signInAs(self::SELLER)->get('/vendor/products/barcode/' . self::OWN_PRODUCT . '?limit=2');

        $response->assertOk()->assertSee('Own product')->assertSee('CODE' . self::OWN_PRODUCT);
    }

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_open_the_page_of_a_product_that_is_not_theirs(int $productId): void
    {
        $response = $this->signInAs(self::SELLER)->get('/vendor/products/view/' . $productId);

        $response->assertRedirect(route('vendor.products.list', ['type' => 'all']));
    }

    public function test_a_seller_can_still_open_the_page_of_their_own_product(): void
    {
        $response = $this->signInAs(self::SELLER)->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $response->assertOk()->assertSee('Own product');
    }

    // ---- restock requests ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_delete_a_restock_request_of_a_product_that_is_not_theirs(int $productId): void
    {
        $restockId = DB::table('restock_products')->insertGetId(['product_id' => $productId, 'variant' => '']);
        DB::table('restock_product_customers')->insert(['restock_product_id' => $restockId, 'customer_id' => 7]);

        $this->signInAs(self::SELLER)->delete('/vendor/products/delete-restock/' . $restockId)->assertRedirect();

        $this->assertTrue(DB::table('restock_products')->where('id', $restockId)->exists());
        $this->assertSame(1, DB::table('restock_product_customers')->where('restock_product_id', $restockId)->count());
    }

    public function test_a_seller_can_still_delete_a_restock_request_of_their_own_product(): void
    {
        $restockId = DB::table('restock_products')->insertGetId(['product_id' => self::OWN_PRODUCT, 'variant' => '']);
        DB::table('restock_product_customers')->insert(['restock_product_id' => $restockId, 'customer_id' => 7]);

        $this->signInAs(self::SELLER)->delete('/vendor/products/delete-restock/' . $restockId)->assertRedirect();

        $this->assertFalse(DB::table('restock_products')->where('id', $restockId)->exists());
        $this->assertSame(0, DB::table('restock_product_customers')->where('restock_product_id', $restockId)->count());
    }

    // ---- digital files ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_seller_cannot_delete_the_digital_file_of_a_product_that_is_not_theirs(int $productId): void
    {
        $variationId = $this->insertDigitalVariation(productId: $productId);

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/products/digital-variation-file-delete', ['product_id' => $productId, 'variant_key' => 'pdf']);

        $response->assertJson(['status' => 0]);
        $this->assertSame(self::DIGITAL_FILE, DB::table('digital_product_variations')->where('id', $variationId)->value('file'));
        Storage::disk('public')->assertExists('product/digital-product/' . self::DIGITAL_FILE);
    }

    public function test_a_seller_can_still_delete_the_digital_file_of_their_own_product(): void
    {
        $variationId = $this->insertDigitalVariation(productId: self::OWN_PRODUCT);

        $response = $this->signInAs(self::SELLER)->postJson('/vendor/products/digital-variation-file-delete', ['product_id' => self::OWN_PRODUCT, 'variant_key' => 'pdf']);

        $response->assertOk()->assertJson(['status' => 1]);
        $this->assertNull(DB::table('digital_product_variations')->where('id', $variationId)->value('file'));
        Storage::disk('public')->assertMissing('product/digital-product/' . self::DIGITAL_FILE);
    }

    // ---- what the product form reads about a product ----

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_the_sku_combinations_of_a_product_that_is_not_the_sellers_are_not_filled_in(int $productId): void
    {
        $this->setVariations(productId: $productId, sku: 'RIVAL-SKU', qty: 77, price: 555);

        $view = $this->signInAs(self::SELLER)->postJson('/vendor/products/sku-combination', $this->combinationForm(productId: $productId))->assertOk()->json('view');

        $this->assertStringNotContainsString('RIVAL-SKU', $view);
        $this->assertStringContainsString('new-combinations:', $view);
    }

    public function test_the_sku_combinations_of_the_sellers_own_product_are_still_filled_in(): void
    {
        $this->setVariations(productId: self::OWN_PRODUCT, sku: 'OWN-SKU', qty: 77, price: 555);

        $view = $this->signInAs(self::SELLER)->postJson('/vendor/products/sku-combination', $this->combinationForm(productId: self::OWN_PRODUCT))->assertOk()->json('view');

        $this->assertStringContainsString('edit-combinations:OWN-SKU/77/555;', $view);
    }

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_the_digital_files_of_a_product_that_is_not_the_sellers_are_not_filled_in(int $productId): void
    {
        $this->insertDigitalVariation(productId: $productId, variantKey: 'document-pdf');

        $view = $this->signInAs(self::SELLER)->postJson('/vendor/products/digital-variation-combination', $this->digitalCombinationForm(productId: $productId))->assertOk()->json('view');

        $this->assertStringNotContainsString(self::DIGITAL_FILE, $view);
    }

    public function test_the_digital_files_of_the_sellers_own_product_are_still_filled_in(): void
    {
        $this->insertDigitalVariation(productId: self::OWN_PRODUCT, variantKey: 'document-pdf');

        $view = $this->signInAs(self::SELLER)->postJson('/vendor/products/digital-variation-combination', $this->digitalCombinationForm(productId: self::OWN_PRODUCT))->assertOk()->json('view');

        $this->assertStringContainsString('document-pdf/', $view);
        $this->assertStringContainsString(self::DIGITAL_FILE, $view);
    }

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_the_stock_of_a_product_that_is_not_the_sellers_is_not_shown(int $productId): void
    {
        $response = $this->signInAs(self::SELLER)->getJson('/vendor/products/get-variations?id=' . $productId);

        $response->assertNotFound()->assertJson(['status' => 0]);
        $this->assertStringNotContainsString('stock-modal', (string) $response->getContent());
    }

    public function test_the_stock_of_the_sellers_own_product_is_still_shown(): void
    {
        $view = $this->signInAs(self::SELLER)->getJson('/vendor/products/get-variations?id=' . self::OWN_PRODUCT)->assertOk()->json('view');

        $this->assertStringContainsString('stock-modal:Own product:5', $view);
    }

    private function setVariations(int $productId, string $sku, int $qty, int $price): void
    {
        DB::table('products')->where('id', $productId)->update(['variation' => json_encode([['type' => 'Large', 'price' => $price, 'sku' => $sku, 'qty' => $qty]])]);
    }

    /** @return array<string, mixed> what the product form posts to list the combinations of one choice option */
    private function combinationForm(int $productId): array
    {
        return ['product_id' => $productId, 'lang' => ['en'], 'name' => ['Any name'], 'unit_price' => 100, 'choice_no' => [1], 'choice_options_1' => ['Large']];
    }

    /** @return array<string, mixed> what the product form posts to list the digital variations of one extension */
    private function digitalCombinationForm(int $productId): array
    {
        return [
            'product_id' => $productId, 'lang' => ['en'], 'name' => ['Any name'], 'unit_price' => 100, 'digital_product_type' => 'ready_product',
            'extensions_type' => ['document'], 'extensions_options_document' => ['pdf'],
        ];
    }

    private function insertDigitalVariation(int $productId, string $variantKey = 'pdf'): int
    {
        Storage::disk('public')->put('product/digital-product/' . self::DIGITAL_FILE, 'PDF-BYTES');

        return DB::table('digital_product_variations')->insertGetId(['product_id' => $productId, 'variant_key' => $variantKey, 'sku' => 'DIGITAL-SKU', 'price' => 9, 'file' => self::DIGITAL_FILE]);
    }

    private function statusOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('status');
    }
}
