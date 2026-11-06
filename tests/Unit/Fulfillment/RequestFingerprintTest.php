<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\RequestFingerprint;
use Tests\TestCase;

/** The fingerprint beside a recorded request says two requests differ without letting anyone work back to the phone number masked above it. */
class RequestFingerprintTest extends TestCase
{
    private const PAYLOAD = ['receiverName' => 'Sara Alharbi', 'receiverPhone' => '00966551234567', 'items' => [['sku' => '13222', 'quantity' => 2]]];

    public function test_the_same_request_always_has_the_same_fingerprint_and_another_request_another(): void
    {
        $this->assertSame(RequestFingerprint::of(self::PAYLOAD), RequestFingerprint::of(self::PAYLOAD));
        $this->assertNotSame(RequestFingerprint::of(self::PAYLOAD), RequestFingerprint::of(['receiverPhone' => '00966551234568'] + self::PAYLOAD));
    }

    public function test_it_cannot_be_checked_by_hashing_guessed_phone_numbers_because_it_is_keyed(): void
    {
        $plain = hash('sha256', (string) json_encode(self::PAYLOAD, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertNotSame($plain, RequestFingerprint::of(self::PAYLOAD), 'a plain hash would give the masked phone back to whoever tries every candidate');

        $first = RequestFingerprint::of(self::PAYLOAD);
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        $this->assertNotSame($first, RequestFingerprint::of(self::PAYLOAD), 'it depends on the key the attacker does not have');
    }
}
