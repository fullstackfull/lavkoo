<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\SalesChannels\Dispatch\BuyerEchoScrubber;
use Illuminate\Support\Facades\DB;

/** What a provider's error text repeats of the buyer is taken out of every stored copy, and only that. */
class BuyerEchoScrubberTest extends DispatchTestCase
{
    private function dispatched(): ProviderFulfillment
    {
        $orderId = (int) $this->dispatcher()->dispatch($this->placed())->internalOrderId;
        app(FulfillmentRouter::class)->route($orderId);

        return ProviderFulfillment::where('order_id', $orderId)->firstOrFail();
    }

    private function logLine(ProviderFulfillment $fulfillment, string $error): void
    {
        DB::table('provider_api_logs')->insert([
            'provider' => 'torod_point', 'operation' => 'fulfillment_order_create', 'provider_fulfillment_id' => $fulfillment->id, 'correlation_id' => 'c-1', 'method' => 'POST', 'url' => 'https://example.test',
            'success' => 0, 'error_message' => $error, 'duration_ms' => 0, 'created_at' => now(),
        ]);
    }

    public function test_the_name_the_street_and_every_spelling_of_the_phone_are_taken_out_and_the_words_around_them_stay(): void
    {
        $fulfillment = $this->dispatched();
        $fulfillment->forceFill(['last_error_message' => 'Invalid receiver Test Buyer at 1 Test Street, call 0500000000 or +966500000000 or 00966500000000'])->save();
        $this->logLine($fulfillment, 'bad request for TEST BUYER (966500000000)');

        $changed = app(BuyerEchoScrubber::class)->scrubOrder($fulfillment->order_id);

        $this->assertSame(2, $changed);
        $this->assertSame('Invalid receiver «redacted» at «redacted», call «redacted» or «redacted» or «redacted»', $fulfillment->refresh()->last_error_message);
        $this->assertSame('bad request for «redacted» («redacted»)', DB::table('provider_api_logs')->value('error_message'), 'the match ignores case');
        $this->assertSame(0, app(BuyerEchoScrubber::class)->scrubOrder($fulfillment->order_id), 'and a second pass finds nothing');
    }

    public function test_the_providers_last_error_line_is_scrubbed_too(): void
    {
        $fulfillment = $this->dispatched();
        DB::table('provider_sync_states')->insert(['provider' => 'torod_point', 'capability' => 'orders', 'last_error' => 'rejected: http_422 Invalid receiver Test Buyer', 'created_at' => now(), 'updated_at' => now()]);

        app(BuyerEchoScrubber::class)->scrubOrder($fulfillment->order_id);

        $this->assertSame('rejected: http_422 Invalid receiver «redacted»', DB::table('provider_sync_states')->value('last_error'));
    }

    public function test_a_value_too_short_to_be_told_from_ordinary_words_is_never_used(): void
    {
        $orderId = (int) $this->dispatcher()->dispatch($this->placed())->internalOrderId;
        DB::table('orders')->where('id', $orderId)->update(['shipping_address_data' => json_encode(['contact_person_name' => 'Al', 'address' => 'No 5', 'phone' => '', 'city' => 'Riyadh'])]);
        app(FulfillmentRouter::class)->route($orderId);
        $fulfillment = ProviderFulfillment::where('order_id', $orderId)->firstOrFail();
        $fulfillment->forceFill(['last_error_message' => 'Al No 5 is not a valid address'])->save();

        $this->assertSame(0, app(BuyerEchoScrubber::class)->scrubOrder($orderId));
        $this->assertSame('Al No 5 is not a valid address', $fulfillment->refresh()->last_error_message);
    }
}
