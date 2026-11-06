<?php

namespace Tests\Support;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\RemoteOrderLine;
use Carbon\CarbonImmutable;

/** Builds channel orders in the application's own shape for tests. The figures are arbitrary. */
trait BuildsRemoteOrders
{
    /** @param list<RemoteOrderLine>|null $lines */
    protected function remoteOrder(string $orderId = 'ORD-1', string $packageId = '', ChannelOrderStatus $status = ChannelOrderStatus::New, ?array $lines = null, string $updatedAt = '2026-11-10 10:00:00', array $overrides = []): RemoteOrder
    {
        $lines ??= [$this->remoteLine('L1', 'SKU-1', 'BAR-1', 2, '100')];
        $total = Money::sum(array_map(static fn (RemoteOrderLine $line): Money => $line->lineTotal->minus($line->discount), $lines));

        return new RemoteOrder(...($overrides + [
            'orderId' => $orderId,
            'packageId' => $packageId,
            'externalStatus' => ucfirst($status->value),
            'status' => $status,
            'fulfillmentMode' => FulfillmentMode::Fbm,
            'fulfillmentChannelRaw' => 'MFN',
            'currency' => 'SAR',
            'itemsTotal' => $total,
            'discountTotal' => Money::zero(),
            'shippingTotal' => Money::zero(),
            'taxTotal' => Money::zero(),
            'orderTotal' => $total,
            'pricesIncludeTax' => true,
            'purchasedAt' => CarbonImmutable::parse('2026-11-10 09:00:00'),
            'updatedAt' => CarbonImmutable::parse($updatedAt),
            'lines' => $lines,
            'destination' => ['city' => 'Riyadh', 'country' => 'SA'],
            'snapshot' => ['marker' => 'kept'],
        ]));
    }

    protected function remoteLine(string $id, ?string $sku, ?string $barcode, int $quantity, string $total, int $cancelled = 0): RemoteOrderLine
    {
        return new RemoteOrderLine($id, $sku, $barcode, null, 'Item ' . $id, $quantity, $cancelled, Money::of($total), Money::zero(), Money::of('0'), null, 'open');
    }
}
