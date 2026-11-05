<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Jobs\Fulfillment\ProcessProviderEvent;
use App\Models\ProviderFulfillmentEvent;
use Illuminate\Support\Facades\Queue;

class WebhookEndpointTest extends TorodTestCase
{
    private const SECRET = 'hook-secret-abcdef';

    private function url(string $secret = self::SECRET, string $type = 'lastmile', string $provider = 'torod-point'): string
    {
        return "/api/fulfillment-webhooks/{$provider}/{$secret}/{$type}";
    }

    public function test_an_authorised_event_is_stored_and_queued_with_a_fast_ok(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted])->save();

        $response = $this->postJson($this->url(), $this->lastmilePayload('OUT_FOR_DELIVERY'));

        $response->assertOk()->assertJson(['success' => true, 'status' => 'accepted']);
        $this->assertSame(1, ProviderFulfillmentEvent::count());
        Queue::assertPushed(ProcessProviderEvent::class);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status, 'applying happens in the queue, not in the request');
    }

    public function test_a_repeated_delivery_is_acknowledged_without_a_second_row(): void
    {
        $payload = $this->lastmilePayload('OUT_FOR_DELIVERY', time: 1762347300095);

        $this->postJson($this->url(), $payload)->assertOk();
        $this->postJson($this->url(), $payload)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, ProviderFulfillmentEvent::count());
    }

    public function test_a_wrong_or_missing_secret_is_a_flat_401_that_reveals_nothing(): void
    {
        foreach (['wrong-secret', 'hook-secret-abcdeX', str_repeat('a', 500)] as $secret) {
            $response = $this->postJson($this->url($secret), $this->lastmilePayload('OUT_FOR_DELIVERY'));
            $response->assertStatus(401)->assertExactJson(['success' => false, 'message' => 'Unauthorized request']);
        }

        $this->assertSame(0, ProviderFulfillmentEvent::count());
    }

    public function test_the_secret_may_instead_be_sent_in_a_header(): void
    {
        $this->postJson($this->url('-'), $this->lastmilePayload('OUT_FOR_DELIVERY'), ['X-Webhook-Secret' => self::SECRET])->assertOk();
        $this->postJson($this->url('-'), $this->lastmilePayload('FAILED'), ['Authorization' => 'Bearer ' . self::SECRET])->assertOk();
        $this->postJson($this->url('-'), $this->lastmilePayload('POSTPONED_DELIVERY'), ['X-Webhook-Secret' => 'nope'])->assertStatus(401);
    }

    public function test_it_is_closed_unless_integrations_the_provider_and_its_webhooks_are_all_enabled(): void
    {
        config(['fulfillment.enabled' => false]);
        $this->postJson($this->url(), $this->lastmilePayload('OUT_FOR_DELIVERY'))->assertStatus(401);

        config(['fulfillment.enabled' => true]);
        $this->enableProvider(ProviderCode::TorodPoint, ['webhooks_enabled' => false, 'company_id' => '714']);
        $this->postJson($this->url(), $this->lastmilePayload('OUT_FOR_DELIVERY'))->assertStatus(401);

        app(\App\Services\Fulfillment\ProviderConnectionManager::class)->save(ProviderCode::TorodPoint, false, null, ['webhooks_enabled' => true]);
        $this->postJson($this->url(), $this->lastmilePayload('OUT_FOR_DELIVERY'))->assertStatus(401);

        $this->assertSame(0, ProviderFulfillmentEvent::count());
    }

    public function test_an_ip_allow_list_narrows_who_may_call(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['webhook_ip_allowlist' => '203.0.113.7, 203.0.113.8', 'webhooks_enabled' => true, 'company_id' => '714']);

        $this->postJson($this->url(), $this->lastmilePayload('OUT_FOR_DELIVERY'))->assertStatus(401);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->postJson($this->url(), $this->lastmilePayload('OUT_FOR_DELIVERY'))->assertOk();
    }

    public function test_bad_payloads_and_unknown_things_are_refused_cleanly(): void
    {
        $this->postJson($this->url(), [])->assertStatus(422);
        $this->postJson($this->url(), ['newStatus' => 'OUT_FOR_DELIVERY'])->assertStatus(422);
        $this->postJson($this->url(provider: 'nope'), $this->lastmilePayload('OUT_FOR_DELIVERY'))->assertStatus(404);
        $this->postJson($this->url(type: 'bogus'), $this->lastmilePayload('OUT_FOR_DELIVERY'))->assertStatus(404);
        $this->postJson($this->url(), ['padding' => str_repeat('x', 70000)])->assertStatus(413);

        $this->assertSame(0, ProviderFulfillmentEvent::count());
    }

    public function test_get_requests_are_not_accepted(): void
    {
        $this->getJson($this->url())->assertStatus(405);
    }

    public function test_the_warehouse_webhook_is_accepted_on_its_own_type(): void
    {
        $this->postJson($this->url(type: 'fulfillment'), ['status' => 'PACKED', 'barcode' => 'O0015030400001', 'packageBarcode' => null])->assertOk();

        $this->assertSame('fulfillment', ProviderFulfillmentEvent::first()->event_type);
    }

    public function test_the_route_is_outside_the_api_group_so_no_per_request_telemetry_sees_the_secret(): void
    {
        $route = app('router')->getRoutes()->getByName('fulfillment.webhooks.receive');
        $middleware = $route->gatherMiddleware();

        $this->assertContains('throttle:fulfillment-webhooks', $middleware);
        $this->assertNotContains(\App\Http\Middleware\RecordHttpTelemetry::class, $middleware);
        $this->assertNotContains(\App\Http\Middleware\RecordAnalytics::class, $middleware);
    }
}
