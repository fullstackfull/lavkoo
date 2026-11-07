<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\Order;
use App\Services\Address\OrderAddressService;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\OrderAddressUpdateService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The address of an order is not edited once a provider holds its shipment: the provider ships to the address it was given, so an edit of the
 * order's copy would leave the shipment and the order saying different things. Before that moment, and for orders without provider shipments, nothing changes.
 */
class OrderAddressEditGuardTest extends AdminPagesTestCase
{
    private const REASON = 'This order has already been handed to the shipping provider, so its delivery address can no longer be edited here. Cancel the shipment and place the order again, or ask the provider to change the address.';

    private function blockedReason(int $orderId): ?string
    {
        return app(OrderAddressService::class)->editBlockedReason($orderId);
    }

    /** @return array<string, array{FulfillmentStatus}> */
    public static function statusesTheProviderHoldsOrMayHold(): array
    {
        $cases = [
            FulfillmentStatus::Submitted, FulfillmentStatus::Processing, FulfillmentStatus::InTransit, FulfillmentStatus::OutForDelivery,
            FulfillmentStatus::DeliveryPostponed, FulfillmentStatus::DeliveryFailed, FulfillmentStatus::PartiallyDelivered, FulfillmentStatus::NeedsAttention,
            FulfillmentStatus::Dispatching, FulfillmentStatus::ReconcilePending,
        ];

        return array_combine(array_map(static fn (FulfillmentStatus $status): string => $status->value, $cases), array_map(static fn (FulfillmentStatus $status): array => [$status], $cases));
    }

    /** @return array<string, array{FulfillmentStatus}> */
    public static function statusesThatNeverLeftOrAreOver(): array
    {
        $cases = [
            FulfillmentStatus::Pending, FulfillmentStatus::Held, FulfillmentStatus::RetryWait, FulfillmentStatus::DryRun, FulfillmentStatus::Failed,
            FulfillmentStatus::Delivered, FulfillmentStatus::Cancelled, FulfillmentStatus::Returned,
        ];

        return array_combine(array_map(static fn (FulfillmentStatus $status): string => $status->value, $cases), array_map(static fn (FulfillmentStatus $status): array => [$status], $cases));
    }

    public function test_an_order_without_provider_shipments_is_never_blocked(): void
    {
        $this->makeProduct(1);
        $this->makeOrder(100);
        $this->addLine(100, 1);

        $this->assertNull($this->blockedReason(100));
        $this->assertNull($this->blockedReason(999), 'an order that does not exist is not this guard\'s business');
    }

    #[DataProvider('statusesTheProviderHoldsOrMayHold')]
    public function test_a_shipment_the_provider_holds_or_may_hold_blocks_the_edit(FulfillmentStatus $status): void
    {
        $this->shipment(100, ProviderCode::TorodPoint, $status);

        $this->assertSame(self::REASON, $this->blockedReason(100));
    }

    #[DataProvider('statusesThatNeverLeftOrAreOver')]
    public function test_a_shipment_that_never_left_or_is_over_does_not(FulfillmentStatus $status): void
    {
        $this->shipment(100, ProviderCode::TorodPoint, $status);

        $this->assertNull($this->blockedReason(100), $status->value);
    }

    public function test_one_shipment_in_the_hands_of_a_provider_is_enough_and_other_orders_are_not_affected(): void
    {
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Held, productId: 10);
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['reference' => '100-M51'], productId: 11);
        $this->shipment(101, ProviderCode::TorodPoint, FulfillmentStatus::Held, productId: 12);

        $this->assertSame(self::REASON, $this->blockedReason(100));
        $this->assertNull($this->blockedReason(101), 'a held shipment is looked at again by the next sweep, so its order can still be corrected');
    }

    public function test_an_edit_holds_the_shipments_of_its_order_until_it_is_written_so_a_claim_cannot_slip_in_between(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Row locks need a real MySQL or MariaDB: SQLite has none.');
        }
        $this->makeProduct(1);
        $this->makeOrder(100);
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Pending);

        $claimWaited = null;
        DB::beforeExecuting(function (string $query) use (&$claimWaited): void {
            if ($claimWaited !== null || preg_match('/^update\s+[`"]orders[`"]/i', $query) !== 1) {
                return;
            }

            $claimWaited = $this->aClaimFromAnotherConnectionHadToWait(orderId: 100);
        });

        app(OrderAddressUpdateService::class)->update(Order::query()->findOrFail(100), 'shipping', ['address' => 'Corrected street 7']);

        $this->assertTrue($claimWaited, 'the dispatcher claims a shipment under the same row lock, so it waits for the edit to be written');
        $this->assertSame('Corrected street 7', Order::query()->findOrFail(100)->shippingAddressSnapshot()['address']);
    }

    /** Tries what the dispatcher's claim does (changing the shipment's status) on a second connection that gives up after a second. */
    private function aClaimFromAnotherConnectionHadToWait(int $orderId): bool
    {
        config(['database.connections.second_connection' => config('database.connections.' . config('database.default'))]);
        DB::purge('second_connection');
        $other = DB::connection('second_connection');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $other->table('provider_fulfillments')->where('order_id', $orderId)->update(['status' => FulfillmentStatus::Dispatching->value]);

            return false;
        } catch (QueryException $exception) {
            return str_contains($exception->getMessage(), 'Lock wait timeout');
        } finally {
            DB::disconnect('second_connection');
        }
    }

    public function test_before_the_fulfillment_tables_exist_nothing_is_blocked(): void
    {
        Schema::dropIfExists('provider_fulfillment_items');
        Schema::dropIfExists('provider_fulfillments');
        FulfillmentSchema::flush();

        $this->assertNull($this->blockedReason(100));
    }
}
