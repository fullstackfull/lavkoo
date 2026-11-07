<?php

namespace App\Services\SalesChannels\Sync;

use App\Models\SalesChannelSyncState;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The bookkeeping of incremental synchronisation: one row per account and resource, holding the watermark, the cursor of an unfinished run, the outcome of the
 * last runs, the back-off after failures, and the lease that stops two runs working on the same account and resource at once.
 *
 * A crashed run loses its lease by itself (it expires), keeps its cursor, and the next run resumes. The watermark moves forward only when a run completes: a run that dies
 * halfway re-reads from the old watermark (less the configured overlap), so nothing changed in the meantime is skipped, and re-reading is harmless because every ingest is idempotent.
 */
class SyncStateStore
{
    public function acquire(int $accountId, string $resource): ?SyncLease
    {
        return DB::transaction(function () use ($accountId, $resource): ?SyncLease {
            $state = SalesChannelSyncState::firstOrCreate(['account_id' => $accountId, 'resource' => $resource]);
            $state = SalesChannelSyncState::whereKey($state->id)->lockForUpdate()->firstOrFail();

            if ($state->lease_expires_at !== null && $state->lease_expires_at->isFuture()) {
                return null;
            }
            if ($state->backoff_until !== null && $state->backoff_until->isFuture()) {
                return null;
            }

            $token = (string) Str::uuid();
            $state->forceFill([
                'lease_token' => $token,
                'lease_expires_at' => now()->addMinutes((int) config('sales_channels.sync.lease_minutes', 20)),
                'last_attempt_at' => now(),
            ])->save();

            return new SyncLease($accountId, $resource, $token, $state->watermark, $state->cursor);
        }, attempts: 3);
    }

    /** Keeps the lease alive during a long run. */
    public function renew(SyncLease $lease): void
    {
        SalesChannelSyncState::where('account_id', $lease->accountId)->where('resource', $lease->resource)->where('lease_token', $lease->token)
            ->update(['lease_expires_at' => now()->addMinutes((int) config('sales_channels.sync.lease_minutes', 20))]);
    }

    /** Remembers where an unfinished run stopped. @param array<string, mixed>|null $cursor */
    public function checkpoint(SyncLease $lease, ?array $cursor): void
    {
        SalesChannelSyncState::where('account_id', $lease->accountId)->where('resource', $lease->resource)->where('lease_token', $lease->token)
            ->update(['cursor' => $cursor === null ? null : json_encode($cursor), 'lease_expires_at' => now()->addMinutes((int) config('sales_channels.sync.lease_minutes', 20))]);
    }

    /** @param array<string, mixed> $stats */
    public function succeed(SyncLease $lease, ?CarbonInterface $newWatermark, array $stats): void
    {
        $this->settle($lease, [
            'watermark' => $newWatermark?->copy()->setTimezone(date_default_timezone_get()),
            'cursor' => null,
            'last_success_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
            'backoff_until' => null,
            'stats' => json_encode($stats),
        ]);
    }

    /** A run stopped before the end of its work but made progress and left a cursor to continue from. @param array<string, mixed> $stats */
    public function pause(SyncLease $lease, array $cursor, array $stats): void
    {
        $this->settle($lease, ['cursor' => json_encode($cursor), 'last_success_at' => now(), 'last_error' => null, 'stats' => json_encode($stats)]);
    }

    public function fail(SyncLease $lease, string $message): void
    {
        $state = SalesChannelSyncState::where('account_id', $lease->accountId)->where('resource', $lease->resource)->first();
        $failures = ($state?->consecutive_failures ?? 0) + 1;
        $minutes = min((int) config('sales_channels.sync.backoff_cap_minutes', 120), $failures * (int) config('sales_channels.sync.backoff_minutes_per_failure', 5));
        $this->settle($lease, [
            'last_failure_at' => now(),
            'last_error' => mb_substr($message, 0, 2000),
            'consecutive_failures' => $failures,
            'backoff_until' => now()->addMinutes($minutes),
        ]);
    }

    /** The channel asked us to wait: not a failure of the sync, only a pause. */
    public function backOff(SyncLease $lease, int $seconds, string $reason): void
    {
        $this->settle($lease, ['backoff_until' => now()->addSeconds(max(1, $seconds)), 'last_error' => mb_substr($reason, 0, 2000)]);
    }

    /** Nothing was attempted because the account is not switched on for this resource. */
    public function skip(SyncLease $lease, string $reason): void
    {
        $this->settle($lease, ['last_error' => mb_substr($reason, 0, 2000)]);
    }

    public function state(int $accountId, string $resource): ?SalesChannelSyncState
    {
        return SalesChannelSyncState::where('account_id', $accountId)->where('resource', $resource)->first();
    }

    /** Is the resource due, given how often it should run? A resource that never ran is due. */
    public function isDue(int $accountId, string $resource, int $everyMinutes): bool
    {
        $state = $this->state($accountId, $resource);
        if ($state === null || $state->last_success_at === null) {
            return $state?->backoff_until === null || $state->backoff_until->isPast();
        }

        return $state->last_success_at->lte(now()->subMinutes($everyMinutes)) && ($state->backoff_until === null || $state->backoff_until->isPast());
    }

    /** The instant a run should read "changed since": the watermark less the overlap, or the start of the first backfill. */
    public function readFrom(SyncLease $lease, ?CarbonInterface $backfillStart = null): CarbonInterface
    {
        if ($lease->watermark === null) {
            return $backfillStart ?? now()->subDays((int) config('sales_channels.sync.order_backfill_days', 30));
        }

        return $lease->watermark->copy()->subMinutes((int) config('sales_channels.sync.overlap_minutes', 10));
    }

    /** @param array<string, mixed> $values */
    private function settle(SyncLease $lease, array $values): void
    {
        SalesChannelSyncState::where('account_id', $lease->accountId)->where('resource', $lease->resource)->where('lease_token', $lease->token)
            ->update($values + ['lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }
}
