<?php

namespace App\Services\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records a webhook delivery and, if it is new, acts on it.
 *
 * The one idea that makes repeated deliveries harmless: a delivery is written to
 * `myfatoorah_webhook_events` FIRST, and `dedupe_key` is unique. MyFatoorah retries up to five times
 * and its own documentation warns that two events may arrive for one transaction, so the second write
 * of an event loses the insert and the processor stops there. Ten identical deliveries produce one
 * insert and nine no-ops, and because the referee is a unique index rather than a SELECT-then-INSERT,
 * that holds when the deliveries land in different processes at the same moment.
 *
 * Order does not matter either. The webhook and the customer's redirect race, and both ask
 * PaymentVerificationService what happened and then PaymentSettlementService to apply it; whichever
 * arrives first does the work and the other finds it done. There is no "webhook first" or "redirect
 * first" path to get wrong.
 *
 * A verified signature is never treated as evidence of the amount — MyFatoorah signs only five fields
 * and the amount is not one of them. So a delivery only ever triggers a server-to-server read.
 */
class WebhookProcessor
{
    public function __construct(
        private readonly WebhookSignatureVerifier $verifier,
        private readonly PaymentVerificationService $verification,
        private readonly PaymentSettlementService $settlement,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array{result: string, note: string, http: int}
     */
    public function handle(array $payload, string $rawBody): array
    {
        $eventCode = $this->verifier->eventCode($payload);
        $data = is_array($payload['Data'] ?? null) ? $payload['Data'] : [];

        $invoiceId = $this->str($data, 'Invoice.Id');
        $paymentId = $this->str($data, 'Transaction.PaymentId');
        $externalIdentifier = $this->str($data, 'Invoice.ExternalIdentifier');
        $invoiceStatus = strtoupper((string) $this->str($data, 'Invoice.Status'));
        $transactionStatus = strtoupper((string) $this->str($data, 'Transaction.Status'));

        $event = new MyFatoorahWebhookEvent([
            'event_code' => $eventCode,
            'event_name' => Str::limit((string) ($payload['Event']['Name'] ?? ''), 60, ''),
            'event_reference' => Str::limit((string) ($payload['Event']['Reference'] ?? ''), 60, ''),
            'event_created_at' => $this->parseDate($payload['Event']['CreationDate'] ?? null),
            'external_identifier' => $externalIdentifier,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'invoice_status' => $invoiceStatus !== '' ? $invoiceStatus : null,
            'transaction_status' => $transactionStatus !== '' ? $transactionStatus : null,
            'dedupe_key' => $this->dedupeKey($payload, $eventCode, $invoiceId, $paymentId, $invoiceStatus, $transactionStatus),
            // Proves two deliveries were byte-identical without keeping the body, which carries a
            // cardholder name and a masked card number we have no reason to store.
            'payload_digest' => hash('sha256', $rawBody),
            'received_at' => now(),
        ]);

        try {
            $event->save();
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                // A retry of something we already have. Answer 200 so MyFatoorah stops retrying.
                return ['result' => 'duplicate', 'note' => 'This event was already received.', 'http' => 200];
            }

            throw $e;
        }

        $outcome = match ($eventCode) {
            MyFatoorahWebhookEvent::EVENT_PAYMENT_STATUS_CHANGED => $this->handlePaymentStatusChanged($externalIdentifier, $paymentId, $invoiceId),
            default => ['result' => 'unsupported', 'note' => 'This event type is recorded but not acted on.'],
        };

        $event->forceFill([
            'processed_at' => now(),
            'process_result' => $outcome['result'],
            'process_note' => Str::limit($outcome['note'], 240, ''),
        ])->save();

        return $outcome + ['http' => 200];
    }

    /**
     * A payment changed state. Find the attempt, verify it properly, settle it if it is genuinely paid.
     *
     * @return array{result: string, note: string}
     */
    private function handlePaymentStatusChanged(?string $externalIdentifier, ?string $paymentId, ?string $invoiceId): array
    {
        $attempt = $this->findAttempt($externalIdentifier, $paymentId, $invoiceId);

        if ($attempt === null) {
            // Not ours, or a payment created by another installation sharing the merchant account. Not
            // an error: answering 200 stops pointless retries.
            return ['result' => 'no_attempt', 'note' => 'No local payment attempt matches this event.'];
        }

        // A payment we have already confirmed cannot be moved by a later notice. MyFatoorah's own
        // guidance is that if any event for a transaction says SUCCESS, that is the answer — so a FAILED
        // notice arriving afterwards describes a different, earlier try at the same invoice.
        if ($attempt->statusEnum()->isLockedAgainstDowngrade() && $attempt->hook_completed_at !== null) {
            return ['result' => 'already_settled', 'note' => 'This payment was already confirmed and applied.'];
        }

        $verified = $this->verification->verify($attempt, $paymentId);

        if ($verified['unknown']) {
            // We could not reach MyFatoorah. Deliberately NOT a failure: the failure hook must never run
            // because of a network problem. Reconciliation will pick this attempt up.
            return ['result' => 'pending', 'note' => 'Could not confirm with MyFatoorah yet; left for reconciliation.'];
        }

        if ($verified['status'] === AttemptStatus::Mismatch) {
            return ['result' => 'mismatch', 'note' => $verified['message']];
        }

        if ($verified['status'] !== AttemptStatus::Paid) {
            // Genuinely not paid. The failure hook is not called from here: a customer may still retry
            // the same order, and running it would tear down a checkout that is still live.
            return ['result' => 'not_paid', 'note' => $verified['message']];
        }

        $settled = $this->settlement->settle($verified['attempt']);

        if ($settled['settled']) {
            return ['result' => 'settled', 'note' => 'Payment confirmed and applied.'];
        }

        return [
            'result' => $settled['already'] ? 'already_settled' : 'settle_failed',
            'note' => $settled['reason'],
        ];
    }


    /**
     * Find the attempt this event is about.
     *
     * `external_identifier` is ours, is unique, and MyFatoorah echoes it back, so it is tried first and
     * is the only one that cannot be ambiguous. The payment id is next (unique in our table too). The
     * invoice id is last and is matched only when it identifies exactly one attempt: an invoice can
     * carry several transactions, so a second attempt on the same invoice would otherwise be settled
     * against the wrong row.
     */
    private function findAttempt(?string $externalIdentifier, ?string $paymentId, ?string $invoiceId): ?MyFatoorahPaymentAttempt
    {
        if ($externalIdentifier !== null) {
            $byReference = MyFatoorahPaymentAttempt::query()->where('external_identifier', $externalIdentifier)->first();

            if ($byReference !== null) {
                return $byReference;
            }

            /*
             * The event names a reference and we have no such attempt. Stop here rather than trying the
             * looser matches below: the reference is ours and is the authoritative link, so one that
             * matches nothing means this event is not about a payment of ours — most likely another
             * installation sharing the merchant account. Falling through to the invoice id would match
             * whichever of OUR attempts happened to share it and settle the wrong order.
             */
            return null;
        }

        if ($paymentId !== null) {
            $byPayment = MyFatoorahPaymentAttempt::query()->where('payment_id', $paymentId)->first();

            if ($byPayment !== null) {
                return $byPayment;
            }
        }

        if ($invoiceId !== null) {
            $matches = MyFatoorahPaymentAttempt::query()->where('invoice_id', $invoiceId)->limit(2)->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }

            if ($matches->count() > 1) {
                Log::warning('myfatoorah.ambiguous_invoice_in_webhook', ['invoice_id' => $invoiceId]);
            }
        }

        return null;
    }

    /**
     * What makes two deliveries "the same".
     *
     * Both statuses are part of the key, so a genuine transition (PENDING then PAID) is a different
     * event and is processed, while a retry of one we already have is not. `Event.Reference` is included
     * when MyFatoorah supplies it, since that is their own id for the delivery.
     */
    private function dedupeKey(array $payload, ?int $eventCode, ?string $invoiceId, ?string $paymentId, string $invoiceStatus, string $transactionStatus): string
    {
        $reference = (string) ($payload['Event']['Reference'] ?? '');

        $parts = [
            'e' . ($eventCode ?? 0),
            $reference !== '' ? 'r' . $reference : 'r-',
            'i' . ($invoiceId ?? '-'),
            'p' . ($paymentId ?? '-'),
            's' . $invoiceStatus . '/' . $transactionStatus,
        ];

        $key = implode('|', $parts);

        // The column is 191 characters; hash anything longer so a long provider reference cannot cause
        // an insert to fail (which would make us retry forever) or get silently truncated into a
        // collision with a different event.
        return strlen($key) <= 191 ? $key : 'h:' . hash('sha256', $key);
    }

    private function str(array $data, string $path): ?string
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        if ($value === null || is_array($value) || $value === '') {
            return null;
        }

        return Str::limit((string) $value, 64, '');
    }

    private function parseDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isDuplicate(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $code === '1062' || str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
