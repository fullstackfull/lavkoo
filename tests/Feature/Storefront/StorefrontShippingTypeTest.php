<?php

namespace Tests\Feature\Storefront;

use App\Models\ShippingType;
use App\Traits\CacheManagerTrait;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\TestCase;

/**
 * GCC-05, slice 5: the shipping tables of the platform (a cost for each method, each category and each zone) are typed in the platform's money, and
 * the cart reads them through the shipping type. A storefront that trades in its own currency prices shipping from the product alone, so no table
 * of the platform can put a riyal figure on a Kuwaiti order; the stored type and everyone else's reading of it are untouched.
 */
class StorefrontShippingTypeTest extends TestCase
{
    use BuildsStorefrontMoneyWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
        Schema::dropIfExists('shipping_types');
        Schema::create('shipping_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('shipping_type')->nullable();
            $table->timestamps();
        });
        Cache::forget(CACHE_FOR_IN_HOUSE_SHIPPING_TYPE);
    }

    protected function tearDown(): void
    {
        Cache::forget(CACHE_FOR_IN_HOUSE_SHIPPING_TYPE);
        Schema::dropIfExists('shipping_types');
        $this->dropStorefrontMoneyWorld();

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function storedTypes(): array
    {
        return ['order wise' => ['order_wise'], 'product wise' => ['product_wise'], 'category wise' => ['category_wise']];
    }

    private function giveShippingType(int $sellerId, string $type): ShippingType
    {
        return ShippingType::query()->create(['seller_id' => $sellerId, 'shipping_type' => $type]);
    }

    private function cacheReader(): object
    {
        return new class {
            use CacheManagerTrait;
        };
    }

    #[DataProvider('storedTypes')]
    public function test_the_default_storefront_a_process_with_no_storefront_and_every_storefront_with_the_switch_off_read_the_stored_type(string $stored): void
    {
        $this->giveShippingType(sellerId: 0, type: $stored);
        $this->giveShippingType(sellerId: 7, type: $stored);

        $this->assertSame($stored, ShippingType::query()->where('seller_id', 0)->first()->shipping_type, 'a process with no storefront');
        $this->assertSame($stored, $this->inStorefront('SA', fn () => ShippingType::query()->where('seller_id', 0)->first()->shipping_type));

        config(['gcc.storefronts.currency.enabled' => false]);

        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            $this->assertSame($stored, $this->inStorefront($country, fn () => ShippingType::query()->where('seller_id', 7)->first()->shipping_type), $country);
        }
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_a_storefront_with_its_own_currency_prices_shipping_from_the_product_whatever_the_platform_stores(string $country): void
    {
        $this->giveShippingType(sellerId: 0, type: 'order_wise');
        $this->giveShippingType(sellerId: 7, type: 'category_wise');
        $this->giveShippingType(sellerId: 8, type: 'product_wise');

        $types = $this->inStorefront($country, fn (): array => ShippingType::query()->orderBy('seller_id')->get()->map(fn (ShippingType $type): ?string => $type->shipping_type)->all());

        $this->assertSame(['product_wise', 'product_wise', 'product_wise'], $types, 'the in-house row and every seller row');
    }

    public function test_the_stored_value_is_left_as_it_is_and_the_model_still_saves_what_it_is_given(): void
    {
        $row = $this->giveShippingType(sellerId: 0, type: 'order_wise');

        $inStorefront = $this->inStorefront('KW', fn (): array => [ShippingType::query()->find($row->id)->shipping_type, ShippingType::query()->find($row->id)->getRawOriginal('shipping_type')]);

        $this->assertSame(['product_wise', 'order_wise'], $inStorefront);
        $this->assertSame('order_wise', DB::table('shipping_types')->where('id', $row->id)->value('shipping_type'));
    }

    public function test_a_storefront_whose_currency_is_not_in_force_reads_the_stored_type(): void
    {
        $this->giveShippingType(sellerId: 0, type: 'order_wise');
        DB::table('currencies')->where('code', 'KWD')->delete();
        Cache::forget('active_currency_list');

        $this->assertSame('order_wise', $this->inStorefront('KW', fn () => ShippingType::query()->where('seller_id', 0)->first()->shipping_type));
    }

    public function test_the_cache_of_the_in_house_type_holds_the_stored_value_so_one_kuwaiti_request_never_fills_the_saudi_entry(): void
    {
        $this->giveShippingType(sellerId: 0, type: 'order_wise');
        $reader = $this->cacheReader();

        $this->assertSame('product_wise', $this->inStorefront('KW', fn () => $reader->cacheInHouseShippingType()));
        $this->assertSame('order_wise', Cache::get(CACHE_FOR_IN_HOUSE_SHIPPING_TYPE), 'the cache holds the raw value');
        $this->assertSame('order_wise', $reader->cacheInHouseShippingType(), 'a process with no storefront');
        $this->assertSame('order_wise', $this->inStorefront('SA', fn () => $reader->cacheInHouseShippingType()));
        $this->assertSame('product_wise', $this->inStorefront('AE', fn () => $reader->cacheInHouseShippingType()));
    }

    public function test_the_cache_of_the_in_house_type_answers_order_wise_when_no_row_exists(): void
    {
        $reader = $this->cacheReader();

        $this->assertSame('order_wise', $reader->cacheInHouseShippingType());
        $this->assertSame('product_wise', $this->inStorefront('KW', fn () => $reader->cacheInHouseShippingType()));
    }

    /** The flat referral discount is an amount typed in the platform's money (a dollar label), so it is 0 on a storefront with its own currency. */
    private function referral(string $amountType): object
    {
        return (object) [
            'customer_discount_amount' => 10,
            'customer_discount_validity' => 30,
            'customer_discount_validity_type' => 'day',
            'customer_discount_amount_type' => $amountType,
            'created_at' => now()->subDay(),
        ];
    }

    public function test_the_flat_referral_discount_is_the_platforms_everywhere_but_on_a_storefront_with_its_own_currency(): void
    {
        $flat = $this->referral(amountType: 'amount');

        $this->assertEqualsWithDelta(5.0, OrderManager::redeemReferralDiscount($flat, 200, 100), 0.000001, 'no storefront');
        $this->assertEqualsWithDelta(5.0, $this->inStorefront('SA', fn () => OrderManager::redeemReferralDiscount($flat, 200, 100)), 0.000001);
        $this->assertSame(0, $this->inStorefront('KW', fn () => OrderManager::redeemReferralDiscount($flat, 200, 100)));

        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertEqualsWithDelta(5.0, $this->inStorefront('KW', fn () => OrderManager::redeemReferralDiscount($flat, 200, 100)), 0.000001, 'the switch is off');
    }

    public function test_the_percentage_referral_discount_is_the_same_on_every_storefront(): void
    {
        $percentage = $this->referral(amountType: 'percentage');

        foreach (['SA', 'KW', 'AE'] as $country) {
            $this->assertEqualsWithDelta(10.0, $this->inStorefront($country, fn () => OrderManager::redeemReferralDiscount($percentage, 200, 100)), 0.000001, $country);
        }
    }
}
