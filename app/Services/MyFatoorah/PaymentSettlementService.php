<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Moves a verified payment into the store exactly once.
 *
 * This is the only place `payment_requests.is_paid` is flipped for MyFatoorah and the only place the
 * success hook is called. Everything that can learn a payment succeeded — the browser callback, a
 * webhook, the app polling for status, the reconciliation sweep — funnels through `settle()`, so
 * "exactly once" has one implementation to get right instead of four.
 *
 * How once-only is actually enforced, in order:
 *
 *  1. `settle()` refuses anything whose status is not `paid`. Only PaymentVerificationService sets that,
 *     and only after a server-to-server check of status, amount, currency and reference.
 *
 *  2. The right to run the hook is claimed in a transaction that locks the attempt row and the
 *     payment_requests row, and the UPDATE is conditional (`where is_paid = 0`). Four simultaneous
 *     deliveries all reach this; one UPDATE affects a row and three affect none. The loser does not
 *     call the hook. This is a database guarantee, not a check-then-act, so it holds across processes.
 *
 *  3. The hook runs OUTSIDE that transaction, because it creates an order, decrements stock and books a
 *     shipment with a courier — work that cannot be rolled back. Before it runs, the store is asked
 *     whether an order already carries this transaction reference; if one does, the hook already did its
 *     job on an earlier, interrupted run and is not called again. That check is what makes recovery safe
 *     to retry: it is the same question "did this already happen?" asked of the real outcome rather than
 *     of a flag.
 *
 *  4. `hook_completed_at` records that step 3 finished. An attempt with `settled_at` set and
 *     `hook_completed_at` null is an interrupted settlement, and `recover()` is what finishes it.
 */
class PaymentSettlementService
{
    public function __construct(private readonly GatewayConfigRepository $configRepo)
    {
    }

    /**
     * @return array{settled: bool, already: bool, reason: string, attempt: MyFatoorahPaymentAttempt}
     */
    public function settle(MyFatoorahPaymentAttempt $attempt): array
    {
        if ($attempt->statusEnum() !== AttemptStatus::Paid) {
            return $this->result(false, false, 'Only a payment verified as paid may be settled.', $attempt);
        }

        // Fast path for the common case — a retried webhook for an order that is already done — so the
        // repeated deliveries MyFatoorah makes do not take a row lock each time.
        if ($attempt->settled_at !== null && $attempt->hook_completed_at !== null) {
            return $this->result(false, true, 'This payment has already been applied.', $attempt);
        }

        $claim = $this->claim($attempt);

        if ($claim === 'paid_elsewhere') {
            // The payment request was settled by something else: another attempt at the same order, or
            // another gateway. Real money may still have arrived through this attempt, so it is flagged
            // rather than quietly dropped — a duplicate payment is a refund case, not a no-op.
            Log::error('myfatoorah.duplicate_payment_for_request', [
                'attempt' => $attempt->external_identifier,
                'payment_request_id' => $attempt->payment_request_id,
            ]);

            $attempt->forceFill([
                'status' => AttemptStatus::Mismatch->value,
                'last_error_code' => 'already_paid_elsewhere',
                'last_error_message' => 'This order was already paid by another payment attempt. Needs review for a possible double charge.',
            ])->save();

            return $this->result(false, true, 'This order was already paid by another attempt.', $attempt->refresh());
        }

        if ($claim === 'missing_request') {
            return $this->result(false, false, 'The payment request this attempt belongs to no longer exists.', $attempt);
        }

        $attempt->refresh();

        if ($attempt->hook_completed_at !== null) {
            return $this->result(false, true, 'This payment has already been applied.', $attempt);
        }

        $ran = $this->runSuccessHookOnce($attempt);

        return $this->result($ran['ran'], !$ran['ran'] && $ran['ok'], $ran['reason'], $attempt->refresh());
    }

    /**
     * Claim the right to apply this payment.
     *
     * @return 'claimed'|'already'|'paid_elsewhere'|'missing_request'
     */
    private function claim(MyFatoorahPaymentAttempt $attempt): string
    {
        return DB::transaction(function () use ($attempt): string {
            /** @var MyFatoorahPaymentAttempt|null $locked */
            $locked = MyFatoorahPaymentAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return 'missing_request';
            }

            if ($locked->settled_at !== null) {
                // Someone already claimed it. They are running the hook, or it is done.
                return 'already';
            }

            $paymentRequest = DB::table('payment_requests')
                ->where('id', $locked->payment_request_id)
                ->lockForUpdate()
                ->first();

            if ($paymentRequest === null) {
                return 'missing_request';
            }

            if ((int) ($paymentRequest->is_paid ?? 0) === 1) {
                // Paid already, and not by this attempt (this attempt has no settled_at).
                return 'paid_elsewhere';
            }

            $transactionId = $this->transactionId($locked);

            // Conditional on is_paid = 0: this is the write that exactly one caller can win.
            $flipped = DB::table('payment_requests')
                ->where('id', $locked->payment_request_id)
                ->where('is_paid', 0)
                ->update([
                    'is_paid' => 1,
                    'transaction_id' => $transactionId,
                    // Normalised to the canonical key so downstream code and reports see one name.
                    'payment_method' => GatewayConfigRepository::KEY,
                    'updated_at' => now(),
                ]);

            if ($flipped === 0) {
                return 'paid_elsewhere';
            }

            $locked->forceFill(['settled_at' => now()])->save();

            return 'claimed';
        });
    }

    /**
     * Run the success hook, unless the store shows it has already run or another process owns it.
     *
     * @return array{ran: bool, ok: bool, reason: string}
     */
    private function runSuccessHookOnce(MyFatoorahPaymentAttempt $attempt): array
    {
        $paymentRequest = PaymentRequest::find($attempt->payment_request_id);

        if ($paymentRequest === null) {
            return ['ran' => false, 'ok' => false, 'reason' => 'The payment request has disappeared.'];
        }

        if (!$this->leaseHook($attempt)) {
            /*
             * Another process is inside the hook right now. It must not be run twice concurrently:
             * `settled_at` only guarantees one caller marked the payment paid, and the
             * order-already-exists check below cannot help while the first caller is still on its way
             * to creating that order — which is most of the hook's work.
             */
            return ['ran' => false, 'ok' => true, 'reason' => 'This payment is already being applied.'];
        }

        $transactionId = (string) ($paymentRequest->transaction_id ?: $this->transactionId($attempt));

        // The idempotency boundary. `OrderManager::generateOrder` stamps every order it creates with
        // `transaction_ref`, so an order carrying this reference means the hook has already done its
        // work — even if the process died before it could say so. Asked of the outcome, not of a flag.
        if ($this->outcomeAlreadyExists($paymentRequest, $transactionId)) {
            $attempt->forceFill([
                'hook_completed_at' => $attempt->hook_completed_at ?? now(),
                'hook_started_at' => null,
                'hook_error' => null,
            ])->save();

            return ['ran' => false, 'ok' => true, 'reason' => 'The order for this payment already exists.'];
        }

        $hook = (string) ($paymentRequest->success_hook ?? '');

        if ($hook === '' || !function_exists($hook)) {
            $attempt->forceFill(['hook_error' => 'No success hook is registered for this payment.'])->save();
            Log::error('myfatoorah.missing_success_hook', ['attempt' => $attempt->external_identifier]);

            return ['ran' => false, 'ok' => false, 'reason' => 'No success hook is registered for this payment.'];
        }

        try {
            // The hook reads is_paid from the row it is handed, and `claim()` has already set it.
            call_user_func($hook, $paymentRequest->refresh());

            $attempt->forceFill([
                'hook_completed_at' => now(),
                'hook_started_at' => null,
                'hook_error' => null,
            ])->save();

            return ['ran' => true, 'ok' => true, 'reason' => 'Payment applied.'];
        } catch (\Throwable $e) {
            // The money is taken and the order did not complete. Left with settled_at set and
            // hook_completed_at null so `recover()` finds it, and logged loudly because this is the
            // state that needs a human if recovery cannot finish it either.
            // The lease is released so recovery can pick this up, but hook_attempts stays where the
            // lease left it, so a hook that keeps throwing is eventually given up on rather than
            // retried for ever.
            $attempt->forceFill([
                'hook_error' => Str::limit($e->getMessage(), 240, ''),
                'hook_started_at' => null,
            ])->save();

            Log::critical('myfatoorah.success_hook_failed', [
                'attempt' => $attempt->external_identifier,
                'payment_request_id' => $attempt->payment_request_id,
                'hook_attempts' => $attempt->hook_attempts,
                'exception' => $e::class,
            ]);

            return ['ran' => false, 'ok' => false, 'reason' => 'The payment was recorded but the order could not be completed.'];
        }
    }

    /**
     * Take the exclusive right to run this attempt's success hook.
     *
     * A conditional UPDATE, so the database decides: whoever's statement affects the row owns the hook
     * and everyone else is told it is in progress. This is what makes "exactly one order" true even when
     * a webhook and the customer's redirect land in the same moment — `settled_at` alone only made
     * "exactly one caller marks it paid" true.
     *
     * The lease goes stale, because a process killed mid-hook would otherwise hold it for ever and
     * recovery could never finish the order. Long enough that a slow-but-live hook is not interrupted,
     * short enough that recovery is not blocked for the rest of the day.
     */
    private function leaseHook(MyFatoorahPaymentAttempt $attempt, int $staleAfterMinutes = 10): bool
    {
        $claimed = DB::table('myfatoorah_payment_attempts')
            ->where('id', $attempt->getKey())
            ->whereNull('hook_completed_at')
            ->where(function ($query) use ($staleAfterMinutes): void {
                $query->whereNull('hook_started_at')
                    ->orWhere('hook_started_at', '<=', now()->subMinutes($staleAfterMinutes));
            })
            ->update([
                'hook_started_at' => now(),
                'hook_attempts' => DB::raw('hook_attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        // Re-read so the caller sees the counter the lease just incremented.
        $attempt->refresh();

        return true;
    }

    /**
     * Does the store already show the result of this hook?
     *
     * Wallet top-ups do not create an order, so for those the question cannot be asked this way and the
     * answer is no — their recovery relies on the claim lock alone, which is enough because a top-up is
     * a single balance write rather than a chain of side effects.
     */
    private function outcomeAlreadyExists(PaymentRequest $paymentRequest, string $transactionId): bool
    {
        if ($transactionId === '') {
            return false;
        }

        if ((string) ($paymentRequest->attribute ?? 'order') !== 'order') {
            return false;
        }

        try {
            // Deliberately the query builder, not the Order model. This is the check that decides
            // whether to run a hook that creates an order, and an Eloquent global scope — a soft
            // delete, a seller or branch restriction, anything added later — would hide an existing
            // order from it and produce a second one for the same payment. The raw table cannot be
            // narrowed by anything.
            return DB::table('orders')->where('transaction_ref', $transactionId)->exists();
        } catch (\Throwable $e) {
            // If the question cannot be answered, do not guess "no" — that risks a second order. Treat
            // it as "already exists" so recovery stops and a human looks instead.
            Log::error('myfatoorah.order_lookup_failed', ['exception' => $e::class]);

            return true;
        }
    }

    /**
     * Finish settlements that were interrupted after the money was recorded but before the order was
     * created. Safe to run repeatedly: each one goes through the same outcome check as a first run.
     *
     * @return array{recovered: int, still_broken: int, skipped: int}
     */
    public function recover(int $limit = 25, int $maxHookAttempts = 5): array
    {
        $stuck = MyFatoorahPaymentAttempt::query()
            ->whereNotNull('settled_at')
            ->whereNull('hook_completed_at')
            ->where('status', AttemptStatus::Paid->value)
            // Not one that a live process is inside right now: leaseHook() would refuse it anyway, but
            // skipping it here keeps the batch doing useful work instead of spending it on no-ops.
            ->where(function ($query): void {
                $query->whereNull('hook_started_at')
                    ->orWhere('hook_started_at', '<=', now()->subMinutes(10));
            })
            ->orderBy('settled_at')
            ->limit(max(1, $limit))
            ->get();

        $recovered = 0;
        $broken = 0;
        $skipped = 0;

        foreach ($stuck as $attempt) {
            if ((int) $attempt->hook_attempts >= $maxHookAttempts) {
                // Retried enough. Repeating a hook that keeps throwing will not fix it and each run
                // risks partial side effects, so it is left for an operator.
                $skipped++;
                continue;
            }

            $outcome = $this->runSuccessHookOnce($attempt);

            if ($outcome['ok']) {
                $recovered++;
            } else {
                $broken++;
            }
        }

        return ['recovered' => $recovered, 'still_broken' => $broken, 'skipped' => $skipped];
    }

    /**
     * The reference written to `payment_requests.transaction_id` and stamped onto the order.
     *
     * MyFatoorah's payment id is preferred because it is what appears on a settlement report and is what
     * an operator matches against. Our own attempt reference is the fallback so the field is never empty
     * — an empty transaction reference would make the order unmatchable and would break the duplicate
     * check above.
     */
    private function transactionId(MyFatoorahPaymentAttempt $attempt): string
    {
        foreach ([$attempt->payment_id, $attempt->invoice_id, $attempt->external_identifier] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return (string) $attempt->getKey();
    }

    private function result(bool $settled, bool $already, string $reason, MyFatoorahPaymentAttempt $attempt): array
    {
        return ['settled' => $settled, 'already' => $already, 'reason' => $reason, 'attempt' => $attempt];
    }
}
