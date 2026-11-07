<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelOrderDispatch;
use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Services\SalesChannels\Dispatch\BuyerDataPurger;
use App\Services\SalesChannels\Dispatch\DispatchOutcome;
use App\Utils\OrderManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** What the internal order does, and does not do, in the rest of the platform. */
class DispatchSideEffectsTest extends DispatchTestCase
{
    private function dispatchedOrderId(): int
    {
        return (int) $this->dispatcher()->dispatch($this->placed())->internalOrderId;
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', 10)->value('current_stock');
    }

    public function test_the_units_of_an_order_are_counted_once_through_its_whole_life(): void
    {
        $mapping = $this->placed();
        $this->assertSame([0, 2, 48], $this->figures(), 'placed: the channel hold counts them');

        $this->dispatcher()->dispatch($mapping);
        $this->assertSame([2, 0, 48], $this->figures(), 'made: the internal order counts them, and nothing else does');

        $this->travel(73)->hours();
        app(BuyerDataPurger::class)->run();
        $this->assertSame([0, 2, 48], $this->figures(), 'reset: the order is open again and the channel counts them again, not nobody');
    }

    public function test_moving_a_dispatched_order_forward_or_cancelling_it_by_hand_never_takes_or_gives_back_stock(): void
    {
        $orderId = $this->dispatchedOrderId();

        OrderManager::getStockUpdateOnOrderStatusChange(Order::with('details')->findOrFail($orderId), 'processing');
        $this->assertSame([50, 0, 'processing'], [$this->stock(), (int) DB::table('order_details')->where('order_id', $orderId)->value('is_stock_decreased'), DB::table('order_details')->where('order_id', $orderId)->value('delivery_status')]);

        app(OrderRepository::class)->updateStockOnOrderStatusChange($orderId, 'delivered');
        $this->assertSame([50, 'delivered'], [$this->stock(), DB::table('order_details')->where('order_id', $orderId)->value('delivery_status')], 'the repository path is guarded the same way');

        OrderManager::getStockUpdateOnOrderStatusChange(Order::with('details')->findOrFail($orderId), 'canceled');
        $this->assertSame(50, $this->stock(), 'and nothing is given back that was never taken');
    }

    public function test_the_guard_touches_only_the_orders_made_for_channel_orders(): void
    {
        $this->makeOrder(9001, ['payment_method' => 'cash_on_delivery']);
        $this->addLine(9001, 10, qty: 3);
        DB::table('order_details')->where('order_id', 9001)->update(['is_stock_decreased' => 0]);

        OrderManager::getStockUpdateOnOrderStatusChange(Order::with('details')->findOrFail(9001), 'processing');

        $this->assertSame(47, $this->stock(), 'the platform\'s own rule is unchanged for the store\'s own orders');
    }

    public function test_the_orders_marker_works_when_the_payment_method_setting_is_changed_later_because_the_link_is_the_proof(): void
    {
        $orderId = $this->dispatchedOrderId();
        config(['sales_channels.dispatch.payment_method' => 'something_else']);

        OrderManager::getStockUpdateOnOrderStatusChange(Order::with('details')->findOrFail($orderId), 'processing');

        $this->assertSame(50, $this->stock());
    }

    public function test_a_database_error_while_making_the_order_never_carries_the_buyer_into_an_exception_and_is_tried_again_later(): void
    {
        $buyer = [self::NAME, self::PHONE_NATIONAL, self::STREET];
        $this->app->bind(OrderItemOwnershipSnapshotter::class, fn () => new class(app(ProviderCurrency::class), $buyer) extends OrderItemOwnershipSnapshotter {
            public function __construct(ProviderCurrency $currency, private readonly array $buyer)
            {
                parent::__construct($currency);
            }

            public function capture(int $orderId, int $orderDetailId, array $product, ?string $variant, int $quantity, float $unitPrice, float $netAmount, float $lineTax, string $taxModel): ?\App\Models\ProviderFulfillmentItem
            {
                throw new QueryException('mysql', 'insert into orders (receiver, phone, street) values (?, ?, ?)', $this->buyer, new \Exception('lock wait timeout'));
            }
        });
        $mapping = $this->placed();

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame([DispatchOutcome::BLOCKED, 'database_error'], [$outcome->state, $outcome->reason]);
        $this->assertNotNull(ChannelOrderDispatch::firstOrFail()->next_attempt_at, 'a database error is worth another try');
        $this->assertSame(0, DB::table('orders')->count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function paymentsThatAreNotPrepaid(): array
    {
        return [
            'flagged cash on delivery' => [['is_cod' => true, 'payment_method' => null, 'destination' => ['city' => 'Riyadh', 'country' => 'SA']], 'cash_on_delivery_not_supported'],
            'a method that says cash' => [['is_cod' => false, 'payment_method' => 'CashOnDelivery', 'destination' => ['city' => 'Riyadh', 'country' => 'SA']], 'cash_on_delivery_not_supported'],
            'nothing said about payment' => [['destination' => ['city' => 'Riyadh', 'country' => 'SA']], 'payment_method_unknown'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paymentsThatAreNotPrepaid')]
    public function test_an_order_is_made_as_already_paid_only_when_the_channel_said_it_was(array $snapshot, string $blocker): void
    {
        $mapping = $this->placed();
        $mapping->forceFill(['snapshot' => $snapshot])->save();

        $outcome = $this->dispatcher()->dispatch($mapping->refresh());

        $this->assertContains($blocker, $outcome->blockers);
        $this->assertSame([0, 0], [DB::table('orders')->count(), $this->recipientReads()]);
    }
}
