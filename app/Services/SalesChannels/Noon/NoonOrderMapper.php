<?php

namespace App\Services\SalesChannels\Noon;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\RemoteOrderLine;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One FBPI order, as `fbpi-orders/list` and `fbpi-order/{nr}/get` both carry it, in the application's own shape.
 *
 * FBPI means the goods are in OUR warehouse and we fulfil them, so this is the order shape that matters most and the one with the least in it. What noon's page documents
 * per order is: `fbpi_order_nr`, `mp_code` (noon or namshi_v2), `mp_order_nr`, `mp_country_code`, `customer_country_code`, `merchant_code`, `currency_code`,
 * `warehouse_code`, `order_created_at` and `items[]`. Three things about that list decide everything below.
 *
 * **1. AN ITEM HAS NO QUANTITY.** No quantity field is documented anywhere on the page. Each `items[]` entry carries one `mp_item_nr`, and `shipment/create` ships
 * individual `mp_item_nr` values, so one entry is read as ONE UNIT and entries sharing a `partner_sku` are grouped into a line whose quantity is how many there are. Every
 * `mp_item_nr` is kept on the line, because it is what a shipment is built from and nothing else identifies the unit. This reading is the only one the documented shape
 * allows; it is NOT stated, and it is recorded as unverified in docs/sales-channels/api-reference.md so that a real order can settle it.
 *
 * **2. AN ORDER HAS NO STATUS.** Only items do, and they have two: `mp_status` (CONFIRMED / CANCELLED) and `integration_status` (ACKNOWLEDGED / OUT_OF_STOCK / SHIPPED).
 * So the order's status is DERIVED from its items, and `externalStatus` is written as the real values that produced it rather than as a word noon never used.
 *
 * **3. THE MONEY IS NOT THERE.** No order total, no shipping, no tax, no discount, no payment method, no cash-on-delivery flag. The one money field is
 * `delivered_invoice_price`, documented as "the invoiced price of the delivered item" — a DELIVERED figure, which a fresh order does not have, and whose page does not even
 * say whether it is a unit or a line amount. So nothing here is estimated: an absent price stays absent, the totals noon does not publish stay zero, and the fact that they
 * are noon's silence rather than genuine zeroes is recorded on the order's snapshot. noon publishes no settlement, payout or fee endpoint either, so this channel's
 * commissions are a declared gap and `OperationalCosts` is given no figure for them. Inventing one is the single worst thing this mapper could do.
 */
class NoonOrderMapper
{
    private const CONFIRMED = 'CONFIRMED';

    private const CANCELLED = 'CANCELLED';

    private const SHIPPED = 'SHIPPED';

    private const OUT_OF_STOCK = 'OUT_OF_STOCK';

    /**
     * @param array<string, mixed> $order one element of `orders[]`, or the whole body of the single-order read
     * @throws InvalidArgumentException when the order carries no number to identify it by
     */
    public function map(array $order, SalesChannelAccount $account): RemoteOrder
    {
        $orderNumber = trim((string) ($order['fbpi_order_nr'] ?? ''));
        if ($orderNumber === '') {
            throw new InvalidArgumentException('A noon FBPI order needs its fbpi_order_nr.');
        }

        $entries = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));
        $lines = $this->lines($entries);

        $itemsTotal = Money::zero();
        foreach ($lines as $line) {
            $itemsTotal = $itemsTotal->plus($line->lineTotal);
        }

        $states = $this->states($entries);
        $createdAt = $this->instant($order['order_created_at'] ?? null);

        return new RemoteOrder(
            orderId: $orderNumber,
            // noon has no package or parcel of its own: a shipment is ours to create and carries OUR `integration_shipment_nr`, so there is nothing to put here.
            packageId: '',
            externalStatus: $states['external'],
            status: $states['status'],
            // FBPI is the seller's own warehouse and the seller's own fulfilment. It is noon's FBN that is the channel's, and FBN stock never joins the shared pool.
            fulfillmentMode: FulfillmentMode::MerchantManaged,
            fulfillmentChannelRaw: 'fbpi',
            currency: strtoupper(trim((string) ($order['currency_code'] ?? $account->currency ?? ''))),
            itemsTotal: $itemsTotal,
            // noon publishes NONE of these on this call. They are zero because there is nothing to put in them, which the snapshot says in so many words.
            discountTotal: Money::zero(),
            shippingTotal: Money::zero(),
            taxTotal: Money::zero(),
            orderTotal: $itemsTotal,
            pricesIncludeTax: (bool) $account->setting('prices_include_tax', true),
            purchasedAt: $createdAt,
            // noon documents no "last modified" on an FBPI order, so the only instant it gives is when the order was created. A sweep therefore cannot narrow by change
            // time, and `orders()` filters by creation time instead -- which is what noon's own filters offer.
            updatedAt: $createdAt,
            lines: $lines,
            // NOT guessed: no payment method and no cash-on-delivery flag appear anywhere in the documented order.
            isCod: false,
            paymentMethod: null,
            destination: array_filter([
                // A city is not published on the order either; the buyer-details call has one, and it is gated separately because it describes a person.
                'country' => strtoupper(trim((string) ($order['customer_country_code'] ?? ''))) ?: null,
            ]),
            snapshot: array_filter([
                'mp_code' => $this->nullable($order['mp_code'] ?? null),
                'mp_order_nr' => $this->nullable($order['mp_order_nr'] ?? null),
                'mp_country_code' => $this->nullable($order['mp_country_code'] ?? null),
                'merchant_code' => $this->nullable($order['merchant_code'] ?? null),
                'warehouse_code' => $this->nullable($order['warehouse_code'] ?? null),
                'units' => count($entries),
                // Said plainly, so that nobody reads the zeroes above as figures noon gave us.
                'noon_publishes_no_order_totals' => true,
                'noon_publishes_no_settlement_endpoint' => true,
                'quantity_is_counted_from_items_because_noon_documents_none' => true,
            ], static fn ($value): bool => $value !== null),
        );
    }

    /**
     * The order's lines: the item entries grouped by our own `partner_sku`.
     *
     * An entry with no `partner_sku` is still a unit of the order and is NOT dropped — it is grouped under its own `mp_item_nr` instead, so the unit count stays right and
     * the line is plainly one we cannot match to a product of ours.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<RemoteOrderLine>
     */
    private function lines(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $itemNumber = trim((string) ($entry['mp_item_nr'] ?? ''));
            $sku = trim((string) ($entry['partner_sku'] ?? ''));
            $key = $sku !== '' ? 'sku:' . $sku : 'item:' . $itemNumber;
            $grouped[$key][] = $entry;
        }

        $lines = [];
        foreach ($grouped as $key => $units) {
            $sku = str_starts_with($key, 'sku:') ? substr($key, 4) : null;
            $cancelled = 0;
            $total = Money::zero();
            $itemNumbers = [];
            $priced = 0;
            $states = [];

            foreach ($units as $unit) {
                $itemNumbers[] = trim((string) ($unit['mp_item_nr'] ?? ''));
                if ($this->shortEnum((string) ($unit['mp_status'] ?? ''), 'MP_ITEM_STATUS_') === self::CANCELLED) {
                    $cancelled++;
                }
                // Absent, null or zero is noon not having published a price, NOT a sale at nothing. It is counted so a person can see how much of the line is unpriced.
                $price = $unit['delivered_invoice_price'] ?? null;
                if (is_numeric($price) && (float) $price > 0.0) {
                    $total = $total->plus(Money::of((string) $price));
                    $priced++;
                }
                $states[] = $this->shortEnum((string) ($unit['integration_status'] ?? ''), 'INTEGRATION_ITEM_STATUS_');
            }

            $lines[] = new RemoteOrderLine(
                // Every unit of the line, in noon's own numbers: this is what a shipment is built from, and it is the only identifier a unit has.
                lineId: implode(',', array_filter($itemNumbers)),
                sku: $sku,
                // noon's order carries no barcode. The returns lookup goes the other way -- a barcode in, a purchase item out.
                barcode: null,
                externalProductId: null,
                // Nor a title. A name shown beside this line has to come from our own product, which is the only place one exists.
                title: $sku ?? implode(',', array_filter($itemNumbers)),
                quantity: count($units),
                quantityCancelled: $cancelled,
                lineTotal: $total,
                discount: Money::zero(),
                tax: Money::zero(),
                shipping: null,
                status: mb_strtolower(implode(',', array_values(array_unique(array_filter($states)))) ?: 'unspecified'),
                snapshot: array_filter([
                    'mp_item_nrs' => array_values(array_filter($itemNumbers)),
                    'units_noon_gave_no_price_for' => count($units) - $priced,
                    'cancellation_reason_codes' => array_values(array_unique(array_filter(array_map(
                        static fn (array $unit): string => trim((string) ($unit['cancellation_reason_code'] ?? '')),
                        $units,
                    )))),
                    'estimated_shipping_dates' => array_values(array_unique(array_filter(array_map(
                        static fn (array $unit): string => trim((string) ($unit['estimated_shipping_date'] ?? '')),
                        $units,
                    )))),
                ], static fn ($value): bool => $value !== [] && $value !== null),
            );
        }

        return $lines;
    }

    /**
     * The order's status, derived from its items, and the real values it was derived from.
     *
     * The order of the tests is what makes it right. Everything cancelled is cancelled. Otherwise, everything still wanted having shipped is shipped — a cancelled unit
     * must not hold an otherwise complete order open. An item marked out of stock is `processing` and never `failed`: noon cancels that unit and lets the rest proceed, so
     * the ORDER is still alive. An order with no items at all is `new` rather than anything more definite, because nothing has been said about it.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{status: ChannelOrderStatus, external: string}
     */
    private function states(array $entries): array
    {
        $mp = [];
        $integration = [];
        foreach ($entries as $entry) {
            $mp[] = $this->shortEnum((string) ($entry['mp_status'] ?? ''), 'MP_ITEM_STATUS_') ?: 'UNSPECIFIED';
            $integration[] = $this->shortEnum((string) ($entry['integration_status'] ?? ''), 'INTEGRATION_ITEM_STATUS_') ?: 'UNSPECIFIED';
        }

        $wanted = [];
        foreach ($entries as $index => $entry) {
            if (($mp[$index] ?? '') !== self::CANCELLED) {
                $wanted[] = $integration[$index] ?? 'UNSPECIFIED';
            }
        }

        $external = 'mp:' . implode('|', array_values(array_unique($mp))) . ' integration:' . implode('|', array_values(array_unique($integration)));
        $status = match (true) {
            $entries === [] => ChannelOrderStatus::New,
            $wanted === [] => ChannelOrderStatus::Cancelled,
            $wanted === array_fill(0, count($wanted), self::SHIPPED) => ChannelOrderStatus::Shipped,
            in_array(self::SHIPPED, $wanted, true) || in_array(self::OUT_OF_STOCK, $wanted, true) => ChannelOrderStatus::Processing,
            default => ChannelOrderStatus::New,
        };

        return ['status' => $status, 'external' => $external];
    }

    /** noon writes its enums in full (`MP_ITEM_STATUS_CONFIRMED`); the part that carries the meaning is what is kept. */
    private function shortEnum(string $value, string $prefix): string
    {
        return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($text)->utc();
        } catch (\Throwable) {
            // An instant we cannot read is left null rather than turned into "now", which would make an old order look like a new one.
            return null;
        }
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
