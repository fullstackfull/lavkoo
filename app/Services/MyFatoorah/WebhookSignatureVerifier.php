<?php

namespace App\Services\MyFatoorah;

use App\Models\MyFatoorahWebhookEvent;

/**
 * Checks that a webhook delivery really came from MyFatoorah.
 *
 * MyFatoorah's scheme (docs.myfatoorah.com/docs/webhook-v2, /docs/webhook-signature):
 *
 *   - the signature travels in the `MyFatoorah-Signature` header;
 *   - it is HMAC-SHA256 of a string built from the payload, keyed with the portal's secret key used as
 *     RAW BINARY, and the result is base64-encoded;
 *   - the signed string is `key=value` pairs joined by commas with no spaces, in the field order the
 *     documentation gives for that event type, with a null rendered as an empty string.
 *
 * The documented example for PAYMENT_STATUS_CHANGED is:
 *
 *   Invoice.Id=6409988,Invoice.Status=PAID,Transaction.Status=SUCCESS,
 *   Transaction.PaymentId=07076409988323998875,Invoice.ExternalIdentifier=asdqwd-f13sdf-fasjkz
 *
 * so the order is the documented field list, not alphabetical.
 *
 * Two deliberate limits:
 *
 *  1. Only five fields are signed, and the AMOUNT IS NOT ONE OF THEM. A valid signature therefore proves
 *     the notification is MyFatoorah's, and nothing about how much was paid. That is why a verified
 *     webhook still only triggers a server-to-server read rather than being believed on its own.
 *
 *  2. The signed field order is published only for PAYMENT_STATUS_CHANGED. For any other event type we
 *     cannot compute the string, so we cannot verify it — and an unverifiable event is never acted on.
 *     It is stored and used only as a hint to go and ask the API, which is safe because asking changes
 *     nothing.
 */
class WebhookSignatureVerifier
{
    /**
     * Fields, in order, that MyFatoorah signs for each event type we can verify.
     *
     * @var array<int, list<string>>
     */
    private const SIGNED_FIELDS = [
        MyFatoorahWebhookEvent::EVENT_PAYMENT_STATUS_CHANGED => [
            'Invoice.Id',
            'Invoice.Status',
            'Transaction.Status',
            'Transaction.PaymentId',
            'Invoice.ExternalIdentifier',
        ],
    ];

    /** Can a signature be computed for this event type at all? */
    public function canVerify(?int $eventCode): bool
    {
        return $eventCode !== null && isset(self::SIGNED_FIELDS[$eventCode]);
    }

    /**
     * @param array<string,mixed> $payload the decoded delivery
     */
    public function verify(array $payload, ?string $providedSignature, ?string $secret): bool
    {
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        if (!is_string($providedSignature) || trim($providedSignature) === '') {
            return false;
        }

        $eventCode = $this->eventCode($payload);

        if (!$this->canVerify($eventCode)) {
            return false;
        }

        $expected = $this->expectedSignature($payload, $eventCode, $secret);

        // Constant time: a timing-sensitive comparison would let a signature be guessed byte by byte.
        return hash_equals($expected, trim($providedSignature));
    }

    /** The base64 HMAC MyFatoorah should have sent for this payload. */
    public function expectedSignature(array $payload, int $eventCode, string $secret): string
    {
        $signedString = $this->signedString($payload, $eventCode);

        // `true` for raw binary output, then base64 — the order the documentation specifies.
        return base64_encode(hash_hmac('sha256', $signedString, $secret, true));
    }

    /** The exact string MyFatoorah signs, for this event type. */
    public function signedString(array $payload, int $eventCode): string
    {
        $data = is_array($payload['Data'] ?? null) ? $payload['Data'] : [];
        $pairs = [];

        foreach (self::SIGNED_FIELDS[$eventCode] ?? [] as $field) {
            $pairs[] = $field . '=' . $this->render($this->dig($data, $field));
        }

        return implode(',', $pairs);
    }

    public function eventCode(array $payload): ?int
    {
        $code = $payload['Event']['Code'] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }

    /** Dotted lookup inside the Data object. */
    private function dig(array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /** A null becomes an empty string; everything else is its plain string form. */
    private function render(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return '';
        }

        return (string) $value;
    }
}
