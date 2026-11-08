<?php

namespace App\Services\SalesChannels\Inventory\Outbox;

use App\Models\ChannelStockEvent;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Support\SalesChannelSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox's writer and its claim desk.
 *
 * `record()` is the whole point: it is called INSIDE the transaction that writes the change, so the intention to work the pool out again cannot outlive — or be outlived by —
 * the change itself. It writes rows and nothing else: it reads no figure, calls no channel, and dispatches no job. Whoever wrote the change dispatches the worker after the
 * transaction has COMMITTED, because a job that started before the commit would read the database as it was.
 *
 * `claim()` hands a batch to one run and no other. A run that dies leaves its rows `claimed`; `reclaimAbandoned()` takes them back after the owner's timeout, so a row is
 * worked late rather than lost. Nothing here decides whether anything may be sent: the worker hands the pools to the division and the publisher, which keep every gate.
 */
class StockEventOutbox
{
    /** A name for the run that holds a batch, so that a run which died can be told from one still working. */
    public static string $runId = '';

    /**
     * Records one row per pool the change affects. Call it inside the transaction that writes the change.
     *
     * @param iterable<PoolKey> $keys the pools the change affects — the one a reservation left as well as the one it went to
     * @param bool $storefrontWritten true when the caller has ALREADY written our own shop's number for it, in this same transaction (a channel hold does; a provider sync
     *                                does by definition). False leaves it for a worker, and the row says which of the two it is.
     * @return int how many rows were written
     */
    public function record(iterable $keys, string $reason, ?string $subjectType = null, ?int $subjectId = null, bool $storefrontWritten = false): int
    {
        if (!$this->on()) {
            return 0;
        }
        $rows = [];
        foreach ($keys as $key) {
            $rows[(string) $key] = $key->columns() + [
                'reason' => $reason,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'state' => ChannelStockEvent::PENDING,
                'rewrites_storefront_stock' => !$storefrontWritten,
                'storefront_written_at' => $storefrontWritten ? now() : null,
                'attempts' => 0,
                'available_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows === []) {
            return 0;
        }
        if (DB::transactionLevel() === 0) {
            // Not fatal — the row is still written and still worked — but the guarantee the table exists for does not hold, so it is said out loud rather than assumed.
            Log::warning('A stock event was recorded outside a transaction: it can now outlive, or be outlived by, the change it describes.', ['reason' => $reason, 'pools' => count($rows)]);
        }

        DB::table('channel_stock_events')->insert(array_values($rows));

        return count($rows);
    }

    /**
     * Takes the oldest due rows for this run. The claim is one statement, so two runs cannot take the same row: the second updates nothing.
     *
     * @return list<ChannelStockEvent>
     */
    public function claim(int $limit = 200): array
    {
        if (!$this->on()) {
            return [];
        }
        $runId = self::runId();
        $ids = ChannelStockEvent::due()->orderBy('id')->limit(max(1, $limit))->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $taken = ChannelStockEvent::whereIn('id', $ids)->where('state', ChannelStockEvent::PENDING)->update([
            'state' => ChannelStockEvent::CLAIMED, 'claimed_at' => now(), 'claimed_by' => $runId, 'updated_at' => now(),
        ]);

        return $taken === 0 ? [] : ChannelStockEvent::whereIn('id', $ids)->where('claimed_by', $runId)->where('state', ChannelStockEvent::CLAIMED)->orderBy('id')->get()->all();
    }

    /**
     * Closes the rows a pass really attended to, with what that pass came to written on them.
     *
     * `worked` is not "published": `$outcome` says whether the pool was recomputed and nobody told, whether the publisher ran, or whether it refused before anything left
     * this host. A publisher that settled nothing (busy, rate limited, failed) must not come here at all — those rows go to `retryLater`.
     *
     * @param list<int> $ids
     * @param string $outcome one of ChannelStockEvent::OUTCOMES
     * @param string|null $publishResult the publisher's own word for the run, where one was asked
     * @param string|null $publishDetail why, as a code of ours or the transport's — never a channel's words
     */
    public function finish(array $ids, string $outcome = ChannelStockEvent::SENT, ?string $publishResult = null, ?string $publishDetail = null, bool $recomputed = true, bool $storefrontWritten = false): void
    {
        if ($ids === []) {
            return;
        }
        ChannelStockEvent::whereIn('id', $ids)->update([
            'state' => ChannelStockEvent::WORKED,
            'worked_at' => now(),
            'outcome' => in_array($outcome, ChannelStockEvent::OUTCOMES, true) ? $outcome : ChannelStockEvent::NOTHING_TO_DO,
            'publish_result' => $publishResult,
            'publish_detail' => $publishDetail,
            'recomputed_at' => $recomputed ? now() : null,
            'storefront_written_at' => $storefrontWritten ? now() : null,
            'failure_code' => null,
            'updated_at' => now(),
        ]);
    }


    /**
     * Puts rows back for another try, or lays them to rest when the owner's limit is reached. A dead row is kept and counted, never dropped and never called done: an event
     * nobody can work is a quantity somewhere that nobody has corrected, and that belongs on a screen.
     *
     * @param list<int> $ids
     */
    public function retryLater(array $ids, string $failureCode, ?string $detail = null): void
    {
        if ($ids === []) {
            return;
        }
        $attempts = (int) config('sales_channels.stock.outbox.attempts', 5);
        $backoff = max(1, (int) config('sales_channels.stock.outbox.retry_seconds', 60));

        // Read, then written one by one: each row's own number of tries decides its next time and whether this was its last. The batch is bounded by what a claim takes.
        foreach (ChannelStockEvent::whereIn('id', $ids)->get() as $event) {
            $tried = (int) $event->attempts + 1;
            $event->forceFill([
                'attempts' => $tried,
                'state' => $tried >= max(1, $attempts) ? ChannelStockEvent::DEAD : ChannelStockEvent::PENDING,
                // Each try waits longer than the last, so a channel that is refusing everything is not asked sixty times a minute.
                'available_at' => now()->addSeconds($backoff * $tried),
                'claimed_at' => null,
                'claimed_by' => null,
                'failure_code' => $failureCode,
                'publish_detail' => $detail ?? $event->publish_detail,
            ])->save();
        }
    }

    /** Rows a dead run still holds, made pending again so another run works them. Returns how many. */
    public function reclaimAbandoned(): int
    {
        if (!$this->on()) {
            return 0;
        }

        return ChannelStockEvent::abandoned((int) config('sales_channels.stock.outbox.claim_timeout_minutes', 10))->update([
            'state' => ChannelStockEvent::PENDING, 'claimed_at' => null, 'claimed_by' => null, 'updated_at' => now(),
        ]);
    }

    /**
     * What is waiting, and for how long — the figure a monitoring screen needs, and the one that says the workers have stopped.
     *
     * @return array{pending: int, claimed: int, dead: int, not_sent: int, storefront_refused: int, oldest_pending_seconds: int|null}
     */
    public function lag(): array
    {
        if (!$this->on()) {
            return ['pending' => 0, 'claimed' => 0, 'dead' => 0, 'not_sent' => 0, 'storefront_refused' => 0, 'oldest_pending_seconds' => null];
        }
        $oldest = ChannelStockEvent::where('state', ChannelStockEvent::PENDING)->min('created_at');

        return [
            'pending' => ChannelStockEvent::where('state', ChannelStockEvent::PENDING)->count(),
            'claimed' => ChannelStockEvent::where('state', ChannelStockEvent::CLAIMED)->count(),
            'dead' => ChannelStockEvent::where('state', ChannelStockEvent::DEAD)->count(),
            // Rows a pass attended to where the publisher refused before anything left this host. Not a failure — but not a published quantity either, so it is counted
            // rather than read as success.
            'not_sent' => ChannelStockEvent::where('state', ChannelStockEvent::WORKED)->where('outcome', ChannelStockEvent::NOT_SENT)->count(),
            // Rows whose STOREFRONT number the writer refused. The most serious count on this list: a unit on sale in our own shop that is gone, waiting for a person to
            // correct a mapping or a product.
            'storefront_refused' => ChannelStockEvent::where('failure_code', 'storefront_write_refused')
                ->whereIn('state', [ChannelStockEvent::PENDING, ChannelStockEvent::CLAIMED, ChannelStockEvent::DEAD])->count(),
            'oldest_pending_seconds' => $oldest === null ? null : max(0, (int) now()->diffInSeconds($oldest, absolute: true)),
        ];
    }

    /** Is the outbox in use? Off, the module behaves exactly as it did: the marks and the scheduled runs, with the gap this table closes. */
    public function on(): bool
    {
        return (bool) config('sales_channels.stock.outbox.enabled', true)
            && SalesChannelSchema::ready()
            && Schema::hasTable('channel_stock_events');
    }

    private static function runId(): string
    {
        if (self::$runId === '') {
            self::$runId = substr(gethostname() ?: 'host', 0, 24) . ':' . getmypid() . ':' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        return self::$runId;
    }
}
