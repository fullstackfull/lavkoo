<?php

namespace Tests\Feature\MyFatoorah;

use App\Models\LegalEntity;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahWebhookEvent;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\PaymentInitiationService;
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Storefront\LegalEntityRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;

/**
 * A notification belongs to the company whose account it names (D2-09), and is processed once (D2-12).
 *
 * The webhook URL, the signing secret and the event scope are the account's, not the platform's. The case this exists to refuse is the first one
 * written: a delivery signed with company A's secret, posted to company B's address. It computes a different signature against B's secret, so it is
 * refused with the same flat 401 as every other refusal, and it settles nothing of B's.
 *
 * Every delivery here is a real POST to the real route; the signatures are computed by the production verifier.
 */
class CompanyWebhookTakesOnlyItsOwnDeliveriesTest extends MyFatoorahTestCase
{
    use RestoresLanguageFiles;

    private const KUWAIT_WEBHOOK_SECRET = 'kuwait-account-webhook-secret';

    private const SAUDI_WEBHOOK_SECRET = 'saudi-account-webhook-secret';

    private PaymentGatewayAccount $kuwait;

    private PaymentGatewayAccount $saudi;

    private MyFatoorahPaymentAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->configureGateway();
        $this->installTheCompanyAndAccountTables();
        $this->turnCompanyAccountsOn();

        $this->kuwait = $this->anAccountFor(country: 'KW', webhookSecret: self::KUWAIT_WEBHOOK_SECRET, token: 'kuwait-webhook-token');
        $this->saudi = $this->anAccountFor(country: 'SA', webhookSecret: self::SAUDI_WEBHOOK_SECRET, token: 'saudi-webhook-token');

        Http::fake(['*/v3/payments' => Http::response([
            'IsSuccess' => true,
            'Data' => ['InvoiceId' => 'INV-1', 'PaymentId' => null, 'PaymentURL' => 'https://sa.myfatoorah.com/KSA/ie/1'],
        ], 201)]);

        app(PaymentInitiationService::class)->start(PaymentRequest::find($this->makePaymentRequest()));
        $this->attempt = MyFatoorahPaymentAttempt::firstOrFail();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
        Schema::dropIfExists('legal_entities');
        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** D2-09: 'A webhook signed with company A's secret is refused for company B's payment'. */
    public function test_a_delivery_signed_with_one_companys_secret_is_refused_at_another_companys_address(): void
    {
        $payload = $this->webhookPayload($this->attempt->external_identifier);

        $this->deliverTo($this->saudi, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertSame(0, MyFatoorahWebhookEvent::count(), 'nothing of the other company was even recorded');
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, (int) DB::table('payment_requests')->where('is_paid', 1)->count());
    }

    /** The same delivery at its own company's address is accepted, so the refusal above is about whose secret it is. */
    public function test_a_delivery_signed_with_a_companys_own_secret_is_accepted_at_its_own_address(): void
    {
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))->assertOk();

        $this->assertSame(1, MyFatoorahWebhookEvent::count());
    }

    /** The platform-wide secret is not a company's secret, and a company's address does not fall back to it. */
    public function test_the_platform_secret_is_refused_at_a_companys_address(): void
    {
        $payload = $this->webhookPayload($this->attempt->external_identifier);

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, 'test-webhook-secret'))->assertStatus(401);

        $this->assertSame(0, MyFatoorahWebhookEvent::count());
    }

    /** An address nobody holds, and an address whose account has been disabled, are the same flat 401. */
    public function test_an_unknown_or_disabled_account_address_is_refused_with_the_same_401(): void
    {
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $signature = $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET);

        $unknown = $this->postJson('/api/payments/account/no-such-account/webhook', $payload, ['MyFatoorah-Signature' => $signature]);
        $unknown->assertStatus(401)->assertJsonPath('message', 'Unauthorized request');

        $this->kuwait->forceFill(['status' => false])->save();
        app(GatewayAccountRegistry::class)->flush();

        $disabled = $this->deliverTo($this->kuwait, $payload, $signature);
        $disabled->assertStatus(401);
        $this->assertSame($unknown->json(), $disabled->json(), 'a prober learns nothing from the difference');
    }

    /** An account with no secret of its own authenticates nothing, whatever the delivery is signed with. */
    public function test_an_account_with_no_secret_of_its_own_authenticates_nothing(): void
    {
        $bare = $this->anAccountFor(country: 'AE', webhookSecret: null, token: 'bare-webhook-token');
        $payload = $this->webhookPayload($this->attempt->external_identifier);

        foreach ([self::KUWAIT_WEBHOOK_SECRET, 'test-webhook-secret', ''] as $attempt) {
            $this->deliverTo($bare, $payload, $this->signatureFor($payload, $attempt))->assertStatus(401);
        }

        $this->assertSame(0, MyFatoorahWebhookEvent::count());
    }

    /** No signature at all, which is what reaching a company's address by hand gives you. */
    public function test_an_unsigned_delivery_is_refused(): void
    {
        $this->postJson('/api/payments/account/' . $this->kuwait->webhook_path_token . '/webhook', $this->webhookPayload($this->attempt->external_identifier))
            ->assertStatus(401);

        $this->assertSame(0, MyFatoorahWebhookEvent::count());
    }

    /** D2-12, kept: the waiver that does nothing on a live account does nothing at a company's address either, in either mode. */
    public function test_the_unsigned_waiver_does_nothing_at_a_companys_address(): void
    {
        config(['myfatoorah.webhook.require_signature' => false]);

        $this->postJson('/api/payments/account/' . $this->kuwait->webhook_path_token . '/webhook', $this->webhookPayload($this->attempt->external_identifier))
            ->assertStatus(401);

        $this->assertSame(0, MyFatoorahWebhookEvent::count());
    }

    /** D2-09: 'Webhook dedupe keys are unique per account'. */
    public function test_the_same_delivery_at_two_companies_addresses_is_two_events_and_at_one_address_is_one(): void
    {
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))->assertOk();
        $this->deliverTo($this->saudi, $payload, $this->signatureFor($payload, self::SAUDI_WEBHOOK_SECRET))->assertOk();

        $this->assertSame(2, MyFatoorahWebhookEvent::count(), 'two accounts, two deliveries, two keys that do not collide');

        $repeated = $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET));
        $repeated->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(2, MyFatoorahWebhookEvent::count(), 'the repeat at the same address was recognised');
    }

    /** D2-12: each delivery is processed once, however many copies of it arrive at a company's address. */
    public function test_ten_copies_of_one_delivery_at_one_address_settle_once(): void
    {
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $signature = $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET);

        for ($delivery = 0; $delivery < 10; $delivery++) {
            $this->deliverTo($this->kuwait, $payload, $signature)->assertOk();
        }

        $this->assertSame(1, MyFatoorahWebhookEvent::count());
        $this->assertSame(1, $this->successHookCalls(), 'ten copies, one settlement');
    }

    /** An authentic delivery outside the account's event scope is acted on in no way. */
    public function test_a_delivery_outside_the_accounts_event_scope_does_nothing(): void
    {
        $this->fakePaid();
        $this->kuwait->forceFill(['webhook_events' => json_encode([2])])->save();
        app(GatewayAccountRegistry::class)->flush();
        $payload = $this->webhookPayload($this->attempt->external_identifier);

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))
            ->assertOk()
            ->assertJsonPath('status', 'out_of_scope');

        $this->assertSame(0, MyFatoorahWebhookEvent::count());
        $this->assertSame(0, $this->successHookCalls());
    }

    /** D2-09: 'Each account shows its own webhook URL and secret status in the admin screen'. The screen is rendered, not argued about. */
    public function test_the_admin_screen_shows_each_accounts_own_webhook_url_and_secret_status(): void
    {
        $this->anAccountFor(country: 'AE', webhookSecret: null, token: 'bare-webhook-token');

        $rendered = $this->renderTheAccountsBlock();

        $this->assertStringContainsString('/api/payments/account/kuwait-webhook-token/webhook', $rendered);
        $this->assertStringContainsString('/api/payments/account/saudi-webhook-token/webhook', $rendered);
        $this->assertStringContainsString('/api/payments/account/bare-webhook-token/webhook', $rendered);
        $this->assertStringContainsString('Lavkoo KW Trading Co.', $rendered);
        $this->assertSame(2, substr_count($rendered, '>Saved<'), 'the two accounts with a secret say so');
        $this->assertSame(1, substr_count($rendered, '>Missing<'), 'and the one without says so');
        $this->assertStringNotContainsString(self::KUWAIT_WEBHOOK_SECRET, $rendered, 'the status, never the secret');
        $this->assertStringNotContainsString(self::SAUDI_WEBHOOK_SECRET, $rendered);
    }

    /** With per-company accounts off, the screen is what it is today: no per-account block at all. */
    public function test_the_admin_screen_is_unchanged_while_per_company_accounts_are_off(): void
    {
        config(['gcc.storefronts.payment_accounts.enabled' => false]);
        app(GatewayAccountRegistry::class)->flush();

        $this->assertSame([], app(GatewayAccountDirectory::class)->rows(gateway: GatewayConfigRepository::KEY));
        $this->assertSame('', trim($this->renderTheAccountsBlock()), 'the webhook card renders exactly what it renders today');
    }

    /** What the webhook card of the gateway screen renders for the company accounts, from the production directory. */
    private function renderTheAccountsBlock(): string
    {
        return view('admin-views.third-party.payment-method.partials._company-gateway-accounts', [
            'gatewayAccounts' => app(GatewayAccountDirectory::class)->rows(gateway: GatewayConfigRepository::KEY),
        ])->render();
    }

    private function deliverTo(PaymentGatewayAccount $account, array $payload, string $signature): TestResponse
    {
        return $this->postJson('/api/payments/account/' . $account->webhook_path_token . '/webhook', $payload, ['MyFatoorah-Signature' => $signature]);
    }

    /** MyFatoorah answers "paid" for this attempt whenever asked, as the settlement tests arrange it. */
    private function fakePaid(string $amount = '150.00'): void
    {
        Http::fake([
            '*/v3/invoices/*' => Http::response(['IsSuccess' => true, 'Data' => ['Transaction' => ['PaymentId' => 'PAY-1', 'Status' => 'SUCCESS']]]),
            '*/v3/payments/*' => Http::response($this->paymentDetails($this->attempt->external_identifier, amount: $amount)),
        ]);
    }

    private function installTheCompanyAndAccountTables(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
        Schema::dropIfExists('legal_entities');

        foreach (['2026_11_27_100000_create_legal_entities_table.php', '2026_11_28_100000_create_payment_gateway_accounts_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
    }

    private function turnCompanyAccountsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.payment_accounts.enabled' => true,
            'gcc.storefronts.payment_accounts.gateways' => [GatewayConfigRepository::KEY, 'paymob_accept'],
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();
    }

    private function anAccountFor(string $country, ?string $webhookSecret, string $token): PaymentGatewayAccount
    {
        $company = LegalEntity::create([
            'country' => $country,
            'legal_name' => "Lavkoo {$country} Trading Co.",
            'invoice_series_key' => "INV-{$country}",
            'status' => true,
        ]);

        $account = PaymentGatewayAccount::create([
            'storefront_country' => $country,
            'gateway' => GatewayConfigRepository::KEY,
            'environment' => 'test',
            'legal_entity_id' => (int) $company->id,
            'webhook_path_token' => $token,
            'status' => true,
        ]);

        if ($webhookSecret !== null) {
            app(GatewayAccountSecrets::class)->put(account: $account, secrets: [GatewayAccountSecrets::WEBHOOK_SECRET => $webhookSecret]);
        }

        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();

        return $account->refresh();
    }

}
