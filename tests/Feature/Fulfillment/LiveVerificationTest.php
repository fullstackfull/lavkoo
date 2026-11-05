<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Verification\CheckResult;
use App\Services\Fulfillment\Verification\EvidenceStore;
use App\Services\Fulfillment\Verification\LiveVerification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * The read-only live verification: what it asks, that it can only read (even on a host that may write), what it keeps as evidence,
 * and the page that runs it. The provider answers used here are ASSUMED shapes (neither provider publishes a response schema);
 * recording the real ones is exactly what the feature is for.
 */
class LiveVerificationTest extends AdminPagesTestCase
{
    private const M5 = 'https://m5azn.com';
    private const V5 = 'https://apisv5.logestechs.com/api';
    private const V2 = 'https://apisv2.logestechs.com/api';

    private const PEOPLE = ['Sara Alharbi', '0551234567', '00966551234567', 'King Fahd Rd 12', 'sara@example.test', 'Leave with the guard', 'Ring twice, the bell is broken'];

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/fulfillment-evidence'));
        parent::tearDown();
    }

    private function jwt(): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['typ' => 'JWT']) . '.' . $encode(['sub' => '1', 'exp' => time() + 86400 * 300]) . '.signature-signature';
    }

    private function fakeM5azn(): void
    {
        $person = ['name' => 'Sara Alharbi', 'phone' => '0551234567', 'email' => 'sara@example.test'];
        Http::fake([
            self::M5 . '/api/v1/auth/login' => Http::response(['data' => ['accessToken' => $this->jwt(), 'userId' => 59653]]),
            self::M5 . '/api/v1/products/qty-changed*' => Http::response(['data' => [['id' => 1947201, 'quantity' => 38, 'changed_at' => '2026-11-05T10:00:00Z']]]),
            self::M5 . '/api/v1/products*' => Http::response(['data' => [['id' => 1947201, 'name' => 'Vitamin C', 'sku' => 'VC1000', 'quantity' => 40, 'price' => 19.0], ['id' => 313658, 'name' => 'Sanitizer', 'sku' => 'HS500', 'quantity' => 8, 'price' => 9.0]], 'meta' => ['total' => 2, 'page' => 1]]),
            self::M5 . '/api/v1/seller/myproductlist*' => Http::response(['data' => [['id' => 1947201, 'name' => 'Vitamin C', 'sku' => 'VC1000', 'quantity' => 40]]]),
            self::M5 . '/api/v1/product/details/1947201' => Http::response(['data' => ['id' => 1947201, 'name' => 'Vitamin C', 'variants' => [['sku' => 'VC1000-30', 'quantity' => 40]]]]),
            self::M5 . '/api/v1/catalog/categories' => Http::response(['data' => [['id' => 5, 'name' => 'Vitamins']]]),
            self::M5 . '/api/v1/orders' => Http::response(['data' => [['id' => 777, 'status' => 'processing', 'customer' => $person, 'shipping_address' => 'King Fahd Rd 12', 'notes' => 'Leave with the guard']]]),
            self::M5 . '/api/orders/777' => Http::response(['data' => ['id' => 777, 'status' => 'processing', 'customer' => $person, 'deliveryInstructions' => 'Ring twice, the bell is broken']]),
        ]);
    }

    /** @param array<string, mixed> $overrides answers that replace the default one for the same URL pattern */
    private function fakeTorod(array $overrides = []): void
    {
        $page1 = ['data' => array_map(static fn (int $n): array => ['id' => 1421520 + $n, 'name' => 'Product ' . $n, 'sku' => (string) (13220 + $n), 'stock' => $n * 10, 'reserved' => $n, 'status' => 'ACTIVE'], range(1, 3)), 'total' => 5];
        $page2 = ['data' => array_map(static fn (int $n): array => ['id' => 1421520 + $n, 'name' => 'Product ' . $n, 'sku' => (string) (13220 + $n), 'stock' => $n * 10, 'reserved' => $n, 'status' => 'ACTIVE'], range(4, 5)), 'total' => 5];
        Http::fake($overrides + [
            self::V5 . '/public/fulfillment/product/category*' => Http::response(['data' => [['id' => 1, 'name' => 'Vitamins']]]),
            self::V5 . '/public/fulfillment/product?page=1&*' => Http::response($page1),
            self::V5 . '/public/fulfillment/product?page=2&*' => Http::response($page2),
            self::V5 . '/public/fulfillment/order*' => Http::response(['data' => [['id' => 4455, 'barcode' => 'O0015030400001', 'invoiceNumber' => '100-TP1', 'status' => 'PICKED', 'receiverName' => 'Sara Alharbi', 'receiverPhone' => '00966551234567', 'receiverAddress' => ['cityId' => 41125, 'addressLine1' => 'King Fahd Rd 12'], 'notes' => 'Leave with the guard']]]),
            self::V2 . '/addresses/cities*' => Http::response(['data' => [['id' => 41125, 'nameAr' => 'الرياض', 'nameEn' => 'Riyadh', 'regionId' => 1335]]]),
            self::V5 . '/addresses/villages*' => Http::response(['data' => [['id' => 9, 'name' => 'Village']]]),
            self::V2 . '/guests/packages/status*' => Http::response(['id' => 368200054, 'status' => 'OUT_FOR_DELIVERY', 'cod' => 0]),
        ]);
    }

    private function verify(ProviderCode $provider, array $options = []): \App\Services\Fulfillment\Verification\VerificationRun
    {
        return app(LiveVerification::class)->run($provider, $options);
    }

    /** @return array<string, CheckResult> */
    private function byId(\App\Services\Fulfillment\Verification\VerificationRun $run): array
    {
        return array_column(array_map(static fn (CheckResult $check): array => ['id' => $check->id, 'check' => $check], $run->checks), 'check', 'id');
    }

    // ── M5AZN ────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_m5azn_run_reads_products_orders_and_stock_and_records_their_shape(): void
    {
        $this->fakeM5azn();

        $run = $this->verify(ProviderCode::M5azn);

        $checks = $this->byId($run);
        $this->assertSame(['M-1', 'M-2', 'M-3', 'M-4', 'M-5', 'M-6', 'M-7', 'M-8'], array_keys($checks));
        foreach ($checks as $id => $check) {
            $this->assertSame(CheckResult::PASS, $check->status, "{$id}: {$check->summary}");
        }
        $this->assertTrue($checks['M-1']->findings['token_issued']);
        $this->assertSame('data', $checks['M-2']->findings['items_path']);
        $this->assertSame(['quantity'], array_column($checks['M-2']->findings['stock_fields'], 'field'), 'the catalogue quantity is the stock candidate');
        $this->assertSame(['quantity'], array_column($checks['M-5']->findings['stock_fields'], 'field'), 'and so is the stock-change feed');
        $this->assertSame(['meta.total' => 2.0, 'meta.page' => 1.0], $checks['M-2']->findings['pagination']);
        $this->assertSame([['field' => 'status', 'values' => ['processing' => 1]]], $checks['M-7']->findings['status_fields'], 'the order status vocabulary M5AZN does not document');
        $this->assertSame(1, $checks['M-8']->findings['status_fields'][0]['values']['processing']);
    }

    public function test_the_run_sends_only_reads_and_the_login_and_never_an_order(): void
    {
        $this->fakeM5azn();
        config(['fulfillment.external_writes_enabled' => true]);

        $this->verify(ProviderCode::M5azn);

        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            $isLogin = $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/auth/login');
            $this->assertTrue($request->method() === 'GET' || $isLogin, "{$request->method()} {$request->url()} is not a read");
        }
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/api/v1/orders'));
    }

    public function test_torod_sends_only_reads_even_when_external_writes_are_on(): void
    {
        $this->fakeTorod();
        config(['fulfillment.external_writes_enabled' => true]);

        $this->verify(ProviderCode::TorodPoint);

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method(), "{$request->method()} {$request->url()} is not a read");
        }
    }

    public function test_the_check_lists_contain_no_write_call(): void
    {
        foreach (['M5aznChecks', 'TorodChecks', 'LiveVerification'] as $class) {
            $source = (string) file_get_contents(app_path("Services/Fulfillment/Verification/{$class}.php"));
            foreach (['createOrder', 'upsertProducts', 'cancelPackage', 'airWaybill', '->orderCreate', "'POST'"] as $forbidden) {
                if ($forbidden === "'POST'" && $class === 'M5aznChecks') {
                    continue; // M-1 documents that the login is a POST in its request description
                }
                $this->assertStringNotContainsString($forbidden, $source, "{$class} must not reach for '{$forbidden}'");
            }
        }
    }

    // ── Torod Point ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_torod_run_lists_the_stock_candidates_and_compares_the_counted_skus(): void
    {
        $this->fakeTorod();

        $run = $this->verify(ProviderCode::TorodPoint, ['skus' => ['13221', '13224', '99999']]);

        $checks = $this->byId($run);
        $this->assertSame(['T-1', 'T-2', 'T-3', 'T-4', 'T-5', 'T-6', 'T-7'], array_keys($checks));
        foreach (['T-1', 'T-2', 'T-3', 'T-4', 'T-5', 'T-6'] as $id) {
            $this->assertSame(CheckResult::PASS, $checks[$id]->status, "{$id}: {$checks[$id]->summary}");
        }
        $this->assertSame(['stock', 'reserved'], array_column($checks['T-1']->findings['stock_fields'], 'field'));
        $this->assertSame(30.0, $checks['T-1']->findings['stock_fields'][0]['max']);
        $this->assertSame(['T-2' => 0], ['T-2' => $checks['T-2']->findings['records_also_on_page_1']]);
        $this->assertStringContainsString('paging works as assumed', $checks['T-2']->findings['paging']);

        $comparison = array_column($checks['T-2']->findings['stock_comparison'], null, 'sku');
        $this->assertSame(['stock' => 10, 'reserved' => 1], $comparison['13221']['numeric_fields']);
        $this->assertSame(['stock' => 40, 'reserved' => 4], $comparison['13224']['numeric_fields'], 'a SKU on page 2 is found too');
        $this->assertFalse($comparison['99999']['found']);
        $this->assertSame(CheckResult::SKIPPED, $checks['T-7']->status, 'no package of ours exists yet');
        $this->assertStringContainsString('No package of ours', $checks['T-7']->summary);
        $this->assertSame(['orders' => 1, 'with_barcode' => 1, 'barcode_starts_with_O' => 1, 'with_invoice_number' => 1], $checks['T-4']->findings['warehouse_order_barcodes']);
    }

    public function test_the_package_status_is_asked_for_our_newest_package_once_there_is_one(): void
    {
        $this->fakeTorod();
        $this->makeProduct(2, ProviderCode::TorodPoint);
        $this->makeOrder(100);
        $this->addLine(100, 2, 1, 35, ProviderCode::TorodPoint);
        app(\App\Services\Fulfillment\FulfillmentRouter::class)->route(100);
        $fulfillment = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted, 'provider_secondary_reference' => 'KSA100422577363'])->save();

        $checks = $this->byId($this->verify(ProviderCode::TorodPoint));

        $this->assertSame(CheckResult::PASS, $checks['T-7']->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/guests/packages/status') && str_contains($request->url(), 'KSA100422577363'));
    }

    // ── what is kept ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_evidence_holds_the_shape_and_no_person_and_no_credential(): void
    {
        $this->fakeM5azn();
        $run = $this->verify(ProviderCode::M5azn);
        $this->fakeTorod();
        $torod = $this->verify(ProviderCode::TorodPoint);

        foreach ([[ProviderCode::M5azn, $run], [ProviderCode::TorodPoint, $torod]] as [$provider, $saved]) {
            $path = app(EvidenceStore::class)->path($provider->value, $saved->id);
            $this->assertNotNull($path);
            $json = (string) file_get_contents($path);

            foreach ([...self::PEOPLE, ...array_values(self::SECRETS), $this->jwt()] as $private) {
                $this->assertStringNotContainsString($private, $json, "{$provider->value}: '{$private}' is in the evidence");
            }
            $this->assertSame($saved->id, json_decode($json, true)['run_id']);
            $this->assertSame(0640, fileperms($path) & 0777);
        }
        $this->assertStringContainsString('O0015030400001', (string) file_get_contents(app(EvidenceStore::class)->path('torod_point', $torod->id)), 'a parcel reference is kept');
    }

    public function test_the_run_is_recorded_as_a_synchronisation_state_the_card_can_show(): void
    {
        $this->fakeM5azn();

        $run = $this->verify(ProviderCode::M5azn);

        $state = ProviderSyncState::where('provider', 'm5azn')->where('capability', 'live_verification')->firstOrFail();
        $this->assertNotNull($state->last_success_at);
        $this->assertSame($run->id, $state->stats['run_id']);
    }

    // ── when it cannot run ───────────────────────────────────────────────────────────────────────────────────

    public function test_with_the_integration_switched_off_nothing_is_asked_and_nothing_is_kept(): void
    {
        Http::fake();
        config(['fulfillment.enabled' => false]);

        try {
            $this->verify(ProviderCode::M5azn);
            $this->fail('the run went ahead with the kill switch on');
        } catch (ProviderUnavailableException $exception) {
            $this->assertStringContainsString('switched off', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame([], app(EvidenceStore::class)->recent('m5azn'));
    }

    public function test_without_saved_credentials_every_check_is_skipped_and_nothing_is_sent(): void
    {
        Http::fake();
        app(ProviderConnectionManager::class)->save(ProviderCode::M5azn, true, null, ['dispatch_enabled' => false], [], ['email', 'password']);

        $run = $this->verify(ProviderCode::M5azn);

        Http::assertNothingSent();
        $this->assertSame(8, $run->summary()[CheckResult::SKIPPED]);
        $this->assertStringContainsString('email', $run->checks[0]->summary);

        $state = ProviderSyncState::where('provider', 'm5azn')->where('capability', 'live_verification')->firstOrFail();
        $this->assertNull($state->last_success_at, 'a run that asked nothing must not count as a successful verification');
        $this->assertNotNull($state->last_failure_at);
    }

    public function test_a_refused_login_is_a_failure_with_a_plain_reason_and_no_token_in_it(): void
    {
        Http::fake([self::M5 . '/*' => Http::response(['message' => 'Invalid credentials for seller@example.test'], 401)]);

        $run = $this->verify(ProviderCode::M5azn);

        $this->assertSame(CheckResult::FAIL, $this->byId($run)['M-1']->status);
        $this->assertStringContainsString('refused the credentials', $this->byId($run)['M-1']->summary);
        $this->assertTrue($run->failed());
        $this->assertNotNull(ProviderSyncState::where('provider', 'm5azn')->where('capability', 'live_verification')->value('last_failure_at'));
    }

    public function test_an_empty_list_is_a_warning_because_there_is_nothing_to_record_a_schema_from(): void
    {
        $this->fakeTorod([self::V5 . '/public/fulfillment/order*' => Http::response(['data' => []])]);

        $check = $this->byId($this->verify(ProviderCode::TorodPoint))['T-4'];

        $this->assertSame(CheckResult::WARN, $check->status);
        $this->assertStringContainsString('empty list', $check->summary);
    }

    public function test_an_answer_with_no_list_of_records_is_a_warning_that_names_the_remedy(): void
    {
        $this->fakeTorod([self::V5 . '/public/fulfillment/product/category*' => Http::response(['message' => 'ok'])]);

        $check = $this->byId($this->verify(ProviderCode::TorodPoint))['T-3'];

        $this->assertSame(CheckResult::WARN, $check->status, $check->summary);
        $this->assertStringContainsString('set the items path', $check->summary);
    }

    // ── the page ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_provider_page_runs_the_checks_and_shows_the_result_with_a_download(): void
    {
        $this->fakeTorod();

        $this->post(route('admin.fulfillment-providers.verification.run', 'torod-point'), ['skus' => '13221, 13224'])
            ->assertRedirect(route('admin.fulfillment-providers.show', 'torod-point') . '#live-verification');
        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();

        $this->assertStringContainsString('id="live-verification"', $html);
        $this->assertStringContainsString(e(translate('verification_check_torod_products')), $html);
        $this->assertStringContainsString(e(translate('verification_status_pass')), $html);
        $this->assertStringContainsString(e(translate('download_evidence')), $html);
        foreach ([...self::PEOPLE, ...array_values(self::SECRETS)] as $private) {
            $this->assertStringNotContainsString($private, $html, "'{$private}' is on the page");
        }

        $run = app(EvidenceStore::class)->recent('torod_point')[0]['run_id'];
        $download = $this->get(route('admin.fulfillment-providers.verification.download', ['torod-point', $run]));
        $download->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertSame($run, json_decode(file_get_contents($download->baseResponse->getFile()->getPathname()), true)['run_id']);
    }

    public function test_the_evidence_of_one_provider_cannot_be_fetched_as_another_and_a_path_cannot_be_smuggled_in(): void
    {
        $this->fakeM5azn();
        $run = $this->verify(ProviderCode::M5azn);

        $this->get(route('admin.fulfillment-providers.verification.download', ['m5azn', $run->id]))->assertOk();
        $this->get(route('admin.fulfillment-providers.verification.download', ['torod-point', $run->id]))->assertNotFound();
        $this->get(route('admin.fulfillment-providers.verification.download', ['m5azn', 'm5azn_20260101_000000_deadbeef']))->assertNotFound();
        $this->get('/admin/fulfillment-providers/m5azn/verification/..%2F..%2F.env')->assertNotFound();
        $this->get('/admin/fulfillment-providers/m5azn/verification/' . urlencode('../../.env'))->assertNotFound();
    }

    public function test_the_run_form_accepts_only_plain_sku_lists_and_is_rate_limited(): void
    {
        Http::fake();
        config(['fulfillment.enabled' => false]);

        $this->post(route('admin.fulfillment-providers.verification.run', 'torod-point'), ['skus' => '13221; DROP TABLE orders'])->assertSessionHasErrors('skus');
        $this->post(route('admin.fulfillment-providers.verification.run', 'nobody'))->assertNotFound();

        foreach (range(1, 2) as $ignored) {
            $this->post(route('admin.fulfillment-providers.verification.run', 'm5azn'))->assertRedirect();
        }
        $this->post(route('admin.fulfillment-providers.verification.run', 'm5azn'))->assertStatus(429);
    }

    public function test_with_the_integration_off_the_page_says_so_instead_of_pretending(): void
    {
        Http::fake();
        config(['fulfillment.enabled' => false]);

        $this->post(route('admin.fulfillment-providers.verification.run', 'm5azn'))->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('provider_api_logs')->count());
    }
}
