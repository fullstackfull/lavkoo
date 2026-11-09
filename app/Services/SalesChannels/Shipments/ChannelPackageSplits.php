<?php

namespace App\Services\SalesChannels\Shipments;

use App\Models\ChannelPackageSplit;
use App\Models\SalesChannelAccount;
use App\Services\AuditLogger;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Trendyol\TrendyolConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cutting one shipment package into several, at most once, and then finding out whether it worked.
 *
 * ## The two facts the whole design comes from
 *
 * **Trendyol documents no idempotency for any of its four split endpoints**, and says nothing about what a repeated identical
 * request does. And the split is **asynchronous**: "New packages will be created asynchronously", with the original package
 * turning `UnPacked` and its id becoming invalid some time after the request is answered.
 *
 * Read together, those say the package's own status cannot be the guard. A run that sends a split and dies before writing
 * anything down leaves a package still reading `Created`; a second run that trusted the status would cut it again, and no page
 * says what that produces — a third package, a 400, or a duplicate nobody notices until a customer gets two parcels.
 *
 * So the intent is written down **before** the request leaves (`may_have_sent`), and from that instant no run sends it again.
 *
 * ## And then, unlike a return decision, it is asked
 *
 * A fenced return decision waits for a person because Trendyol publishes no way to ask what became of it. A fenced split does
 * not have to: Trendyol's own pages say to read the order back, the original package's `UnPacked` status is the proof, and the
 * new packages identify themselves with `createdBy: "split"` and `originPackageIds` pointing at the package they came from.
 * So `check()` goes and reads, and only a row whose answer never arrives ends up with a person.
 *
 * **Nothing is ever read out of the split response.** All four endpoints document 200 with no content schema in the
 * international set, so an empty body is the documented success, and the new ids are learned from the order or not at all.
 */
class ChannelPackageSplits
{
    /** This run's identity, minted once per object, so one run cannot write over its own earlier claim as if it were another's. */
    private readonly string $runId;

    public function __construct(
        private readonly TrendyolConnector $trendyol,
        private readonly AuditLogger $audit,
    ) {
        $this->runId = 'splits-' . Str::uuid()->toString();
    }

    /** The table ships before its migration has to have run; until it has, this says so rather than failing. */
    public static function ready(): bool
    {
        return Schema::hasTable('channel_package_splits');
    }

    /**
     * Records a request to split a package. It does NOT send it.
     *
     * The package is read first, for two reasons that are not the same: to check the plan against the lines the package really
     * holds, and to settle `shouldKeepPreviousStatus`, which Trendyol requires to be true for a package already in `Picking`.
     *
     * @return array{row: ChannelPackageSplit|null, refusal: string|null}
     */
    public function request(SalesChannelAccount $account, string $externalOrderId, string $packageId, PackageSplitPlan $plan, ?int $adminId = null): array
    {
        if (!self::ready()) {
            return ['row' => null, 'refusal' => 'splits_table_not_migrated'];
        }
        if (trim($externalOrderId) === '' || trim($packageId) === '') {
            return ['row' => null, 'refusal' => 'package_split_names_no_package'];
        }

        // **A package with a live split row is not split again.** This is the cheap guard; the fence is the one that holds when
        // two runs pass this check at the same moment.
        $open = ChannelPackageSplit::where('account_id', $account->id)->where('package_id', $packageId)->get()
            ->first(static fn (ChannelPackageSplit $row): bool => $row->blocksAnotherSplit());
        if ($open !== null) {
            return ['row' => $open, 'refusal' => 'package_already_has_a_split_' . $open->state];
        }

        $package = $this->readPackage($account, $externalOrderId, $packageId);
        if ($package === null) {
            // Not an absence: the channel did not describe this package. Splitting a package we cannot see would be sending a
            // plan about lines we never checked.
            return ['row' => null, 'refusal' => 'package_could_not_be_read_before_splitting'];
        }
        if ($this->alreadySplit($package)) {
            return ['row' => null, 'refusal' => 'package_is_already_unpacked_and_its_id_is_no_longer_valid'];
        }

        $lineIds = array_map(static fn (object $line): string => (string) $line->lineId, $package->lines);
        $refusal = $plan->refusal($lineIds);
        if ($refusal !== null) {
            return ['row' => null, 'refusal' => $refusal];
        }

        // Trendyol's rule, not a preference: a package in `Picking` must be split with `shouldKeepPreviousStatus` true, or the
        // new packages come back `Created` and the preparation already recorded against the old package is lost.
        $keep = $this->trendyol->splitKeepsPreviousStatus($package);
        $effective = $keep === $plan->keepPreviousStatus ? $plan : $this->withKeep($plan, $keep);

        $row = ChannelPackageSplit::create([
            'seller_id' => (int) ($account->seller_id ?? 0),
            'account_id' => (int) $account->id,
            'channel' => (string) $account->channel,
            'external_order_id' => $externalOrderId,
            'package_id' => $packageId,
            'kind' => $effective->kind,
            'plan' => $effective->body(),
            'keep_previous_status' => $effective->keepPreviousStatus,
            'expected_packages' => $effective->packageCount(),
            'state' => ChannelPackageSplit::PENDING,
            'requested_by' => $adminId,
            'observed_status' => $package->externalStatus,
        ]);

        $this->audit->record(action: 'sales_channel.package_split_requested', subject: $account, context: [
            'order' => $externalOrderId, 'package' => $packageId, 'kind' => $effective->kind,
            'packages' => $effective->packageCount(), 'keep_previous_status' => $effective->keepPreviousStatus, 'by' => $adminId,
        ]);

        return ['row' => $row, 'refusal' => null];
    }

    /**
     * Works through what is due: sends the rows that may be sent, and CHECKS the rows that may not.
     *
     * One pass does both because they are the same queue. What a row gets is decided by its state and never by having been
     * claimed: a fenced row is picked up to be asked about, not to be sent.
     *
     * @return array{sent: int, confirmed: int, checked: int, needs_review: int, failed: int, stopped: string|null}
     */
    public function run(SalesChannelAccount $account, int $limit = 20): array
    {
        $stats = ['sent' => 0, 'confirmed' => 0, 'checked' => 0, 'needs_review' => 0, 'failed' => 0, 'stopped' => null];
        if (!self::ready()) {
            $stats['stopped'] = 'splits_table_not_migrated';

            return $stats;
        }

        foreach ($this->claimed($account, $limit) as $row) {
            try {
                if ($row->state === ChannelPackageSplit::MAY_HAVE_SENT) {
                    $this->check($account, $row, $stats);

                    continue;
                }
                $this->sendOne($account, $row, $stats);
            } catch (ChannelUnavailableException | ChannelPermissionDenied $gate) {
                // A switch, a capability or the approval went away. The row goes back untouched and no attempt is counted:
                // somebody's decision not to allow this must not spend a row's tries.
                $this->release($row, $gate->reason ?? 'refused');
                $stats['stopped'] = $gate->reason ?? 'refused';
                break;
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $stats
     * @throws ChannelUnavailableException|ChannelPermissionDenied
     */
    private function sendOne(SalesChannelAccount $account, ChannelPackageSplit $row, array &$stats): void
    {
        $plan = $this->planOf($row);

        // **THE FENCE, AND IT GOES BEFORE THE REQUEST.** From here the package may already be cut. A compare-and-set on the
        // claim AND the state: a run that no longer owns this row, or a row somebody already settled, must not send.
        if (!$this->fenced($row)) {
            $this->release($row, 'another_run_holds_or_already_sent_this_split', liftTheFence: true);

            return;
        }
        $stats['sent']++;

        try {
            $response = $this->trendyol->splitShipmentPackage($account, (string) $row->package_id, $plan);
        } catch (ChannelUnavailableException | ChannelPermissionDenied $gate) {
            // A gate refuses BEFORE anything leaves this host, so the fence comes down, the row goes back -- and the attempt
            // the fence counted is given back with it. Somebody's decision not to allow this must not spend a row's tries.
            $this->release($row, $gate->reason ?? 'refused', liftTheFence: true, giveBackTheAttempt: true);

            throw $gate;
        }

        if ($response->isDryRun()) {
            $this->settle($row, ChannelPackageSplit::PENDING, 'rehearsal_sent_nothing', null, liftTheFence: true);

            return;
        }
        if ($response->certainlyNotProcessed()) {
            // Nothing left this host. The row may be sent again, which is the only road back from the fence.
            $this->settle($row, ChannelPackageSplit::PENDING, 'request_never_left_this_host', $response->message(), liftTheFence: true);
            $stats['failed']++;

            return;
        }
        if ($response->kind === ChannelResponse::REJECTED || $response->kind === ChannelResponse::AUTH_ERROR) {
            // Trendyol arrived at an answer and refused. A refusal is proof the package was not cut, so the fence comes down.
            $this->settle($row, ChannelPackageSplit::FAILED, 'trendyol_refused_' . (int) $response->status, $response->message(), liftTheFence: true);
            $stats['failed']++;

            return;
        }

        // **A 200 IS NOT A CONFIRMATION, and its empty body is not a defect.** Every international split endpoint documents
        // 200 "Package split successfully" with no content schema, and the packages are created asynchronously. So even the
        // happy path goes to `may_have_sent` and is then CHECKED by reading the order. A 5xx or a timeout lands in exactly the
        // same place for the same reason: what has to be learned is the package's state, not the request's.
        //
        // It is NOT read back here. Trendyol makes the packages asynchronously, so a read taken in the same breath as the
        // request would almost always find the package unchanged -- and would then overwrite the one thing worth keeping,
        // which is whether the answer was clear. The row waits `check_seconds` and the next pass asks.
        $this->waitForProof($row, $response->ok() ? 'sent_awaiting_the_packages' : 'answer_unclear_awaiting_the_packages', $response->ok() ? null : $response->message());
    }

    /**
     * Reads the package back and decides whether the split really happened.
     *
     * Three outcomes, and the difference between the last two is the point:
     *
     *  - the original package is gone or `UnPacked`  -> **confirmed**, and the new ids are read off the order
     *  - the package still reads as it did, and there are checks left -> wait and read again. The split is asynchronous; a
     *    package that has not changed yet is not a package that was never cut.
     *  - the checks ran out -> **needs_review**. Not "failed": the request was answered and the package may still be cut.
     *    Nothing sends it again either way.
     *
     * @param array<string, mixed> $stats
     */
    private function check(SalesChannelAccount $account, ChannelPackageSplit $row, array &$stats, bool $countAsCheck = true): void
    {
        if ($countAsCheck) {
            $stats['checked']++;
        }

        $package = $this->readPackage($account, (string) $row->external_order_id, (string) $row->package_id);
        $successors = $this->successorsOf($account, $row);

        if ($package === null && $successors === []) {
            // The package cannot be read AND nothing claims to have come from it. That is not evidence of either outcome: an
            // unreadable package is not an absent one. It waits, and if it never resolves it goes to a person.
            $this->waitOrGiveUp($row, 'package_could_not_be_read_back', $stats);

            return;
        }
        if ($package !== null && !$this->alreadySplit($package) && $successors === []) {
            $this->waitOrGiveUp($row, 'package_still_reads_as_it_did', $stats, $package->externalStatus);

            return;
        }

        ChannelPackageSplit::whereKey($row->id)->update([
            'state' => ChannelPackageSplit::CONFIRMED,
            'observed_status' => $package?->externalStatus ?? 'gone',
            'new_package_ids' => $successors,
            'failure_code' => null,
            'failure_detail' => null,
            'settled_at' => now(),
            'checks' => DB::raw('checks + 1'),
            'claimed_at' => null,
            'claimed_by' => null,
            'updated_at' => now(),
        ]);
        $stats['confirmed']++;

        $this->audit->record(action: 'sales_channel.package_split_confirmed', subject: $account, context: [
            'order' => (string) $row->external_order_id, 'package' => (string) $row->package_id,
            'status' => $package?->externalStatus ?? 'gone', 'new_packages' => count($successors),
        ]);
    }

    /**
     * The packages Trendyol made out of this one, by its OWN markers and not by arithmetic.
     *
     * `createdBy: "split"` and `originPackageIds` are documented fields of the order read. Counting packages or comparing
     * quantities would be guessing, and would mistake a cancellation for a split.
     *
     * **`replacesPackageId` alone is not enough, and relying on it was a bug this file's own test caught.** The mapper sets it
     * for `split`, `cancel` AND `transfer`, because for an INGEST all three mean the same thing: this package supersedes that
     * one. For a split they do not: a package Trendyol made because a line was cancelled would have been read as proof that
     * the cut landed, confirming a split that never happened. So `createdBy` is read as well, and only `split` counts.
     *
     * @return list<string>
     */
    private function successorsOf(SalesChannelAccount $account, ChannelPackageSplit $row): array
    {
        $order = $this->readOrder($account, (string) $row->external_order_id);
        $ids = [];
        foreach ($order as $package) {
            $madeBySplit = ($package->snapshot['created_by'] ?? null) === 'split';
            if ($madeBySplit && $package->replacesPackageId === (string) $row->package_id && $package->packageId !== (string) $row->package_id) {
                $ids[] = $package->packageId;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<RemoteOrder> */
    private function readOrder(SalesChannelAccount $account, string $externalOrderId): array
    {
        try {
            return $this->trendyol->orderPackages($account, $externalOrderId);
        } catch (ChannelUnavailableException | ChannelPermissionDenied | RuntimeException) {
            // A read that failed is a read that failed. It never stands in for "there is nothing there".
            return [];
        }
    }

    private function readPackage(SalesChannelAccount $account, string $externalOrderId, string $packageId): ?RemoteOrder
    {
        try {
            return $this->trendyol->order($account, $externalOrderId, $packageId);
        } catch (ChannelUnavailableException | ChannelPermissionDenied | RuntimeException) {
            return null;
        }
    }

    /**
     * Trendyol's own proof that a package was split: "The current shipmentPackageIds gets invalid and its status is updated to
     * UnPacked". The casing differs between its pages ("Unpacked", "UnPack"), so the comparison does not depend on it.
     */
    private function alreadySplit(RemoteOrder $package): bool
    {
        return str_starts_with(strtolower($package->externalStatus), 'unpack');
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function waitOrGiveUp(ChannelPackageSplit $row, string $code, array &$stats, ?string $status = null): void
    {
        $checks = (int) $row->checks + 1;
        $allowed = max(1, (int) config('sales_channels.splits.max_checks', 8));
        if ($checks < $allowed) {
            $this->waitForProof($row, $code, null, $status, $checks);

            return;
        }

        // The answer never came. **`needs_review`, never `failed`**: the request was answered, so the package may well be cut,
        // and calling it failed would invite somebody to ask for the same split again.
        ChannelPackageSplit::whereKey($row->id)->update([
            'state' => ChannelPackageSplit::NEEDS_REVIEW,
            'failure_code' => mb_substr($code . '_after_' . $checks . '_checks', 0, 120),
            'observed_status' => $status ?? $row->observed_status,
            'checks' => $checks,
            'claimed_at' => null,
            'claimed_by' => null,
            'updated_at' => now(),
        ]);
        $stats['needs_review']++;
    }

    private function waitForProof(ChannelPackageSplit $row, string $code, ?string $detail = null, ?string $status = null, ?int $checks = null): void
    {
        ChannelPackageSplit::whereKey($row->id)->update([
            // The state does NOT move back to pending: the package may be cut, so nothing sends again. It only waits to be read.
            'state' => ChannelPackageSplit::MAY_HAVE_SENT,
            'available_at' => now()->addSeconds(max(5, (int) config('sales_channels.splits.check_seconds', 60))),
            'failure_code' => mb_substr($code, 0, 120),
            'failure_detail' => $detail,
            'observed_status' => $status ?? $row->observed_status,
            'checks' => $checks ?? $row->checks,
            'claimed_at' => null,
            'claimed_by' => null,
            'updated_at' => now(),
        ]);
    }

    /** @return list<ChannelPackageSplit> */
    private function claimed(SalesChannelAccount $account, int $limit): array
    {
        $ids = ChannelPackageSplit::sendable()->where('account_id', $account->id)->orderBy('id')->limit(max(1, $limit))->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        // The state is part of the condition, as it is for a return decision and for the same reason: the ids above came from a
        // SELECT, and between the two statements another run can claim a row, settle it and clear `claimed_by`. A claim that
        // matched a settled row would hand this run a finished split to send.
        $cutoff = now()->subMinutes(max(1, (int) config('sales_channels.splits.claim_minutes', 10)));
        ChannelPackageSplit::whereIn('id', $ids)
            ->whereIn('state', [ChannelPackageSplit::PENDING, ChannelPackageSplit::MAY_HAVE_SENT])
            ->where(fn ($query) => $query->whereNull('claimed_by')->orWhere('claimed_at', '<', $cutoff))
            ->update(['claimed_at' => now(), 'claimed_by' => $this->runId, 'updated_at' => now()]);

        return ChannelPackageSplit::whereIn('id', $ids)->where('claimed_by', $this->runId)->orderBy('id')->get()->all();
    }

    /**
     * **Written before the request: a compare-and-set whose answer is not optional to check.**
     *
     * It affects nothing when this run no longer owns the row, or when the row is no longer pending. A run that cannot fence
     * must not send: its request would leave this host with no durable record that it had.
     */
    private function fenced(ChannelPackageSplit $row): bool
    {
        $lease = now()->subMinutes(max(1, (int) config('sales_channels.splits.claim_minutes', 10)));
        $affected = ChannelPackageSplit::whereKey($row->id)
            ->where('claimed_by', $this->runId)
            ->where('claimed_at', '>', $lease)
            ->where('state', ChannelPackageSplit::PENDING)
            ->update([
                'state' => ChannelPackageSplit::MAY_HAVE_SENT,
                'attempts' => DB::raw('attempts + 1'),
                'sent_at' => now(),
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);
        if ($affected !== 1) {
            return false;
        }
        // The attribute is set, the ORIGINALS are not: `mayHaveReachedTheChannel()` now reads true, while `getOriginal()` can
        // still say what the row carried before the fence -- which is what a gate refusal has to put back.
        $row->forceFill(['state' => ChannelPackageSplit::MAY_HAVE_SENT]);

        return true;
    }

    private function settle(ChannelPackageSplit $row, string $state, ?string $code, ?string $detail, bool $liftTheFence = false): void
    {
        // **A ROW WHOSE FENCE IS UP KEEPS IT** unless the caller can prove the request was not processed.
        $keep = !$liftTheFence && $row->mayHaveReachedTheChannel() && $state === ChannelPackageSplit::PENDING;
        ChannelPackageSplit::whereKey($row->id)->where('claimed_by', $this->runId)->update([
            'state' => $keep ? ChannelPackageSplit::MAY_HAVE_SENT : $state,
            'failure_code' => $code === null ? null : mb_substr($code, 0, 120),
            'failure_detail' => $detail,
            'settled_at' => in_array($state, [ChannelPackageSplit::CONFIRMED, ChannelPackageSplit::FAILED], true) ? now() : null,
            'claimed_at' => null,
            'claimed_by' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Hands the row back.
     *
     * `$giveBackTheAttempt` is only for the one case where the fence went up and then nothing was sent at all: a gate refused
     * between the two. It is not for a row that could not be fenced -- that run counted no attempt to give back.
     */
    private function release(ChannelPackageSplit $row, string $code, bool $liftTheFence = false, bool $giveBackTheAttempt = false): void
    {
        $changes = [
            'state' => $liftTheFence || !$row->mayHaveReachedTheChannel() ? ChannelPackageSplit::PENDING : ChannelPackageSplit::MAY_HAVE_SENT,
            'failure_code' => mb_substr($code, 0, 120),
            'claimed_at' => null,
            'claimed_by' => null,
            'updated_at' => now(),
        ];
        if ($giveBackTheAttempt) {
            // The count the row carried BEFORE this run fenced it. Taken from the model rather than computed in SQL, because
            // `attempts - 1` would need a different function on every engine and this needs none.
            $changes['attempts'] = max(0, (int) $row->getOriginal('attempts'));
            $changes['sent_at'] = $row->getOriginal('sent_at');
        }
        ChannelPackageSplit::whereKey($row->id)->where('claimed_by', $this->runId)->update($changes);
    }

    /** The plan exactly as it was recorded, rebuilt from the stored body so that what is sent is what a person asked for. */
    private function planOf(ChannelPackageSplit $row): PackageSplitPlan
    {
        return PackageSplitPlan::fromStored((string) $row->kind, (array) $row->plan, (bool) $row->keep_previous_status);
    }

    private function withKeep(PackageSplitPlan $plan, bool $keep): PackageSplitPlan
    {
        return PackageSplitPlan::fromStored($plan->kind, $plan->body(), $keep);
    }

    /** Which run this is, so a run that died can be told from one still working. */
    public function runId(): string
    {
        return $this->runId;
    }
}
