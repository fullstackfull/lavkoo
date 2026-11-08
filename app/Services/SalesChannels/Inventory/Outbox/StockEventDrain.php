<?php

namespace App\Services\SalesChannels\Inventory\Outbox;

use App\Models\ChannelStockEvent;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\StorefrontStockRefresher;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\DirtyPoolRun;
use App\Services\SalesChannels\Sync\ChannelSyncRunner;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works the outbox: tells the CHANNELS. Our own shop's number is not its business — the change itself wrote that, in its own transaction — except for the changes a
 * transaction could not cover (a provider figure read by a sweep, a setting saved on a screen) and for a host with the inline write switched off.
 *
 * How quickly the channels hear is therefore the only thing this governs, and it is seconds where a queue worker runs and up to a minute where none does. Not "the same
 * second": a marketplace has to be asked, and asking is what a worker is for. The scheduled runs stay exactly as they were, as the net beneath it.
 *
 * IT HANDS OVER ITS OWN POOLS, not the marks. `DirtyPools::mark()` does nothing at all while the division is switched off, so a worker that read the marks would find nothing,
 * publish nothing, and close its events as though the work had been done. It knows which pools changed — that is what the rows say — so it says so (`DirtyPoolRun::runFor`),
 * and the arrangement is therefore right with the division on or off.
 *
 * IT DOES NOT DECIDE. Not one gate lives here. Whether a quantity may be sent at all is the publisher's and the transport's business — the host's switches, the account's
 * capability and its recorded approval, a figure too old to trust, an order sweep too old to raise a quantity on, a listing the channel owns the stock of (Amazon FBA, Noon
 * FBN). This class hands over pool keys and nothing else, so a listing that was skipped before is skipped now, for the same reason, in the same words.
 *
 * AND IT CLAIMS NOTHING IT DID NOT DO. A row becomes `worked` only when a pass really attended to it, and `outcome` says what that came to: the pool recomputed and nobody to
 * tell, the publisher asked and run, or the publisher asked and refused before anything left this host (which is a settled answer and NOT a successful publish). Two things
 * settle nothing and therefore leave the row OPEN for another pass, because the alternative is a closed row and a wrong quantity:
 *
 *  - a publisher that was BUSY, rate limited or that FAILED;
 *  - a storefront write the single writer REFUSED (a whole-product figure on a product sold per variant, a variant that is gone). That one is usually PERMANENT — a person
 *    has to correct the mapping or the product — so it is tried the owner's number of times and then kept as `dead` with `storefront_write_refused` on it, which the hub
 *    names. Bounded, visible, and never retried for ever.
 */
class StockEventDrain
{
    /** The publisher's answers that settle nothing: the work has to be attempted again rather than closed. */
    private const UNSETTLED = [ChannelSyncRunner::BUSY, ChannelSyncRunner::RATE_LIMITED, ChannelSyncRunner::FAILED];

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
     * @return array{claimed: int, pools: int, recomputed: int, products: int, storefront: array{applied: int, refused: int}, sent: array<int, array<string, mixed>>, outcome: string|null, failed: int, reopened: int, reclaimed: int}
     */
    public function drain(int $limit = 200, ?SalesChannelAccount $only = null): array
    {
        $empty = [
            'claimed' => 0, 'pools' => 0, 'recomputed' => 0, 'products' => 0, 'storefront' => ['applied' => 0, 'refused' => 0],
            'sent' => [], 'outcome' => null, 'failed' => 0, 'reopened' => 0, 'reclaimed' => 0,
        ];
        if (!$this->outbox->on()) {
            return $empty;
        }

        $reclaimed = $this->outbox->reclaimAbandoned();
        $events = $this->outbox->claim($limit);
        if ($events === []) {
            return array_merge($empty, ['reclaimed' => $reclaimed]);
        }

        $ids = array_map(static fn (ChannelStockEvent $event): int => (int) $event->id, $events);
        $pools = $this->poolsOf($events);

        try {
            $storefront = $this->rewriteTheStorefront($events);
            // The marks are still made, so that a scheduled run which comes along meanwhile sees the same pools; but the work is done on the keys this pass holds, not on them.
            $this->dirtyPools->mark($pools);
            $worked = $this->run->runFor($pools, $only);
        } catch (Throwable $exception) {
            // Only the kind of failure: what a channel says about a product can repeat the whole payload back.
            Log::warning('Working a batch of stock events failed; the events go back for another try.', ['events' => count($ids), 'error' => $exception::class]);
            $this->outbox->retryLater($ids, 'stock_event_batch_failed');

            return array_merge($empty, ['claimed' => count($ids), 'failed' => count($ids), 'reclaimed' => $reclaimed]);
        }

        [$outcome, $result, $detail] = $this->whatThePassCameTo($worked);

        // A storefront write the single writer REFUSED settles nothing either, and it is the more serious of the two: the channels can be behind for a minute without harm,
        // but OUR OWN number being wrong is a unit on sale here that is gone. The row stays open with that reason — never closed with `storefront_written_at` set over a
        // number nobody corrected, which is what `products > 0` (the count ATTEMPTED, not written) used to do.
        $refusedHere = $storefront['refused'] > 0;
        if ($refusedHere || in_array($result, self::UNSETTLED, true)) {
            // The regular run holds the account's stock lease, the channel asked us to wait, the run failed, or our own number was refused. Nothing is settled: the rows stay
            // for another pass, with the reason on them, rather than being closed over a quantity that was never sent or a number that was never corrected.
            $this->outbox->retryLater(
                $ids,
                $refusedHere ? 'storefront_write_refused' : 'publisher_' . $result,
                $refusedHere ? null : $detail,
            );

            return array_merge($empty, [
                'claimed' => count($ids), 'pools' => count($pools), 'recomputed' => (int) ($worked['recomputed'] ?? 0),
                'products' => $storefront['products'], 'storefront' => ['applied' => $storefront['applied'], 'refused' => $storefront['refused']],
                'sent' => $worked['accounts'] ?? [], 'outcome' => null, 'reopened' => count($ids), 'reclaimed' => $reclaimed,
            ]);
        }

        // Written only when the writer really wrote it (or had nothing to write), never when it was merely asked.
        $this->outbox->finish($ids, $outcome, $result, $detail, recomputed: (int) ($worked['recomputed'] ?? 0) > 0, storefrontWritten: $storefront['applied'] > 0);

        return [
            'claimed' => count($ids),
            'pools' => count($pools),
            'recomputed' => (int) ($worked['recomputed'] ?? 0),
            'products' => $storefront['products'],
            'storefront' => ['applied' => $storefront['applied'], 'refused' => $storefront['refused']],
            'sent' => $worked['accounts'] ?? [],
            'outcome' => $outcome,
            'failed' => 0,
            'reopened' => 0,
            'reclaimed' => $reclaimed,
        ];
    }

    /**
     * What the pass came to, in the three words the row keeps: the outcome, the publisher's own result, and why.
     *
     * No account was asked at all → the pool was recomputed and there was nobody to tell. Every account that WAS asked ran → the publisher was asked and ran (what each
     * listing's quantity came to is the listing's own `publish_state`, not this). Every account refused before anything left this host → asked and not sent, with the switch
     * or approval that refused it. Anything unsettled wins over a settled answer, because one account being busy means the pass is not finished.
     *
     * @param array{pools: int, recomputed: int, accounts: array<int, array{result: string, detail: string|null, stats: array<string, mixed>}>} $worked
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function whatThePassCameTo(array $worked): array
    {
        $results = array_map(static fn (array $run): string => (string) $run['result'], $worked['accounts'] ?? []);
        if ($results === []) {
            return [($worked['recomputed'] ?? 0) > 0 ? ChannelStockEvent::RECOMPUTED_ONLY : ChannelStockEvent::NOTHING_TO_DO, null, null];
        }

        foreach (self::UNSETTLED as $unsettled) {
            if (in_array($unsettled, $results, true)) {
                return [ChannelStockEvent::NOT_SENT, $unsettled, $this->detailOf($worked, $unsettled)];
            }
        }
        if (in_array(ChannelSyncRunner::OK, $results, true)) {
            return [ChannelStockEvent::SENT, ChannelSyncRunner::OK, null];
        }

        return [ChannelStockEvent::NOT_SENT, ChannelSyncRunner::SKIPPED, $this->detailOf($worked, ChannelSyncRunner::SKIPPED)];
    }

    /**
     * The first account's own reason for that result, as a code — never a channel's words.
     *
     * @param array{accounts: array<int, array{result: string, detail: string|null, stats: array<string, mixed>}>} $worked
     */
    private function detailOf(array $worked, string $result): ?string
    {
        foreach ($worked['accounts'] ?? [] as $run) {
            if ((string) $run['result'] === $result) {
                return $run['detail'] === null ? null : mb_substr((string) $run['detail'], 0, 64);
            }
        }

        return null;
    }

    /**
     * The storefront's own stock, for every product whose event still asks for it. A channel hold writes it in its OWN transaction, at the moment the units are held, and
     * clears the flag: this is for the changes that cannot do that — a provider's figure read by a sweep, a setting saved on a screen — and for a host that has the inline
     * write switched off.
     *
     * One product is written once however many of its pools changed, and the write is a RECOMPUTE (figure minus promises), never a decrement, so doing it twice takes nothing
     * off twice.
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
