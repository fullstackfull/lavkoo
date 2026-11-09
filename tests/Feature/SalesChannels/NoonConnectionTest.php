<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Noon\NoonConnector;
use App\Services\SalesChannels\Noon\NoonOperations;
use App\Services\SalesChannels\Support\ChannelCredentialStore;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * noon as a channel: it exists, it signs in, it says who it is, and it tells the truth about what is built.
 *
 * The sign-in is the call everything else rests on: a JWT signed RS256 with the service account's private key, exchanged at `/identity/public/v1/api/login` — on the
 * PRODUCTION host even for a sandbox account — for a session cookie noon never names. These tests pin its shape, prove the sandbox never receives a sign-in, and prove an
 * account that only rehearses can still check its key file.
 *
 * The rest is about honesty, and it cuts both ways. The capability matrix may not claim a call this version cannot make; the account page's gap list may not report a
 * capability that IS built, because telling a person their account is less capable than it is sends them looking for a problem that is not there. The gap list is also not a
 * list of permissions noon withheld and not a list of settings left blank: those are three different problems and the screen is no use if it runs them together.
 *
 * Every response here is a fixture. No test reaches noon, and nothing here proves a real seller account works.
 */
class NoonConnectionTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const PRODUCTION = 'https://noon-api-gateway.noon.partners';
    private const SANDBOX = 'https://noon-sandbox-api-gateway.noon.partners';
    private const LOGIN = self::PRODUCTION . '/identity/public/v1/api/login';
    private const WHOAMI = self::PRODUCTION . '/identity/v1/whoami';
    private const PROJECT = 'proj_pharmacy_ksa';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = $this->noonAccount();
    }

    // ---- noon is a channel at all --------------------------------------------------------------------------------

    public function test_noon_is_a_connectable_channel_with_a_connector(): void
    {
        $this->assertContains(SalesChannel::Noon, SalesChannel::connectable());
        $this->assertTrue(SalesChannel::Noon->isExternal());
        $this->assertSame(SalesChannel::Noon, app(NoonConnector::class)->channel());
        $this->assertSame(NoonConnector::class, config('sales_channels.connectors.noon'));
    }

    public function test_an_account_can_be_created_for_noon_and_keeps_its_key_file_encrypted(): void
    {
        $this->assertSame('noon', $this->account->channel);
        $this->assertSame(self::PROJECT, app(ChannelCredentialStore::class)->get($this->account)->secret('project_code'));
        // The key file is a secret: it is never readable from the row itself.
        $this->assertArrayNotHasKey('credentials', $this->account->toArray());
        $this->assertStringNotContainsString('BEGIN', json_encode($this->account->toArray()));
    }

    // ---- the one call noon documents the body of -----------------------------------------------------------------

    public function test_the_sign_in_sends_an_rs256_jwt_and_the_project_code(): void
    {
        $this->fakeSignInAndWhoami();

        app(NoonConnector::class)->testConnection($this->account);

        $login = $this->sentTo('/identity/public/v1/api/login');
        $this->assertCount(1, $login);
        $body = $login[0]->data();
        $this->assertSame(self::PROJECT, $body['default_project_code']);

        [$header, $claims, $signature] = explode('.', (string) $body['token']);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($this->unBase64($header), true));
        $decoded = json_decode($this->unBase64($claims), true);
        $this->assertSame('key-abc', $decoded['sub'], 'the subject is the key id from the key file');
        $this->assertSame(now()->getTimestamp(), $decoded['iat']);
        $this->assertNotSame('', (string) ($decoded['jti'] ?? ''), 'a fresh id per attempt: noon refuses a stale token');
        $this->assertNotSame('', $signature);
    }

    public function test_each_sign_in_carries_its_own_jti_so_no_token_is_ever_reused(): void
    {
        $this->fakeSignInAndWhoami();
        app(NoonConnector::class)->testConnection($this->account);
        // Push the stored session past its margin so the next call signs in again.
        $this->travel(31)->days();
        app(NoonConnector::class)->testConnection($this->account->fresh());

        $tokens = array_map(fn (Request $request): string => (string) $request->data()['token'], $this->sentTo('/identity/public/v1/api/login'));

        $this->assertCount(2, $tokens);
        $this->assertNotSame($tokens[0], $tokens[1]);
    }

    public function test_every_request_carries_the_user_agent_noon_insists_on(): void
    {
        $this->fakeSignInAndWhoami();

        app(NoonConnector::class)->testConnection($this->account);

        foreach ([...$this->sentTo('/identity/public/v1/api/login'), ...$this->sentTo('/identity/v1/whoami')] as $request) {
            $headers = array_change_key_case($request->headers(), CASE_LOWER);
            $this->assertNotSame('', (string) ($headers['user-agent'][0] ?? ''), 'noon answers 400 without one');
        }
    }

    public function test_the_session_cookie_is_replayed_whole_because_noon_never_names_it(): void
    {
        $this->fakeSignInAndWhoami(cookies: ['noon_session=abc123; Path=/; HttpOnly', 'noon_csrf=xyz789; Path=/']);

        app(NoonConnector::class)->testConnection($this->account);

        $headers = array_change_key_case($this->sentTo('/identity/v1/whoami')[0]->headers(), CASE_LOWER);
        $cookie = (string) ($headers['cookie'][0] ?? '');
        $this->assertStringContainsString('noon_session=abc123', $cookie);
        $this->assertStringContainsString('noon_csrf=xyz789', $cookie, 'no cookie is singled out by a name we would have had to guess');
        $this->assertStringNotContainsString('HttpOnly', $cookie, 'attributes belong to the server, not to what a client sends back');
    }

    public function test_the_session_is_kept_so_a_second_call_does_not_sign_in_again(): void
    {
        $this->fakeSignInAndWhoami();
        app(NoonConnector::class)->testConnection($this->account);
        app(NoonConnector::class)->testConnection($this->account->fresh());

        $this->assertCount(1, $this->sentTo('/identity/public/v1/api/login'), 'one sign-in, two calls');
        $this->assertCount(2, $this->sentTo('/identity/v1/whoami'));
    }

    // ---- the sandbox never signs anybody in ----------------------------------------------------------------------

    public function test_a_sandbox_account_calls_the_sandbox_host_but_signs_in_on_production(): void
    {
        $this->account->forceFill(['environment' => 'sandbox'])->save();
        Http::fake([
            self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc123; Path=/']),
            self::SANDBOX . '/identity/v1/whoami' => Http::response(['ok' => true]),
        ]);

        $result = app(NoonConnector::class)->testConnection($this->account->fresh());

        $this->assertTrue($result->ok());
        // The documentation is explicit: only the other calls move to the sandbox. A sandbox-issued session is one production would not know.
        $this->assertCount(1, $this->sentTo(self::PRODUCTION . '/identity/public/v1/api/login'));
        $this->assertCount(1, $this->sentTo(self::SANDBOX . '/identity/v1/whoami'));
        $this->assertCount(0, $this->sentTo(self::SANDBOX . '/identity/public/v1/api/login'));
    }

    // ---- what a failure says -------------------------------------------------------------------------------------

    public function test_an_account_with_no_key_file_says_what_to_do_and_sends_nothing(): void
    {
        // A fresh account with nothing entered yet. `put()` deliberately ignores empty values, so credentials cannot be cleared: an account that never had them is the case.
        $bare = app(ChannelAccountService::class)->create(
            ['channel' => 'noon', 'name' => 'noon, not set up', 'external_account_id' => 'partner-2', 'currency' => 'SAR', 'settings' => []],
            [],
            adminId: 1,
        );
        $bare->forceFill(['enabled' => true, 'capabilities' => ['connection_test']])->save();
        Http::fake([self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'a=b'])]);

        $result = app(NoonConnector::class)->testConnection($bare->fresh());

        $this->assertFalse($result->ok());
        $this->assertSame(ConnectionStatus::AuthExpired, $result->status);
        $this->assertStringContainsString('Access app', $result->message);
        Http::assertNothingSent();
    }

    public function test_a_private_key_openssl_cannot_read_is_reported_and_nothing_is_sent(): void
    {
        app(ChannelCredentialStore::class)->save($this->account, ['private_key' => 'not actually a key']);
        Http::fake([self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'a=b'])]);

        $result = app(NoonConnector::class)->testConnection($this->account->fresh());

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('OpenSSL', $result->message);
        Http::assertNothingSent();
    }

    public function test_a_refused_sign_in_names_the_usual_causes(): void
    {
        Http::fake([self::LOGIN => Http::response(['message' => 'unauthorized'], 401)]);

        $result = app(NoonConnector::class)->testConnection($this->account);

        $this->assertFalse($result->ok());
        $this->assertSame(ConnectionStatus::AuthExpired, $result->status);
        $this->assertStringContainsString('allow-list', $result->message);
    }

    public function test_a_sign_in_that_sets_no_cookie_is_a_failure_not_a_session(): void
    {
        Http::fake([self::LOGIN => Http::response('', 200)]);

        $result = app(NoonConnector::class)->testConnection($this->account);

        $this->assertFalse($result->ok());
        $this->assertStringContainsString('set no cookie', $result->message);
        $this->assertCount(0, $this->sentTo('/identity/v1/whoami'), 'nothing is called with a session that does not exist');
    }

    public function test_a_rehearsing_account_can_still_test_its_credentials_because_a_test_changes_nothing(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        $this->fakeSignInAndWhoami();

        $result = app(NoonConnector::class)->testConnection($this->account->fresh());

        // A rehearsal withholds WRITES. Signing in and asking who you are change nothing at noon, so a person can check their key file before turning any write on -- which
        // is the only order in which that check is useful.
        $this->assertTrue($result->ok());
    }

    public function test_a_successful_test_does_not_read_anything_out_of_an_undocumented_response(): void
    {
        $this->fakeSignInAndWhoami(whoami: ['user' => ['name' => 'Someone'], 'project_code' => 'proj_SOMETHING_ELSE']);

        $result = app(NoonConnector::class)->testConnection($this->account);

        $this->assertTrue($result->ok());
        // The shape of whoami's answer is not published, so no field of it is trusted: that it answered is the result.
        $this->assertStringNotContainsString('Someone', json_encode($result->details));
        $this->assertStringNotContainsString('SOMETHING_ELSE', json_encode($result->details));
    }

    // ---- the matrix and the allow-list tell the truth -------------------------------------------------------------

    // ---- the account page separates three different problems ------------------------------------------------------

    public function test_a_capability_this_module_now_serves_is_no_longer_reported_as_unbuilt(): void
    {
        // Stock and price publishing ARE built. Naming them as gaps told a person their account was less capable than it is, which is the same class of lie as claiming a
        // capability that does not work -- it just errs the other way.
        $this->account->forceFill(['capabilities' => ['connection_test', 'read_only_sync', 'inventory_publishing', 'price_publishing']])->save();
        $this->fakeSignInAndWhoami();

        $result = app(NoonConnector::class)->testConnection($this->account->fresh());

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->missing, 'everything this account switched on is built');
    }

    public function test_a_capability_this_module_has_not_built_is_still_reported_as_a_gap(): void
    {
        $this->account->forceFill(['capabilities' => ['connection_test', 'product_sync', 'order_import', 'fulfillment_dispatch', 'financial_reconciliation']])->save();
        $this->fakeSignInAndWhoami();

        $result = app(NoonConnector::class)->testConnection($this->account->fresh());

        $this->assertTrue($result->ok(), 'the key file works; what is missing is our own code');
        $this->assertSame([
            'noon_product_upsert_not_built',
            'noon_order_import_not_built',
            'noon_shipments_not_built',
            'noon_publishes_no_settlement_endpoint',
        ], $result->missing);
    }

    public function test_the_gap_list_names_every_capability_the_matrix_calls_unbuilt_and_no_other(): void
    {
        // The list on the account page and the matrix have to say the same thing, or a person reads one of them and is wrong. Every capability noon's connector can be
        // granted is switched on here, so the answer is the complete list rather than a sample.
        $this->account->forceFill(['capabilities' => array_map(
            static fn (IntegrationCapability $capability): string => $capability->value,
            IntegrationCapability::cases(),
        )])->save();
        $this->fakeSignInAndWhoami();

        $result = app(NoonConnector::class)->testConnection($this->account->fresh());
        $matrix = app(NoonConnector::class)->capabilityMatrix();

        foreach (['stock', 'pricing', 'category_discovery', 'warehouses'] as $built) {
            $this->assertTrue($matrix[$built]['built'], "{$built} is built, so no gap may be reported for it");
        }
        foreach ($result->missing as $gap) {
            $this->assertStringNotContainsString('inventory', $gap, 'publishing stock is built');
            $this->assertStringNotContainsString('price', $gap, 'publishing prices is built');
        }
        // A gap is a promise THIS MODULE would otherwise make. It is not a permission noon withheld and not a setting left blank.
        $this->assertContains('noon_product_upsert_not_built', $result->missing);
        $this->assertContains('noon_shipments_not_built', $result->missing);
    }

    public function test_a_missing_setting_is_not_reported_as_an_unbuilt_capability(): void
    {
        // Three different problems: our code is not written, noon refused a permission, or nobody chose a warehouse. The screen is no use if it runs them together, so a
        // blank setting shows up on the publish that needs it and never as a gap in what was built.
        $this->account->forceFill([
            'capabilities' => ['connection_test', 'inventory_publishing', 'price_publishing'],
            'settings' => ['warehouse_code' => '', 'country_code' => ''],
        ])->save();
        $this->fakeSignInAndWhoami();

        $result = app(NoonConnector::class)->testConnection($this->account->fresh());

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->missing, 'a blank warehouse is a setting to fill in, not code that was never written');
    }

    public function test_every_gap_code_is_a_key_an_administrator_can_read_in_every_locale(): void
    {
        $this->account->forceFill(['capabilities' => array_map(
            static fn (IntegrationCapability $capability): string => $capability->value,
            IntegrationCapability::cases(),
        )])->save();
        $this->fakeSignInAndWhoami();

        $missing = app(NoonConnector::class)->testConnection($this->account->fresh())->missing;

        $this->assertNotSame([], $missing);
        foreach (['en', 'sa', 'ae'] as $locale) {
            $catalogue = include base_path("resources/lang/{$locale}/new-messages.php");
            foreach ($missing as $gap) {
                $this->assertArrayHasKey($gap, $catalogue, "{$locale} has no words for the gap {$gap}");
            }
        }
    }

    public function test_the_capability_matrix_claims_only_what_is_built(): void
    {
        $matrix = app(NoonConnector::class)->capabilityMatrix();

        foreach (['service_account_login', 'identity_whoami', 'static_sandbox', 'warehouses', 'stock', 'pricing', 'category_discovery'] as $built) {
            $this->assertTrue($matrix[$built]['built'], "{$built} is built and its path and body are documented");
        }
        // Documented by noon, and deliberately not built: either its reference page was never read (so no path is known) or nothing here needs it yet.
        foreach (['product_groups', 'oauth_for_integrators', 'returns', 'catalog_product_upsert', 'catalog_content_state', 'barcode_map', 'fbpi_orders', 'shipments', 'event_destinations'] as $notBuilt) {
            $this->assertTrue($matrix[$notBuilt]['documented']);
            $this->assertFalse($matrix[$notBuilt]['built']);
        }
        // No settlement or fee endpoint exists anywhere in noon's index, so OperationalCosts has nothing to ingest and no figure for noon is invented.
        $this->assertFalse($matrix['settlements_and_fees']['documented']);
        $this->assertFalse($matrix['settlements_and_fees']['built']);
        // FBP: NOT a claim that it does not exist. It is not on the reference or guide pages read, and noon's help centre is where it has to be read.
        $this->assertFalse($matrix['fbp']['documented']);
        $this->assertStringContainsString('help centre', $matrix['fbp']['note']);
        $this->assertStringNotContainsString('does not exist', $matrix['fbp']['note']);
    }

    public function test_the_matrix_and_the_allow_list_agree_about_every_operation(): void
    {
        $sources = file_get_contents(app_path('Services/SalesChannels/Noon/NoonConnector.php')) . file_get_contents(app_path('Services/SalesChannels/Noon/NoonClient.php'));

        // Each registered operation is either called by this module or named in the matrix as something it does not do. Nothing may be registered and silently unused, and
        // nothing may be called that the matrix calls unbuilt.
        $uncalled = [];
        foreach (array_keys(NoonOperations::operations()) as $operation) {
            if (!str_contains($sources, "'{$operation}'")) {
                $uncalled[] = $operation;
            }
        }

        // Every one of these is registered with a verified path and a documented body, and the matrix says plainly that nothing calls it yet. A registered operation may
        // be unused; it may NOT be claimed as built.
        $this->assertSame([
            'noon.product_upsert', 'noon.product_content_get', 'noon.product_group_upsert',
            'noon.barcode_map', 'noon.fbpi_orders_list', 'noon.shipment_create', 'noon.webhook_destinations_list',
        ], $uncalled, 'a registered operation nothing calls must be a row the matrix marks unbuilt');
        $matrix = app(NoonConnector::class)->capabilityMatrix();
        foreach (['catalog_product_upsert', 'catalog_content_state', 'product_groups', 'barcode_map', 'fbpi_orders', 'shipments', 'event_destinations'] as $row) {
            $this->assertFalse($matrix[$row]['built'], "{$row} is registered and uncalled, so the matrix may not claim it");
            $this->assertStringContainsString('NOT BUILT YET', $matrix[$row]['note']);
        }
    }

    public function test_an_operation_whose_reference_page_was_never_read_is_not_registered_at_all(): void
    {
        $registered = array_keys(NoonOperations::operations());

        // Their paths are NOT guessed from the pattern of their neighbours: a plausible path is still a wrong one, and guessing is what produced the 404s this module was
        // corrected for. They are named so that "not registered" reads as a gap somebody can close by reading one page.
        $this->assertNotSame([], NoonOperations::documentedButUnread());
        foreach (NoonOperations::documentedButUnread() as $unread) {
            $this->assertNotContains('noon.' . $unread, $registered);
        }
    }

    public function test_every_registered_operation_has_a_documented_path(): void
    {
        $this->assertSame(array_keys(NoonOperations::operations()), array_keys(NoonOperations::paths()));
        foreach (NoonOperations::paths() as $operation => $path) {
            $this->assertStringStartsWith('/', $path, "{$operation} must carry noon's own path");
        }
    }

    public function test_a_call_that_is_not_registered_is_refused_before_anything_is_sent(): void
    {
        Http::fake([self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'a=b'])]);

        $this->expectExceptionMessageMatches('/not a registered channel call/');
        app(\App\Services\SalesChannels\Noon\NoonClient::class)->call($this->account, 'noon.invented', 'GET', '/v1/whatever');
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function noonAccount(): SalesChannelAccount
    {
        $account = app(ChannelAccountService::class)->create(
            ['channel' => 'noon', 'name' => 'noon KSA', 'external_account_id' => 'partner-1', 'currency' => 'SAR', 'settings' => ['warehouse_code' => 'RUH1']],
            ['key_id' => 'key-abc', 'private_key' => $this->aRealPrivateKey(), 'project_code' => self::PROJECT, 'channel_identifier' => 'noon'],
            adminId: 1,
        );

        $account->forceFill([
            'enabled' => true, 'write_enabled' => false, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync'],
        ])->save();

        return $account;
    }

    /** A real RSA key, generated here: the sign-in has to be signed for the test to prove anything about the token. */
    private function aRealPrivateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    /** @param list<string> $cookies */
    private function fakeSignInAndWhoami(array $cookies = ['noon_session=abc123; Path=/; HttpOnly'], array $whoami = ['ok' => true]): void
    {
        Http::fake([
            self::LOGIN => Http::response('', 200, ['Set-Cookie' => $cookies]),
            self::WHOAMI => Http::response($whoami),
        ]);
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }

    private function unBase64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
