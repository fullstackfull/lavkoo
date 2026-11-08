<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use Illuminate\Support\Facades\Log;

/**
 * Catches the payments that nothing else told us about.
 *
 * Both of the paths that normally confirm a payment can be lost: the customer closes the tab before the
 * redirect, and a webhook delivery fails five times and is given up on. Without a sweep, a customer who
 * paid in either of those cases is charged and has no order, and nobody finds out until they complain.
 *
 * The sweep asks MyFatoorah about every attempt still in flight and settles the ones that turn out to be
 * paid, through exactly the same verify-then-settle path a webhook uses — so a payment recovered here
 * cannot produce a second order for one already recovered by a webhook.
 *
 * It is deliberately polite about rate limits, which MyFatoorah does not document: a bounded batch, a
 * minimum interval before an attempt is asked about again, and a pause between calls.
 */
class ReconciliationService
{
    public function __construct(
        private readonly PaymentVerificationService $verification,
        private readonly PaymentSettlementService $settlement,
        private readonly GatewayAvailability $availability,
    ) {
    }

    /**
     * @return array{checked: int, settled: int, failed: int, pending: int, mismatch: int, expired: int, unknown: int, skipped: string|null}
     */
    public function sweep(?int $limit = null): array
    {
        $tally = ['checked' => 0, 'settled' => 0, 'failed' => 0, 'pending' => 0, 'mismatch' => 0, 'expired' => 0, 'unknown' => 0, 'skipped' => null];

        if (!$this->availability->verificationAllowed()) {
            $tally['skipped'] = 'MyFatoorah calls are switched off for this deployment.';

            return $tally;
        }

        $batch = $limit ?? max(1, (int) config('myfatoorah.reconciliation.batch_size', 50));
        $maxAgeHours = max(1, (int) config('myfatoorah.reconciliation.max_age_hours', 72));
        $minInterval = max(0, (int) config('myfatoorah.reconciliation.min_seconds_between', 60));
        $sleepMs = max(0, (int) config('myfatoorah.reconciliation.sleep_ms_between', 250));

        $attempts = MyFatoorahPaymentAttempt::query()
            ->inFlight()
            // Anything older than the window is beyond help from a sweep: the invoice has long expired
            // and chasing it forever would spend the rate limit on nothing.
            ->where('created_at', '>=', now()->subHours($maxAgeHours))
            // Do not ask about the same attempt twice in quick succession.
            ->where(function ($query) use ($minInterval): void {
                $query->whereNull('last_verified_at')
                    ->orWhere('last_verified_at', '<=', now()->subSeconds($minInterval));
            })
            // Attempts that have a payment id are the ones most likely to be a real lost payment.
            ->orderByRaw('CASE WHEN payment_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('last_verified_at')
            ->limit($batch)
            ->get();

        foreach ($attempts as $attempt) {
            $tally['checked']++;

            $verified = $this->verification->verify($attempt);

            if ($verified['unknown']) {
                $tally['unknown']++;
                $this->pause($sleepMs);
                continue;
            }

            match ($verified['status']) {
                AttemptStatus::Paid => $this->settleAndTally($verified['attempt'], $tally),
                AttemptStatus::Failed, AttemptStatus::Cancelled => $tally['failed']++,
                AttemptStatus::Expired => $tally['expired']++,
                AttemptStatus::Mismatch => $tally['mismatch']++,
                default => $tally['pending']++,
            };

            $this->pause($sleepMs);
        }

        // Finish anything that took the money but never got its order created.
        $recovered = $this->settlement->recover();

        if ($recovered['recovered'] > 0 || $recovered['still_broken'] > 0 || $recovered['skipped'] > 0) {
            Log::info('myfatoorah.recovery', $recovered);
            $tally['settled'] += $recovered['recovered'];
        }

        return $tally;
    }

    private function settleAndTally(MyFatoorahPaymentAttempt $attempt, array &$tally): void
    {
        $result = $this->settlement->settle($attempt);

        if ($result['settled']) {
            $tally['settled']++;

            // Worth a line in the log: this is a payment that neither the redirect nor the webhook
            // managed to deliver, and a rising count means one of those two is broken.
            Log::warning('myfatoorah.recovered_by_reconciliation', [
                'attempt' => $attempt->external_identifier,
                'payment_request_id' => $attempt->payment_request_id,
            ]);
        }
    }

    /**
     * Attempts that need a person: paid at the gateway but not applied, or paid for the wrong amount.
     * This is what the admin monitor shows at the top of the page.
     *
     * @return array{mismatched: int, stuck: int, in_doubt: int, hook_unverifiable: int}
     */
    public function needsAttention(): array
    {
        $leaseMinutes = max(1, (int) config('myfatoorah.hook_lease_minutes', 15));

        return [
            'mismatched' => MyFatoorahPaymentAttempt::query()->where('status', AttemptStatus::Mismatch->value)->count(),

            // Money recorded, order never created. Everything in here needs a person: see
            // PaymentSettlementService for why a hook that has started is never re-run unattended.
            'stuck' => MyFatoorahPaymentAttempt::query()
                ->where('status', AttemptStatus::Paid->value)
                ->whereNotNull('settled_at')
                ->whereNull('hook_completed_at')
                ->count(),

            // The subset of those where the hook definitely began, so whether its side effects ran once,
            // twice or part-way cannot be established from here at all.
            'hook_unverifiable' => MyFatoorahPaymentAttempt::query()
                ->where('status', AttemptStatus::Paid->value)
                ->whereNotNull('settled_at')
                ->whereNull('hook_completed_at')
                ->whereNotNull('hook_started_at')
                ->count(),

            /*
             * Attempts whose create call never came back and which MyFatoorah will not settle the
             * question of — typically because this account does not serve the invoice-lookup path. Each
             * one holds its order's live slot, so the customer cannot start another payment until
             * someone checks the portal and releases it.
             */
            'in_doubt' => MyFatoorahPaymentAttempt::query()
                ->where('status', AttemptStatus::Created->value)
                ->whereNull('payment_url')
                ->whereNotNull('live_slot')
                ->where('created_at', '<=', now()->subSeconds(max(1, (int) config('myfatoorah.attempt_in_doubt_grace_seconds', 70))))
                ->count(),
        ];
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
