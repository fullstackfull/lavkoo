<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;

/**
 * M2-02 of the master plan: the isolation of one country from another is proven for every ordered pair of the six storefronts, not for Saudi Arabia against one other.
 * Each country sells one product (the same name and code, its own id and price). For each of the thirty pairs (a customer served from one storefront, the product of
 * another): the product is unknown to the catalog, cannot be put in the cart, and the carts of the two storefronts do not show each other's rows; and no two storefronts
 * share a cache key.
 */
class StorefrontSixCountryIsolationTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;

    private const PRODUCT_OF = ['SA' => 1, 'AE' => 2, 'KW' => 3, 'QA' => 4, 'BH' => 5, 'OM' => 6];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
    }

    /** Runs the callback as a request bound to the storefront, the way the storefront middleware binds it, and unbinds it afterwards. */
    private function boundTo(string $storefront, callable $callback): mixed
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $storefront));

        try {
            return $callback();
        } finally {
            app()->forgetInstance(StorefrontContext::class);
        }
    }

    /** @return array<string, array{string, string}> every ordered pair of two different storefronts */
    public static function everyOrderedPair(): array
    {
        $pairs = [];

        foreach (array_keys(self::PRODUCT_OF) as $served) {
            foreach (array_keys(self::PRODUCT_OF) as $other) {
                if ($served !== $other) {
                    $pairs["served from {$served}, product of {$other}"] = [$served, $other];
                }
            }
        }

        return $pairs;
    }

    #[DataProvider('everyOrderedPair')]
    public function test_the_product_of_another_storefront_is_unknown_to_the_catalog(string $served, string $other): void
    {
        $found = $this->boundTo($served, fn (): array => [
            'own' => Product::active()->find(self::PRODUCT_OF[$served])?->id,
            'other' => Product::active()->find(self::PRODUCT_OF[$other])?->id,
            'all' => Product::active()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ]);

        $this->assertSame(self::PRODUCT_OF[$served], (int) $found['own']);
        $this->assertNull($found['other']);
        $this->assertSame([self::PRODUCT_OF[$served]], $found['all'], 'a storefront lists only its own product');
    }

    #[DataProvider('everyOrderedPair')]
    public function test_the_product_of_another_storefront_cannot_be_put_in_the_cart(string $served, string $other): void
    {
        $customer = $this->makeCustomer();

        $this->asCustomer($customer)->withHeader('X-Storefront', $served)->postJson('/api/v1/cart/add', ['id' => self::PRODUCT_OF[$other], 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('message', 'Product is unavailable');

        $this->assertSame(0, DB::table('carts')->count());
    }

    #[DataProvider('everyOrderedPair')]
    public function test_a_customer_who_shops_two_storefronts_has_two_carts_that_do_not_show_each_other(string $served, string $other): void
    {
        $customer = $this->makeCustomer();

        foreach ([$served, $other] as $storefront) {
            $this->asCustomer($customer)->withHeader('X-Storefront', $storefront)->postJson('/api/v1/cart/add', ['id' => self::PRODUCT_OF[$storefront], 'quantity' => 1])
                ->assertOk()
                ->assertJsonPath('status', 1);
        }

        $this->assertSame(2, DB::table('carts')->count());
        $this->assertCount(2, DB::table('carts')->pluck('cart_group_id')->unique(), 'one cart group for each storefront');

        foreach ([$served, $other] as $storefront) {
            $seen = $this->asCustomer($customer)->withHeader('X-Storefront', $storefront)->getJson('/api/v1/cart')->assertOk()->json();

            $this->assertSame([self::PRODUCT_OF[$storefront]], array_map(static fn (array $row): int => (int) $row['product_id'], $seen), "the cart seen from {$storefront}");
        }
    }

    #[DataProvider('everyOrderedPair')]
    public function test_the_price_and_stock_of_the_product_of_another_storefront_are_not_answered(string $served, string $other): void
    {
        $this->withHeader('X-Storefront', $served)
            ->postJson('/cart/variant_price', ['id' => self::PRODUCT_OF[$other], 'quantity' => 1, 'product_variation_code' => ''])
            ->assertNotFound();
    }

    #[DataProvider('everyOrderedPair')]
    public function test_two_storefronts_never_share_a_cache_key(string $served, string $other): void
    {
        $key = fn (string $storefront): string => $this->inStorefront($storefront, fn (): string => storefrontCacheKey('home_products'));

        $this->assertNotSame($key($served), $key($other));
    }

    public function test_the_six_pairs_that_name_the_default_storefront_first_or_last_are_among_the_thirty(): void
    {
        $this->assertCount(30, self::everyOrderedPair());
        $this->assertArrayHasKey('served from SA, product of OM', self::everyOrderedPair());
        $this->assertArrayHasKey('served from OM, product of SA', self::everyOrderedPair());
    }
}
