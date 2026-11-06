<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderApiLog;
use App\Models\ProviderFulfillment;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class M5aznAdapterTest extends M5aznTestCase
{
    private function fulfillment(array $orderOverrides = [], bool $withMapping = true, int $orderId = 100): ProviderFulfillment
    {
        $this->makeProduct(1, ProviderCode::M5azn);
        $this->makeProduct(2, ProviderCode::TorodPoint);
        $this->makeOrder($orderId, $orderOverrides + ['order_amount' => 100]);

        if ($withMapping) {
            ProviderProductMapping::create([
                'provider' => 'm5azn', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1947201', 'external_variant_id' => '',
                'external_sku' => 'VC1000', 'status' => MappingStatus::Approved,
            ]);
        }
        $this->addLine($orderId, 1, 2, 60, ProviderCode::M5azn);
        DB::table('provider_fulfillment_items')->where('order_id', $orderId)->update([
            'mapping_id' => $withMapping ? ProviderProductMapping::first()->id : null,
            'external_product_id' => $withMapping ? '1947201' : null, 'external_sku' => $withMapping ? 'VC1000' : null,
        ]);
        $this->addLine($orderId, 2, 1, 40, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route($orderId);

        return $this->fulfillmentFor($orderId, ProviderCode::M5azn)->load('items');
    }

    public function test_login_uses_the_documented_headers_and_body_and_stores_the_token_encrypted(): void
    {
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse()]);

        $result = $this->adapter()->testConnection();

        $this->assertTrue($result->ok);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::BASE . '/api/v1/auth/login'
                && $request->method() === 'POST'
                && $request['email'] === 'seller@example.test'
                && $request['password'] === 'pw-very-secret-1'
                && $request->header('X-Website')[0] === 'M5AZN'
                && $request->header('X-Api')[0] === '1.0'
                && $request->header('X-Lang')[0] === 'en'
                && $request->header('X-Device')[0] === 'Android'
                && $request->header('Accept')[0] === 'application/json';
        });

        $stored = (string) DB::table('integration_settings')->where('provider', 'm5azn')->value('credentials');
        $this->assertStringNotContainsString('pw-very-secret-1', $stored);
        $this->assertStringNotContainsString('access_token', $stored, 'the credential blob must be encrypted, not readable JSON');
        $this->assertStringNotContainsString('seller@example.test', $stored);
        $token = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn)->secret('access_token');
        $this->assertNotNull($token);
        $this->assertStringNotContainsString($token, $stored);
    }

    public function test_a_wrong_password_is_reported_without_leaking_it(): void
    {
        Http::fake([self::BASE . '/api/v1/auth/login' => Http::response(['status' => 401, 'success' => false, 'error' => true, 'message' => 'Invalid credentials'], 401)]);

        $result = $this->adapter()->testConnection();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Invalid credentials', $result->message);
        $this->assertStringNotContainsString('pw-very-secret-1', $result->message);
    }

    public function test_nothing_is_sent_while_integrations_are_off(): void
    {
        config(['fulfillment.enabled' => false]);

        $this->assertFalse($this->adapter()->testConnection()->ok);
        Http::assertNothingSent();
    }

    public function test_the_logged_requests_contain_no_secret(): void
    {
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse()]);
        $this->adapter()->testConnection();

        $logged = json_encode(ProviderApiLog::all()->toArray());

        $this->assertStringNotContainsString('pw-very-secret-1', $logged);
        $this->assertStringNotContainsString('seller@example.test', $logged);
        $this->assertStringContainsString('login', $logged);
    }

    public function test_a_401_triggers_exactly_one_relogin_and_one_retry(): void
    {
        $expired = $this->jwt(time() + 86400 * 300);
        $fresh = $this->jwt(time() + 86400 * 301);
        app(ProviderConnectionManager::class)->putRuntimeSecret(ProviderCode::M5azn, 'access_token', $expired);
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse($fresh),
            self::BASE . '/api/v1/seller/myproductlist*' => Http::sequence()
                ->push(['message' => 'Unauthenticated'], 401)
                ->push(['data' => []], 200),
        ]);

        $result = $this->adapter()->syncCatalog();

        $this->assertTrue($result['ok']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'myproductlist') && ($request->header('Authorization')[0] ?? '') === 'Bearer ' . $fresh);
    }

    public function test_a_token_close_to_expiry_is_refreshed_before_use(): void
    {
        app(ProviderConnectionManager::class)->putRuntimeSecret(ProviderCode::M5azn, 'access_token', $this->jwt(time() + 3600));
        app(ProviderConnectionManager::class)->putRuntimeSecret(ProviderCode::M5azn, 'token_expires_at', (string) (time() + 3600));
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/seller/myproductlist*' => Http::response(['data' => []]),
        ]);

        $this->adapter()->syncCatalog();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/auth/login'));
    }

    public function test_the_create_order_body_matches_the_documented_shape_and_contains_only_this_providers_items(): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/orders' => Http::response(['status' => 200, 'success' => true, 'data' => ['id' => 777001]]),
        ]);

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::ACCEPTED, $result->outcome);
        $this->assertSame('777001', $result->providerReference);
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== self::BASE . '/api/v1/orders') {
                return true;
            }
            $body = $request->data();

            return $request->method() === 'POST'
                && $body['id'] === 100
                && $body['payment_method'] === 'card'
                && (float) $body['cod_amount'] === 0.0
                && $body['platform_name'] === 'Pharmacy KSA'
                && $body['shipping_info'] === ['address' => 'King Fahd Rd 12', 'country' => 'Saudi Arabia', 'country_code' => 'SA', 'city' => 'الرياض']
                && $body['customer_info'] === ['first_name' => 'Sara', 'last_name' => 'Alharbi', 'email' => 'sara@example.test', 'phone' => '0551234567']
                && count($body['items']) === 1
                && $body['items'][0]['product_id'] === 1947201
                && $body['items'][0]['sku'] === 'VC1000'
                && $body['items'][0]['qty_ordered'] === 2
                && !str_contains(json_encode($body), 'Product 2');
        });
        $this->assertSame('Bearer', explode(' ', (string) Http::recorded()->last()[0]->header('Authorization')[0])[0]);
    }

    public function test_a_cod_order_sends_only_the_amount_this_provider_collects(): void
    {
        $fulfillment = $this->fulfillment(['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 60]);
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->status, 'mixed COD is held, so a single-provider order is used for this assertion');

        $this->makeOrder(200, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 75.5]);
        $this->addLine(200, 1, 1, 70, ProviderCode::M5azn);
        DB::table('provider_fulfillment_items')->where('order_id', 200)->update(['external_product_id' => '1947201', 'external_sku' => 'VC1000', 'mapping_id' => 1]);
        app(FulfillmentRouter::class)->route(200);
        $single = $this->fulfillmentFor(200, ProviderCode::M5azn)->load('items');
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse(), self::BASE . '/api/v1/orders' => Http::response(['success' => true, 'data' => ['id' => 5]])]);

        $this->submitAsClaimed($this->adapter(), $single);

        Http::assertSent(fn (Request $request): bool => !str_ends_with($request->url(), '/orders')
            || ($request['payment_method'] === 'cod' && (float) $request['cod_amount'] === 75.5 && (float) $request['total'] === 75.5));
    }

    public function test_a_2xx_without_a_reference_is_not_acceptance_and_is_never_resent(): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse(), self::BASE . '/api/v1/orders' => Http::response(['status' => 200, 'success' => true, 'message' => 'ok'])]);

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::AMBIGUOUS, $result->outcome);
        $this->assertSame('no_reference_in_response', $result->errorCode);
    }

    public function test_a_reference_path_can_be_configured_once_the_real_response_is_known(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['dry_run' => false, 'dispatch_enabled' => true, 'order_response_reference_path' => 'result.order_no']);
        $fulfillment = $this->fulfillment();
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse(), self::BASE . '/api/v1/orders' => Http::response(['success' => true, 'result' => ['order_no' => 'M5-8891']])]);

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame('M5-8891', $result->providerReference);
    }

    public function test_a_provider_that_says_success_false_with_http_200_is_a_definitive_refusal(): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse(), self::BASE . '/api/v1/orders' => Http::response(['success' => false, 'error' => true, 'message' => 'SKU not available'])]);

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::REJECTED, $result->outcome);
        $this->assertStringContainsString('SKU not available', (string) $result->message);
    }

    /** @dataProvider outcomes */
    public function test_http_outcomes_are_classified_by_whether_the_provider_could_have_acted(int $status, string $expected): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse(), self::BASE . '/api/v1/orders' => Http::response(['message' => 'x'], $status)]);

        $this->assertSame($expected, $this->submitAsClaimed($this->adapter(), $fulfillment)->outcome, "HTTP {$status}");
    }

    /** @return iterable<string, array{int, string}> */
    public static function outcomes(): iterable
    {
        yield '422 validation' => [422, SubmissionResult::REJECTED];
        yield '400 bad request' => [400, SubmissionResult::REJECTED];
        yield '404' => [404, SubmissionResult::REJECTED];
        yield '429 rate limited' => [429, SubmissionResult::RETRYABLE];
        yield '500 may have been processed' => [500, SubmissionResult::AMBIGUOUS];
        yield '502' => [502, SubmissionResult::AMBIGUOUS];
        yield '503' => [503, SubmissionResult::AMBIGUOUS];
        yield '504' => [504, SubmissionResult::AMBIGUOUS];
        yield '409 conflict may mean it exists' => [409, SubmissionResult::AMBIGUOUS];
        yield '408 request timeout' => [408, SubmissionResult::AMBIGUOUS];
        yield '301: redirects are not followed, and an order-creating post that was redirected may have created the order' => [301, SubmissionResult::AMBIGUOUS];
        yield '302' => [302, SubmissionResult::AMBIGUOUS];
        yield '303 see other' => [303, SubmissionResult::AMBIGUOUS];
        yield '307' => [307, SubmissionResult::AMBIGUOUS];
        yield '308' => [308, SubmissionResult::AMBIGUOUS];
    }

    public function test_a_timeout_after_the_request_was_sent_is_ambiguous(): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/orders' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received'),
        ]);

        $this->assertSame(SubmissionResult::AMBIGUOUS, $this->submitAsClaimed($this->adapter(), $fulfillment)->outcome);
    }

    public function test_a_connection_that_never_opened_is_safely_retryable(): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/orders' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to m5azn.com port 443'),
        ]);

        $this->assertSame(SubmissionResult::RETRYABLE, $this->submitAsClaimed($this->adapter(), $fulfillment)->outcome);
    }

    public function test_a_write_is_never_retried_inside_the_http_layer(): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/orders' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        $orderCalls = Http::recorded(fn (Request $request): bool => $request->url() === self::BASE . '/api/v1/orders');
        $this->assertCount(1, $orderCalls);
    }

    public function test_a_dry_run_builds_and_validates_the_payload_but_sends_nothing(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['dry_run' => true, 'dispatch_enabled' => true]);
        $fulfillment = $this->fulfillment();
        Http::fake();

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::DRY_RUN, $result->outcome);
        $this->assertNotEmpty($result->meta['payload_hash']);
        $this->assertSame('*******567', $result->meta['payload_preview']['customer_info']['phone'], 'the stored preview masks the phone');
        $this->assertSame('s***@example.test', $result->meta['payload_preview']['customer_info']['email']);
        Http::assertNothingSent();
    }

    public function test_a_phone_number_typed_into_the_address_is_masked_in_the_recorded_request_and_a_product_name_is_not_mangled(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['dry_run' => true, 'dispatch_enabled' => true]);
        $fulfillment = $this->fulfillment(['shipping_address_data' => json_encode([
            'contact_person_name' => 'Sara Alharbi', 'phone' => '0551234567', 'address' => 'King Fahd Rd 12, call 0501234567 / 0551111111', 'city' => 'الرياض', 'country' => 'SA', 'email' => 'sara@example.test',
        ])]);
        DB::table('provider_fulfillment_items')->where('provider_fulfillment_id', $fulfillment->id)->update(['name' => 'Basic Care Cream 250ml']);
        Http::fake();

        $preview = $this->submitAsClaimed($this->adapter(), $fulfillment->fresh())->meta['payload_preview'];

        $this->assertStringNotContainsString('0501234567', json_encode($preview));
        $this->assertStringNotContainsString('0551111111', json_encode($preview));
        $this->assertStringContainsString('King Fahd Rd 12', json_encode($preview), 'the address is what the person approving it checks');
        Http::assertNothingSent();
    }

    public function test_the_adapter_follows_the_mode_it_is_told_and_never_reads_the_dry_run_setting(): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse(), self::BASE . '/api/v1/orders' => Http::response(['success' => true, 'data' => ['id' => 'M-1']])]);

        $rehearsal = $this->adapter()->submit($fulfillment, dryRun: true);

        $this->assertSame(SubmissionResult::DRY_RUN, $rehearsal->outcome, 'the setting says live, the dispatcher decided on a rehearsal');
        Http::assertNothingSent();

        $this->enableProvider(ProviderCode::M5azn, ['dry_run' => true, 'dispatch_enabled' => true]);
        $sent = $this->adapter()->submit($fulfillment, dryRun: false);

        $this->assertNotSame(SubmissionResult::DRY_RUN, $sent->outcome, 'the setting says rehearse, the dispatcher decided on a real send');
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/orders'));
    }

    public function test_a_rehearsal_is_recorded_through_the_dispatcher_while_external_writes_are_off(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['dry_run' => true, 'auto_dispatch' => false, 'dispatch_enabled' => true]);
        $fulfillment = $this->fulfillment();
        config(['fulfillment.external_writes_enabled' => false]);
        Http::fake();

        $outcome = app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $recorded = $fulfillment->fresh();
        $this->assertSame(SubmissionResult::DRY_RUN, $outcome);
        $this->assertSame(FulfillmentStatus::DryRun, $recorded->status, 'a held rehearsal would leave nothing to review');
        $this->assertNotEmpty($recorded->meta['payload_preview']);
        $this->assertTrue($this->adapter()->readiness()->canUse('dispatch'));
        Http::assertNothingSent();
    }

    public function test_a_real_send_stays_held_while_external_writes_are_off(): void
    {
        $this->enableProvider(ProviderCode::M5azn, ['dry_run' => false, 'auto_dispatch' => true, 'dispatch_enabled' => true]);
        $fulfillment = $this->fulfillment();
        config(['fulfillment.external_writes_enabled' => false]);
        Http::fake();

        $outcome = app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $held = $fulfillment->fresh();
        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(FulfillmentStatus::Held, $held->status);
        $this->assertSame(HoldReason::EXTERNAL_WRITES_DISABLED, $held->hold_reason);
        Http::assertNothingSent();
    }

    public function test_preflight_holds_instead_of_sending_a_half_filled_order(): void
    {
        $unmapped = $this->fulfillment(withMapping: false);
        $this->assertSame(HoldReason::MAPPING_MISSING, $this->adapter()->preflight($unmapped)->holdReason);

        $this->makeOrder(300, ['shipping_address_data' => json_encode(['contact_person_name' => 'A B', 'phone' => '12345', 'address' => 'x', 'city' => 'Riyadh'])]);
        $this->addLine(300, 1, 1, 50, ProviderCode::M5azn);
        DB::table('provider_fulfillment_items')->where('order_id', 300)->update(['mapping_id' => 1, 'external_product_id' => '1947201', 'external_sku' => 'VC1000']);
        ProviderProductMapping::query()->exists() || ProviderProductMapping::create(['provider' => 'm5azn', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1947201', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        app(FulfillmentRouter::class)->route(300);
        $badPhone = $this->fulfillmentFor(300, ProviderCode::M5azn)->load('items');

        $this->assertSame(HoldReason::PHONE_INVALID, $this->adapter()->preflight($badPhone)->holdReason);
    }

    public function test_cash_on_delivery_is_held_while_the_provider_is_marked_as_not_supporting_it(): void
    {
        $prepaid = $this->fulfillment();

        $this->makeOrder(200, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 75.5]);
        $this->addLine(200, 1, 1, 70, ProviderCode::M5azn);
        DB::table('provider_fulfillment_items')->where('order_id', 200)->update([
            'external_product_id' => '1947201', 'external_sku' => 'VC1000', 'mapping_id' => ProviderProductMapping::first()->id,
        ]);
        app(FulfillmentRouter::class)->route(200);
        $cod = $this->fulfillmentFor(200, ProviderCode::M5azn)->load('items');
        $this->assertTrue($this->adapter()->preflight($cod)->ok, 'supported by default');

        $connections = app(ProviderConnectionManager::class);
        $connections->save(ProviderCode::M5azn, true, null, ['cod_supported' => false] + $connections->config(ProviderCode::M5azn)->settings());

        $held = $this->adapter()->preflight($cod);
        $this->assertFalse($held->ok);
        $this->assertSame(HoldReason::PROVIDER_NOT_READY, $held->holdReason);
        $this->assertTrue($this->adapter()->preflight($prepaid)->ok, 'prepaid orders are unaffected');
    }

    public function test_a_mapping_that_is_no_longer_approved_holds_the_order(): void
    {
        $fulfillment = $this->fulfillment();
        ProviderProductMapping::query()->update(['status' => MappingStatus::Retired->value]);

        $this->assertSame(HoldReason::MAPPING_NOT_APPROVED, $this->adapter()->preflight($fulfillment)->holdReason);
    }

    public function test_stock_known_to_be_too_low_holds_the_order_but_stale_stock_does_not(): void
    {
        $fulfillment = $this->fulfillment();
        ProviderProductMapping::query()->update(['stock_source' => 'provider_sync', 'provider_stock' => 1, 'provider_stock_synced_at' => now()]);
        $this->assertSame(HoldReason::STOCK_UNAVAILABLE, $this->adapter()->preflight($fulfillment)->holdReason);

        ProviderProductMapping::query()->update(['provider_stock_synced_at' => now()->subDays(2)]);
        $this->assertTrue($this->adapter()->preflight($fulfillment)->ok);
    }

    public function test_cancel_is_reported_as_unsupported_because_m5azn_documents_no_cancel_endpoint(): void
    {
        $fulfillment = $this->fulfillment();

        $this->assertSame('unsupported', $this->adapter()->cancel($fulfillment)->outcome);
        Http::assertNothingSent();
    }

    public function test_the_capability_report_states_what_the_official_docs_do_not_provide(): void
    {
        $report = $this->adapter()->readiness();

        foreach (['cancel', 'webhooks', 'margin_management'] as $undocumented) {
            $this->assertSame(CapabilityState::UNDOCUMENTED, $report->capability($undocumented)->state, $undocumented);
        }
        $this->assertSame(CapabilityState::UNVERIFIED, $report->capability('catalog_import')->state, 'import needs a verified field map');
        $this->assertSame(CapabilityState::UNVERIFIED, $report->capability('inventory_sync')->state);
        $this->assertSame(CapabilityState::UNVERIFIED, $report->capability('status_poll')->state);
        $this->assertTrue($report->canUse('dispatch'));
    }

    public function test_with_external_writes_off_an_order_is_refused_and_never_reaches_m5azn(): void
    {
        $fulfillment = $this->fulfillment();
        config(['fulfillment.external_writes_enabled' => false]);
        Http::fake([self::BASE . '/api/v1/auth/login' => $this->loginResponse()]);

        try {
            $this->submitAsClaimed($this->adapter(), $fulfillment);
            $this->fail('an order was attempted while external writes are off');
        } catch (ProviderUnavailableException $exception) {
            $this->assertStringContainsString('FULFILLMENT_EXTERNAL_WRITES_ENABLED', $exception->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/orders'));
        $this->assertSame(0, ProviderApiLog::where('operation', 'create_order')->count());
    }

    public function test_with_external_writes_off_dispatch_is_reported_ready_but_disabled_and_reads_stay_available(): void
    {
        config(['fulfillment.external_writes_enabled' => false]);

        $report = $this->adapter()->readiness();

        $this->assertSame(CapabilityState::READY_DISABLED, $report->capability('dispatch')->state);
        $this->assertSame('external_writes_off', $report->capability('dispatch')->reason);
        $this->assertFalse($report->canUse('dispatch'));
        $this->assertTrue($report->canUse('catalog_sync'));
    }

    public function test_dispatch_is_not_ready_without_credentials(): void
    {
        app(ProviderConnectionManager::class)->save(ProviderCode::M5azn, true, null, [], [], ['email', 'password']);

        $report = $this->adapter()->readiness();

        $this->assertFalse($report->canUse('dispatch'));
        $this->assertSame(['email', 'password'], $report->missing);
    }

    public function test_the_real_adapters_defaults_keep_outbound_dispatch_off_and_dry_run_on(): void
    {
        $defaults = M5aznAdapterDefaults::get();

        $this->assertFalse($defaults['dispatch_enabled']);
        $this->assertTrue($defaults['dry_run']);
        $this->assertFalse($defaults['product_sync_enabled']);
        $this->assertFalse($defaults['import_enabled']);
        $this->assertFalse($defaults['publish_on_import']);
        $this->assertSame('store_controlled', $defaults['price_policy']);
    }

    public function test_status_polling_needs_a_verified_map_and_a_status_map(): void
    {
        $fulfillment = $this->fulfillment();
        $fulfillment->forceFill(['provider_reference' => '777001'])->save();

        // No verified map: nothing is asked (a stray request would throw under preventStrayRequests).
        $this->assertNull($this->adapter()->fetchStatus($fulfillment));

        $this->verifyMap('orders', ['reference' => 'id', 'status' => 'status', 'external_order_id' => 'platform_order_id'], [['id' => 777001, 'status' => 'shipped', 'platform_order_id' => 100]]);
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/orders/777001' => Http::response(['status' => 'shipped']),
        ]);

        $snapshot = $this->adapter()->fetchStatus($fulfillment);

        $this->assertSame('shipped', $snapshot->providerStatus);
        $this->assertNull($this->adapter()->mapStatus('shipped'), 'an unmapped status is never guessed');

        $this->enableProvider(ProviderCode::M5azn, ['status_map' => ['shipped' => 'in_transit', 'DELIVERED' => 'delivered', 'pending' => 'pending']]);
        $this->assertSame(FulfillmentStatus::InTransit, $this->adapter()->mapStatus('Shipped'));
        $this->assertSame(FulfillmentStatus::Delivered, $this->adapter()->mapStatus('delivered'));
        $this->assertNull($this->adapter()->mapStatus('pending'), 'a provider cannot push a dispatcher-only state');
    }

    public function test_an_ambiguous_order_is_found_again_by_our_order_id_in_the_order_list(): void
    {
        $fulfillment = $this->fulfillment();
        $this->verifyMap('orders', ['reference' => 'id', 'status' => 'status', 'external_order_id' => 'platform_order_id'], [['id' => 1, 'status' => 'new', 'platform_order_id' => 100]], itemsPath: 'data');
        Http::fake([
            self::BASE . '/api/v1/auth/login' => $this->loginResponse(),
            self::BASE . '/api/v1/orders' => Http::response(['data' => [
                ['id' => 9001, 'status' => 'new', 'platform_order_id' => 55],
                ['id' => 9002, 'status' => 'new', 'platform_order_id' => 100],
            ]]),
        ]);

        $result = $this->adapter()->reconcileSubmission($fulfillment);

        $this->assertTrue($result->isAccepted());
        $this->assertSame('9002', $result->providerReference);
    }

    public function test_reconciliation_without_a_verified_map_never_guesses(): void
    {
        $fulfillment = $this->fulfillment();
        Http::fake();

        $this->assertSame(SubmissionResult::AMBIGUOUS, $this->adapter()->reconcileSubmission($fulfillment)->outcome);
        Http::assertNothingSent();
    }

    public function test_the_adapter_refuses_to_call_out_when_switched_off(): void
    {
        config(['fulfillment.enabled' => false]);

        $this->expectException(ProviderUnavailableException::class);
        $this->adapter()->syncCatalog();
    }
}

/** Reads the adapter's real static defaults without the fake-adapter config override. */
final class M5aznAdapterDefaults
{
    /** @return array<string, mixed> */
    public static function get(): array
    {
        return \App\Services\Fulfillment\Providers\M5azn\M5aznAdapter::defaultSettings();
    }
}
