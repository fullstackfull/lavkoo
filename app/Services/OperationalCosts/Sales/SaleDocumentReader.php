<?php

namespace App\Services\OperationalCosts\Sales;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads a sale in the one shape the cost and profit modules use (SaleDocument), from either place a sale lives:
 *
 *  - the application's own orders (`orders` / `order_details`): the platform's own sales (`seller_is = admin`) that did NOT come from a
 *    sales channel. A marketplace seller's sale is the seller's revenue, not ours. An internal order created to fulfil a channel order is
 *    not read here at all: the channel order is the sale and the internal order is only its fulfilment vehicle, so it is counted once;
 *  - the channel orders (`external_order_mappings` / `external_order_lines`), in the currency the channel charged.
 *
 * Amounts of storefront orders are stored in the platform's own unit and are converted to the reporting currency here; when that cannot
 * be done the document says so and its revenue is not trusted.
 */
class SaleDocumentReader
{
    /** @var array<string, int>|null */
    private ?array $locations = null;

    public function __construct(
        private readonly ProviderCurrency $storeCurrency,
    ) {
    }

    public function forOrder(int $orderId): ?SaleDocument
    {
        if (!Schema::hasTable('orders') || !Schema::hasTable('order_details')) {
            return null;
        }
        $row = $this->storeOrderQuery()->where('orders.id', $orderId)->select('orders.*')->first();

        return $row === null ? null : $this->fromStoreOrderRow($row);
    }

    public function forChannelOrder(int $mappingId): ?SaleDocument
    {
        $mapping = ExternalOrderMapping::with('lines')->find($mappingId);

        return $mapping === null ? null : $this->fromChannelOrder($mapping);
    }

    /**
     * @return \Generator<int, SaleDocument>
     */
    public function storeOrders(CarbonImmutable $from, CarbonImmutable $to, ?string $status = null): \Generator
    {
        if (!Schema::hasTable('orders') || !Schema::hasTable('order_details')) {
            return;
        }

        $query = $this->storeOrderQuery()->whereBetween('orders.created_at', [$from->startOfDay(), $to->endOfDay()])->select('orders.*')->orderBy('orders.id');
        foreach ($query->lazyById(200, 'orders.id', 'id') as $row) {
            $document = $this->fromStoreOrderRow($row);
            if ($status === null || $document->state === $status) {
                yield $document;
            }
        }
    }

    /**
     * @return \Generator<int, SaleDocument>
     */
    public function channelOrders(CarbonImmutable $from, CarbonImmutable $to, ?string $channel = null, ?int $accountId = null, ?string $status = null): \Generator
    {
        $query = ExternalOrderMapping::query()->with('lines')
            ->whereBetween('purchased_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($channel !== null, static fn ($q) => $q->where('sales_channel', $channel))
            ->when($accountId !== null, static fn ($q) => $q->where('account_id', $accountId))
            ->orderBy('id');

        foreach ($query->lazyById(200) as $mapping) {
            $document = $this->fromChannelOrder($mapping);
            if ($status === null || $document->state === $status) {
                yield $document;
            }
        }
    }

    private function storeOrderQuery(): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('orders')->where('orders.seller_is', 'admin');
        if (Schema::hasTable('external_order_mappings')) {
            $query->whereNotIn('orders.id', DB::table('external_order_mappings')->whereNotNull('order_id')->select('order_id'));
        }

        return $query;
    }

    private function fromStoreOrderRow(object $order): SaleDocument
    {
        $notes = [];
        $details = DB::table('order_details')->where('order_id', $order->id)->orderBy('id')->get();
        $providerItems = Schema::hasTable('provider_fulfillment_items')
            ? DB::table('provider_fulfillment_items')->where('order_id', $order->id)->get()->keyBy('order_detail_id')
            : collect();
        $refunds = $this->refundsByDetail($details);
        $locations = $this->locationsByProvider();

        $convert = function (mixed $stored) use (&$notes): Money {
            $converted = $this->storeCurrency->fromStored($stored ?? 0);
            if ($converted === null) {
                $notes['amount_not_convertible'] = 'amount_not_convertible';

                return Money::zero();
            }

            return Money::of($converted);
        };

        $lines = [];
        $lineTotals = Money::zero();
        $tax = Money::zero();
        $providers = [];
        foreach ($details as $detail) {
            $included = ($detail->tax_model ?? $order->tax_model ?? 'exclude') === 'include';
            $gross = $convert((float) $detail->price * (int) $detail->qty);
            $discount = $convert($detail->discount ?? 0);
            $lineTax = $convert($detail->tax ?? 0);
            $revenue = $gross->minus($discount)->minus($included ? $lineTax : Money::zero());
            $lineTotals = $lineTotals->plus($gross)->minus($discount);
            $tax = $tax->plus($lineTax);

            $providerItem = $providerItems->get($detail->id);
            $provider = $providerItem->provider ?? null;
            $providers[$provider ?? 'in_house'] = true;
            $basis = match ($provider) {
                'm5azn' => SaleLine::BASIS_SUPPLIER,
                default => SaleLine::BASIS_OWNED_STOCK,
            };
            $lines[] = new SaleLine(
                targetType: 'order_line',
                targetId: (int) $detail->id,
                productId: $detail->product_id === null ? null : (int) $detail->product_id,
                variantKey: (string) ($detail->variant ?? ''),
                sku: null,
                quantity: (int) $detail->qty,
                revenue: $revenue,
                refunded: isset($refunds[$detail->id]) ? $convert($refunds[$detail->id]) : Money::zero(),
                inventoryLocationId: $locations[$provider ?? 'own_warehouse'] ?? null,
                costBasis: $basis,
                provider: $provider,
                weightKg: isset($providerItem->weight_kg) ? Money::of($providerItem->weight_kg) : null,
            );
        }

        $shipping = $convert($order->shipping_cost ?? 0);
        $orderDiscounts = $convert($order->discount_amount ?? 0)->plus($convert($order->extra_discount ?? 0))->plus($convert($order->refer_and_earn_discount ?? 0));
        $expectedTotal = $lineTotals->plus(($order->tax_model ?? 'exclude') === 'include' ? Money::zero() : $tax)->plus($shipping)->minus($orderDiscounts);
        $reportedTotal = $convert($order->order_amount ?? 0);
        // The platform's own total should equal what its lines, tax, shipping and discounts add up to. When it does not (an edited order, a
        // fee added by hand) the revenue built from the parts is still shown, but marked unverified.
        if ($lines !== [] && $expectedTotal->minus($reportedTotal)->abs()->compareTo('0.05') > 0) {
            $notes['total_does_not_add_up'] = 'total_does_not_add_up';
        }

        return new SaleDocument(
            subjectType: SaleDocument::SUBJECT_ORDER,
            subjectId: (int) $order->id,
            salesChannel: 'storefront',
            accountId: null,
            sellerId: 0,
            currency: $this->storeCurrency->code(),
            occurredAt: CarbonImmutable::parse($order->created_at ?? now()),
            state: $this->storeState((string) $order->order_status),
            fulfillmentMode: $this->storeFulfillmentMode(array_keys($providers)),
            provider: count($providers) === 1 && !isset($providers['in_house']) ? array_key_first($providers) : null,
            orderId: (int) $order->id,
            mappingId: null,
            shippingCharged: $shipping,
            orderDiscounts: $orderDiscounts,
            lines: $lines,
            revenueNotes: array_values($notes),
            reference: (string) $order->id,
            paymentMethod: $order->payment_method ?? null,
            orderTotal: $reportedTotal,
        );
    }

    private function fromChannelOrder(ExternalOrderMapping $mapping): SaleDocument
    {
        $status = $mapping->orderStatus();
        $locations = $this->locationsByProvider();
        $includesTax = (bool) $mapping->prices_include_tax;
        $notes = [];
        $mode = $mapping->fulfillmentMode();
        if ($mapping->currency === null || $mapping->items_total === null) {
            $notes[] = 'channel_amounts_missing';
        }

        $lines = $mapping->lines->map(function (ExternalOrderLine $line) use ($includesTax, $mode, $mapping, $locations): SaleLine {
            $active = $line->activeQuantity();
            $ordered = max(1, $line->quantity);
            // The channel gives line amounts for the quantity ordered; cancelled units are not revenue.
            $gross = Money::of($line->line_total ?? 0)->times($active)->dividedBy($ordered);
            $discount = Money::of($line->discount_total ?? 0)->times($active)->dividedBy($ordered);
            $tax = Money::of($line->tax_total ?? 0)->times($active)->dividedBy($ordered);
            $revenue = $gross->minus($discount)->minus($includesTax ? $tax : Money::zero());
            $returned = min($line->quantity_returned, $active);
            $unitRevenue = $active > 0 ? $revenue->dividedBy($active) : Money::zero();

            $location = $line->inventory_location_id
                ?? ($mode === FulfillmentMode::Fba ? ($locations['amazon_fba:' . $mapping->account_id] ?? null) : ($locations[$line->provider ?? 'own_warehouse'] ?? null));

            return new SaleLine(
                targetType: 'channel_order_line',
                targetId: (int) $line->id,
                productId: $line->product_id,
                variantKey: (string) $line->variant_key,
                sku: $line->sku,
                quantity: $active,
                revenue: $revenue,
                refunded: $unitRevenue->times($returned),
                inventoryLocationId: $location === null ? null : (int) $location,
                costBasis: $line->provider === 'm5azn' ? SaleLine::BASIS_SUPPLIER : SaleLine::BASIS_OWNED_STOCK,
                provider: $line->provider,
                weightKg: $line->weight_kg === null ? null : Money::of($line->weight_kg),
                quantityReturned: $returned,
            );
        })->all();

        return new SaleDocument(
            subjectType: SaleDocument::SUBJECT_CHANNEL_ORDER,
            subjectId: (int) $mapping->id,
            salesChannel: (string) $mapping->sales_channel,
            accountId: (int) $mapping->account_id,
            sellerId: (int) $mapping->seller_id,
            currency: (string) ($mapping->currency ?? ''),
            occurredAt: CarbonImmutable::parse($mapping->purchased_at ?? $mapping->created_at ?? now()),
            state: $this->channelState($status),
            fulfillmentMode: $mapping->fulfillment_mode,
            provider: $mapping->provider,
            orderId: $mapping->order_id,
            mappingId: (int) $mapping->id,
            shippingCharged: Money::of($mapping->shipping_total ?? 0),
            orderDiscounts: Money::zero(),
            lines: $lines,
            revenueNotes: $notes,
            reference: $mapping->external_order_id . ($mapping->external_package_id !== '' ? '/' . $mapping->external_package_id : ''),
            orderTotal: $mapping->order_total === null ? null : Money::of($mapping->order_total),
        );
    }

    private function storeState(string $status): string
    {
        return match ($status) {
            'delivered' => SaleDocument::STATE_DELIVERED,
            'canceled', 'failed' => SaleDocument::STATE_CANCELLED,
            'returned', 'refunded' => SaleDocument::STATE_RETURNED,
            default => SaleDocument::STATE_OPEN,
        };
    }

    private function channelState(ChannelOrderStatus $status): string
    {
        return match ($status) {
            ChannelOrderStatus::Shipped => SaleDocument::STATE_SHIPPED,
            ChannelOrderStatus::Delivered => SaleDocument::STATE_DELIVERED,
            ChannelOrderStatus::Cancelled, ChannelOrderStatus::Failed => SaleDocument::STATE_CANCELLED,
            ChannelOrderStatus::Returned => SaleDocument::STATE_RETURNED,
            default => SaleDocument::STATE_OPEN,
        };
    }

    /** @param list<string> $providers */
    private function storeFulfillmentMode(array $providers): string
    {
        return match (true) {
            in_array('m5azn', $providers, true) => FulfillmentMode::Dropshipping->value,
            in_array('torod_point', $providers, true) => FulfillmentMode::ThirdParty->value,
            default => FulfillmentMode::MerchantManaged->value,
        };
    }

    /**
     * Refunded amounts per order line, in the platform's stored unit.
     *
     * @param Collection<int, object> $details
     * @return array<int, float>
     */
    private function refundsByDetail(Collection $details): array
    {
        if ($details->isEmpty() || !Schema::hasTable('refund_requests')) {
            return [];
        }

        return DB::table('refund_requests')
            ->whereIn('order_details_id', $details->pluck('id'))
            ->where('status', 'refunded')
            ->selectRaw('order_details_id, SUM(amount) as refunded')
            ->groupBy('order_details_id')
            ->pluck('refunded', 'order_details_id')
            ->map(static fn ($amount): float => (float) $amount)
            ->all();
    }

    /** @return array<string, int> location ids by provider code ('torod_point', 'm5azn'), 'own_warehouse' and 'amazon_fba:{account}' */
    private function locationsByProvider(): array
    {
        if ($this->locations !== null) {
            return $this->locations;
        }
        $map = [];
        if (Schema::hasTable('inventory_locations')) {
            foreach (InventoryLocation::query()->active()->orderBy('id')->get() as $location) {
                if ($location->provider !== null) {
                    $map[$location->provider] ??= (int) $location->id;
                }
                if ($location->type === InventoryLocation::TYPE_OWN) {
                    $map['own_warehouse'] ??= (int) $location->id;
                }
                if ($location->type === InventoryLocation::TYPE_AMAZON_FBA && $location->sales_channel_account_id !== null) {
                    $map['amazon_fba:' . $location->sales_channel_account_id] = (int) $location->id;
                }
            }
        }

        return $this->locations = $map;
    }
}
