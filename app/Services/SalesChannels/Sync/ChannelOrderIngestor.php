<?php

namespace App\Services\SalesChannels\Sync;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\RemoteOrderLine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records a channel order in the application, once, however many times and in whatever order it is delivered.
 *
 *  - IDENTITY: account + the channel's order id + its package id. Reading an order twice is one order; two concurrent reads serialise on the row.
 *  - NEWER WINS: an order older than the one held is ignored; an unchanged order only has its timestamp touched; a changed one updates the record and says what changed.
 *  - NEVER BACKWARDS: a status earlier than the one held (a late delivery of an old state) is not applied, except cancellation and return, which are real.
 *  - NOTHING ELSE IS TOUCHED: ingesting creates no internal order, no shipment, no stock movement and no product. That is a separate, deliberate step (routing).
 *  - NO PERSONAL DATA: a buyer's name, address, phone and e-mail never reach this table; only the city and country needed to price a delivery do.
 */
class ChannelOrderIngestor
{
    public function ingest(SalesChannelAccount $account, RemoteOrder $remote): IngestResult
    {
        return DB::transaction(function () use ($account, $remote): IngestResult {
            $mapping = $this->lockedMapping($account, $remote);
            if ($mapping === null) {
                try {
                    return DB::transaction(fn (): IngestResult => $this->create($account, $remote));
                } catch (UniqueConstraintViolationException) {
                    $mapping = $this->lockedMapping($account, $remote);
                }
            }

            return $this->update($mapping, $remote);
        });
    }

    /** Re-links the lines of an order to products after a person linked a listing. Returns how many lines it linked. */
    public function relinkLines(ExternalOrderMapping $mapping): int
    {
        $linked = 0;
        foreach ($mapping->lines()->whereNull('product_id')->get() as $line) {
            $product = $this->productFor($mapping->account_id, $mapping->sales_channel, $line->sku, $line->barcode);
            if ($product !== null) {
                $line->forceFill(['product_id' => $product->product_id, 'variant_key' => (string) $product->variant_key, 'product_mapping_id' => $product->id, 'inventory_location_id' => $product->stock_location_id ?? $line->inventory_location_id])->save();
                $linked++;
            }
        }
        $this->refreshReview($mapping);

        return $linked;
    }

    private function lockedMapping(SalesChannelAccount $account, RemoteOrder $remote): ?ExternalOrderMapping
    {
        return ExternalOrderMapping::where('account_id', $account->id)->where('external_order_id', $remote->orderId)->where('external_package_id', $remote->packageId)->lockForUpdate()->first();
    }

    private function create(SalesChannelAccount $account, RemoteOrder $remote): IngestResult
    {
        $mapping = ExternalOrderMapping::create($this->fields($account, $remote) + [
            'account_id' => $account->id,
            'sales_channel' => $account->channel,
            'seller_id' => (int) $account->seller_id,
            'external_order_id' => $remote->orderId,
            'external_package_id' => $remote->packageId,
        ]);
        foreach ($remote->lines as $line) {
            $this->createLine($mapping, $line);
        }
        $this->refreshReview($mapping);
        if ($remote->externalStatus === 'UnPacked') {
            $mapping->forceFill(['review_reason' => 'split_replaced'])->save();
        }

        return new IngestResult(IngestResult::CREATED, $mapping->refresh(), ['created', 'status:' . $remote->status->value]);
    }

    private function update(ExternalOrderMapping $mapping, RemoteOrder $remote): IngestResult
    {
        if ($remote->updatedAt !== null && $mapping->external_updated_at !== null && $remote->updatedAt->lt($mapping->external_updated_at)) {
            return new IngestResult(IngestResult::STALE, $mapping);
        }

        if ($mapping->payload_hash === $remote->hash()) {
            $mapping->forceFill(['last_synced_at' => now()])->save();
            if ($mapping->lines()->whereNull('product_id')->exists()) {
                $this->relinkLines($mapping);
            }

            return new IngestResult(IngestResult::UNCHANGED, $mapping);
        }

        $changes = [];
        $held = $mapping->orderStatus();
        $incoming = $remote->status;
        $regression = $incoming->rank() < $held->rank() && !in_array($incoming, [ChannelOrderStatus::Cancelled, ChannelOrderStatus::Returned, ChannelOrderStatus::Failed], true);

        $fields = $this->fields($mapping->account ?? SalesChannelAccount::findOrFail($mapping->account_id), $remote);
        if ($mapping->shipment_link_state === ExternalOrderMapping::LINK_LINKED) {
            // The linked shipment's carrier and tracking number are what the order was linked to; the channel's own (often empty) values must not wipe them.
            unset($fields['carrier'], $fields['tracking_number'], $fields['tracking_url']);
        }
        if ($regression) {
            unset($fields['status'], $fields['shipped_at'], $fields['delivered_at']);
            $changes[] = 'status_regression_ignored';
        } elseif ($incoming !== $held) {
            $changes[] = 'status:' . $incoming->value;
            if ($incoming === ChannelOrderStatus::Cancelled) {
                $changes[] = 'cancelled';
            }
        }
        if ($mapping->order_total !== null && $remote->orderTotal->compareTo($mapping->order_total) !== 0) {
            $changes[] = 'amount_changed';
        }
        if ($mapping->tracking_number !== $remote->trackingNumber && $remote->trackingNumber !== null) {
            $changes[] = 'tracking_changed';
        }

        $mapping->forceFill($fields)->save();
        $linesChanged = $this->syncLines($mapping, $remote);
        if ($linesChanged) {
            $changes[] = 'lines_changed';
        }

        // An order that was already routed and then changed in a way that matters is for a person to look at; nothing is sent or cancelled by itself on the strength of it.
        if ($mapping->execution_policy !== null && in_array($mapping->review_reason, [null, 'unmapped_lines'], true) && array_intersect($changes, ['amount_changed', 'lines_changed']) !== []) {
            $mapping->forceFill(['review_reason' => 'modified_after_routing'])->save();
            $changes[] = 'needs_review';
        }
        if (in_array('cancelled', $changes, true) && ($mapping->order_id !== null || $mapping->shipment_reference !== null)) {
            $mapping->forceFill(['review_reason' => 'cancelled_after_dispatch'])->save();
            $changes[] = 'needs_review';
        }
        if ($remote->externalStatus === 'UnPacked') {
            $mapping->forceFill(['review_reason' => $mapping->order_id !== null ? 'split_after_dispatch' : 'split_replaced'])->save();
        }
        $this->refreshReview($mapping);

        return new IngestResult(IngestResult::UPDATED, $mapping->refresh(), $changes);
    }

    /** @return array<string, mixed> */
    private function fields(SalesChannelAccount $account, RemoteOrder $remote): array
    {
        $snapshot = PiiRedactor::redact($remote->snapshot, true);
        $snapshot['destination'] = array_filter(['city' => $remote->destination['city'] ?? null, 'country' => $remote->destination['country'] ?? null], static fn ($value): bool => $value !== null && $value !== '');
        $snapshot['is_cod'] = $remote->isCod;
        $snapshot['payment_method'] = $remote->paymentMethod;
        if ($remote->replacesPackageId !== null) {
            $snapshot['replaces_package_id'] = $remote->replacesPackageId;
        }

        return [
            'external_status' => mb_substr($remote->externalStatus, 0, 48),
            'status' => $remote->status->value,
            'fulfillment_mode' => $remote->fulfillmentMode->value,
            'fulfillment_channel_raw' => $remote->fulfillmentChannelRaw,
            'currency' => $remote->currency,
            'items_total' => $remote->itemsTotal->toStorage(),
            'discount_total' => $remote->discountTotal->toStorage(),
            'shipping_total' => $remote->shippingTotal->toStorage(),
            'tax_total' => $remote->taxTotal->toStorage(),
            'order_total' => $remote->orderTotal->toStorage(),
            'prices_include_tax' => $remote->pricesIncludeTax,
            'purchased_at' => $remote->purchasedAt,
            'external_updated_at' => $remote->updatedAt,
            'shipped_at' => $remote->shippedAt,
            'delivered_at' => $remote->deliveredAt,
            'cancelled_at' => $remote->cancelledAt ?? ($remote->status === ChannelOrderStatus::Cancelled ? now() : null),
            'carrier' => $remote->carrier,
            'tracking_number' => $remote->trackingNumber,
            'tracking_url' => $remote->trackingUrl,
            'payload_hash' => $remote->hash(),
            'snapshot' => $snapshot,
            'last_synced_at' => now(),
        ];
    }

    private function createLine(ExternalOrderMapping $mapping, RemoteOrderLine $line): ExternalOrderLine
    {
        $product = $this->productFor($mapping->account_id, $mapping->sales_channel, $line->sku, $line->barcode);

        return ExternalOrderLine::create($this->lineFields($line) + [
            'mapping_id' => $mapping->id,
            'external_line_id' => $line->lineId,
            'product_id' => $product?->product_id,
            'variant_key' => (string) ($product->variant_key ?? ''),
            'product_mapping_id' => $product?->id,
            'inventory_location_id' => $product?->stock_location_id,
            'currency' => $mapping->currency,
        ]);
    }

    /** @return bool whether any line changed */
    private function syncLines(ExternalOrderMapping $mapping, RemoteOrder $remote): bool
    {
        $changed = false;
        $existing = $mapping->lines()->get()->keyBy('external_line_id');
        foreach ($remote->lines as $line) {
            $row = $existing->get($line->lineId);
            if ($row === null) {
                $this->createLine($mapping, $line);
                $changed = true;
                continue;
            }
            $row->fill($this->lineFields($line));
            if ($row->isDirty()) {
                $changed = true;
            }
            $row->save();
            if ($row->product_id === null) {
                $this->relinkLines($mapping);
            }
        }

        return $changed;
    }

    /** @return array<string, mixed> */
    private function lineFields(RemoteOrderLine $line): array
    {
        return [
            'sku' => $line->sku,
            'barcode' => $line->barcode,
            'external_product_id' => $line->externalProductId,
            'title' => mb_substr($line->title, 0, 500),
            'quantity' => $line->quantity,
            'quantity_cancelled' => $line->quantityCancelled,
            'line_total' => $line->lineTotal->toStorage(),
            'unit_price' => $line->unitPrice()->toStorage(),
            'discount_total' => $line->discount->toStorage(),
            'tax_total' => $line->tax->toStorage(),
            'shipping_total' => $line->shipping?->toStorage(),
            'weight_kg' => $line->weightKg?->toScale(3),
            'status' => mb_substr($line->status, 0, 24),
            'snapshot' => PiiRedactor::redact($line->snapshot, true),
        ];
    }

    private function productFor(int $accountId, string $channel, ?string $sku, ?string $barcode): ?SalesChannelProductMapping
    {
        $keys = array_values(array_filter($channel === 'amazon' ? [$sku] : [$barcode, $sku], static fn ($key): bool => $key !== null && $key !== ''));
        if ($keys === []) {
            return null;
        }

        return SalesChannelProductMapping::where('account_id', $accountId)->whereIn('listing_key', $keys)->where('status', SalesChannelProductMapping::MAPPED)->whereNotNull('product_id')->first();
    }

    /** `unmapped_lines` is owned by this class: set while a line has no product, cleared when none lacks one. Every other reason waits for a person. */
    private function refreshReview(ExternalOrderMapping $mapping): void
    {
        $unmapped = $mapping->lines()->whereNull('product_id')->where('status', '!=', 'cancelled')->exists();
        if ($unmapped && $mapping->review_reason === null) {
            $mapping->forceFill(['review_reason' => 'unmapped_lines'])->save();
        } elseif (!$unmapped && $mapping->review_reason === 'unmapped_lines') {
            $mapping->forceFill(['review_reason' => null])->save();
        }
    }
}
