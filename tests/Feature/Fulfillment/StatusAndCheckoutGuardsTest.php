<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\CheckoutCodGuard;
use App\Services\Fulfillment\FulfillmentOrderGuard;
use App\Services\Fulfillment\FulfillmentRouter;
use Illuminate\Support\Facades\DB;

/**
 * The questions every place that writes an order's status, or offers cash on delivery, must ask before it does.
 */
class StatusAndCheckoutGuardsTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableProvider(ProviderCode::M5azn);
        $this->enableProvider(ProviderCode::TorodPoint);
        foreach ([1 => ProviderCode::TorodPoint, 2 => ProviderCode::M5azn, 3 => null] as $id => $provider) {
            $this->makeProduct($id, $provider);
        }
    }

    private function guard(): FulfillmentOrderGuard
    {
        return app(FulfillmentOrderGuard::class);
    }

    private function orderWithTorodLine(int $orderId = 100, array $orderOverrides = []): void
    {
        $this->makeOrder($orderId, $orderOverrides);
        $this->addLine($orderId, 1, 1, 40, ProviderCode::TorodPoint);
    }

    // ---- order status writers ------------------------------------------------------------------------------------

    public function test_an_order_cannot_move_to_delivered_while_a_provider_shipment_is_outstanding(): void
    {
        $this->orderWithTorodLine();
        app(FulfillmentRouter::class)->route(100);

        $this->assertNotNull($this->guard()->statusChangeBlockedReason(orderId: 100, newStatus: 'delivered'));
    }

    public function test_an_order_without_provider_lines_is_never_blocked(): void
    {
        $this->makeOrder(100);
        $this->addLine(100, 3, 1, 40);

        foreach (['delivered', 'pending', 'confirmed', 'processing', 'out_for_delivery', 'canceled', 'returned'] as $status) {
            $this->assertNull($this->guard()->statusChangeBlockedReason(orderId: 100, newStatus: $status), $status);
        }
    }

    public function test_a_closed_order_with_provider_lines_cannot_be_reopened(): void
    {
        $this->orderWithTorodLine(orderOverrides: ['order_status' => 'canceled']);

        foreach (['pending', 'confirmed', 'processing', 'out_for_delivery'] as $status) {
            $this->assertNotNull($this->guard()->statusChangeBlockedReason(orderId: 100, newStatus: $status), "reopening to {$status}");
        }
        $this->assertNull($this->guard()->statusChangeBlockedReason(orderId: 100, newStatus: 'returned'), 'moving between closed states is not a reopening');
    }

    public function test_an_open_order_with_provider_lines_moves_through_its_normal_statuses(): void
    {
        $this->orderWithTorodLine(orderOverrides: ['order_status' => 'pending']);

        foreach (['confirmed', 'processing', 'canceled'] as $status) {
            $this->assertNull($this->guard()->statusChangeBlockedReason(orderId: 100, newStatus: $status), $status);
        }
    }

    public function test_a_closed_order_without_provider_lines_may_be_reopened_as_before(): void
    {
        $this->makeOrder(100, ['order_status' => 'canceled']);
        $this->addLine(100, 3, 1, 40);

        $this->assertNull($this->guard()->statusChangeBlockedReason(orderId: 100, newStatus: 'pending'));
    }

    /**
     * Every place that writes an order's status asks the guard first. A new writer that forgets to is the failure this
     * list exists to catch: add the file here once it asks.
     */
    public function test_every_known_order_status_writer_asks_the_guard_first(): void
    {
        $writers = [
            'app/Http/Controllers/Admin/Order/OrderController.php',
            'app/Http/Controllers/Vendor/Order/OrderController.php',
            'app/Http/Controllers/RestAPI/v2/seller/OrderController.php',
            'app/Http/Controllers/RestAPI/v3/seller/OrderController.php',
            'app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php',
        ];

        foreach ($writers as $writer) {
            $this->assertStringContainsString('statusChangeBlockedReason', (string) file_get_contents(base_path($writer)), $writer);
        }
        $this->assertSame(
            2,
            substr_count((string) file_get_contents(base_path('app/Http/Controllers/RestAPI/v3/seller/OrderController.php')), 'statusChangeBlockedReason'),
            'the seller API has two status endpoints (single and combined update)',
        );
    }

    // ---- cash on delivery at checkout ------------------------------------------------------------------------------

    /** @param array<int, list<int>> $groups cart group => product ids */
    private function cart(array $groups, bool $checked = true): array
    {
        foreach ($groups as $group => $productIds) {
            foreach ($productIds as $productId) {
                DB::table('carts')->insert(['cart_group_id' => 'g' . $group, 'product_id' => $productId, 'is_checked' => $checked, 'customer_id' => 1]);
            }
        }

        return array_map(static fn (int $group): string => 'g' . $group, array_keys($groups));
    }

    private function codGuard(): CheckoutCodGuard
    {
        return app(CheckoutCodGuard::class);
    }

    public function test_cash_on_delivery_is_refused_for_a_basket_shipped_by_two_providers(): void
    {
        $this->assertNotNull($this->codGuard()->blockedReason($this->cart([1 => [1, 2]])));
    }

    public function test_cash_on_delivery_is_refused_for_a_basket_mixing_a_provider_with_the_shops_own_delivery(): void
    {
        $this->assertNotNull($this->codGuard()->blockedReason($this->cart([1 => [1, 3]])));
    }

    public function test_cash_on_delivery_stays_available_for_baskets_with_one_collector(): void
    {
        $this->assertNull($this->codGuard()->blockedReason($this->cart([1 => [1], 2 => [2], 3 => [3]])), 'one provider, or the shop alone, per order');
        $this->assertNull($this->codGuard()->blockedReason($this->cart([4 => [1, 1]])), 'two lines of the same provider');
    }

    public function test_each_cart_group_is_judged_on_its_own_because_each_becomes_its_own_order(): void
    {
        $this->assertNull($this->codGuard()->blockedReason($this->cart([1 => [1], 2 => [2]])), 'two orders, one provider each');
        $this->assertNotNull($this->codGuard()->blockedReason($this->cart([3 => [1, 2]])));
    }

    public function test_items_not_ticked_for_checkout_do_not_count(): void
    {
        $groups = $this->cart([1 => [1]]);
        DB::table('carts')->insert(['cart_group_id' => 'g1', 'product_id' => 2, 'is_checked' => false, 'customer_id' => 1]);

        $this->assertNull($this->codGuard()->blockedReason($groups));
    }

    public function test_mixed_cash_on_delivery_is_allowed_when_enabled_and_every_provider_is_verified(): void
    {
        config(['fulfillment.cod.allow_mixed' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['cod_verified' => true]);
        $this->enableProvider(ProviderCode::M5azn, ['cod_verified' => true]);

        $this->assertNull($this->codGuard()->blockedReason($this->cart([1 => [1, 2]])));
        $this->assertNotNull($this->codGuard()->blockedReason($this->cart([2 => [1, 3]])), 'but never together with the shop\'s own delivery');
    }

    public function test_an_empty_basket_or_an_unmigrated_store_is_never_blocked(): void
    {
        $this->assertNull($this->codGuard()->blockedReason([]));
        $this->assertNull($this->codGuard()->blockedReason(['no-such-group']));
    }
}
