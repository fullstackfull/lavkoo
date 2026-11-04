<?php

namespace App\Services\Marketplace;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderTransaction;
use App\Models\VendorLedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What one order is actually worth to the seller who fulfilled it.
 *
 * A seller looking at an order sees the total the customer paid, which is not their number: out of
 * it come commission, tax and — depending on who bears it — shipping. Until now nothing in the app
 * or the panel said what was left, so a seller had to reconcile a payout against a month of orders
 * to find out what any single one earned them.
 *
 * The figures come from whichever record actually exists, and the answer says which:
 *
 *   `commission_ledger` — `order_item_commissions`, written per line when the order was placed. The
 *   most precise source: it names the rule that was applied and what it was applied to.
 *
 *   `order_transaction` — the older per-order row, which carries the same totals without the
 *   per-line rule.
 *
 *   `not_recorded` — neither exists yet. This is a real state, not an error: an order placed before
 *   settlement ran has no earning recorded, and the honest answer is to show what the order itself
 *   states and say the rest is not settled — never to compute a plausible number and present it as
 *   what the seller will receive.
 */
class SellerOrderBreakdownService
{
    public const SOURCE_COMMISSION_LEDGER = 'commission_ledger';
    public const SOURCE_ORDER_TRANSACTION = 'order_transaction';
    public const SOURCE_NOT_RECORDED = 'not_recorded';

    public function __construct(private readonly VendorLedger $ledger)
    {
    }

    /**
     * The money side of one order, for one seller.
     *
     * @return array<string, mixed>|null null when the order is not this seller's
     */
    public function breakdownFor(int|string $orderId, int|string $sellerId): ?array
    {
        $details = $this->detailsFor($orderId, $sellerId);

        if ($details->isEmpty()) {
            return null;
        }

        $order = Order::find($orderId);
        $items = $this->itemTotals($details);
        $commission = $this->commissionFrom($orderId, $sellerId, $details);

        return [
            'source' => $commission['source'],
            'currency' => $this->ledger->baseCurrency(),
            'items' => $items,
            // What the seller is judged to have sold, before the marketplace takes its share.
            'commissionable_amount' => $commission['commissionable_amount'],
            'commission_amount' => $commission['commission_amount'],
            'commission_rules' => $commission['rules'],
            'reversed_amount' => $commission['reversed_amount'],
            'seller_receives' => $commission['seller_net_amount'],
            // Present so a client can say "not settled yet" rather than showing a blank where a
            // number should be.
            'is_settled' => $commission['source'] !== self::SOURCE_NOT_RECORDED,
            'shipping' => $this->shipping($order, $details),
            'order_total' => $order ? round((float) $order->order_amount, 2) : null,
            'payment_method' => $order?->payment_method,
            'payment_status' => $order?->payment_status,
            'ledger' => $this->ledgerEntries($details, $sellerId),
        ];
    }

    /**
     * @return Collection<int, OrderDetail>
     */
    private function detailsFor(int|string $orderId, int|string $sellerId): Collection
    {
        if (!Schema::hasTable('order_details')) {
            return collect();
        }

        // Scoped on the seller, so an order belonging to someone else is not found rather than
        // returned with somebody else's margins in it.
        // Without the digital-file storage rows the model eager-loads by default: this reads
        // quantities and money, and nothing here ever looks at a download link.
        return OrderDetail::withoutEagerLoads()
            ->where(['order_id' => $orderId, 'seller_id' => $sellerId])
            ->get();
    }

    /**
     * What the seller sold, from the order lines themselves.
     *
     * @param  Collection<int, OrderDetail>  $details
     * @return array<string, mixed>
     */
    private function itemTotals(Collection $details): array
    {
        return [
            'count' => $details->count(),
            'quantity' => (int) $details->sum('qty'),
            'price' => round((float) $details->sum(fn (OrderDetail $line) => (float) $line->price * (int) $line->qty), 2),
            'discount' => round((float) $details->sum(fn (OrderDetail $line) => (float) $line->discount * (int) $line->qty), 2),
            'tax' => round((float) $details->sum(fn (OrderDetail $line) => (float) $line->tax * (int) $line->qty), 2),
        ];
    }

    /**
     * The commission, from the best record that exists.
     *
     * @param  Collection<int, OrderDetail>  $details
     * @return array<string, mixed>
     */
    private function commissionFrom(int|string $orderId, int|string $sellerId, Collection $details): array
    {
        if (Schema::hasTable('order_item_commissions')) {
            $lines = DB::table('order_item_commissions')
                ->where(['order_id' => $orderId, 'seller_id' => $sellerId])
                ->get();

            if ($lines->isNotEmpty()) {
                return [
                    'source' => self::SOURCE_COMMISSION_LEDGER,
                    'commissionable_amount' => round((float) $lines->sum('commissionable_amount'), 2),
                    'commission_amount' => round((float) $lines->sum('commission_amount'), 2),
                    'seller_net_amount' => round((float) $lines->sum('seller_net_amount'), 2),
                    'reversed_amount' => round((float) $lines->sum('reversed_amount'), 2),
                    // Named, so a seller can see which rule took what rather than one lump sum.
                    'rules' => $lines->map(fn ($line) => [
                        'label' => $line->rule_label,
                        'scope' => $line->rule_scope_type,
                        'rate_type' => $line->rate_type,
                        'percentage' => $line->percentage === null ? null : (float) $line->percentage,
                        'fixed_amount' => $line->fixed_amount === null ? null : (float) $line->fixed_amount,
                        'commissionable_amount' => round((float) $line->commissionable_amount, 2),
                        'commission_amount' => round((float) $line->commission_amount, 2),
                    ])->values()->all(),
                ];
            }
        }

        $transaction = Schema::hasTable('order_transactions')
            ? OrderTransaction::where(['order_id' => $orderId, 'seller_id' => $sellerId])->first()
            : null;

        if ($transaction) {
            return [
                'source' => self::SOURCE_ORDER_TRANSACTION,
                'commissionable_amount' => round((float) $transaction->order_amount, 2),
                'commission_amount' => round((float) $transaction->admin_commission, 2),
                'seller_net_amount' => round((float) $transaction->seller_amount, 2),
                'reversed_amount' => 0.0,
                'rules' => [],
            ];
        }

        // Nothing recorded. Said plainly rather than estimated: a number a seller reads as "what I
        // will be paid" must come from the record that will pay them.
        return [
            'source' => self::SOURCE_NOT_RECORDED,
            'commissionable_amount' => null,
            'commission_amount' => null,
            'seller_net_amount' => null,
            'reversed_amount' => null,
            'rules' => [],
        ];
    }

    /**
     * Who pays to move the order, and how much.
     *
     * @param  Collection<int, OrderDetail>  $details
     * @return array<string, mixed>
     */
    private function shipping(?Order $order, Collection $details): array
    {
        return [
            'cost' => $order ? round((float) $order->shipping_cost, 2) : null,
            // The marketplace decides who bears shipping per order; a seller reading a margin needs
            // to know whether this came out of theirs.
            'borne_by' => $order?->shipping_responsibility,
            'is_free' => (bool) ($order?->is_shipping_free),
            'deliveryman_charge' => $order ? round((float) $order->deliveryman_charge, 2) : null,
        ];
    }

    /**
     * The ledger lines this order produced, which is where the money actually moves.
     *
     * @param  Collection<int, OrderDetail>  $details
     * @return array<int, array<string, mixed>>
     */
    private function ledgerEntries(Collection $details, int|string $sellerId): array
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return [];
        }

        return VendorLedgerEntry::query()
            ->where('seller_id', $sellerId)
            ->where('reference_type', 'order_details')
            ->whereIn('reference_id', $details->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->map(fn (VendorLedgerEntry $entry) => [
                'entry_type' => $entry->entry_type,
                'credit' => round((float) $entry->credit, 2),
                'debit' => round((float) $entry->debit, 2),
                'status' => $entry->status,
                // When a pending earning becomes withdrawable. The single most asked question about
                // a seller's balance, and it has never been visible per order.
                'available_at' => $entry->available_at,
                'description' => $entry->description,
                'created_at' => $entry->created_at,
            ])->values()->all();
    }
}
