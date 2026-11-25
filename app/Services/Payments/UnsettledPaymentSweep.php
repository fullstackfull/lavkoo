<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Finds payments the gateway took and this shop never heard about, and settles them (D2-12, D2-16).
 *
 * EVERY Paymob payment on this platform is settled by the CUSTOMER'S BROWSER coming back, and the
 * callback reads `session('payment_id')` to know which payment it is about -- so a customer who paid and
 * then closed the tab, lost signal, or was timed out of their session leaves the money with Paymob and
 * the payment row unpaid for ever. No order, no wallet credit, no record, and nothing on the platform
 * that notices. Paymob has the money and the shop does not know.
 *
 * The sweep is the server's own way of finding out, and it asks with the one reference the shop is sure
 * to hold: the payment's own id, which the payment start sends to Paymob as `special_reference`
 * precisely so that this is possible. Paymob's answer -- paid, refused, or nothing it can tell us -- is
 * read by the same code that reads a confirmed browser return, and a payment is settled through the same
 * service a return settles through, so a swept payment and a returned one are the same payment in the
 * same state.
 *
 * WHAT IT WILL NOT DO, and each is deliberate:
 *
 *   - it settles nothing the gateway does not say is PAID, with the amount and currency of the stored
 *     payment. A refusal and an unknown answer both leave the row exactly as it was;
 *   - it never fails a payment. A refusal is logged and left: running the failure hook from a sweep
 *     would move an order's state with no customer present and on the strength of one answer, and a
 *     payment that was never paid is already in the state a failure would leave it in;
 *   - it leaves the youngest payments alone. A customer at the gateway right now is not an abandoned
 *     payment, and settling one under them would have the shop and the browser settling at once;
 *   - it does nothing at all while it is switched off, which is how it ships.
 *
 * Settling twice is not prevented here and must not be: `SettlesAConfirmedPayment` holds the gateway's
 * own reference in a register under a unique key and sets the paid flag only while it is unset, so a
 * sweep racing a browser return settles once between them. A second guard here would be a second answer
 * to a question that already has one.
 */
#[Singleton]
final class UnsettledPaymentSweep
{
    /** The gateway this sweep can ask, which is the one with a server-to-server inquiry wired end to end. */
    public const GATEWAY = 'paymob_accept';

    public function __construct(
        private readonly PaymobTransactionInquiry $inquiry,
        private readonly PaymentAccountBinding $binding,
        private readonly SettlesAConfirmedPayment $settlement,
    ) {
    }

    /** Off as it ships: nothing is asked and nothing is settled until an operator turns it on. */
    public function isEnabled(): bool
    {
        return (bool) config('gcc.payments.recovery_sweep.enabled');
    }

    /**
     * @return array<string, int> what the run found, by outcome: considered, settled, refused, unknown, unaskable
     */
    public function sweep(bool $dryRun = false, ?int $limit = null): array
    {
        $found = ['considered' => 0, 'settled' => 0, 'refused' => 0, 'unknown' => 0, 'unaskable' => 0];

        if (!$this->isEnabled()) {
            return $found;
        }

        foreach ($this->candidates(limit: $limit) as $payment) {
            $found['considered']++;

            $account = $this->binding->accountOf(payment: $payment);

            if (!$this->inquiry->canAsk(account: $account)) {
                $found['unaskable']++;
                continue;
            }

            $state = $this->inquiry->transactionOfOurReference(account: $account, ourReference: (string) $payment->id);

            if ($state->isRefused()) {
                $found['refused']++;
                Log::info('payments.sweep_found_a_refused_payment', ['payment' => (string) $payment->id]);
                continue;
            }

            if (!$state->isPaid()) {
                $found['unknown']++;
                continue;
            }

            if ($dryRun) {
                $found['settled']++;
                continue;
            }

            $settled = $this->settlement->settle(
                gateway: self::GATEWAY,
                paymentId: (string) $payment->id,
                reference: (string) $state->reference,
                minorUnits: $state->minorUnits,
                currency: (string) $state->currency,
            );

            if ($settled === null) {
                // The confirmation settled no payment: another delivery got there first, or the amount
                // Paymob names is not this payment's -- which GatewayConfirmations has already logged as
                // critical, because a confirmation for another amount is not a near miss.
                $found['unknown']++;
                continue;
            }

            $found['settled']++;
            Log::warning('payments.sweep_settled_a_payment_nobody_returned_from', [
                'payment' => (string) $settled->id,
                'reference' => (string) $state->reference,
            ]);

            $this->runTheSuccessHook(payment: $settled);
        }

        return $found;
    }

    /**
     * What the hook a browser return would have run does: the order, the wallet credit, the records.
     *
     * Called OUTSIDE the settling transaction, exactly as the browser return calls it, so a sweep cannot
     * give the hook a different transactional context from the one it has always run in -- the hook opens
     * its own transactions and `generateOrder` is one of them.
     */
    private function runTheSuccessHook(PaymentRequest $payment): void
    {
        $hook = (string) $payment->success_hook;

        if ($hook === '' || !function_exists($hook)) {
            Log::warning('payments.sweep_settled_a_payment_with_no_hook', ['payment' => (string) $payment->id, 'hook' => $hook]);

            return;
        }

        call_user_func($hook, $payment);
    }

    /**
     * The payments worth asking about: this gateway's, unpaid, old enough that nobody is still at the
     * gateway, and young enough that the gateway still answers about them.
     *
     * Oldest first, because the oldest unsettled payment is the one that has been owed longest, and
     * because a run that is cut short by its limit then leaves the newest behind rather than the oldest.
     *
     * @return \Illuminate\Support\Collection<int, PaymentRequest>
     */
    private function candidates(?int $limit = null)
    {
        $now = Carbon::now();

        return PaymentRequest::query()
            ->where('payment_method', self::GATEWAY)
            ->where('is_paid', 0)
            ->where('created_at', '<=', $now->copy()->subMinutes($this->minimumAgeInMinutes()))
            ->where('created_at', '>=', $now->copy()->subDays($this->oldestInDays()))
            ->orderBy('created_at')
            ->limit($limit ?? $this->batchSize())
            ->get();
    }

    private function minimumAgeInMinutes(): int
    {
        return max(1, (int) config('gcc.payments.recovery_sweep.minimum_age_minutes', 15));
    }

    private function oldestInDays(): int
    {
        return max(1, (int) config('gcc.payments.recovery_sweep.oldest_days', 7));
    }

    private function batchSize(): int
    {
        return max(1, (int) config('gcc.payments.recovery_sweep.batch', 200));
    }
}
