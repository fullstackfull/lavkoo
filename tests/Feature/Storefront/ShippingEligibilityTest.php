<?php

namespace Tests\Feature\Storefront;

use App\Models\ProductDestinationPermission as PermissionRow;
use App\Services\Shipping\EligibilityDecision;
use App\Services\Shipping\CustomsDuty;
use App\Services\Shipping\ProductDestinationPermission;
use App\Services\Shipping\RegulatedShippingGate;
use App\Services\Shipping\ShippingRefusal;
use App\Services\Shipping\ShippingEligibility;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * H1-06: one service answers whether a basket may be shipped to a destination, and whether that crosses a border.
 *
 * Each surface used to ask its own question -- the website and the API each checked the platform's global country list, the dispatch
 * preflight checked an address and a phone, and nothing anywhere compared the destination with what a product is permitted to do or what a
 * carrier is contracted to carry. Three surfaces with three answers is how a customer is refused at one and accepted at another.
 *
 * The first test is the dormancy, and it is the one that matters most: with the switch off -- which is how the platform ships -- the engine
 * answers local and allowed for everything, so every checkout behaves exactly as today. A platform serving one country has no cross-border
 * question to answer.
 *
 * The engine only ever REFUSES. Both facts it composes fail closed (no permission row means no, an undeclared route is not served), so
 * turning the switch on can never open a shipment that was closed.
 */
class ShippingEligibilityTest extends TestCase
{
    private const GROUP = 'cart-group-1';

    private const HOME = 'SA';

    private const ABROAD = 'KW';

    /** Every table the city coverage is read from; the service answers "cannot judge" until they all exist. */
    private const CITY_TABLES = [
        'provider_city_mappings', 'provider_city_sync_runs', 'provider_cities', 'address_city_aliases', 'address_cities', 'address_regions',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCartWorld();
        (require database_path('migrations/2026_12_20_100000_create_product_destination_permissions_table.php'))->up();
        (require database_path('migrations/2026_12_20_100300_add_clearance_reference_to_product_destination_permissions.php'))->up();
        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        foreach ([...self::CITY_TABLES, 'product_destination_permissions', 'carts', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();

        parent::tearDown();
    }

    private function buildCartWorld(): void
    {
        Schema::dropIfExists('carts');
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('brand_id')->nullable();
            $table->text('category_ids')->nullable();
            $table->string(StorefrontCatalog::COLUMN, 2)->nullable();
            $table->string('fulfillment_provider')->nullable();
            $table->timestamps();
        });
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->string('cart_group_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->boolean('is_checked')->default(1);
            $table->timestamps();
        });
    }

    private function aLineOf(string $market, ?string $provider = null, int $productId = 1, ?string $categoryIds = null): void
    {
        DB::table('products')->insert([
            'id' => $productId, 'name' => 'Product ' . $productId, 'category_ids' => $categoryIds,
            StorefrontCatalog::COLUMN => $market, 'fulfillment_provider' => $provider,
        ]);
        DB::table('carts')->insert(['cart_group_id' => self::GROUP, 'product_id' => $productId, 'is_checked' => 1]);
    }

    private function crossBorderIsOpen(): void
    {
        config(['gcc.shipping.cross_border.enabled' => true]);
    }

    private function permit(string $destination, int $productId = 1): void
    {
        PermissionRow::create([
            'product_id' => $productId, 'destination_country' => $destination,
            'may_ship' => true, 'may_export' => true, 'may_import' => true,
        ]);
    }

    private function decide(?string $destination): EligibilityDecision
    {
        return app(ShippingEligibility::class)->decide([self::GROUP], $destination);
    }

    public function test_with_the_switch_off_everything_is_local_and_allowed(): void
    {
        $this->aLineOf(market: self::HOME);

        foreach ([self::HOME, self::ABROAD, null] as $destination) {
            $decision = $this->decide($destination);

            $this->assertTrue($decision->allowed, 'checkout behaves exactly as today');
            $this->assertSame(EligibilityDecision::LOCAL, $decision->kind);
            $this->assertSame([], $decision->reasons);
        }
    }

    public function test_a_basket_delivered_in_its_own_country_is_local_and_allowed(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME);

        $decision = $this->decide(self::HOME);

        $this->assertTrue($decision->allowed);
        $this->assertFalse($decision->isCrossBorder());
    }

    public function test_a_basket_going_abroad_with_no_permission_is_refused_and_says_why(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME);

        $decision = $this->decide(self::ABROAD);

        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->isCrossBorder());
        $this->assertContains('shipping_product_not_permitted_abroad', $decision->reasons);
    }

    public function test_a_permitted_product_with_no_carrier_named_crosses_the_border(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME);
        $this->permit(self::ABROAD);

        $decision = $this->decide(self::ABROAD);

        $this->assertTrue($decision->allowed, 'nothing refuses it: the permission is granted and no carrier is named on the line');
        $this->assertTrue($decision->isCrossBorder(), 'and it is still a border crossing, which is what the order has to record');
    }

    /** H1-02 at checkout: a line whose carrier does not serve the route is refused before payment, not held after it. */
    public function test_a_line_whose_carrier_does_not_serve_the_route_is_refused(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME, provider: 'torod_point');
        $this->permit(self::ABROAD);

        $decision = $this->decide(self::ABROAD);

        $this->assertFalse($decision->allowed);
        $this->assertSame(['shipping_no_carrier_serves_this_route'], $decision->reasons);
    }

    public function test_the_same_line_is_allowed_once_its_carrier_declares_the_route(): void
    {
        $this->crossBorderIsOpen();
        config(['fulfillment.providers.torod_point.routes' => ['SA:SA', 'SA:KW']]);
        $this->aLineOf(market: self::HOME, provider: 'torod_point');
        $this->permit(self::ABROAD);

        $this->assertTrue($this->decide(self::ABROAD)->allowed);
    }

    /** Every reason, not the first: a customer told one of two problems fixes it and is refused again. */
    public function test_a_basket_collects_every_reason_it_is_refused_for(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME, provider: 'torod_point');

        $decision = $this->decide(self::ABROAD);

        $this->assertSame(['shipping_product_not_permitted_abroad', 'shipping_no_carrier_serves_this_route'], $decision->reasons);
    }

    /** One line of several being refused refuses the basket: an order is shipped whole or not at all. */
    public function test_one_refused_line_refuses_the_basket(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME, productId: 1);
        $this->aLineOf(market: self::HOME, productId: 2);
        $this->permit(self::ABROAD, productId: 1);

        $decision = $this->decide(self::ABROAD);

        $this->assertFalse($decision->allowed, 'the second line has no permission');
        $this->assertContains('shipping_product_not_permitted_abroad', $decision->reasons);
    }

    public function test_a_destination_nobody_knows_is_refused(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME);

        $this->assertSame(['shipping_destination_unknown'], $this->decide(null)->reasons);
        $this->assertSame(['shipping_destination_unknown'], $this->decide('  ')->reasons);
    }

    /** An empty basket has nothing to refuse, and a checkout with no lines is a problem for another guard. */
    public function test_an_empty_basket_is_local_and_allowed(): void
    {
        $this->crossBorderIsOpen();

        $this->assertTrue($this->decide(self::ABROAD)->allowed);
    }

    /** The decision as an order stores it, so that what was decided stays decided when the settings change later. */
    public function test_the_decision_can_be_stored_as_it_was_made(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME);

        $this->assertSame(
            ['kind' => 'cross_border', 'allowed' => false, 'reasons' => ['shipping_product_not_permitted_abroad']],
            $this->decide(self::ABROAD)->toArray(),
        );
    }

    /**
     * H1-04 through the engine, which is the form the requirement is written in: a carrier that covers the route never overrides a
     * regulated product.
     *
     * Everything else about this shipment is in order. The product has all three destination permissions, so H1-03 is satisfied; the
     * carrier declares the route, so H1-02 is satisfied. It is refused anyway, and refused by the engine every surface asks -- the
     * website, the three API paths, the recorded decision and the dispatch re-ask -- rather than by a check one of them remembered to
     * make.
     */
    public function test_a_regulated_product_is_refused_even_when_the_carrier_covers_the_route(): void
    {
        $this->crossBorderIsOpen();
        config([
            'gcc.shipping.regulated.mode' => RegulatedShippingGate::MODE_STRICT,
            'gcc.shipping.regulated.category_ids' => ['7'],
            'fulfillment.providers.courier_abroad.routes' => [self::HOME . ':' . self::ABROAD],
        ]);
        $this->aLineOf(market: self::HOME, provider: 'courier_abroad', categoryIds: json_encode([['id' => '7', 'position' => 1]]));
        $this->permit(self::ABROAD);

        $decision = $this->decide(self::ABROAD);

        $this->assertFalse($decision->allowed);
        $this->assertSame([RegulatedShippingGate::NEEDS_CLEARANCE], $decision->reasons, 'the carrier and the permissions are both in order');
        $this->assertTrue($decision->isCrossBorder());
    }

    /** And with the regulated mode off, the same shipment goes: the gate is a refusal and never a permission. */
    public function test_with_the_regulated_mode_off_the_same_shipment_is_allowed(): void
    {
        $this->crossBorderIsOpen();
        config(['fulfillment.providers.courier_abroad.routes' => [self::HOME . ':' . self::ABROAD]]);
        $this->aLineOf(market: self::HOME, provider: 'courier_abroad', categoryIds: json_encode([['id' => '7', 'position' => 1]]));
        $this->permit(self::ABROAD);

        $this->assertTrue($this->decide(self::ABROAD)->allowed);
    }

    /**
     * H1-09's own sentence, asked by the same engine before payment: a route whose duty cannot be determined refuses the order and never
     * charges zero.
     *
     * Everything else about this shipment is in order -- the permission granted, the carrier declaring the route -- and it is refused
     * because nobody has written what the duty on this border is. The alternative is a customer charged nothing at checkout and handed a
     * bill at the door, which is the platform promising something it has no authority to promise.
     */
    public function test_a_route_whose_duty_cannot_be_determined_is_refused_rather_than_charged_nothing(): void
    {
        $this->crossBorderIsOpen();
        config([
            'gcc.shipping.duty.mode' => CustomsDuty::MODE_STRICT,
            'gcc.shipping.duty.routes' => [],
            'fulfillment.providers.courier_abroad.routes' => [self::HOME . ':' . self::ABROAD],
        ]);
        $this->aLineOf(market: self::HOME, provider: 'courier_abroad');
        $this->permit(self::ABROAD);

        $decision = $this->decide(self::ABROAD);

        $this->assertFalse($decision->allowed);
        $this->assertSame([ShippingRefusal::DUTY_NOT_QUOTED], $decision->reasons);
    }

    /** A route somebody HAS written a rule for passes, which is what makes the refusal above a rule rather than a wall. */
    public function test_a_route_with_a_duty_rule_is_allowed(): void
    {
        $this->crossBorderIsOpen();
        config([
            'gcc.shipping.duty.mode' => CustomsDuty::MODE_STRICT,
            'gcc.shipping.duty.routes' => [self::HOME . ':' . self::ABROAD => ['percentage' => 5, 'payer' => 'buyer_on_delivery']],
            'fulfillment.providers.courier_abroad.routes' => [self::HOME . ':' . self::ABROAD],
        ]);
        $this->aLineOf(market: self::HOME, provider: 'courier_abroad');
        $this->permit(self::ABROAD);

        $this->assertTrue($this->decide(self::ABROAD)->allowed);
    }

    /** `declare` records what it can and refuses nothing: only `strict` stops an order, and `off` is how the platform ships. */
    public function test_neither_declare_nor_off_refuses_an_unquotable_route(): void
    {
        $this->crossBorderIsOpen();
        config(['fulfillment.providers.courier_abroad.routes' => [self::HOME . ':' . self::ABROAD]]);
        $this->aLineOf(market: self::HOME, provider: 'courier_abroad');
        $this->permit(self::ABROAD);

        foreach ([CustomsDuty::MODE_OFF, CustomsDuty::MODE_DECLARE] as $mode) {
            config(['gcc.shipping.duty.mode' => $mode, 'gcc.shipping.duty.routes' => []]);

            $this->assertTrue($this->decide(self::ABROAD)->allowed, "{$mode} refuses nothing");
        }
    }

    /**
     * H1-05's last criterion: a city no carrier delivers to is refused at CHECKOUT, not held after payment.
     *
     * The dispatcher has always known this and held the shipment with `hold_city_unmapped` -- after the customer paid. The fact was
     * available at checkout all along, and refusing there is the difference between a customer choosing another address and a customer
     * waiting for a parcel that was never going to move.
     */
    public function test_a_city_the_carrier_does_not_serve_is_refused_at_checkout(): void
    {
        $this->crossBorderIsOpen();
        config(['fulfillment.providers.courier_abroad.routes' => [self::HOME . ':' . self::ABROAD]]);
        $this->aLineOf(market: self::HOME, provider: 'courier_abroad');
        $this->permit(self::ABROAD);
        $this->buildCityMappings(approvedCityId: 77);

        $refused = $this->decideForCity(cityId: 91);
        $served = $this->decideForCity(cityId: 77);

        $this->assertSame([ShippingRefusal::NO_CARRIER_SERVES_THIS_CITY], $refused->reasons);
        $this->assertTrue($served->allowed, 'and a city the carrier does serve goes through');
    }

    /** Where the coverage cannot be judged, nothing is refused: that is every shop today and every free-text address. */
    public function test_an_unjudgeable_city_is_never_a_refusal(): void
    {
        $this->crossBorderIsOpen();
        config(['fulfillment.providers.courier_abroad.routes' => [self::HOME . ':' . self::ABROAD]]);
        $this->aLineOf(market: self::HOME, provider: 'courier_abroad');
        $this->permit(self::ABROAD);
        $this->buildCityMappings(approvedCityId: 77);

        $this->assertTrue($this->decideForCity(cityId: null)->allowed, 'a free-text address names no city');

        foreach (self::CITY_TABLES as $table) {
            Schema::dropIfExists($table);
        }
        \App\Services\Address\AddressSchema::flush();

        $this->assertTrue($this->decideForCity(cityId: 91)->allowed, 'and a shop that has not migrated cannot be asked');
    }

    private function decideForCity(?int $cityId)
    {
        return app(ShippingEligibility::class)->decide([self::GROUP], self::ABROAD, $cityId);
    }

    /**
     * The tables the city coverage is read from, with one city approved for the carrier under test.
     *
     * All of them, because ProviderCityMappingService answers "cannot judge" until the whole address and mapping set has been migrated
     * (AddressSchema::available): the masters, the mapping tables and the columns a provider's city list is promoted into.
     */
    private function buildCityMappings(int $approvedCityId): void
    {
        foreach (self::CITY_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('address_city_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('address_city_id');
            $table->string('alias')->nullable();
            $table->timestamps();
        });
        Schema::create('provider_city_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->nullable();
            $table->timestamps();
        });
        Schema::create('provider_cities', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('external_city_id')->nullable();
            $table->string('region_name_ar')->nullable();
            $table->string('region_name_en')->nullable();
            $table->string('prefix')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
        });

        Schema::create('address_regions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamps();
        });
        Schema::create('address_cities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->string('name')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamps();
        });
        Schema::create('provider_city_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->unsignedBigInteger('address_city_id');
            $table->string('external_city_id')->nullable();
            $table->string('status')->default('approved');
            $table->string('country_code', 2)->nullable();
            $table->timestamps();
        });

        DB::table('address_cities')->insert([
            ['id' => $approvedCityId, 'name' => 'Served city', 'country_code' => self::ABROAD],
            ['id' => 91, 'name' => 'Unserved city', 'country_code' => self::ABROAD],
        ]);
        DB::table('provider_city_mappings')->insert([
            'provider' => 'courier_abroad', 'address_city_id' => $approvedCityId, 'external_city_id' => 'X1', 'status' => 'approved',
        ]);
        \App\Services\Address\AddressSchema::flush();
    }

    /**
     * The address book holds the English NAME of a country, and a product's market holds a CODE. They have to be read as the same country.
     *
     * This is the defect the scenario matrix of H1-13 found. The engine compared the two directly, so an order placed in Riyadh for
     * delivery in Riyadh -- market `SA`, address "Saudi Arabia" -- read as crossing a border and was refused for want of an export
     * permission it should never have been asked for. Nothing broke in production, because the engine ships off; it would have refused
     * every order on the day it was switched on, which is worse than a bug that shows.
     */
    public function test_an_address_holding_the_countrys_name_is_the_same_country_as_the_products_code(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME);

        foreach (['Saudi Arabia', 'saudi arabia', 'SA', 'sa'] as $spelling) {
            $decision = $this->decide($spelling);

            $this->assertTrue($decision->allowed, "an order delivered in {$spelling} from SA crosses no border");
            $this->assertSame(EligibilityDecision::LOCAL, $decision->kind);
            $this->assertSame([], $decision->reasons);
        }
    }

    /** And a name that really is another country is still another country. */
    public function test_a_foreign_country_named_in_full_is_still_foreign(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME);

        foreach (['Kuwait', 'KW'] as $spelling) {
            $decision = $this->decide($spelling);

            $this->assertFalse($decision->allowed, "an order delivered in {$spelling} from SA does cross a border");
            $this->assertSame(EligibilityDecision::CROSS_BORDER, $decision->kind);
        }
    }

    /** A value that names no country the platform knows is "nobody said where this is going", which is refused rather than guessed. */
    public function test_a_destination_that_names_no_country_is_not_a_destination(): void
    {
        $this->crossBorderIsOpen();
        $this->aLineOf(market: self::HOME);

        $this->assertSame([ShippingRefusal::DESTINATION_UNKNOWN], $this->decide('Nowhereland')->reasons);
    }

    /** A domestic order is never asked about duty, however strict the mode: nothing crosses a border, so nothing is due. */
    public function test_a_domestic_order_is_not_refused_by_the_duty_rule(): void
    {
        $this->crossBorderIsOpen();
        config(['gcc.shipping.duty.mode' => CustomsDuty::MODE_STRICT, 'gcc.shipping.duty.routes' => []]);
        $this->aLineOf(market: self::HOME);

        $this->assertTrue($this->decide(self::HOME)->allowed);
    }
}
