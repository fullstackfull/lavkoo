<?php

namespace Tests\Feature\Storefront;

use App\Models\ProductDestinationPermission as PermissionRow;
use App\Services\Shipping\EligibilityDecision;
use App\Services\Shipping\ProductDestinationPermission;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCartWorld();
        (require database_path('migrations/2026_12_20_100000_create_product_destination_permissions_table.php'))->up();
        ProductDestinationPermission::forget();
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        foreach (['product_destination_permissions', 'carts', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
        ProductDestinationPermission::forget();

        parent::tearDown();
    }

    private function buildCartWorld(): void
    {
        Schema::dropIfExists('carts');
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
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

    private function aLineOf(string $market, ?string $provider = null, int $productId = 1): void
    {
        DB::table('products')->insert(['id' => $productId, 'name' => 'Product ' . $productId, StorefrontCatalog::COLUMN => $market, 'fulfillment_provider' => $provider]);
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
}
