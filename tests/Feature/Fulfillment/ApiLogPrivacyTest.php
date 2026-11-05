<?php

namespace Tests\Feature\Fulfillment;

use App\Models\ProviderApiLog;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFulfillmentClient;
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

    public function test_an_order_list_with_keys_nobody_has_seen_leaves_no_name_address_id_or_coordinates_in_the_log(): void
    {
        $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/order*' => Http::response(['data' => [[
            'id' => 9, 'status' => 'PICKED', 'receiver' => ['name' => 'Sara Alharbi', 'line1' => 'King Fahd Rd 12, Olaya'], 'consignee' => ['title' => 'Mrs Sara Alharbi'],
            'to' => 'Sara Alharbi, King Fahd Rd 12, Riyadh', 'national_id' => '1012345678', 'contact_no' => 551234567, 'lat' => 24.7136, 'lng' => 46.6753, 'courier' => 'Khalid',
        ]]])]);

        app(TorodFulfillmentClient::class)->listOrders(1, 20);

        $log = ProviderApiLog::where('operation', 'fulfillment_order_list')->firstOrFail();
        foreach (['Sara', 'King Fahd', 'Olaya', '1012345678', '551234567', '24.7136', '46.6753', 'Khalid'] as $personal) {
            $this->assertStringNotContainsString($personal, (string) $log->response_summary, "'{$personal}' reached the API log");
        }
        $this->assertStringContainsString('PICKED', (string) $log->response_summary, 'the status word is what a person debugging needs');
    }

    public function test_a_catalogue_answer_stays_readable_in_the_log_because_no_person_is_in_it(): void
    {
        $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/product*' => Http::response(['data' => [['id' => 1421521, 'name' => 'Vitamin C 1000mg', 'sku' => '13222', 'stock' => 40]]])]);

        app(TorodFulfillmentClient::class)->listProducts(1, 50);

        $log = ProviderApiLog::where('operation', 'fulfillment_product_list')->firstOrFail();
        $this->assertStringContainsString('Vitamin C 1000mg', (string) $log->response_summary);
    }

    public function test_a_refusal_that_echoes_a_phone_number_or_an_email_is_scrubbed_on_the_shipment_too(): void
    {
        $fulfillment = $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['message' => 'Invalid receiver phone 0551234567 for sara@example.test, password=hunter2'], 422)]);

        app(\App\Services\Fulfillment\ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $kept = (string) $fulfillment->fresh()->last_error_message;
        foreach (['0551234567', 'sara@example.test', 'hunter2'] as $personal) {
            $this->assertStringNotContainsString($personal, $kept, "'{$personal}' was kept on the shipment page");
        }
        $this->assertStringContainsString('Invalid receiver phone', $kept, 'what a person debugging needs is kept');
    }
}
