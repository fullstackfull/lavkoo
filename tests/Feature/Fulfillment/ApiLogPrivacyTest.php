<?php

namespace Tests\Feature\Fulfillment;

use App\Models\ProviderApiLog;
use Illuminate\Support\Facades\Http;

/**
 * The API log is read on an admin screen and may be copied into a report: it keeps what a person debugging a call needs (the
 * operation, the statuses, the parcel references, the city) and none of what identifies the customer.
 */
class ApiLogPrivacyTest extends TorodTestCase
{
    private const PERSONAL = ['Sara Alharbi', '0551234567', '00966551234567', 'King Fahd Rd 12', 'sara@example.test', 'Leave with the guard'];

    public function test_the_stored_request_and_response_hold_no_name_phone_address_or_note(): void
    {
        $fulfillment = $this->torodFulfillment(['order_note' => 'Leave with the guard']);
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response([
            'id' => 4455, 'barcode' => 'O0015030400001', 'receiverName' => 'Sara Alharbi', 'receiverPhone' => '00966551234567',
            'receiverAddress' => ['cityId' => 41125, 'addressLine1' => 'King Fahd Rd 12'], 'message' => 'created for sara@example.test',
        ])]);

        $this->adapter()->submit($fulfillment);

        $log = ProviderApiLog::where('operation', 'fulfillment_order_create')->firstOrFail();
        $stored = json_encode([$log->request_summary, $log->response_summary, $log->url, $log->error_message], JSON_UNESCAPED_UNICODE);
        foreach (self::PERSONAL as $personal) {
            $this->assertStringNotContainsString($personal, $stored, "'{$personal}' reached the API log");
        }
        $this->assertStringContainsString('100-TP1', $stored, 'our invoice number is kept');
        $this->assertStringContainsString('O0015030400001', $stored, 'the provider\'s barcode is kept');
        $this->assertStringContainsString('41125', $stored, 'the city is kept');
        $this->assertStringNotContainsString('wh-pass-secret-9', $stored);
    }

    public function test_a_provider_error_that_echoes_a_phone_number_is_masked(): void
    {
        $fulfillment = $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['message' => 'Invalid receiver phone 0551234567'], 422)]);

        $this->adapter()->submit($fulfillment);

        $log = ProviderApiLog::where('operation', 'fulfillment_order_create')->firstOrFail();
        $this->assertStringNotContainsString('0551234567', (string) $log->error_message);
        $this->assertStringNotContainsString('0551234567', (string) $log->response_summary);
    }
}
