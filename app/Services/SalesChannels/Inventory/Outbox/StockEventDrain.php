<?php

namespace App\Services\SalesChannels\Inventory\Outbox;

use App\Models\ChannelStockEvent;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\StorefrontStockRefresher;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\DirtyPoolRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works the outbox: takes a batch, writes the storefront's own stock again where a channel took units, and hands the pools to the division and the publisher that already
 * exist. It is the "least possible delay" half of the arrangement — the scheduled runs stay exactly as they were, as the net beneath it.
 *
 * THE ORDER MATTERS, and it is the storefront first. The storefront is the shop we control: a unit Amazon has sold must stop being offered on pharmacyksa.com in the same
 * second, not when a marketplace gets round to acknowledging a push. The channels come next, lowest-first (`publishReductions` sends only what LOWERS a quantity), because a
 * reduction that is late is an oversell and a rise that is late is only a lost sale.
 *
 * WHAT IT DOES NOT DECIDE. Not one gate lives here. Whether a quantity may be sent at all is the publisher's and the transport's business — the host's switches, the account's
 * capability and its recorded approval, a figure too old to trust, an order sweep too old to raise a quantity on, a listing the channel owns the stock of (Amazon FBA, Noon
 * FBN). This class hands over pool keys and nothing else, so a listing that was skipped before is skipped now, for the same reason, in the same words.
 *
 * FAIL CLOSED. A batch that threw is NOT marked done: its rows go back for another try, and after the owner's number of tries they are kept as dead for a person to see. An
 * event that cannot be worked means a quantity somewhere nobody has corrected, which is exactly the thing that must not disappear quietly. And because a row carries no
 * figure, a row worked late sends a fresh quantity late — never a stale quantity.
 */
class StockEventDrain
{
    public function __construct(
        private readonly StockEventOutbox $outbox,
        private readonly DirtyPools $dirtyPools,
        private readonly DirtyPoolRun $run,
        private readonly StorefrontStockRefresher $storefront,
    ) {
    }

    /**
     * One pass: at most `$limit` events.
     *
     * @return array{claimed: int, pools: int, products: int, storefront: array{applied: int, refused: int}, sent: array<int, array<string, mixed>>, failed: int, reclaimed: int}
     */
    public function drain(int $limit = 200, ?SalesChannelAccount $only = null): array
    {
        $empty = ['claimed' => 0, 'pools' => 0, 'products' => 0, 'storefront' => ['applied' => 0, 'refused' => 0], 'sent' => [], 'failed' => 0, 'reclaimed' => 0];
        if (!$this->outbox->on()) {
            return $empty;
        }

        $reclaimed = $this->outbox->reclaimAbandoned();
        $events = $this->outbox->claim($limit);
        if ($events === []) {
            return array_merge($empty, ['reclaimed' => $reclaimed]);
        }

        $ids = array_map(static fn (ChannelStockEvent $event): int => (int) $event->id, $events);
        $pools = [];

        try {
            $storefront = $this->rewriteTheStorefront($events);
            // The pools go through the same mark the scheduled run reads, so the division and the publisher see them exactly as they always have.
            $pools = $this->poolsOf($events);
            $this->dirtyPools->mark($pools);
            $sent = $this->run->run($only);
        } catch (Throwable $exception) {
            // Only the kind of failure: what a channel says about a product can repeat the whole payload back.
            Log::warning('Working a batch of stock events failed; the events go back for another try.', ['events' => count($ids), 'error' => $exception::class]);
            $this->outbox->retryLater($ids, 'stock_event_batch_failed');

            return ['claimed' => count($ids), 'pools' => 0, 'products' => 0, 'storefront' => ['applied' => 0, 'refused' => 0], 'sent' => [], 'failed' => count($ids), 'reclaimed' => $reclaimed];
        }

        $this->outbox->finish($ids);

        return [
            'claimed' => count($ids),
            'pools' => count($pools),
            'products' => $storefront['products'],
            'storefront' => ['applied' => $storefront['applied'], 'refused' => $storefront['refused']],
            'sent' => $sent['accounts'] ?? [],
            'failed' => 0,
            'reclaimed' => $reclaimed,
        ];
    }

    /**
     * The storefront's own stock, for every product whose event says a channel took units or gave them back. One product is written once however many of its pools changed.
     *
     * @param list<ChannelStockEvent> $events
     * @return array{products: int, applied: int, refused: int}
     */
    private function rewriteTheStorefront(array $events): array
    {
        if (!config('sales_channels.stock.outbox.rewrite_storefront_stock', true)) {
            return ['products' => 0, 'applied' => 0, 'refused' => 0];
        }
        $productIds = [];
        foreach ($events as $event) {
            if ($event->rewrites_storefront_stock) {
                $productIds[(int) $event->product_id] = true;
            }
        }

        $applied = 0;
        $refused = 0;
        foreach (array_keys($productIds) as $productId) {
            $stats = $this->storefront->refresh($productId);
            $applied += $stats['applied'];
            $refused += $stats['refused'];
        }

        return ['products' => count($productIds), 'applied' => $applied, 'refused' => $refused];
    }

    /**
     * @param list<ChannelStockEvent> $events
     * @return list<PoolKey> each pool once, however many events named it
     */
    private function poolsOf(array $events): array
    {
        $keys = [];
        foreach ($events as $event) {
            $key = new PoolKey((int) $event->location_id, (int) $event->product_id, (string) $event->variant_key);
            $keys[(string) $key] = $key;
        }

        return array_values($keys);
    }
}
