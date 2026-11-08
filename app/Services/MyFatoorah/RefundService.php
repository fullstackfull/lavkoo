<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahRefund;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Refunds, full or partial, against a MyFatoorah payment.
 *
 * A MyFatoorah refund is a REQUEST, not a transfer: `POST /v3/refunds` answers with Status "Pending"
 * and their finance team executes it after review. So `requested`/`pending` and `executed` are kept
 * strictly apart here, and nothing in the store may tell a customer their money is back until the
 * status is `executed`. Conflating the two is how a customer is told they were refunded a week before
 * they were.
 *
 * Two things this class will not allow:
 *
 *  - refunding more than was actually paid, counting requests still under review. The total is summed
 *    under a row lock on the attempt, in the same transaction as the insert, so two operators clicking
 *    at once cannot each pass a check the other invalidates;
 *  - refunding twice for the same decision. `external_identifier` is unique and is derived from the
 *    attempt plus a caller-supplied idempotency key, so a double-submitted form collides in the
 *    database instead of sending two refunds.
 *
 * Returning stock is deliberately NOT done here. Money going back and goods coming back are different
 * events with different evidence, and the store already has a returns workflow that owns the stock side.
 */
class RefundService
{
    public function __construct(
        private readonly GatewayConfigRepository $configRepo,
        private readonly MyFatoorahClient $client,
    ) {
    }

    /**
     * Ask MyFatoorah for a refund.
     *
     * @param string|null $idempotencyKey a stable key for THIS decision (an approval id, a refund
     *                                    request id). Two calls with the same key refund once.
     *
     * @return array{ok: bool, refund?: MyFatoorahRefund, error?: string, code?: string}
     */
    public function request(
        MyFatoorahPaymentAttempt $attempt,
        float $amount,
        ?string $comment = null,
        ?int $requestedBy = null,
        ?int $refundRequestId = null,
        ?int $orderId = null,
        ?string $idempotencyKey = null,
    ): array {
        if ($attempt->statusEnum() !== AttemptStatus::Paid) {
            return ['ok' => false, 'code' => 'not_paid', 'error' => 'Only a payment confirmed as paid can be refunded.'];
        }

        if (!is_string($attempt->payment_id) || $attempt->payment_id === '') {
            // `POST /v3/refunds` keys on PaymentId. Without one there is nothing to refund against.
            return ['ok' => false, 'code' => 'no_payment_id', 'error' => 'This payment has no MyFatoorah payment id to refund against.'];
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            return ['ok' => false, 'code' => 'bad_amount', 'error' => 'A refund amount must be greater than zero.'];
        }

        $reference = $this->refundReference($attempt, $idempotencyKey);

        // Reserve the refund and check the headroom in one transaction, under a lock on the attempt, so
        // the sum cannot change between the check and the insert.
        $reservation = $this->reserve($attempt, $amount, $reference, $comment, $requestedBy, $refundRequestId, $orderId);

        if (!$reservation['ok']) {
            return $reservation;
        }

        /** @var MyFatoorahRefund $refund */
        $refund = $reservation['refund'];

        if ($reservation['existing'] ?? false) {
            // Same decision, already sent. Report the refund we already have rather than sending again.
            return ['ok' => true, 'refund' => $refund];
        }

        $config = $this->configRepo->forMode((string) $attempt->mode);

        $response = $this->client->post($config, 'create_refund', [
            'PaymentId' => (string) $attempt->payment_id,
            'Amount' => $amount,
            'ExternalIdentifier' => $reference,
            'Comment' => Str::limit((string) ($comment ?? ''), 200, ''),
            // The store absorbs MyFatoorah's service charge rather than deducting it from the customer's
            // refund: a customer owed 100 gets 100.
            'ServiceChargeOnCustomer' => false,
        ]);

        if (!$response->succeeded) {
            if ($response->isUnknown()) {
                /*
                 * We do not know whether MyFatoorah accepted it. The row stays `requested` — NOT failed —
                 * because marking it failed would let an operator send a second refund for the same money.
                 * `refreshStatus()` resolves it from MyFatoorah's side.
                 */
                $refund->forceFill([
                    'last_error_code' => $response->errorCode,
                    'last_error_message' => 'Could not confirm with MyFatoorah; left as requested so it is not sent twice.',
                ])->save();

                Log::error('myfatoorah.refund_unknown', ['reference' => $reference, 'attempt' => $attempt->external_identifier]);

                return ['ok' => false, 'code' => 'unknown', 'error' => 'MyFatoorah could not be reached. The refund is recorded as requested and must be checked before retrying.'];
            }

            // A definite refusal means nothing was created, so the reservation is released and the
            // headroom returns.
            $refund->forceFill([
                'status' => MyFatoorahRefund::STATUS_FAILED,
                'last_error_code' => $response->errorCode,
                'last_error_message' => $response->message,
            ])->save();

            return ['ok' => false, 'code' => $response->errorCode ?? 'refused', 'error' => $response->message];
        }

        // v3 answers with Data.Refund.{Id,Reference,Status,InvoiceId}; v2 MakeRefund answers with
        // Data.{RefundId,RefundReference,RefundInvoiceId} and no status. Both are read so the service
        // keeps working if the account is only permitted the v2 endpoint.
        $status = strtolower((string) ($response->get('Refund.Status') ?? 'pending'));

        $refund->forceFill([
            'myfatoorah_refund_id' => $this->firstString([$response->get('Refund.Id'), $response->get('RefundId')]),
            'myfatoorah_reference' => $this->firstString([$response->get('Refund.Reference'), $response->get('RefundReference')]),
            'myfatoorah_invoice_id' => $this->firstString([$response->get('Refund.InvoiceId'), $response->get('RefundInvoiceId')]),
            'status' => $this->mapStatus($status),
            'requested_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return ['ok' => true, 'refund' => $refund->refresh()];
    }

    /**
     * Write the refund row and confirm there is money left to refund, atomically.
     *
     * @return array{ok: bool, refund?: MyFatoorahRefund, existing?: bool, code?: string, error?: string}
     */
    private function reserve(
        MyFatoorahPaymentAttempt $attempt,
        float $amount,
        string $reference,
        ?string $comment,
        ?int $requestedBy,
        ?int $refundRequestId,
        ?int $orderId,
    ): array {
        return DB::transaction(function () use ($attempt, $amount, $reference, $comment, $requestedBy, $refundRequestId, $orderId): array {
            /** @var MyFatoorahPaymentAttempt|null $locked */
            $locked = MyFatoorahPaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return ['ok' => false, 'code' => 'gone', 'error' => 'The payment attempt no longer exists.'];
            }

            $existing = MyFatoorahRefund::query()->where('external_identifier', $reference)->first();

            if ($existing !== null) {
                return ['ok' => true, 'refund' => $existing, 'existing' => true];
            }

            // What the customer actually paid — the verified figure, not the one we asked for.
            $paid = (float) ($locked->captured_amount ?? $locked->expected_amount);
            $alreadyAsked = (float) MyFatoorahRefund::query()
                ->where('payment_attempt_id', $locked->getKey())
                ->whereIn('status', MyFatoorahRefund::COUNTS_AGAINST_PAID)
                ->sum('amount');

            $remaining = round($paid - $alreadyAsked, 2);

            // Exact, in halalas: an epsilon fudge here would let a refund exceed the paid amount by a
            // fraction, and repeated partial refunds would accumulate that drift.
            if (Money::greaterThan($amount, $remaining)) {
                return [
                    'ok' => false,
                    'code' => 'exceeds_paid',
                    'error' => 'Only ' . number_format($remaining, 2) . ' ' . $locked->captured_currency . ' is left to refund on this payment.',
                ];
            }

            try {
                $refund = MyFatoorahRefund::create([
                    'payment_attempt_id' => $locked->getKey(),
                    'refund_request_id' => $refundRequestId,
                    'order_id' => $orderId,
                    'external_identifier' => $reference,
                    'amount' => number_format($amount, 2, '.', ''),
                    'currency' => (string) ($locked->captured_currency ?? $locked->expected_currency),
                    'status' => MyFatoorahRefund::STATUS_REQUESTED,
                    'requested_by' => $requestedBy,
                    'comment' => $comment !== null ? Str::limit($comment, 200, '') : null,
                ]);
            } catch (QueryException $e) {
                if (!$this->isDuplicate($e)) {
                    throw $e;
                }

                $refund = MyFatoorahRefund::query()->where('external_identifier', $reference)->first();

                return $refund !== null
                    ? ['ok' => true, 'refund' => $refund, 'existing' => true]
                    : ['ok' => false, 'code' => 'conflict', 'error' => 'A refund for this decision is already being created.'];
            }

            return ['ok' => true, 'refund' => $refund, 'existing' => false];
        });
    }

    /**
     * Re-read a refund's real state from MyFatoorah.
     *
     * Called when a REFUND_STATUS_CHANGED webhook arrives (whose contents we cannot authenticate, so it
     * is only a prompt) and by the reconciliation sweep.
     */
    public function refreshStatus(MyFatoorahRefund $refund): string
    {
        $attempt = $refund->attempt;

        if ($attempt === null) {
            return 'The payment for this refund no longer exists.';
        }

        // Nothing documented in the pages this integration was built from gives a refund-details path,
        // so the refund is re-read through the payment it belongs to: a fully refunded payment reports a
        // remaining value, which is enough to tell "executed" from "still pending".
        $config = $this->configRepo->forMode((string) $attempt->mode);
        $response = $this->client->get($config, 'payment_details', ['paymentId' => (string) $attempt->payment_id]);

        if (!$response->succeeded) {
            return 'Could not reach MyFatoorah to check this refund.';
        }

        // Left as a provider-confirmed read rather than inferred: without a documented refund-status
        // endpoint, promoting a refund to `executed` from indirect evidence could tell a customer their
        // money is back when it is not. An operator confirms it from the MyFatoorah portal instead.
        $refund->forceFill([
            'status' => $refund->status === MyFatoorahRefund::STATUS_REQUESTED
                ? MyFatoorahRefund::STATUS_PENDING
                : $refund->status,
        ])->save();

        return 'Refund is still awaiting execution by MyFatoorah.';
    }

    /** Mark a refund executed. Only an operator confirming against the MyFatoorah portal may do this. */
    public function markExecuted(MyFatoorahRefund $refund, ?int $approvedBy = null): void
    {
        $refund->forceFill([
            'status' => MyFatoorahRefund::STATUS_EXECUTED,
            'approved_by' => $approvedBy,
            'executed_at' => now(),
        ])->save();
    }

    /**
     * Our reference for one refund decision.
     *
     * With an idempotency key, the reference is a function of it, so the same decision always produces
     * the same reference and the unique index catches a repeat. Without one, a random suffix is used:
     * an ad-hoc refund has no natural key, and silently merging two deliberate partial refunds of the
     * same amount would be worse than sending both.
     */
    private function refundReference(MyFatoorahPaymentAttempt $attempt, ?string $idempotencyKey): string
    {
        $basis = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : Str::random(16);

        return 'rf-' . substr(hash('sha256', $attempt->external_identifier . '|' . $basis), 0, 40);
    }

    private function mapStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'executed', 'completed', 'refunded', 'success' => MyFatoorahRefund::STATUS_EXECUTED,
            'rejected', 'declined', 'canceled', 'cancelled' => MyFatoorahRefund::STATUS_REJECTED,
            default => MyFatoorahRefund::STATUS_PENDING,
        };
    }

    private function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && !is_array($candidate)) {
                return Str::limit((string) $candidate, 64, '');
            }
        }

        return null;
    }

    private function isDuplicate(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $code === '1062' || str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
