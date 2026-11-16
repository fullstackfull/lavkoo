<?php

namespace Tests\Feature\Security;

use App\Enums\SessionKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The vendor panel's point of sale looks a product up by the id the page sends and puts it in the session cart, and the order that follows sells
 * it and takes its stock. The lookup had no owner in it: a seller could add another seller's product, or the in-house shop's (whose admin
 * user_id equals seller 1's id), to the cart, read its price and variations, and sell it at their own till.
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
}
