<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\Providers\TorodPoint\TorodCityResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TorodAdapterTest extends TorodTestCase
{
    private function fakeOrderAccepted(array $body = ['id' => 4455, 'barcode' => 'O0015030400001']): void
    {
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response($body)]);
    }

    public function test_warehouse_calls_carry_the_documented_headers_and_our_company_id(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::V5 . '/public/fulfillment/order'
                && $request->method() === 'POST'
                && $request->header('company-id')[0] === '714'
                && $request->header('username')[0] === 'wh-user'
                && $request->header('password')[0] === 'wh-pass-secret-9';
        });
    }

    public function test_the_example_company_id_from_the_lastmile_docs_is_never_used(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('136', (string) ($request->header('company-id')[0] ?? ''));
            $this->assertStringNotContainsString('/136/', $request->url());
        }
    }

    public function test_the_add_order_body_follows_the_documented_sample_for_a_prepaid_order(): void
    {
        $fulfillment = $this->torodFulfillment(['order_note' => 'Leave with the guard']);
        $this->fakeOrderAccepted();

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::ACCEPTED, $result->outcome);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $body['receiverName'] === 'Sara Alharbi'
                && $body['receiverPhone'] === '00966551234567'
                && $body['receiverPhone2'] === ''
                && $body['receiverAddress'] === ['cityId' => '41125', 'regionId' => '1335', 'addressLine1' => 'King Fahd Rd 12']
                && $body['notes'] === 'Leave with the guard'
                && $body['weight'] === 1
                && $body['receiverBusinessName'] === ''
                && $body['shipmentType'] === 'REGULAR'
                && $body['codCollectionMethod'] === 'PREPAID'
                && $body['cod'] === '0.00'
                && $body['items'] === [['productId' => 1421521, 'sku' => '13222', 'price' => 30.0, 'quantity' => 2]]
                && $body['invoiceNumber'] === '100-TP1'
                && !array_key_exists('cost', $body)
                && !array_key_exists('villageId', $body['receiverAddress']);
        });
    }

    public function test_only_this_providers_items_are_in_the_body(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertSent(fn (Request $request): bool => count($request->data()['items']) === 1 && !str_contains(json_encode($request->data()), 'Product 2'));
    }

    public function test_the_phone_format_can_be_switched_to_local(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['phone_format' => 'local', 'company_id' => '714', 'dry_run' => false]);
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertSent(fn (Request $request): bool => $request->data()['receiverPhone'] === '0551234567');
    }

    public function test_the_cost_field_is_sent_only_when_an_operator_chose_what_it_carries(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['cost_source' => 'shipping_fee', 'company_id' => '714', 'dry_run' => false]);
        $fulfillment = $this->torodFulfillment(['order_amount' => 100]);
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertSent(fn (Request $request): bool => array_key_exists('cost', $request->data()));
    }

    public function test_a_cod_order_uses_cod_with_the_collection_method_logestechs_gave_us(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['cod_collection_method' => 'CASH', 'company_id' => '714', 'dry_run' => false]);
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(300, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 72.5]);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1421521', 'external_variant_id' => '', 'external_sku' => '13222', 'status' => MappingStatus::Approved]);
        $this->addLine(300, 1, 1, 70, ProviderCode::TorodPoint);
        DB::table('provider_fulfillment_items')->where('order_id', 300)->update(['mapping_id' => 1, 'external_product_id' => '1421521', 'external_sku' => '13222']);
        app(\App\Services\Fulfillment\FulfillmentRouter::class)->route(300);
        $fulfillment = $this->fulfillmentFor(300, ProviderCode::TorodPoint)->load('items');
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertSent(fn (Request $request): bool => $request->data()['shipmentType'] === 'COD'
            && $request->data()['codCollectionMethod'] === 'CASH' && $request->data()['cod'] === '72.50');
    }

    public function test_a_cod_order_is_held_until_the_collection_method_is_configured(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(300, ['payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid', 'order_amount' => 72.5]);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 1, 'variant_key' => '', 'external_id' => '1421521', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);
        $this->addLine(300, 1, 1, 70, ProviderCode::TorodPoint);
        DB::table('provider_fulfillment_items')->where('order_id', 300)->update(['mapping_id' => 1, 'external_product_id' => '1421521']);
        app(\App\Services\Fulfillment\FulfillmentRouter::class)->route(300);
        $fulfillment = $this->fulfillmentFor(300, ProviderCode::TorodPoint)->load('items');

        $result = $this->adapter()->preflight($fulfillment);

        $this->assertSame(HoldReason::PROVIDER_NOT_READY, $result->holdReason);
        $this->assertSame('cod_collection_method', $result->detail);
    }

    public function test_the_lastmile_create_shipment_call_is_never_made_because_it_would_ship_twice(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/ship/request') || str_contains($request->url(), 'apisv2'));
        $this->assertSame(CapabilityState::NOT_USED, $this->adapter()->readiness()->capability('lastmile_create_shipment')->state);
    }

    /** @dataProvider acceptedBodies */
    public function test_an_accepted_order_yields_the_warehouse_reference_the_webhooks_will_use(array $body, string $reference, ?string $internal): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted($body);

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::ACCEPTED, $result->outcome);
        $this->assertSame($reference, $result->providerReference);
        $this->assertSame($internal, $result->providerInternalId);
    }

    /** @return iterable<string, array{array<string, mixed>, string, ?string}> */
    public static function acceptedBodies(): iterable
    {
        yield 'barcode and id' => [['id' => 4455, 'barcode' => 'O0015030400001'], 'O0015030400001', '4455'];
        yield 'only an id' => [['id' => 4455], '4455', null];
        yield 'wrapped' => [['data' => ['id' => 4455, 'barcode' => 'O001']], 'O001', '4455'];
        yield 'list like the bulk upsert response' => [[['id' => 9, 'barcode' => 'O9']], 'O9', '9'];
    }

    public function test_a_2xx_with_nothing_to_hold_on_to_is_not_acceptance_and_is_never_resent(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted(['message' => 'ok']);

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::AMBIGUOUS, $result->outcome);
        $this->assertSame('no_reference_in_response', $result->errorCode);
    }

    public function test_a_configured_reference_path_overrides_the_guess(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['order_response_reference_path' => 'order.code', 'company_id' => '714', 'dry_run' => false]);
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted(['order' => ['code' => 'WH-77'], 'id' => 5]);

        $this->assertSame('WH-77', $this->submitAsClaimed($this->adapter(), $fulfillment)->providerReference);
    }

    /** @dataProvider outcomes */
    public function test_http_outcomes_are_classified_by_whether_the_provider_could_have_acted(int $status, string $expected): void
    {
        $fulfillment = $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['message' => 'x'], $status)]);

        $this->assertSame($expected, $this->submitAsClaimed($this->adapter(), $fulfillment)->outcome, "HTTP {$status}");
    }

    /** @return iterable<string, array{int, string}> */
    public static function outcomes(): iterable
    {
        yield '401 bad credentials: the credential is wrong, not the order' => [401, SubmissionResult::RETRYABLE];
        yield '403 likewise' => [403, SubmissionResult::RETRYABLE];
        yield '400' => [400, SubmissionResult::REJECTED];
        yield '422' => [422, SubmissionResult::REJECTED];
        yield '429' => [429, SubmissionResult::RETRYABLE];
        yield '500' => [500, SubmissionResult::AMBIGUOUS];
        yield '503' => [503, SubmissionResult::AMBIGUOUS];
        yield '504' => [504, SubmissionResult::AMBIGUOUS];
        yield '409' => [409, SubmissionResult::AMBIGUOUS];
        yield '301: redirects are not followed, and an order-creating post that was redirected may have created the order' => [301, SubmissionResult::AMBIGUOUS];
        yield '302' => [302, SubmissionResult::AMBIGUOUS];
        yield '303 see other' => [303, SubmissionResult::AMBIGUOUS];
        yield '307' => [307, SubmissionResult::AMBIGUOUS];
        yield '308' => [308, SubmissionResult::AMBIGUOUS];
    }

    public function test_a_timeout_after_sending_is_ambiguous(): void
    {
        $fulfillment = $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/order' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds')]);

        $this->assertSame(SubmissionResult::AMBIGUOUS, $this->submitAsClaimed($this->adapter(), $fulfillment)->outcome);
    }

    public function test_a_connection_that_never_opened_is_safely_retryable(): void
    {
        $fulfillment = $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/order' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: apisv5.logestechs.com')]);

        $this->assertSame(SubmissionResult::RETRYABLE, $this->submitAsClaimed($this->adapter(), $fulfillment)->outcome);
    }

    public function test_a_connect_phase_timeout_means_the_request_never_left(): void
    {
        $fulfillment = $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/order' => fn () => throw new ConnectionException('cURL error 28: Connection timed out after 5001 milliseconds')]);

        $this->assertSame(SubmissionResult::RETRYABLE, $this->submitAsClaimed($this->adapter(), $fulfillment)->outcome);
    }

    public function test_a_write_is_never_retried_inside_the_http_layer(): void
    {
        $fulfillment = $this->torodFulfillment();
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response('boom', 500)]);

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertCount(1, Http::recorded());
    }

    public function test_a_dry_run_sends_nothing_and_never_claims_submission(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true, 'company_id' => '714']);
        $fulfillment = $this->torodFulfillment();

        $result = $this->submitAsClaimed($this->adapter(), $fulfillment);

        $this->assertSame(SubmissionResult::DRY_RUN, $result->outcome);
        $this->assertSame('invoiceNumber', array_key_last($result->meta['payload_preview']));
        $this->assertStringContainsString('*', $result->meta['payload_preview']['receiverPhone']);
        Http::assertNothingSent();
    }

    public function test_a_phone_number_or_an_email_typed_into_a_free_text_field_is_masked_in_the_recorded_request(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true, 'company_id' => '714']);
        $fulfillment = $this->torodFulfillment(['order_note' => 'Call my brother on 0501234567 or write brother@example.test, ID 1012345678']);

        $preview = $this->submitAsClaimed($this->adapter(), $fulfillment)->meta['payload_preview'];

        foreach (['0501234567', 'brother@example.test', '1012345678'] as $contact) {
            $this->assertStringNotContainsString($contact, json_encode($preview), $contact);
        }
        $this->assertStringContainsString('Call my brother on', (string) $preview['notes'], 'the rest of the note is what the person approving it reads');
        $this->assertSame('100-TP1', $preview['invoiceNumber']);
        $this->assertSame('13222', (string) $preview['items'][0]['sku'], 'a code is not mistaken for a telephone number');
    }

    public function test_the_adapter_follows_the_mode_it_is_told_and_never_reads_the_dry_run_setting(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted();

        $rehearsal = $this->adapter()->submit($fulfillment, dryRun: true);

        $this->assertSame(SubmissionResult::DRY_RUN, $rehearsal->outcome, 'the setting says live, the dispatcher decided on a rehearsal');
        Http::assertNothingSent();

        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true, 'company_id' => '714']);
        $sent = $this->adapter()->submit($fulfillment, dryRun: false);

        $this->assertNotSame(SubmissionResult::DRY_RUN, $sent->outcome, 'the setting says rehearse, the dispatcher decided on a real send');
        Http::assertSentCount(1);
    }

    public function test_a_rehearsal_is_recorded_through_the_dispatcher_while_external_writes_are_off(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true, 'auto_dispatch' => false, 'company_id' => '714']);
        $fulfillment = $this->torodFulfillment();
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
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false, 'auto_dispatch' => true, 'company_id' => '714']);
        $fulfillment = $this->torodFulfillment();
        config(['fulfillment.external_writes_enabled' => false]);
        Http::fake();

        $outcome = app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $held = $fulfillment->fresh();
        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(FulfillmentStatus::Held, $held->status);
        $this->assertSame(HoldReason::EXTERNAL_WRITES_DISABLED, $held->hold_reason);
        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_while_integrations_are_off(): void
    {
        $fulfillment = $this->torodFulfillment();
        config(['fulfillment.enabled' => false]);

        $this->expectException(\App\Services\Fulfillment\Exceptions\ProviderUnavailableException::class);
        $this->submitAsClaimed($this->adapter(), $fulfillment);
    }

    public function test_logged_requests_never_contain_the_warehouse_credentials(): void
    {
        $fulfillment = $this->torodFulfillment();
        $this->fakeOrderAccepted();
        $this->submitAsClaimed($this->adapter(), $fulfillment);

        $logged = json_encode(\App\Models\ProviderApiLog::all()->toArray());

        $this->assertStringNotContainsString('wh-pass-secret-9', $logged);
        $this->assertStringNotContainsString('wh-user', $logged);
        $this->assertStringContainsString('fulfillment_order_create', $logged);
    }

    // ---- preflight ----------------------------------------------------------------------------

    public function test_preflight_passes_for_a_complete_mapped_order(): void
    {
        $this->assertTrue($this->adapter()->preflight($this->torodFulfillment())->ok);
    }

    public function test_an_unmapped_line_holds_the_order(): void
    {
        $fulfillment = $this->torodFulfillment(mapped: false);

        $this->assertSame(HoldReason::MAPPING_MISSING, $this->adapter()->preflight($fulfillment)->holdReason);
    }

    public function test_a_city_that_cannot_be_matched_exactly_holds_the_order_instead_of_guessing(): void
    {
        $fulfillment = $this->torodFulfillment(['shipping_address_data' => json_encode([
            'contact_person_name' => 'A B', 'phone' => '0551234567', 'address' => 'x street', 'city' => 'Riyad City North',
        ])]);

        $result = $this->adapter()->preflight($fulfillment);

        $this->assertSame(HoldReason::CITY_UNMAPPED, $result->holdReason);
        $this->assertSame('Riyad City North', $result->detail);
    }

    public function test_an_invalid_phone_or_empty_address_holds_the_order(): void
    {
        $badPhone = $this->torodFulfillment(['shipping_address_data' => json_encode(['contact_person_name' => 'A', 'phone' => '123', 'address' => 'x', 'city' => 'Riyadh'])]);
        $this->assertSame(HoldReason::PHONE_INVALID, $this->adapter()->preflight($badPhone)->holdReason);

        $noAddress = $this->torodFulfillment(['shipping_address_data' => json_encode(['contact_person_name' => 'A', 'phone' => '0551234567', 'address' => '', 'city' => 'Riyadh'])], orderId: 101);
        $this->assertSame(HoldReason::ADDRESS_INCOMPLETE, $this->adapter()->preflight($noAddress)->holdReason);
    }

    public function test_known_low_stock_holds_but_stale_stock_does_not(): void
    {
        $fulfillment = $this->torodFulfillment();
        ProviderProductMapping::query()->update(['stock_source' => 'provider_sync', 'provider_stock' => 1, 'provider_stock_synced_at' => now()]);
        $this->assertSame(HoldReason::STOCK_UNAVAILABLE, $this->adapter()->preflight($fulfillment)->holdReason);

        ProviderProductMapping::query()->update(['provider_stock_synced_at' => now()->subDays(3)]);
        $this->assertTrue($this->adapter()->preflight($fulfillment)->ok);
    }

    // ---- cities -------------------------------------------------------------------------------

    public function test_cities_are_synced_from_the_documented_response_and_cached_normalised(): void
    {
        \App\Models\ProviderCity::query()->delete();
        Http::fake([self::V2 . '/addresses/cities*' => Http::response(['data' => [
            ['createdDate' => '2024-02-29T12:59:26.000+0000', 'id' => 41125, 'companyId' => 0, 'name' => 'Riyadh', 'arabicName' => 'الرياض', 'englishName' => 'Riyadh', 'regionId' => 1335, 'hubId' => 0],
            ['id' => 41127, 'name' => 'Dammam', 'arabicName' => 'الدمام', 'englishName' => 'Dammam', 'regionId' => 1337],
        ]]), ]);

        $result = $this->adapter()->syncCities();

        $this->assertSame(2, $result['count']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), self::V2 . '/addresses/cities') && str_contains($request->url(), 'returnAll=true') && $request->header('company-id')[0] === '714');
        $this->assertSame('41125', app(TorodCityResolver::class)->resolve('الرياض')->external_id);
    }

    public function test_city_matching_is_exact_after_normalising_arabic_and_english(): void
    {
        $resolver = app(TorodCityResolver::class);

        foreach (['الرياض', 'رياض', 'الرِّيَاض', 'Riyadh', 'RIYADH', ' riyadh '] as $written) {
            $this->assertSame('41125', $resolver->resolve($written)?->external_id, $written);
        }
        $this->assertSame('41126', $resolver->resolve('Unknown', 'جدة')?->external_id, 'the state is the fallback');
        $this->assertNull($resolver->resolve('Riyadh North'));
        $this->assertNull($resolver->resolve(''));
        $this->assertNull($resolver->resolve(null));
    }

    public function test_an_ambiguous_city_name_is_refused_not_picked(): void
    {
        \App\Models\ProviderCity::create(['provider' => 'torod_point', 'external_id' => '99999', 'name_en' => 'Riyadh', 'normalized_en' => 'riyadh']);

        $this->assertNull(app(TorodCityResolver::class)->resolve('Riyadh'));
    }

    public function test_an_explicit_alias_resolves_an_unusual_spelling(): void
    {
        $resolver = app(TorodCityResolver::class);
        $this->assertNull($resolver->resolve('Al-Khobar Corniche'));

        $resolver->addAlias('Al-Khobar Corniche', '41127', 1);

        $this->assertSame('41127', $resolver->resolve('al-khobar   corniche')->external_id);
    }

    // ---- lastmile helpers: cancel, awb, status ---------------------------------------------------

    public function test_a_warehouse_order_cannot_be_cancelled_remotely_before_its_package_exists(): void
    {
        $fulfillment = $this->torodFulfillment();

        $result = $this->adapter()->cancel($fulfillment);

        $this->assertSame('unsupported', $result->outcome);
        Http::assertNothingSent();
    }

    public function test_a_cancellation_is_not_sent_while_the_provider_is_switched_off_and_goes_through_once_it_is_back(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA100453706880'])->save();
        Http::fake([self::V2 . '/guests/714/packages/KSA100453706880/cancel' => Http::response(['success' => true])]);
        app(\App\Services\Fulfillment\ProviderConnectionManager::class)->save(provider: ProviderCode::TorodPoint, enabled: false, baseUrl: null, settings: ['company_id' => '714', 'dry_run' => false, 'dispatch_enabled' => true], secrets: []);

        try {
            $this->adapter()->cancel($fulfillment);
            $this->fail('a cancellation left the host while Integration enabled was off');
        } catch (ProviderUnavailableException) {
            Http::assertNothingSent();
        }

        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714']);

        $this->assertSame('requested', $this->adapter()->cancel($fulfillment)->outcome);
        Http::assertSentCount(1);
    }

    public function test_a_package_is_cancelled_through_the_lastmile_api_with_our_company_id(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA100422577363'])->save();
        Http::fake([self::V2 . '/guests/714/packages/KSA100422577363/cancel' => Http::response(['status' => 'ok'])]);

        $result = $this->adapter()->cancel($fulfillment);

        $this->assertSame('requested', $result->outcome, 'a 2xx only means the request was received');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['email'] === 'lm@example.test' && $request['password'] === 'lm-pass-secret-9' && $request->header('company-id')[0] === '714');
    }

    public function test_a_refused_cancellation_is_reported_as_refused(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA1'])->save();
        Http::fake([self::V2 . '/guests/714/packages/KSA1/cancel' => Http::response(['message' => 'Package already picked'], 400)]);

        $result = $this->adapter()->cancel($fulfillment);

        $this->assertSame('refused', $result->outcome);
        $this->assertStringContainsString('already picked', (string) $result->message);
    }

    // ---- external writes off: the adapter's real write paths ---------------------------------------

    private function assertRefusedAsExternalWrite(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('a write was attempted while external writes are off');
        } catch (ProviderUnavailableException $exception) {
            $this->assertStringContainsString('FULFILLMENT_EXTERNAL_WRITES_ENABLED', $exception->getMessage());
        }
    }

    public function test_with_external_writes_off_a_warehouse_order_is_refused_and_nothing_is_sent(): void
    {
        $fulfillment = $this->torodFulfillment();
        config(['fulfillment.external_writes_enabled' => false]);
        Http::fake();

        $this->assertRefusedAsExternalWrite(fn () => $this->submitAsClaimed($this->adapter(), $fulfillment));

        Http::assertNothingSent();
    }

    public function test_with_external_writes_off_a_package_cancellation_is_refused_and_nothing_is_sent(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA100422577363'])->save();
        config(['fulfillment.external_writes_enabled' => false]);
        Http::fake();

        $this->assertRefusedAsExternalWrite(fn () => $this->adapter()->cancel($fulfillment));

        Http::assertNothingSent();
    }

    public function test_with_external_writes_off_a_product_push_is_refused_but_its_dry_run_preview_still_works(): void
    {
        $this->torodFulfillment();
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'company_id' => '714', 'dry_run' => false]);
        config(['fulfillment.external_writes_enabled' => false]);
        Http::fake();

        $this->assertRefusedAsExternalWrite(fn () => $this->adapter()->pushProducts([1]));

        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'company_id' => '714', 'dry_run' => true]);
        $preview = $this->adapter()->pushProducts([1]);

        $this->assertTrue($preview['ok']);
        $this->assertTrue($preview['dry_run']);
        Http::assertNothingSent();
    }

    public function test_with_external_writes_off_reads_still_work_but_the_air_waybill_post_is_held(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA100453706880'])->save();
        config(['fulfillment.external_writes_enabled' => false]);
        Http::fake([
            self::V2 . '/guests/packages/status*' => Http::response(['status' => 'OUT_FOR_DELIVERY', 'cod' => 0]),
            self::V2 . '/guests/714/packages/pdf' => Http::response(['url' => 'https://elasticbeanstalk-eu-central-1.s3.amazonaws.com/awb.pdf']),
            self::V5 . '/public/fulfillment/product*' => Http::response(['data' => []]),
        ]);

        $this->assertSame('OUT_FOR_DELIVERY', $this->adapter()->fetchStatus($fulfillment)?->providerStatus);
        $this->assertTrue($this->adapter()->testConnection()->ok);

        try {
            $this->adapter()->labelUrl($fulfillment);
            $this->fail('the air waybill post left the host with external writes off');
        } catch (ProviderUnavailableException) {
            Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/packages/pdf'));
        }
    }

    public function test_the_capability_report_marks_every_writing_capability_off_while_external_writes_are_off(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'company_id' => '714', 'dry_run' => false]);
        config(['fulfillment.external_writes_enabled' => false]);

        $report = $this->adapter()->readiness();

        foreach (['dispatch', 'product_push', 'cancel_package', 'air_waybill'] as $writing) {
            $this->assertSame(CapabilityState::READY_DISABLED, $report->capability($writing)->state, $writing);
            $this->assertSame('external_writes_off', $report->capability($writing)->reason, $writing);
        }
        $this->assertFalse($report->canUse('dispatch'));
        foreach (['connection', 'product_catalog', 'cities', 'package_status', 'status_poll'] as $reading) {
            $this->assertNotSame('external_writes_off', $report->capability($reading)->reason, $reading);
        }
        $this->assertTrue($report->canUse('cities'));
        $this->assertTrue($report->canUse('package_status'));
    }

    public function test_the_same_capabilities_are_enabled_when_external_writes_are_on(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['push_products_enabled' => true, 'company_id' => '714', 'dry_run' => false]);

        $report = $this->adapter()->readiness();

        foreach (['dispatch', 'product_push', 'cancel_package', 'air_waybill'] as $writing) {
            $this->assertSame(CapabilityState::ENABLED, $report->capability($writing)->state, $writing);
        }
    }

    public function test_the_air_waybill_comes_from_the_documented_pdf_endpoint(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA100453706880'])->save();
        Http::fake([self::V2 . '/guests/714/packages/pdf' => Http::response(['url' => 'https://elasticbeanstalk-eu-central-1.s3.amazonaws.com/awb.pdf'])]);

        $url = $this->adapter()->labelUrl($fulfillment);

        $this->assertSame('https://elasticbeanstalk-eu-central-1.s3.amazonaws.com/awb.pdf', $url);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['barcodes'] === ['KSA100453706880']);
    }

    public function test_an_air_waybill_url_that_is_not_https_is_not_handed_out(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA1'])->save();
        Http::fake([self::V2 . '/guests/714/packages/pdf' => Http::response(['url' => 'javascript:alert(1)'])]);

        $this->assertNull($this->adapter()->labelUrl($fulfillment));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignLabelHosts')]
    public function test_an_air_waybill_url_on_a_host_the_provider_does_not_use_is_not_handed_out(string $url): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA1'])->save();
        Http::fake([self::V2 . '/guests/714/packages/pdf' => Http::response(['url' => $url])]);

        $this->assertNull($this->adapter()->labelUrl($fulfillment));
    }

    /** @return iterable<string, array{string}> */
    public static function foreignLabelHosts(): iterable
    {
        yield 'another site' => ['https://evil.example/awb.pdf'];
        yield 'look-alike suffix' => ['https://notamazonaws.com/awb.pdf'];
        yield 'look-alike prefix' => ['https://amazonaws.com.evil.example/awb.pdf'];
        yield 'credentials in the url' => ['https://x.s3.amazonaws.com@evil.example/awb.pdf'];
        yield 'plain http' => ['http://x.s3.amazonaws.com/awb.pdf'];
        yield 'internal address' => ['https://169.254.169.254/latest/meta-data'];
    }

    public function test_no_label_is_available_before_the_package_barcode_is_known(): void
    {
        $this->assertNull($this->adapter()->labelUrl($this->torodFulfillment()));
        Http::assertNothingSent();
    }

    public function test_package_status_is_read_from_the_documented_endpoint(): void
    {
        $fulfillment = $this->torodFulfillment();
        $fulfillment->forceFill(['provider_secondary_reference' => 'KSA7'])->save();
        Http::fake([self::V2 . '/guests/packages/status*' => Http::response(['cost' => 77.0, 'cod' => 20.0, 'status' => 'PENDING_CUSTOMER_CARE_APPROVAL', 'notes' => '', 'id' => 7624])]);

        $snapshot = $this->adapter()->fetchStatus($fulfillment);

        $this->assertSame('PENDING_CUSTOMER_CARE_APPROVAL', $snapshot->providerStatus);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'barcode=KSA7') && $request->method() === 'GET');
    }

    // ---- readiness & status vocabulary -----------------------------------------------------------

    public function test_the_capability_report_states_what_the_documents_do_not_provide(): void
    {
        $report = $this->adapter()->readiness();

        foreach (['inventory_endpoint', 'cancel_warehouse_order', 'warehouse_order_status_lookup'] as $undocumented) {
            $this->assertSame(CapabilityState::UNDOCUMENTED, $report->capability($undocumented)->state, $undocumented);
        }
        $this->assertSame(CapabilityState::UNVERIFIED, $report->capability('inventory_sync')->state);
        $this->assertTrue($report->canUse('dispatch'));
        $this->assertTrue($report->canUse('cancel_package'));
    }

    public function test_dispatch_needs_the_company_id_to_be_saved_explicitly(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '', 'dry_run' => false]);

        $report = $this->adapter()->readiness();

        $this->assertFalse($report->canUse('dispatch'));
        $this->assertContains('company_id', $report->missing);
    }

    public function test_the_real_defaults_keep_everything_off_and_dry_run_on(): void
    {
        $defaults = \App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter::defaultSettings();

        $this->assertFalse($defaults['dispatch_enabled']);
        $this->assertTrue($defaults['dry_run']);
        $this->assertFalse($defaults['inventory_sync_enabled']);
        $this->assertFalse($defaults['push_products_enabled']);
        $this->assertFalse($defaults['webhooks_enabled']);
        $this->assertSame('', $defaults['company_id'], 'the company id must be entered and saved by a person');
        $this->assertSame('https://apisv2.logestechs.com/api', $defaults['lastmile_base_url']);
        $this->assertSame('', $defaults['cod_collection_method']);
    }

    public function test_the_connection_test_is_a_read_only_one_row_product_list_call(): void
    {
        Http::fake([self::V5 . '/public/fulfillment/product*' => Http::response([])]);

        $result = $this->adapter()->testConnection();

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), 'page=1') && str_contains($request->url(), 'pageSize=1'));
        $this->assertCount(1, Http::recorded());
    }

    public function test_a_failed_connection_test_explains_why_without_leaking_credentials(): void
    {
        Http::fake([self::V5 . '/public/fulfillment/product*' => Http::response(['message' => 'Invalid username or password'], 401)]);

        $result = $this->adapter()->testConnection();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Invalid username or password', $result->message);
        $this->assertStringNotContainsString('wh-pass-secret-9', $result->message);
    }

    public function test_every_documented_lastmile_status_has_a_defined_meaning(): void
    {
        $documented = ['PENDING_CUSTOMER_CARE_APPROVAL', 'APPROVED_BY_CUSTOMER_CARE_AND_WAITING_FOR_DISPATCHER', 'CANCELLED', 'ASSIGNED_TO_DRIVER_AND_PENDING_APPROVAL',
            'REJECTED_BY_DRIVER_AND_PENDING_MANGEMENT', 'ACCEPTED_BY_DRIVER_AND_PENDING_PICKUP', 'SCANNED_BY_DRIVER_AND_IN_CAR', 'OPENED_ISSUE_AND_WAITING_FOR_MANAGEMENT',
            'MOVED_TO_SHELF_AND_OUT_OF_HANDLER_CUSTODY', 'DELIVERED_TO_RECIPIENT', 'POSTPONED_DELIVERY', 'RETURNED_BY_RECIPIENT', 'COMPLETED', 'TRANSFERRED_OUT',
            'PARTIALLY_DELIVERED', 'SWAPPED', 'BROUGHT', 'DELIVERED_TO_SENDER', 'EXPORTED_TO_THIRD_PARTY', 'FAILED', 'DAMAGED', 'LOST', 'OUT_FOR_DELIVERY', 'SCANNED_BY_HANDLER_AND_UNLOADED'];

        foreach ($documented as $status) {
            $this->assertNotNull($this->adapter()->mapStatus($status), "{$status} has no mapping");
        }
        $this->assertNull($this->adapter()->mapStatus('DRAFT'), 'a draft package is not a shipment state');
        $this->assertNull($this->adapter()->mapStatus('SOMETHING_NEW'), 'an unknown status is never guessed');
    }
}
