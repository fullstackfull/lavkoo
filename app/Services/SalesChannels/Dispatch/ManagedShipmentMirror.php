<?php

namespace App\Services\SalesChannels\Dispatch;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\DispatchState;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Services\AuditLogger;
use App\Services\Fulfillment\FulfillmentHooks;
use App\Services\Fulfillment\OrderEligibility;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the channel order in step with the provider shipment the platform's own fulfilment holds for it, and the other way round for a cancellation.
 * It only READS the fulfilment (status, reference, tracking) and records it on the channel order; it never creates, sends or changes a shipment, so it cannot cause a second one.
 *
 *  - the provider accepted the shipment: the order is LINKED to it, so the channel can be told the tracking number, and a later search of the
 *    provider's own list finds the shipment already owned by this order (the reference is unique per provider);
 *  - the fulfilment failed or was cancelled while the channel order is still open: nothing is retried here, the order is flagged for a person;
 *  - the channel cancelled the order: the internal order is closed, which is what makes the platform cancel (or drop) the shipment by its own rules. The cancellation counts as done only when
 *    the fulfilment says the provider stopped it: a provider that holds the shipment and cannot be asked to cancel (M5AZN has no cancel call) leaves the order flagged for a person, and a parcel
 *    already on the road is flagged too, because only a person can stop a parcel.
 *
 * STOCK. The internal order is a store order: the pool counts its units while it stands, and the channel order's own hold is released. Whatever this finds about an order, its reservations
 * are brought in line with it afterwards (`ChannelOrderReservations`), so that when the internal order stops standing (closed by a person, its shipment cancelled by the provider) while the
 * channel order is still open, the channel order's hold is back within one run.
 *
 * Safe to run as often as you like, and fair: every run takes the orders it looked at longest ago, so orders that need a person never keep newer ones out of the window.
 */
class ManagedShipmentMirror
{
    public const LINKED = 'linked';
    public const UPDATED = 'updated';
    public const CANCELLED = 'cancelled';
    public const ATTENTION = 'attention';
    public const UNTOUCHED = 'untouched';

    public const REASON_FAILED = 'managed_shipment_failed';
    public const REASON_CANCELLED = 'managed_shipment_cancelled';
    public const REASON_CANCELLED_AFTER_DISPATCH = 'cancelled_after_dispatch';
    public const REASON_CANCEL_NOT_CONFIRMED = 'managed_cancel_not_confirmed';
    public const REASON_LINKED_ELSEWHERE = 'shipment_linked_elsewhere';

    private const OWN_REASONS = [self::REASON_FAILED, self::REASON_CANCELLED, self::REASON_CANCELLED_AFTER_DISPATCH, self::REASON_CANCEL_NOT_CONFIRMED];

    public function __construct(
        private readonly ChannelOrderReservations $reservations,
        private readonly FulfillmentHooks $hooks,
        private readonly AuditLogger $audit,
        private readonly BuyerEchoScrubber $scrubber,
    ) {
    }

    /** @return array<string, int> how many orders each outcome applied to */
    public function sweep(int $limit = 200): array
    {
        $counts = [self::LINKED => 0, self::UPDATED => 0, self::CANCELLED => 0, self::ATTENTION => 0, self::UNTOUCHED => 0];
        $open = ExternalOrderMapping::query()
            ->where('execution_policy', ExecutionPolicy::Managed->value)
            ->whereNotNull('order_id')
            ->where(static fn ($query) => $query->whereNull('shipment_status')->orWhereNotIn('shipment_status', FulfillmentStatus::terminalValues()))
            ->whereNotExists(static fn ($settled) => $settled->select(DB::raw(1))->from('channel_order_dispatches as dispatches')
                ->whereColumn('dispatches.mapping_id', 'external_order_mappings.id')->where('dispatches.state', DispatchState::Cancelled->value))
            ->orderByRaw('(shipment_checked_at IS NULL) DESC')->orderBy('shipment_checked_at')->orderBy('id')
            ->limit($limit)->get();

        foreach ($open as $mapping) {
            $counts[$this->mirror($mapping)]++;
            ExternalOrderMapping::whereKey($mapping->id)->update(['shipment_checked_at' => now()]);
        }

        return $counts;
    }

    public function mirror(ExternalOrderMapping $mapping): string
    {
        $cancelledOrderId = null;
        $outcome = DB::transaction(function () use ($mapping, &$cancelledOrderId): string {
            $locked = ExternalOrderMapping::whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            if ($locked->order_id === null || $locked->execution_policy !== ExecutionPolicy::Managed->value) {
                return self::UNTOUCHED;
            }
            $fulfillment = ProviderFulfillment::where('order_id', $locked->order_id)->orderByDesc('generation')->orderByDesc('id')->first();
            if ($fulfillment === null) {
                return self::UNTOUCHED;
            }
            // Whatever a provider's error text repeated of the buyer goes out of the stored texts at once, not when the details are removed.
            $this->scrubber->scrubOrder((int) $locked->order_id);

            if (in_array($locked->orderStatus(), [ChannelOrderStatus::Cancelled, ChannelOrderStatus::Failed], true)) {
                return $this->mirrorCancellation($locked, $fulfillment, $cancelledOrderId);
            }

            return $this->mirrorShipment($locked, $fulfillment);
        });

        if ($cancelledOrderId !== null) {
            $this->hooks->orderChanged($cancelledOrderId, ['order_status' => 'canceled']);
        }
        // Whatever the internal order has become (accepted, cancelled by the provider, closed by a person), the channel order's hold is brought in line with it: the units are counted
        // by the internal order while it counts them, and by the channel order's own hold when it stops.
        $this->reservations->sync(ExternalOrderMapping::findOrFail($mapping->id));

        return $outcome;
    }

    private function mirrorShipment(ExternalOrderMapping $mapping, ProviderFulfillment $fulfillment): string
    {
        $status = $fulfillment->status;
        if ($status === FulfillmentStatus::Cancelled || $status === FulfillmentStatus::Failed) {
            return $this->flag($mapping, $status === FulfillmentStatus::Failed ? self::REASON_FAILED : self::REASON_CANCELLED, DispatchState::Failed);
        }
        if (!$status->isAcceptedByProvider() || blank($fulfillment->provider_reference)) {
            return self::UNTOUCHED;
        }

        $firstLink = $mapping->shipment_link_state !== ExternalOrderMapping::LINK_LINKED;
        $fields = [
            'provider' => $fulfillment->provider,
            'shipment_link_state' => ExternalOrderMapping::LINK_LINKED,
            'shipment_reference' => $fulfillment->provider_reference,
            'shipment_status' => $status->value,
            'tracking_number' => $fulfillment->tracking_number ?? $mapping->tracking_number,
            'tracking_url' => $fulfillment->tracking_url ?? $mapping->tracking_url,
            'shipment_checked_at' => now(),
            'review_reason' => in_array($mapping->review_reason, [...self::OWN_REASONS, self::REASON_LINKED_ELSEWHERE], true) ? null : $mapping->review_reason,
        ];
        if (!$firstLink && $this->unchanged($mapping, $fields)) {
            return self::UNTOUCHED;
        }

        try {
            $mapping->forceFill($fields)->save();
        } catch (UniqueConstraintViolationException) {
            $mapping->refresh();

            return $this->flag($mapping, self::REASON_LINKED_ELSEWHERE, DispatchState::Failed, ExternalOrderMapping::LINK_CONFLICT);
        }

        if ($firstLink) {
            ChannelOrderDispatch::where('mapping_id', $mapping->id)->update(['state' => DispatchState::Accepted->value, 'accepted_at' => now(), 'closed_at' => null]);
            $this->audit->record(action: 'sales_channel.managed_shipment_linked', subject: $mapping, context: ['mapping_id' => $mapping->id, 'provider' => $fulfillment->provider, 'reference' => $fulfillment->provider_reference, 'internal_order_id' => $mapping->order_id]);
        }

        return $firstLink ? self::LINKED : self::UPDATED;
    }

    private function mirrorCancellation(ExternalOrderMapping $mapping, ProviderFulfillment $fulfillment, ?int &$cancelledOrderId): string
    {
        $status = $fulfillment->status;
        $order = DB::table('orders')->where('id', $mapping->order_id)->lockForUpdate()->first(['id', 'order_status']);
        $closed = $order === null || in_array($order->order_status, OrderEligibility::CLOSED_STATUSES, true);
        $providerHolds = $status->isAcceptedByProvider() && $status !== FulfillmentStatus::Cancelled;

        if ($providerHolds && $status->rank() >= FulfillmentStatus::InTransit->rank()) {
            return $this->flag($mapping, self::REASON_CANCELLED_AFTER_DISPATCH, DispatchState::Failed);
        }
        if (!$closed) {
            DB::table('orders')->where('id', $order->id)->update(array_intersect_key(['order_status' => 'canceled', 'updated_at' => now()], array_flip(Schema::getColumnListing('orders'))));
            $this->audit->record(action: 'sales_channel.managed_order_cancelled', subject: $mapping, context: ['mapping_id' => $mapping->id, 'internal_order_id' => $order->id, 'fulfillment_status' => $status->value]);
            $cancelledOrderId = (int) $order->id;
        }

        // The provider holds the shipment and nothing says it was stopped: the order is closed here, but only a person can make sure the parcel does not go out.
        if ($providerHolds) {
            $outcome = $this->flag($mapping, self::REASON_CANCEL_NOT_CONFIRMED, DispatchState::Accepted);

            return $closed ? $outcome : self::CANCELLED;
        }

        $changed = $this->markDispatch($mapping->id, DispatchState::Cancelled);
        if (in_array($mapping->review_reason, [self::REASON_CANCEL_NOT_CONFIRMED, self::REASON_FAILED, self::REASON_CANCELLED], true)) {
            $mapping->forceFill(['review_reason' => null])->save();
            $changed = true;
        }

        return $changed || $cancelledOrderId !== null ? self::CANCELLED : self::UNTOUCHED;
    }

    private function flag(ExternalOrderMapping $mapping, string $reason, DispatchState $dispatchState, ?string $linkState = null): string
    {
        if ($mapping->review_reason === $reason && ($linkState === null || $mapping->shipment_link_state === $linkState)) {
            return self::UNTOUCHED;
        }
        $mapping->forceFill(array_filter(['review_reason' => $reason, 'shipment_link_state' => $linkState]))->save();
        $this->markDispatch($mapping->id, $dispatchState);
        $this->audit->record(action: 'sales_channel.managed_shipment_flagged', subject: $mapping, context: ['mapping_id' => $mapping->id, 'reason' => $reason]);

        return self::ATTENTION;
    }

    /**
     * Writes the state only when it changes, so that a sweep that finds nothing new leaves no trace (the row's own times are what the retention rules and the sweeps read), and starts the
     * clock the buyer's details are kept against the first time the order ends without a shipment.
     */
    private function markDispatch(int $mappingId, DispatchState $state): bool
    {
        $changed = ChannelOrderDispatch::where('mapping_id', $mappingId)->where('state', '!=', $state->value)->update(['state' => $state->value]) > 0;
        if (in_array($state, [DispatchState::Cancelled, DispatchState::Failed], true)) {
            ChannelOrderDispatch::where('mapping_id', $mappingId)->whereNull('closed_at')->update(['closed_at' => now()]);
        }

        return $changed;
    }

    /** @param array<string, mixed> $fields */
    private function unchanged(ExternalOrderMapping $mapping, array $fields): bool
    {
        foreach (['provider', 'shipment_reference', 'shipment_status', 'tracking_number', 'tracking_url', 'review_reason'] as $column) {
            if ($mapping->{$column} !== $fields[$column]) {
                return false;
            }
        }

        return true;
    }
}
