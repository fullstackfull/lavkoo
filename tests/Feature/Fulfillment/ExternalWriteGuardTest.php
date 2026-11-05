<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\IntegrationMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderApiLog;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\Providers\M5azn\M5aznClient;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFulfillmentClient;
use App\Services\Fulfillment\Providers\TorodPoint\TorodLastmileClient;
use App\Services\Fulfillment\Support\ExternalOperations;
use App\Services\Fulfillment\Support\HttpResult;
use App\Services\Fulfillment\Support\ProviderHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The third outbound switch: this host may READ from providers but may not WRITE to them.
 *
 * Layer 1 is the global kill switch, layer 3 the per-provider admin switches; this layer sits in the transport
 * (`ProviderHttp`), below both, so no code path (a job, a controller, a console command, a future feature) can
 * create, change or cancel anything at a provider while `FULFILLMENT_EXTERNAL_WRITES_ENABLED` is off.
 */
class ExternalWriteGuardTest extends FulfillmentTestCase
{
    private const M5AZN = 'https://m5azn.com';
    private const V5 = 'https://apisv5.logestechs.com/api';
    private const V2 = 'https://apisv2.logestechs.com/api';

    /** Clients are scanned for the operation names they send; the Torod clients prefix theirs in `send()`. */
    private const CLIENTS = [
        'app/Services/Fulfillment/Providers/M5azn/M5aznClient.php' => '',
        'app/Services/Fulfillment/Providers/TorodPoint/TorodFulfillmentClient.php' => 'fulfillment_',
        'app/Services/Fulfillment/Providers/TorodPoint/TorodLastmileClient.php' => 'lastmile_',
    ];

    /** The only files allowed to open a connection. Everything else goes through ProviderHttp. */
    private const NETWORK_FILES = [
        'app/Services/Fulfillment/Support/ProviderHttp.php',
        'app/Services/Fulfillment/Support/RemoteImageFetcher.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['fulfillment.external_writes_enabled' => false]);
        $this->enableProvider(ProviderCode::M5azn, [], ['email' => 'seller@example.test', 'password' => 'pw-very-secret-1']);
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714', 'lastmile_base_url' => self::V2], [
            'fulfillment_username' => 'wh-user', 'fulfillment_password' => 'wh-pass-secret-9',
            'lastmile_email' => 'lm@example.test', 'lastmile_password' => 'lm-pass-secret-9',
        ]);
    }

    // ── the registry ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_exactly_these_operations_are_writes(): void
    {
        $writes = ExternalOperations::operationsOfKind(ExternalOperations::WRITE);
        sort($writes);

        // A tripwire on purpose: adding a write means editing this list, which means someone decided it on purpose.
        $this->assertSame(['create_order', 'fulfillment_order_create', 'fulfillment_product_upsert', 'lastmile_awb', 'lastmile_package_cancel'], $writes);
        $this->assertSame(['login'], ExternalOperations::operationsOfKind(ExternalOperations::AUTH));
    }

    public function test_a_read_operation_may_only_use_get(): void
    {
        foreach (ExternalOperations::operationsOfKind(ExternalOperations::READ) as $operation) {
            $this->assertSame(['GET'], ExternalOperations::methodsOf($operation), "{$operation} is registered as a read but allows a mutating method");
        }
    }

    public function test_read_only_mode_lets_nothing_but_get_requests_and_the_sign_in_leave_the_host(): void
    {
        foreach (ExternalOperations::registered() as $operation) {
            foreach (ExternalOperations::methodsOf($operation) as $method) {
                $leavesInReadOnlyMode = !ExternalOperations::isWrite($operation, $method);
                $expected = $method === 'GET' || $operation === 'login';
                $this->assertSame($expected, $leavesInReadOnlyMode, "{$method} {$operation}");
            }
        }
    }

    public function test_unknown_operations_and_unexpected_methods_are_writes(): void
    {
        $this->assertTrue(ExternalOperations::isWrite('made_up_operation', 'GET'));
        $this->assertTrue(ExternalOperations::isWrite('catalog_list', 'DELETE'));
        $this->assertTrue(ExternalOperations::isWrite('catalog_list', 'POST'));
        $this->assertTrue(ExternalOperations::isWrite('lastmile_awb', 'PUT'));
        $this->assertTrue(ExternalOperations::isWrite('lastmile_awb', 'POST'));
        $this->assertTrue(ExternalOperations::isWrite('login', 'GET'));
        $this->assertFalse(ExternalOperations::isWrite('catalog_list', 'get'), 'method names are case-insensitive');
    }

    public function test_every_operation_the_clients_send_is_registered_with_the_method_they_use(): void
    {
        $sent = $this->operationsSentByTheClients();

        $this->assertNotEmpty($sent);
        foreach ($sent as [$operation, $method, $file]) {
            $this->assertContains($operation, ExternalOperations::registered(), "{$file} sends '{$operation}', which is not in ExternalOperations");
            $this->assertContains($method, ExternalOperations::methodsOf($operation), "{$file} sends '{$operation}' with {$method}, which the registry does not allow");
        }
    }

    public function test_the_registry_holds_no_operation_that_no_client_sends(): void
    {
        $names = array_map(static fn (array $row): string => $row[0], $this->operationsSentByTheClients());

        $this->assertSame([], array_values(array_diff(ExternalOperations::registered(), $names)), 'stale registry entries: remove them or wire the client');
    }

    public function test_only_the_transport_and_the_image_fetcher_open_connections(): void
    {
        $offenders = [];
        foreach ($this->phpFilesUnder(['app/Services/Fulfillment', 'app/Http/Controllers/Admin/Fulfillment', 'app/Http/Controllers/Api', 'app/Jobs/Fulfillment', 'app/Console/Commands']) as $relative => $source) {
            if (in_array($relative, self::NETWORK_FILES, true) || !str_contains($relative, 'ulfillment')) {
                continue;
            }
            if (preg_match('/\bHttp::|GuzzleHttp|curl_(?:init|exec)|fsockopen|stream_socket_client|file_get_contents\(\s*[\'"]https?:/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'fulfillment code must reach providers through ProviderHttp only');
    }

    // ── the transport, writes off ─────────────────────────────────────────────────────────────────────────────

    public function test_a_write_is_refused_before_anything_leaves_the_host(): void
    {
        Http::fake();

        foreach ([
            'torod order' => fn () => app(TorodFulfillmentClient::class)->createOrder(['invoiceNumber' => '100-TP1']),
            'torod product push' => fn () => app(TorodFulfillmentClient::class)->upsertProducts([['externalId' => 1]]),
            'torod package cancel' => fn () => app(TorodLastmileClient::class)->cancelPackage('KSA100422577363'),
        ] as $label => $attempt) {
            try {
                $attempt();
                $this->fail("{$label} was not refused");
            } catch (ProviderUnavailableException $exception) {
                $this->assertStringContainsString('FULFILLMENT_EXTERNAL_WRITES_ENABLED', $exception->getMessage(), $label);
            }
        }

        Http::assertNothingSent();
        $this->assertSame(0, ProviderApiLog::count(), 'a refused write is not an API call and leaves no audit row');
    }

    public function test_an_m5azn_order_is_refused_and_only_the_token_request_may_have_been_made(): void
    {
        Http::fake([self::M5AZN . '/api/v1/auth/login' => Http::response(['data' => ['accessToken' => $this->unexpiredJwt()]])]);

        try {
            app(M5aznClient::class)->createOrder(['reference' => '100-MZ1']);
            $this->fail('the order was not refused');
        } catch (ProviderUnavailableException) {
            // expected
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/orders'));
        $this->assertSame(['/api/v1/auth/login'], array_map(static fn (array $pair): string => parse_url($pair[0]->url(), PHP_URL_PATH), Http::recorded()->all()));
    }

    public function test_reads_and_the_token_login_still_work_while_writes_are_off(): void
    {
        Http::fake([
            self::M5AZN . '/api/v1/auth/login' => Http::response(['data' => ['accessToken' => $this->unexpiredJwt()]]),
            self::M5AZN . '/api/v1/products*' => Http::response(['data' => []]),
            self::V5 . '/public/fulfillment/product*' => Http::response(['data' => []]),
            self::V5 . '/public/fulfillment/order*' => Http::response(['data' => []]),
            self::V2 . '/addresses/cities*' => Http::response([]),
            self::V2 . '/guests/packages/status*' => Http::response(['status' => 'CREATED']),
        ]);

        $results = [
            app(M5aznClient::class)->catalog(),
            app(TorodFulfillmentClient::class)->listProducts(),
            app(TorodFulfillmentClient::class)->listOrders(),
            app(TorodLastmileClient::class)->cities(),
            app(TorodLastmileClient::class)->packageStatus('KSA100422577363'),
        ];

        foreach ($results as $result) {
            $this->assertTrue($result->ok());
        }
        $this->assertGreaterThanOrEqual(5, ProviderApiLog::count());
    }

    public function test_the_air_waybill_post_is_held_with_the_writes_and_sent_when_they_are_on(): void
    {
        Http::fake([self::V2 . '/guests/714/packages/pdf' => Http::response(['url' => 'https://bucket.s3.amazonaws.com/awb.pdf'])]);

        try {
            app(TorodLastmileClient::class)->airWaybill(['KSA100422577363']);
            $this->fail('the air waybill post was sent with external writes off');
        } catch (ProviderUnavailableException) {
            Http::assertNothingSent();
        }

        config(['fulfillment.external_writes_enabled' => true]);

        $this->assertTrue(app(TorodLastmileClient::class)->airWaybill(['KSA100422577363'])->ok());
        Http::assertSentCount(1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirectStatuses')]
    public function test_a_redirect_is_ambiguous_for_a_write_and_rejected_for_everything_else(int $status): void
    {
        config(['fulfillment.external_writes_enabled' => true]);
        Http::fake(['*' => Http::response('', $status, ['Location' => 'https://elsewhere.example/thanks'])]);
        $http = app(ProviderHttp::class);

        $write = $http->send(provider: ProviderCode::M5azn, operation: 'create_order', method: 'POST', url: self::M5AZN . '/api/v1/orders');
        $read = $http->send(provider: ProviderCode::M5azn, operation: 'catalog_list', method: 'GET', url: self::M5AZN . '/api/v1/products');
        $signIn = $http->send(provider: ProviderCode::M5azn, operation: 'login', method: 'POST', url: self::M5AZN . '/api/v1/auth/login');

        $this->assertSame(HttpResult::AMBIGUOUS, $write->kind, 'a write that was redirected may have been acted on');
        $this->assertSame(HttpResult::REJECTED, $read->kind);
        $this->assertSame(HttpResult::REJECTED, $signIn->kind);
        Http::assertSentCount(3);
    }

    /** @return iterable<string, array{int}> */
    public static function redirectStatuses(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function test_an_unregistered_operation_or_an_unexpected_method_is_refused_by_the_transport(): void
    {
        Http::fake();
        $http = app(ProviderHttp::class);

        foreach ([['made_up_operation', 'GET'], ['catalog_list', 'DELETE'], ['catalog_list', 'POST']] as [$operation, $method]) {
            try {
                $http->send(provider: ProviderCode::M5azn, operation: $operation, method: $method, url: self::M5AZN . '/api/v1/products');
                $this->fail("{$method} {$operation} was not refused");
            } catch (ProviderUnavailableException) {
                // expected
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_kill_switch_still_refuses_reads_too(): void
    {
        Http::fake();
        config(['fulfillment.enabled' => false]);

        try {
            app(TorodLastmileClient::class)->cities();
            $this->fail('a read was sent with the kill switch on');
        } catch (ProviderUnavailableException $exception) {
            $this->assertStringContainsString('FULFILLMENT_INTEGRATIONS_ENABLED', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_same_write_is_sent_once_writes_are_switched_on(): void
    {
        config(['fulfillment.external_writes_enabled' => true]);
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['id' => 1, 'barcode' => 'O1'])]);

        $result = app(TorodFulfillmentClient::class)->createOrder(['invoiceNumber' => '100-TP1']);

        $this->assertTrue($result->ok());
        Http::assertSentCount(1);
    }

    // ── the mode ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_mode_follows_the_two_environment_switches(): void
    {
        $this->assertSame(IntegrationMode::Off, IntegrationMode::resolve(enabled: false, externalWritesEnabled: false));
        $this->assertSame(IntegrationMode::Off, IntegrationMode::resolve(enabled: false, externalWritesEnabled: true), 'writes cannot be on while the kill switch is');
        $this->assertSame(IntegrationMode::ReadOnly, IntegrationMode::resolve(enabled: true, externalWritesEnabled: false));
        $this->assertSame(IntegrationMode::Live, IntegrationMode::resolve(enabled: true, externalWritesEnabled: true));

        $this->assertFalse(IntegrationMode::Off->canRead());
        $this->assertTrue(IntegrationMode::ReadOnly->canRead());
        $this->assertFalse(IntegrationMode::ReadOnly->canWrite());
        $this->assertTrue(IntegrationMode::Live->canWrite());
    }

    public function test_the_connection_manager_reports_the_mode_and_whether_writes_are_allowed(): void
    {
        $connections = app(ProviderConnectionManager::class);

        $this->assertSame(IntegrationMode::ReadOnly, $connections->mode());
        $this->assertFalse($connections->externalWritesEnabled());

        config(['fulfillment.external_writes_enabled' => true]);
        $this->assertSame(IntegrationMode::Live, $connections->mode());
        $this->assertTrue($connections->externalWritesEnabled());

        config(['fulfillment.enabled' => false]);
        $this->assertSame(IntegrationMode::Off, $connections->mode());
        $this->assertFalse($connections->externalWritesEnabled());
    }

    public function test_the_shipped_defaults_keep_external_writes_off(): void
    {
        $this->assertStringContainsString("env('FULFILLMENT_EXTERNAL_WRITES_ENABLED', false)", (string) file_get_contents(base_path('config/fulfillment.php')));
        $this->assertStringContainsString("env('FULFILLMENT_INTEGRATIONS_ENABLED', false)", (string) file_get_contents(base_path('config/fulfillment.php')));
        $this->assertMatchesRegularExpression('/^FULFILLMENT_EXTERNAL_WRITES_ENABLED=false$/m', (string) file_get_contents(base_path('.env.example')));
        $this->assertMatchesRegularExpression('/^FULFILLMENT_INTEGRATIONS_ENABLED=false$/m', (string) file_get_contents(base_path('.env.example')));
    }

    // ── the dispatcher ────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_real_send_is_held_not_attempted_while_writes_are_off(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false]);
        $fulfillment = $this->torodFulfillment();

        $outcome = app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $fresh = $fulfillment->fresh();
        $this->assertSame(ProviderOrderDispatcher::SKIPPED, $outcome);
        $this->assertSame(FulfillmentStatus::Held, $fresh->status);
        $this->assertSame(HoldReason::EXTERNAL_WRITES_DISABLED, $fresh->hold_reason);
        $this->assertSame(0, $fresh->attempts, 'nothing was sent, so no attempt is consumed');
        $this->assertSame([], $this->torod->calls);
    }

    public function test_an_operator_cannot_force_a_send_past_the_environment_guard(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false]);
        $fulfillment = $this->torodFulfillment();

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id, manual: true);

        $this->assertSame(HoldReason::EXTERNAL_WRITES_DISABLED, $fulfillment->fresh()->hold_reason);
        $this->assertSame([], $this->torod->calls);
    }

    public function test_the_hold_clears_itself_when_the_environment_allows_writes(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false]);
        $fulfillment = $this->torodFulfillment();
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
        $this->assertSame(HoldReason::EXTERNAL_WRITES_DISABLED, $fulfillment->fresh()->hold_reason);

        config(['fulfillment.external_writes_enabled' => true, 'queue.default' => 'sync']);
        $this->torod->submitQueue = [SubmissionResult::accepted('O001')];
        app(FulfillmentRecovery::class)->dispatchDue();

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertCount(1, $this->torod->calls);
    }

    public function test_a_dry_run_is_still_computed_while_writes_are_off(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => true]);
        $fulfillment = $this->torodFulfillment();
        $this->torod->submitQueue = [SubmissionResult::dryRun(['payload_hash' => 'abc'])];

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertSame(FulfillmentStatus::DryRun, $fulfillment->fresh()->status);
    }

    public function test_the_kill_switch_is_reported_ahead_of_the_write_guard(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false]);
        $fulfillment = $this->torodFulfillment();
        config(['fulfillment.enabled' => false]);

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertSame(HoldReason::PROVIDER_DISABLED, $fulfillment->fresh()->hold_reason);
    }

    // ── the environment switches fail closed ─────────────────────────────────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function theTwoEnvironmentSwitches(): array
    {
        return ['the kill switch' => ['FULFILLMENT_INTEGRATIONS_ENABLED', 'enabled'], 'the write switch' => ['FULFILLMENT_EXTERNAL_WRITES_ENABLED', 'external_writes_enabled']];
    }

    /** @dataProvider theTwoEnvironmentSwitches */
    #[\PHPUnit\Framework\Attributes\DataProvider('theTwoEnvironmentSwitches')]
    public function test_every_spelling_of_off_means_off_and_a_typo_never_switches_anything_on(string $variable, string $key): void
    {
        foreach (['off', 'OFF', 'no', 'n', 'disabled', 'none', '0.0', '0', 'false', 'garbage', 'tru', 'ture', ''] as $raw) {
            $this->assertFalse($this->fulfillmentConfigWith($variable, $raw)[$key], "{$variable}={$raw} must not switch anything on");
        }
        foreach (['true', 'TRUE', '1', 'on', 'yes'] as $raw) {
            $this->assertTrue($this->fulfillmentConfigWith($variable, $raw)[$key], "{$variable}={$raw}");
        }
    }

    public function test_mixed_cash_collection_fails_closed_the_same_way(): void
    {
        foreach (['off', 'no', 'disabled', '0.0', 'garbage'] as $raw) {
            $this->assertFalse($this->fulfillmentConfigWith('FULFILLMENT_ALLOW_MIXED_COD', $raw)['cod']['allow_mixed'], $raw);
        }
    }

    public function test_the_preflight_says_when_a_switch_holds_something_that_is_not_an_on_off_value(): void
    {
        $preflight = fn (): array => collect(app(\App\Services\Fulfillment\Readiness\FulfillmentPreflight::class)->safety(allowProduction: true))->keyBy('name')->all();

        $_SERVER['FULFILLMENT_EXTERNAL_WRITES_ENABLED'] = $_ENV['FULFILLMENT_EXTERNAL_WRITES_ENABLED'] = 'disabled';
        try {
            $odd = $preflight()['switch spelling'];
        } finally {
            unset($_SERVER['FULFILLMENT_EXTERNAL_WRITES_ENABLED'], $_ENV['FULFILLMENT_EXTERNAL_WRITES_ENABLED']);
        }
        $clean = $preflight()['switch spelling'];

        $this->assertSame(\App\Services\Fulfillment\Readiness\PreflightCheck::WARN, $odd->status);
        $this->assertStringContainsString('FULFILLMENT_EXTERNAL_WRITES_ENABLED', $odd->detail);
        $this->assertSame(\App\Services\Fulfillment\Readiness\PreflightCheck::PASS, $clean->status);
    }

    public function test_the_preflight_does_not_pass_the_switch_spelling_when_the_configuration_is_cached_and_the_env_file_is_not_read(): void
    {
        // The framework decides once, while it boots, whether a cached configuration was loaded, and remembers the answer in the container.
        $this->app->instance('config_loaded_from_cache', true);

        $check = collect(app(\App\Services\Fulfillment\Readiness\FulfillmentPreflight::class)->safety(allowProduction: true))->keyBy('name')->all()['switch spelling'];

        $this->assertSame(\App\Services\Fulfillment\Readiness\PreflightCheck::WARN, $check->status);
        $this->assertStringContainsString('configuration is cached', $check->detail);
        $this->assertStringContainsString('optimize:clear', $check->detail);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> config/fulfillment.php as it evaluates when the environment says `$raw` */
    private function fulfillmentConfigWith(string $variable, string $raw): array
    {
        $_SERVER[$variable] = $_ENV[$variable] = $raw;
        try {
            return require base_path('config/fulfillment.php');
        } finally {
            unset($_SERVER[$variable], $_ENV[$variable]);
        }
    }


    private function torodFulfillment(int $orderId = 100): \App\Models\ProviderFulfillment
    {
        $this->makeProduct(2, ProviderCode::TorodPoint);
        $this->makeOrder($orderId);
        $this->addLine($orderId, 2, 1, 35, ProviderCode::TorodPoint);
        app(FulfillmentRouter::class)->route($orderId);

        return $this->fulfillmentFor($orderId, ProviderCode::TorodPoint);
    }

    private function unexpiredJwt(): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['typ' => 'JWT', 'alg' => 'RS256']) . '.' . $encode(['sub' => '59653', 'exp' => time() + 86400 * 300]) . '.signature';
    }

    /** @return list<array{0: string, 1: string, 2: string}> [operation, method, file] for every call a client makes */
    private function operationsSentByTheClients(): array
    {
        $found = [];
        foreach (self::CLIENTS as $relative => $prefix) {
            $source = (string) file_get_contents(base_path($relative));

            preg_match_all('/->(?:authenticated|send)\(\s*\'([a-z_]+)\'\s*,\s*\'([A-Z]+)\'/', $source, $calls, PREG_SET_ORDER);
            preg_match_all('/operation:\s*\'([a-z_]+)\',\s*method:\s*\'([A-Z]+)\'/', $source, $named, PREG_SET_ORDER);

            foreach (array_merge($calls, $named) as $match) {
                $found[] = [$prefix . $match[1], $match[2], $relative];
            }
        }

        return $found;
    }

    /**
     * @param list<string> $directories relative to the repository root
     * @return array<string, string> relative path => source
     */
    private function phpFilesUnder(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            if (!is_dir(base_path($directory))) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[ltrim(str_replace(base_path(), '', $file->getPathname()), '/')] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
