<?php

namespace Tests\Unit\MyFatoorah;

use App\Models\MyFatoorahWebhookEvent;
use App\Services\MyFatoorah\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The webhook signature, checked against MyFatoorah's own worked example.
 *
 * This is the one piece of the integration that cannot be inferred from behaviour: either the string we
 * build is byte-identical to the one MyFatoorah signs, or every real delivery is rejected. The
 * documentation gives one example string, so that is what this pins.
 */
class WebhookSignatureTest extends TestCase
{
    private WebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new WebhookSignatureVerifier();
    }

    /** The exact string from docs.myfatoorah.com/docs/webhook-v2. */
    public function test_the_signed_string_matches_the_documented_example(): void
    {
        $payload = [
            'Event' => ['Code' => 1, 'Name' => 'PAYMENT_STATUS_CHANGED'],
            'Data' => [
                'Invoice' => [
                    'Id' => '6409988',
                    'Status' => 'PAID',
                    'ExternalIdentifier' => 'asdqwd-f13sdf-fasjkz',
                ],
                'Transaction' => [
                    'Status' => 'SUCCESS',
                    'PaymentId' => '07076409988323998875',
                ],
            ],
        ];

        $this->assertSame(
            'Invoice.Id=6409988,Invoice.Status=PAID,Transaction.Status=SUCCESS,Transaction.PaymentId=07076409988323998875,Invoice.ExternalIdentifier=asdqwd-f13sdf-fasjkz',
            $this->verifier->signedString($payload, MyFatoorahWebhookEvent::EVENT_PAYMENT_STATUS_CHANGED),
        );
    }

    public function test_the_field_order_is_the_documented_one_not_alphabetical(): void
    {
        // MyFatoorah's example is not sorted. Sorting it would produce a signature that never matches.
        $payload = [
            'Event' => ['Code' => 1],
            'Data' => [
                'Invoice' => ['Id' => '1', 'Status' => 'PAID', 'ExternalIdentifier' => 'z'],
                'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => '9'],
            ],
        ];

        $string = $this->verifier->signedString($payload, 1);
        $fields = array_map(static fn (string $pair): string => explode('=', $pair)[0], explode(',', $string));

        $this->assertSame([
            'Invoice.Id',
            'Invoice.Status',
            'Transaction.Status',
            'Transaction.PaymentId',
            'Invoice.ExternalIdentifier',
        ], $fields);
    }

    public function test_a_null_field_becomes_an_empty_string(): void
    {
        $payload = [
            'Event' => ['Code' => 1],
            'Data' => [
                'Invoice' => ['Id' => '6409988', 'Status' => 'PAID', 'ExternalIdentifier' => null],
                'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => null],
            ],
        ];

        $this->assertSame(
            'Invoice.Id=6409988,Invoice.Status=PAID,Transaction.Status=SUCCESS,Transaction.PaymentId=,Invoice.ExternalIdentifier=',
            $this->verifier->signedString($payload, 1),
        );
    }

    public function test_a_missing_field_is_treated_the_same_as_a_null_one(): void
    {
        $payload = ['Event' => ['Code' => 1], 'Data' => ['Invoice' => ['Id' => '1']]];

        $this->assertSame(
            'Invoice.Id=1,Invoice.Status=,Transaction.Status=,Transaction.PaymentId=,Invoice.ExternalIdentifier=',
            $this->verifier->signedString($payload, 1),
        );
    }

    /** HMAC-SHA256, secret as raw binary, result base64-encoded — in that order. */
    public function test_the_signature_is_base64_of_a_raw_binary_hmac(): void
    {
        $payload = [
            'Event' => ['Code' => 1],
            'Data' => [
                'Invoice' => ['Id' => '6409988', 'Status' => 'PAID', 'ExternalIdentifier' => 'asdqwd-f13sdf-fasjkz'],
                'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => '07076409988323998875'],
            ],
        ];

        $secret = 'a-portal-secret-key';
        $expected = base64_encode(hash_hmac(
            'sha256',
            $this->verifier->signedString($payload, 1),
            $secret,
            true,
        ));

        $this->assertSame($expected, $this->verifier->expectedSignature($payload, 1, $secret));
        $this->assertTrue($this->verifier->verify($payload, $expected, $secret));

        // Hex rather than base64 — the mistake that silently rejects every delivery.
        $this->assertFalse($this->verifier->verify($payload, hash_hmac('sha256', $this->verifier->signedString($payload, 1), $secret), $secret));
    }

    public function test_a_changed_field_invalidates_the_signature(): void
    {
        $payload = [
            'Event' => ['Code' => 1],
            'Data' => [
                'Invoice' => ['Id' => '1', 'Status' => 'PAID', 'ExternalIdentifier' => 'ours'],
                'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => '9'],
            ],
        ];

        $signature = $this->verifier->expectedSignature($payload, 1, 'secret');

        foreach ([
            ['Invoice', 'ExternalIdentifier', 'someone-elses'],
            ['Invoice', 'Status', 'PENDING'],
            ['Transaction', 'Status', 'FAILED'],
            ['Transaction', 'PaymentId', '10'],
            ['Invoice', 'Id', '2'],
        ] as [$group, $field, $value]) {
            $tampered = $payload;
            $tampered['Data'][$group][$field] = $value;

            $this->assertFalse(
                $this->verifier->verify($tampered, $signature, 'secret'),
                "changing Data.{$group}.{$field} should invalidate the signature",
            );
        }
    }

    public function test_a_signature_from_the_wrong_secret_is_rejected(): void
    {
        $payload = [
            'Event' => ['Code' => 1],
            'Data' => [
                'Invoice' => ['Id' => '1', 'Status' => 'PAID', 'ExternalIdentifier' => 'x'],
                'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => '9'],
            ],
        ];

        $signature = $this->verifier->expectedSignature($payload, 1, 'the-right-secret');

        $this->assertFalse($this->verifier->verify($payload, $signature, 'the-wrong-secret'));
    }

    public function test_an_empty_or_missing_signature_or_secret_is_rejected(): void
    {
        $payload = ['Event' => ['Code' => 1], 'Data' => ['Invoice' => ['Id' => '1']]];

        $this->assertFalse($this->verifier->verify($payload, null, 'secret'));
        $this->assertFalse($this->verifier->verify($payload, '', 'secret'));
        $this->assertFalse($this->verifier->verify($payload, '   ', 'secret'));
        $this->assertFalse($this->verifier->verify($payload, 'anything', null));
        $this->assertFalse($this->verifier->verify($payload, 'anything', ''));
    }

    public function test_only_the_payment_event_can_be_verified_at_all(): void
    {
        // MyFatoorah publishes the signed-field order for PAYMENT_STATUS_CHANGED only. An event type we
        // cannot build a string for cannot be authenticated, and is never acted on from its contents.
        $this->assertTrue($this->verifier->canVerify(MyFatoorahWebhookEvent::EVENT_PAYMENT_STATUS_CHANGED));

        foreach ([
            MyFatoorahWebhookEvent::EVENT_REFUND_STATUS_CHANGED,
            MyFatoorahWebhookEvent::EVENT_BALANCE_TRANSFERRED,
            MyFatoorahWebhookEvent::EVENT_SUPPLIER_STATUS_CHANGED,
            MyFatoorahWebhookEvent::EVENT_RECURRING_UPDATES,
            MyFatoorahWebhookEvent::EVENT_DISPUTE_STATUS_CHANGED,
            MyFatoorahWebhookEvent::EVENT_SUPPLIER_UPDATE_REQUEST_CHANGED,
        ] as $code) {
            $this->assertFalse($this->verifier->canVerify($code));
        }

        $this->assertFalse($this->verifier->canVerify(null));
    }

    public function test_the_event_code_is_read_from_the_envelope(): void
    {
        $this->assertSame(1, $this->verifier->eventCode(['Event' => ['Code' => 1]]));
        $this->assertSame(2, $this->verifier->eventCode(['Event' => ['Code' => '2']]));
        $this->assertNull($this->verifier->eventCode([]));
        $this->assertNull($this->verifier->eventCode(['Event' => ['Name' => 'PAYMENT_STATUS_CHANGED']]));
    }
}
