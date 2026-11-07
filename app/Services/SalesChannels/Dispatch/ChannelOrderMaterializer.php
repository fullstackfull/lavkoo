<?php

namespace App\Services\SalesChannels\Dispatch;

use App\Enums\SalesChannels\DispatchState;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Services\AuditLogger;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Utils\OrderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the ONE internal order a channel order needs so that the existing Torod Point / M5AZN fulfilment can ship it, and nothing more: the application's order, its lines, who owns each line
 * (through the Fulfillment module's own snapshotter) and the link from the channel order to it, all in one transaction. A crash writes nothing; a second attempt finds the order already made.
 *
 * What it does NOT do: call a provider (the Fulfillment pipeline claims and sends, retries and reconciles), create a customer (the order has none: customer 0, not a guest), touch stock (the lines
 * are marked as not decreased, so cancelling can never restock units that were never taken), or keep the buyer: the recipient is written to `orders.shipping_address_data` only because the
 * provider builders read it there, and BuyerDataPurger removes it again once the provider holds the shipment.
 *
 * It writes only the columns the orders and order_details tables really have, so it follows the platform's table as it is.
 */
class ChannelOrderMaterializer
{
    /** How often a number another order took first is replaced by the next one before the attempt is given up. */
    private const ORDER_NUMBER_ATTEMPTS = 3;

    /** How long one dispatch waits for another to finish handing out its order number. */
    private const NUMBER_LOCK_SECONDS = 15;

    public function __construct(
        private readonly DispatchGate $gate,
        private readonly OrderItemOwnershipSnapshotter $snapshotter,
        private readonly ProviderCurrency $currency,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return int the internal order's id (the one made by an earlier attempt, when there is one)
     * @throws DispatchRefused
     */
    public function materialize(ExternalOrderMapping $mapping, string $provider, ChannelRecipient $recipient, string $claimToken): int
    {
        return $this->oneAtATime(function () use ($mapping, $provider, $recipient, $claimToken): int {
            for ($attempt = 1; ; $attempt++) {
                try {
                    return $this->makeOrder($mapping, $provider, $recipient, $claimToken);
                } catch (UniqueConstraintViolationException $collision) {
                    if ($attempt >= self::ORDER_NUMBER_ATTEMPTS) {
                        throw $collision;
                    }
                }
            }
        });
    }

    /**
     * The platform hands out order numbers by reading the highest one under a row lock, which holds nothing when two orders are made at the same instant in an empty table, and its own checkout
     * has the same weakness. Orders made here wait for each other on a database-wide lock, so that only a checkout can still take a number first; then the next attempt takes the next one.
     */
    private function oneAtATime(callable $work): int
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return $work();
        }
        if ((int) DB::scalar('SELECT GET_LOCK(?, ?)', ['sales_channels.dispatch.order_number', self::NUMBER_LOCK_SECONDS]) !== 1) {
            throw new DispatchRefused('order_number_busy');
        }

        try {
            return $work();
        } finally {
            DB::scalar('SELECT RELEASE_LOCK(?)', ['sales_channels.dispatch.order_number']);
        }
    }

    private function makeOrder(ExternalOrderMapping $mapping, string $provider, ChannelRecipient $recipient, string $claimToken): int
    {
        return DB::transaction(function () use ($mapping, $provider, $recipient, $claimToken): int {
            $locked = ExternalOrderMapping::whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            $dispatch = ChannelOrderDispatch::where('mapping_id', $locked->id)->lockForUpdate()->firstOrFail();

            if ($locked->order_id !== null) {
                return (int) $locked->order_id;
            }
            if ($dispatch->claim_token !== $claimToken || !$dispatch->claimIsLive()) {
                throw new DispatchRefused('claim_lost');
            }
            $verdict = $this->gate->evaluate($locked);
            if (!$verdict->open() || $verdict->provider !== $provider) {
                throw new DispatchRefused($verdict->blockers[0] ?? 'provider_changed');
            }
            if ($recipient->problems() !== []) {
                throw new DispatchRefused('recipient_incomplete');
            }

            $orderId = $this->insertOrder($locked, $recipient);
            foreach ($locked->lines->filter(static fn (ExternalOrderLine $line): bool => $line->activeQuantity() > 0) as $line) {
                $this->insertLine($orderId, $locked, $line, $provider);
            }

            $locked->forceFill(['order_id' => $orderId, 'execution_policy' => ExecutionPolicy::Managed->value, 'provider' => $provider])->save();
            $dispatch->forceFill([
                'state' => DispatchState::Materialized->value, 'provider' => $provider, 'internal_order_id' => $orderId, 'materialized_at' => now(),
                'claim_token' => null, 'claimed_at' => null, 'reason_code' => null, 'next_attempt_at' => null,
            ])->save();
            // No order is made without the record that says it was: if the audit trail cannot be written the whole step is undone.
            $this->audit->record(action: 'sales_channel.dispatch_materialized', subject: $locked, context: ['mapping_id' => $locked->id, 'internal_order_id' => $orderId, 'provider' => $provider, 'by' => $dispatch->requested_by, 'trigger' => $dispatch->trigger, 'fields_sent_to_provider' => ['name', 'mobile', 'street', 'city']])
                ?? throw new DispatchRefused('audit_not_written');

            return $orderId;
        }, 3);
    }

    private function insertOrder(ExternalOrderMapping $mapping, ChannelRecipient $recipient): int
    {
        $orderId = (int) OrderManager::generateNewOrderID();
        $total = $this->stored($mapping->order_total);
        $now = now();

        $row = [
            'id' => $orderId,
            'verification_code' => random_int(100000, 999999),
            // No customer record: customer 0 is how the platform's own point-of-sale orders say "walk-in", and a guest has a guest id this order does not have.
            'customer_id' => 0,
            'is_guest' => 0,
            'customer_type' => 'customer',
            'seller_id' => 1,
            'seller_is' => 'admin',
            // Already paid at the channel: never cash on delivery, whatever the provider's collection rules are.
            'payment_status' => 'paid',
            'payment_method' => (string) config('sales_channels.dispatch.payment_method', 'marketplace_prepaid'),
            'order_status' => 'confirmed',
            'transaction_ref' => $mapping->external_order_id,
            'order_group_id' => OrderManager::generateUniqueOrderID(),
            'discount_amount' => 0,
            'order_amount' => $total,
            'init_order_amount' => $total,
            'paid_amount' => $total,
            'total_tax_amount' => $this->stored($mapping->tax_total),
            'tax_model' => $mapping->prices_include_tax === false ? 'exclude' : 'include',
            'shipping_cost' => 0,
            'shipping_address' => 0,
            'shipping_address_data' => json_encode($recipient->shippingAddressData(), JSON_UNESCAPED_UNICODE),
            'billing_address' => 0,
            'order_type' => (string) config('sales_channels.dispatch.order_type', 'default_type'),
            // The channel's own order number: enough to find the order again, and nothing about the buyer.
            'order_note' => 'Channel order ' . $mapping->external_order_id,
            // The moment the buyer ordered, so that the provider's go-live cut-off (orders placed before it are never sent) judges the real order, not the time of this step.
            'created_at' => $mapping->purchased_at ?? $now,
            'updated_at' => $now,
        ];

        DB::table('orders')->insert($this->rowFor('orders', $row));

        return $orderId;
    }

    private function insertLine(int $orderId, ExternalOrderMapping $mapping, ExternalOrderLine $line, string $provider): void
    {
        $product = (array) DB::table('products')->where('id', $line->product_id)->first();
        $product['fulfillment_provider'] = $provider;

        $active = $line->activeQuantity();
        $share = $line->quantity > 0 ? $active / $line->quantity : 1.0;
        $net = $this->stored(((float) $line->line_total - (float) $line->discount_total) * $share);
        $tax = $this->stored((float) $line->tax_total * $share);
        $unitPrice = $this->stored((float) $line->unit_price);
        $taxModel = $mapping->prices_include_tax === false ? 'exclude' : 'include';

        $detailId = DB::table('order_details')->insertGetId($this->rowFor('order_details', [
            'order_id' => $orderId,
            'product_id' => $line->product_id,
            'seller_id' => $product['user_id'] ?? 1,
            'product_details' => json_encode($product, JSON_UNESCAPED_UNICODE),
            'qty' => $active,
            'price' => $unitPrice,
            'tax' => $tax,
            'tax_model' => $taxModel,
            'discount' => 0,
            'discount_type' => 'discount_on_product',
            'variant' => (string) $line->variant_key,
            'variation' => '[]',
            'delivery_status' => 'pending',
            'payment_status' => 'paid',
            // Units that were never taken off the store's stock must never be given back by a cancellation: the column's own default is 1.
            'is_stock_decreased' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->snapshotter->capture($orderId, $detailId, $product, (string) $line->variant_key === '' ? null : (string) $line->variant_key, $active, $unitPrice, $net, $tax, $taxModel);
    }

    /** An amount in the channel's currency as the platform stores it, in the platform's own unit. */
    private function stored(float|int|string|null $amount): float
    {
        return round((float) $this->currency->toStored($amount ?? 0), 6);
    }

    /**
     * The row as the platform's table can take it: only the columns it has. A column the table insists on (not null, no default) that this version does not fill refuses the dispatch BEFORE
     * anything is written, with the names, instead of failing half way inside the database; the platform's own table is the platform's to change.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws DispatchRefused
     */
    private function rowFor(string $table, array $row): array
    {
        $known = array_intersect_key($row, array_flip(Schema::getColumnListing($table)));
        $unfilled = $this->unfilledRequiredColumns($table, $known);
        if ($unfilled !== []) {
            throw new DispatchRefused('orders_table_not_supported:' . implode(',', array_slice($unfilled, 0, 4)));
        }

        return $known;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string> the columns of the table that need a value and are not in the row
     */
    public function unfilledRequiredColumns(string $table, array $row): array
    {
        return collect(Schema::getColumns($table))
            ->filter(static fn (array $column): bool => !$column['nullable'] && $column['default'] === null && !$column['auto_increment'] && empty($column['generation']) && !array_key_exists($column['name'], $row))
            ->pluck('name')->all();
    }
}
