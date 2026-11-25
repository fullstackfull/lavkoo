<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Customer\SystemController;
use App\Http\Controllers\RestAPI\v1\ShippingMethodController;
use App\Models\ShippingZone;
use App\Services\Marketplace\ShippingRateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * H1-08: what the shipping charge for a destination actually is today, pinned before anything is changed.
 *
 * Two of this requirement's criteria were recorded as "in code, unproven", and unproven was exact: nothing in any tree mentioned
 * ShippingZone. The zone rule is reachable in production -- `shipping_zones` is one of the tables the platform restores from its dump
 * rather than creating by migration -- so a test has to build the table itself, which is what this does.
 *
 * The reading that matters most is the LAST group. The website writes the chosen method's cost through the zone rule
 * (`SystemController::zoneShippingCost`) and the REST API wrote the method's own cost with no zone anywhere near it, so with
 * `zone_wise_shipping` on the same basket going to the same address was charged one figure in a browser and another in the app. That is
 * the requirement's third criterion, and the test for it is written here as the comparison it is.
 */
class ShippingZoneRateTest extends TestCase
{
    private const GROUP = 'cart-group-1';

    private const METHOD = 7;

    private const METHOD_COST = 25.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShippingWorld();
    }

    protected function tearDown(): void
    {
        foreach (['shipping_zones', 'cart_shippings', 'shipping_methods', 'carts', 'products', 'shipping_types', 'shipping_addresses', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Cache::flush();

        parent::tearDown();
    }

    private function buildShippingWorld(): void
    {
        foreach (['shipping_zones', 'cart_shippings', 'shipping_methods', 'carts', 'products', 'shipping_types', 'shipping_addresses', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_zones', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('countries')->nullable();
            $table->text('regions')->nullable();
            $table->decimal('base_cost', 24, 2)->default(0);
            $table->decimal('per_kg_cost', 24, 2)->default(0);
            $table->decimal('free_over', 24, 2)->nullable();
            $table->integer('priority')->default(0);
            $table->string('status')->default(ShippingZone::STATUS_ACTIVE);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->decimal('cost', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('cart_shippings', function (Blueprint $table): void {
            $table->id();
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->decimal('shipping_cost', 24, 2)->default(0);
            $table->string('rate_source', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('product_id')->default(1);
            $table->decimal('price', 24, 2)->default(0);
            $table->decimal('discount', 24, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->boolean('is_checked')->default(1);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('shipping_type')->default('order_wise');
            $table->timestamps();
        });
        Schema::create('shipping_addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->timestamps();
        });

        DB::table('shipping_methods')->insert(['id' => self::METHOD, 'title' => 'Standard', 'cost' => self::METHOD_COST]);
    }

    private function aZone(array $attributes): ShippingZone
    {
        return ShippingZone::create($attributes + [
            'name' => 'Zone',
            'countries' => [],
            'regions' => [],
            'base_cost' => 10,
            'per_kg_cost' => 0,
            'free_over' => null,
            'priority' => 0,
            'status' => ShippingZone::STATUS_ACTIVE,
        ]);
    }

    private function service(): ShippingRateService
    {
        return new ShippingRateService();
    }

    private function aBasketWorth(float $value, ?int $weightGrams = null, int $quantity = 1, int $productId = 1): void
    {
        // `updateProductShippingCost` reads each line's product, so the row has to be there.
        DB::table('products')->insertOrIgnore([
            'id' => $productId, 'name' => 'Product ' . $productId, 'weight_grams' => $weightGrams,
        ]);
        DB::table('carts')->insert([
            'cart_group_id' => self::GROUP, 'product_id' => $productId, 'price' => $value, 'discount' => 0,
            'quantity' => $quantity, 'is_checked' => 1,
        ]);
    }

    private function anAddressIn(string $country, ?string $region = null): int
    {
        return (int) DB::table('shipping_addresses')->insertGetId(['country' => $country, 'state' => $region, 'city' => 'Anywhere']);
    }

    private function zoneShippingIs(bool $on): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'zone_wise_shipping'], ['value' => $on ? '1' : '0']);
        Cache::flush();
    }

    // ---- the rate rule as it stands -------------------------------------------------------------------------------------------------

    /** The state a shop without the dump's table is in: no zone rule at all, so the caller's own cost stands. */
    public function test_without_the_zones_table_there_is_no_rate_and_the_fallback_stands(): void
    {
        Schema::dropIfExists('shipping_zones');

        $this->assertNull($this->service()->resolveZone('SA'));
        $this->assertNull($this->service()->rateFor('SA'));
        $this->assertSame(self::METHOD_COST, $this->service()->checkoutCost(self::METHOD_COST, 'SA'));
    }

    public function test_a_country_zone_is_preferred_to_the_catch_all(): void
    {
        $catchAll = $this->aZone(['name' => 'rest of world', 'base_cost' => 99]);
        $saudi = $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 15]);

        $this->assertSame($saudi->id, $this->service()->resolveZone('SA')?->id);
        $this->assertSame($catchAll->id, $this->service()->resolveZone('KW')?->id, 'and a country nobody named falls to the catch-all');
        $this->assertSame(15.0, $this->service()->checkoutCost(self::METHOD_COST, 'SA'));
    }

    /** A precise rule beats a general one, and among equals the lowest priority wins. */
    public function test_the_lowest_priority_wins_among_equally_specific_zones(): void
    {
        $this->aZone(['name' => 'second', 'countries' => ['SA'], 'base_cost' => 30, 'priority' => 5]);
        $first = $this->aZone(['name' => 'first', 'countries' => ['SA'], 'base_cost' => 20, 'priority' => 1]);

        $this->assertSame($first->id, $this->service()->resolveZone('SA')?->id);
    }

    public function test_a_region_scoped_zone_needs_the_region_to_match(): void
    {
        $this->aZone(['name' => 'riyadh', 'countries' => ['SA'], 'regions' => ['Riyadh'], 'base_cost' => 5]);

        $this->assertNotNull($this->service()->resolveZone('SA', 'riyadh'), 'the region is matched whatever its case');
        $this->assertNull($this->service()->resolveZone('SA', 'Makkah'));
        $this->assertNull($this->service()->resolveZone('SA'), 'and a destination with no region matches no region-scoped zone');
    }

    public function test_an_inactive_zone_is_not_a_zone(): void
    {
        $this->aZone(['name' => 'switched off', 'countries' => ['SA'], 'base_cost' => 5, 'status' => ShippingZone::STATUS_INACTIVE]);

        $this->assertNull($this->service()->resolveZone('SA'));
    }

    public function test_free_over_a_threshold_is_free_and_says_so(): void
    {
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 20, 'free_over' => 100]);

        $this->assertSame(['cost' => 0.0, 'free' => true], $this->rate('SA', orderValue: 100.0), 'the threshold itself is free');
        $this->assertSame(['cost' => 0.0, 'free' => true], $this->rate('SA', orderValue: 150.0));
        $this->assertSame(['cost' => 20.0, 'free' => false], $this->rate('SA', orderValue: 99.99));
    }

    /** The per-kilogram part is real but reaches nothing today: no product carries a weight, so every call asks for weight 0. */
    public function test_the_per_kilogram_cost_is_added_to_the_base(): void
    {
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 20, 'per_kg_cost' => 3]);

        $this->assertSame(['cost' => 20.0, 'free' => false], $this->rate('SA', weight: 0.0));
        $this->assertSame(['cost' => 26.0, 'free' => false], $this->rate('SA', weight: 2.0));
        $this->assertSame(['cost' => 20.0, 'free' => false], $this->rate('SA', weight: -5.0), 'a negative weight adds nothing');
    }

    /** @return array{cost: float, free: bool} */
    private function rate(string $country, float $weight = 0.0, float $orderValue = 0.0, ?string $region = null): array
    {
        $rate = $this->service()->rateFor($country, $weight, $orderValue, $region);

        return ['cost' => (float) $rate['cost'], 'free' => (bool) $rate['free']];
    }

    // ---- what the website writes ----------------------------------------------------------------------------------------------------

    /** With the setting off the chosen method's cost is written unchanged, whatever zones exist. That is every shop today. */
    public function test_with_the_setting_off_the_website_writes_the_methods_own_cost(): void
    {
        $this->zoneShippingIs(false);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(self::METHOD_COST, $this->writtenCost());
    }

    public function test_with_the_setting_on_the_website_writes_the_destinations_zone_rate(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(5.0, $this->writtenCost());
    }

    /** No address chosen yet, so there is no destination to rate: the method's cost stands. */
    public function test_with_no_address_the_website_writes_the_methods_own_cost(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);
        session()->forget('address_id');

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(self::METHOD_COST, $this->writtenCost());
    }

    /** The order's value decides a free-over threshold, and it is read off the basket's own rows. */
    public function test_the_website_reads_the_basket_value_for_the_free_over_threshold(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5, 'free_over' => 100]);
        $this->aBasketWorth(150.0);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(0.0, $this->writtenCost());
    }

    // ---- and what the app writes, which has to be the same --------------------------------------------------------------------------

    /**
     * H1-08's third criterion: the REST shipping step uses the same destination rate as the website.
     *
     * The same basket, the same address, the same method, the same setting -- and so the same charge. A customer quoted one figure in a
     * browser and another in the app has been quoted wrongly in one of them, and nothing in the platform says which.
     */
    public function test_the_app_writes_what_the_website_writes_for_the_same_basket_and_address(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);
        $addressId = $this->anAddressIn('SA');
        session(['address_id' => $addressId]);

        SystemController::insertIntoCartShipping($this->chooseRequest());
        $website = $this->writtenCost();

        DB::table('cart_shippings')->delete();
        ShippingMethodController::insert_into_cart_shipping($this->chooseRequest(['address_id' => $addressId]));

        $this->assertSame($website, $this->writtenCost(), 'the app and the website charge the same shipping');
        $this->assertSame(5.0, $this->writtenCost());
    }

    /** And with the setting off both surfaces write the method's own cost, which is the platform as it runs today. */
    public function test_with_the_setting_off_both_surfaces_write_the_methods_own_cost(): void
    {
        $this->zoneShippingIs(false);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);
        $addressId = $this->anAddressIn('SA');
        session(['address_id' => $addressId]);

        ShippingMethodController::insert_into_cart_shipping($this->chooseRequest(['address_id' => $addressId]));

        $this->assertSame(self::METHOD_COST, $this->writtenCost());
    }

    // ---- the weight the per-kilogram rule was never given --------------------------------------------------------------------------

    /**
     * The dormancy, and the honest kind: a product with no recorded weight weighs nothing, so a zone's per-kilogram rule adds nothing
     * and the cost is what it has always been. `products.weight_grams` is null for every product that exists.
     */
    public function test_a_basket_of_products_with_no_recorded_weight_pays_the_base_cost_only(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 20, 'per_kg_cost' => 3]);
        $this->aBasketWorth(200.0, weightGrams: null);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(20.0, $this->writtenCost());
    }

    /**
     * And the first shop to record a weight is a shop asking for its rule to apply. Before this, `per_kg_cost` was a setting the platform
     * collected and then ignored: every caller passed a weight of zero, so a shop that wrote a per-kilogram rate charged the base cost
     * for ever with no sign that its rule was not being applied.
     */
    public function test_a_recorded_weight_is_charged_at_the_zones_per_kilogram_rate(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 20, 'per_kg_cost' => 3]);
        $this->aBasketWorth(200.0, weightGrams: 2_000);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(26.0, $this->writtenCost(), 'two kilograms at three');
    }

    /** The quantity counts: two of a one-kilogram thing is two kilograms. */
    public function test_the_weight_is_counted_per_item_in_the_basket(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 0, 'per_kg_cost' => 10]);
        $this->aBasketWorth(50.0, weightGrams: 1_000, quantity: 3);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(30.0, $this->writtenCost());
    }

    /**
     * Grams are summed and divided once at the end, because a carrier's rate table is banded in grams and rounding each line into
     * kilograms first puts the parcel in the wrong band: three lines of 400g are 1.2kg, not 0kg and not 3kg.
     */
    public function test_grams_are_summed_before_they_become_kilograms(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 0, 'per_kg_cost' => 10]);
        $this->aBasketWorth(50.0, weightGrams: 400, quantity: 3);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(12.0, $this->writtenCost(), '1.2 kilograms at ten');
    }

    /** A basket of several products weighs what they weigh together, and a weightless line adds nothing. */
    public function test_a_basket_of_several_products_weighs_them_together(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 0, 'per_kg_cost' => 4]);
        $this->aBasketWorth(50.0, weightGrams: 1_500, productId: 1);
        $this->aBasketWorth(50.0, weightGrams: 500, productId: 2);
        $this->aBasketWorth(50.0, weightGrams: null, productId: 3);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(8.0, $this->writtenCost(), 'two kilograms at four');
    }

    /** And the app weighs the same basket the same way. */
    public function test_the_app_charges_the_same_weight_as_the_website(): void
    {
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 20, 'per_kg_cost' => 3]);
        $this->aBasketWorth(200.0, weightGrams: 2_000);
        $addressId = $this->anAddressIn('SA');

        ShippingMethodController::insert_into_cart_shipping($this->chooseRequest(['address_id' => $addressId]));

        $this->assertSame(26.0, $this->writtenCost());
    }

    // ---- and which rule decided it, so the charge can be explained later ------------------------------------------------------------

    /**
     * H1-08's last criterion, the half that was missing: the order kept the figure and nothing about where it came from.
     *
     * A zone's rate and a method's own cost are different decisions that land in the same column, so once the zones change nobody can
     * tell which one a past order was charged under. The order's currency it already kept (`orders.currency_code`); this is the rest.
     */
    public function test_the_zone_that_decided_the_charge_is_recorded_on_the_cart(): void
    {
        $this->zoneShippingIs(true);
        $zone = $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame('zone:' . $zone->id, $this->writtenSource());
    }

    /** And where no zone decided it, the method did, which is every charge on the platform today. */
    public function test_the_method_is_recorded_when_no_zone_decided_the_charge(): void
    {
        $this->zoneShippingIs(false);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame('method:' . self::METHOD, $this->writtenSource());
    }

    /** A free-over threshold is still the zone's decision: nought charged by a rule is not the same as nought charged by no rule. */
    public function test_a_free_shipment_still_names_the_zone_that_made_it_free(): void
    {
        $this->zoneShippingIs(true);
        $zone = $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 20, 'free_over' => 100]);
        $this->aBasketWorth(150.0);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(0.0, $this->writtenCost());
        $this->assertSame('zone:' . $zone->id, $this->writtenSource());
    }

    /** The app records it the same way. */
    public function test_the_app_records_the_rule_that_decided_the_charge(): void
    {
        $this->zoneShippingIs(true);
        $zone = $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);

        ShippingMethodController::insert_into_cart_shipping($this->chooseRequest(['address_id' => $this->anAddressIn('SA')]));

        $this->assertSame('zone:' . $zone->id, $this->writtenSource());
    }

    /** A shop whose column has not been created writes nothing and is unharmed. */
    public function test_a_shop_without_the_column_still_charges_and_records_nothing(): void
    {
        Schema::table('cart_shippings', fn (Blueprint $table) => $table->dropColumn('rate_source'));
        $this->zoneShippingIs(true);
        $this->aZone(['name' => 'saudi', 'countries' => ['SA'], 'base_cost' => 5]);
        $this->aBasketWorth(200.0);
        session(['address_id' => $this->anAddressIn('SA')]);

        SystemController::insertIntoCartShipping($this->chooseRequest());

        $this->assertSame(5.0, $this->writtenCost());
        $this->assertNull(\App\Utils\CartManager::getShippingRateSource(groupId: self::GROUP));
    }

    private function writtenSource(): ?string
    {
        return \App\Utils\CartManager::getShippingRateSource(groupId: self::GROUP);
    }

    private function chooseRequest(array $extra = []): Request
    {
        return new Request(['cart_group_id' => self::GROUP, 'id' => self::METHOD] + $extra);
    }

    private function writtenCost(): float
    {
        return (float) DB::table('cart_shippings')->where('cart_group_id', self::GROUP)->value('shipping_cost');
    }
}
