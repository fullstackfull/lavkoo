<?php

namespace App\Services\SalesChannels\Dispatch;

use App\Enums\SalesChannels\DispatchState;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Services\AuditLogger;
use App\Services\Fulfillment\FulfillmentHooks;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Takes the buyer's details back out of the platform once nobody needs them, so they live for as short a time as the shipment allows.
 * The only copy is the internal order's `shipping_address_data` (and the readable payload preview and error text a rehearsal leaves on its fulfilment).
 *
 *  - the provider holds the shipment, or it ended (cancelled, delivered, returned), or the channel order was cancelled, and `retain_after_accept_hours` have passed: the details are wiped in
 *    place (city and country stay);
 *  - nothing reached any provider and `rehearsal_buyer_data_hours` have passed (a rehearsal in dry run, or a send that keeps failing): the details are wiped, the internal order is closed and
 *    the channel order goes back to waiting, so that dispatching it again reads the buyer afresh and makes a new internal order;
 *  - whatever the state, `max_buyer_data_days` after the order was made nothing is kept: no order's buyer details outlive that, even a send whose outcome was never settled (a later send of it
 *    would then be held for want of an address, for a person to decide).
 * A shipment whose send outcome is unknown is otherwise never touched: it may have to be sent again.
 *
 * Safe to run as often as you like, and fair: a run takes the rows it looked at longest ago first, so rows that cannot be purged yet never keep the others out of its window.
 */
class BuyerDataPurger
{
    public const PURGED = 'purged';
    public const RESET = 'reset';
    public const SKIPPED = 'skipped';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FulfillmentHooks $hooks,
        private readonly ChannelOrderReservations $reservations,
        private readonly BuyerEchoScrubber $scrubber,
    ) {
    }

    /** @return array<string, int> */
    public function run(int $limit = 200): array
    {
        $counts = [self::PURGED => 0, self::RESET => 0, self::SKIPPED => 0];
        $graceEnds = now()->subHours((int) config('sales_channels.dispatch.retain_after_accept_hours', 24));
        $rehearsalEnds = now()->subHours((int) config('sales_channels.dispatch.rehearsal_buyer_data_hours', 72));
        $mostEverKept = now()->subDays((int) config('sales_channels.dispatch.max_buyer_data_days', 30));

        $due = ChannelOrderDispatch::query()
            ->whereNotNull('internal_order_id')->whereNull('buyer_data_purged_at')
            ->where(static fn ($query) => $query
                ->where(static fn ($done) => $done->whereIn('state', [DispatchState::Accepted->value, DispatchState::Cancelled->value, DispatchState::Failed->value])
                    ->where(static fn ($clock) => $clock->where('accepted_at', '<=', $graceEnds)->orWhere('closed_at', '<=', $graceEnds)))
                ->orWhere(static fn ($unsent) => $unsent->whereIn('state', [DispatchState::Materialized->value, DispatchState::Failed->value])->where('materialized_at', '<=', $rehearsalEnds))
                ->orWhere('materialized_at', '<=', $mostEverKept))
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id');

        foreach ($due as $dispatchId) {
            $counts[$this->purge((int) $dispatchId, $mostEverKept)]++;
        }

        return $counts;
    }

    private function purge(int $dispatchId, \DateTimeInterface $mostEverKept): string
    {
        $mappingId = ChannelOrderDispatch::whereKey($dispatchId)->value('mapping_id');
        if ($mappingId === null) {
            return self::SKIPPED;
        }

        $closedOrderId = null;
        $outcome = DB::transaction(function () use ($dispatchId, $mappingId, $mostEverKept, &$closedOrderId): string {
            // The same order of locks as a dispatch takes (the channel order first, then its dispatch row), so the two can never wait on each other.
            $mapping = ExternalOrderMapping::whereKey($mappingId)->lockForUpdate()->firstOrFail();
            $row = ChannelOrderDispatch::whereKey($dispatchId)->lockForUpdate()->first();
            if ($row === null || $row->buyer_data_purged_at !== null || $row->internal_order_id === null) {
                return self::SKIPPED;
            }

            $fulfillments = ProviderFulfillment::where('order_id', $row->internal_order_id)->lockForUpdate()->get();
            $outcomeUnknown = $fulfillments->contains(static fn (ProviderFulfillment $fulfillment): bool => $fulfillment->status->isSendOutcomeUnknown());
            $reachedAProvider = $fulfillments->contains(static fn (ProviderFulfillment $fulfillment): bool => !$fulfillment->status->isCancellableLocally() && !$fulfillment->status->isSendOutcomeUnknown());
            $pastTheLimit = $row->materialized_at !== null && $row->materialized_at->lte($mostEverKept);
            if ($outcomeUnknown && !$pastTheLimit) {
                // Not purgeable yet: it goes to the back of the line, so that it does not keep the others out of the window.
                $row->touch();

                return self::SKIPPED;
            }

            $this->wipe($row, $fulfillments->all());
            if ($reachedAProvider || $outcomeUnknown || !in_array($row->state, [DispatchState::Materialized->value, DispatchState::Failed->value], true)) {
                return self::PURGED;
            }

            $closedOrderId = $this->closeAndRelease($row, $mapping);

            return self::RESET;
        });

        if ($closedOrderId !== null) {
            $this->hooks->orderChanged($closedOrderId, ['order_status' => 'canceled']);
        }

        return $outcome;
    }

    /** @param list<ProviderFulfillment> $fulfillments */
    private function wipe(ChannelOrderDispatch $row, array $fulfillments): void
    {
        $this->scrubber->scrubOrder((int) $row->internal_order_id);
        $order = DB::table('orders')->where('id', $row->internal_order_id)->lockForUpdate()->first(['id', 'shipping_address_data']);
        if ($order !== null) {
            $kept = Arr::only((array) json_decode((string) $order->shipping_address_data, true), ['city', 'country']);
            DB::table('orders')->where('id', $order->id)->update(['shipping_address_data' => json_encode($kept + ['redacted' => true], JSON_UNESCAPED_UNICODE)] + $this->touched('orders'));
        }
        foreach ($fulfillments as $fulfillment) {
            $fulfillment->forceFill(['meta' => Arr::except((array) $fulfillment->meta, ['payload_preview']), 'last_error_message' => null])->save();
        }
        $row->forceFill(['buyer_data_purged_at' => now()])->save();
        $this->audit->record(action: 'sales_channel.buyer_data_purged', subject: $row, context: ['mapping_id' => $row->mapping_id, 'internal_order_id' => $row->internal_order_id, 'state' => $row->state]);
    }

    /** Nothing was sent: the internal order is closed and the channel order waits to be dispatched again. */
    private function closeAndRelease(ChannelOrderDispatch $row, ExternalOrderMapping $mapping): int
    {
        $orderId = (int) $row->internal_order_id;
        DB::table('orders')->where('id', $orderId)->update(['order_status' => 'canceled'] + $this->touched('orders'));
        $mapping->forceFill(['order_id' => null])->save();
        $row->forceFill([
            'state' => DispatchState::Blocked->value, 'reason_code' => 'buyer_data_expired', 'internal_order_id' => null, 'materialized_at' => null,
            'accepted_at' => null, 'closed_at' => null, 'next_attempt_at' => null, 'attempts' => 0,
        ])->save();
        $this->audit->record(action: 'sales_channel.dispatch_reset', subject: $mapping, context: ['mapping_id' => $mapping->id, 'closed_internal_order_id' => $orderId, 'reason' => 'buyer_data_expired']);
        $this->reservations->sync($mapping);

        return $orderId;
    }

    /** @return array<string, mixed> */
    private function touched(string $table): array
    {
        return Schema::hasColumn($table, 'updated_at') ? ['updated_at' => now()] : [];
    }
}
