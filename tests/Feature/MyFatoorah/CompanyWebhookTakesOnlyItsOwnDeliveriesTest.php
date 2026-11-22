<?php

namespace Tests\Feature\MyFatoorah;

use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahWebhookEvent;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\PaymentInitiationService;
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\PaymentAccountBinding;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;

/**
 * A notification belongs to the company whose account it names (D2-09), and is processed once (D2-12).
 *
 * The webhook URL, the signing secret and the event scope are the account's, not the platform's. Three refusals carry the whole unit, and a delivery
 * has to clear all three:
 *
 *   WHOSE GATEWAY THE ACCOUNT IS FOR — a webhook secret authenticates a body whatever gateway the account holding it is registered for, so an account
 *   of another gateway is refused before the body is read.
 *
 *   WHOSE SECRET SIGNED IT — a delivery signed with company A's secret, posted to company B's address, computes a different signature against B's
 *   secret and is refused.
 *
 *   WHOSE PAYMENT IT NAMES — a delivery signed with company A's OWN secret, at company A's OWN address, naming a payment that was taken through
 *   company B's account, is refused too. Authentication says who sent it, not what it may move, and the attempt it names is found by an identifier
 *   that carries no company: without this third refusal one company's address settles another company's payment.
 *
 * The payment under test is taken through the Kuwait account, stamped by the production writer, so "another company's payment" is a fact of the
 * fixture and not an assumption. Every delivery here is a real POST to the real route; the signatures are computed by the production verifier.
 */
class CompanyWebhookTakesOnlyItsOwnDeliveriesTest extends CompanyAccountsTestCase
{
    use RestoresLanguageFiles;

    /** A value an operator could type into the environment column: no translation key, and short enough for the column (8 characters). */
    private const AN_ENVIRONMENT_NOBODY_TRANSLATED = 'stage-7';

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

        $payment = $this->makePaymentRequest(['additional_data' => $this->stampedForTheMarketOf($this->kuwait)]);
        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));
        $this->attempt = MyFatoorahPaymentAttempt::firstOrFail();

        $this->assertSame(
            (int) $this->kuwait->id,
            app(PaymentAccountBinding::class)->of(payment: (array) DB::table('payment_requests')->where('id', $payment)->first())?->accountId,
            'the payment under test is taken through the Kuwait account, or none of the refusals below mean anything',
        );
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();
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
        $this->assertSame([], $this->paidPayments());
    }

    /** The same delivery at its own company's address is accepted and settles that company's payment, so the refusal above is about whose secret it is. */
    public function test_a_delivery_signed_with_a_companys_own_secret_is_accepted_at_its_own_address(): void
    {
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame(1, MyFatoorahWebhookEvent::count());
        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame([$this->attempt->payment_request_id], $this->paidPayments(), 'the Kuwait account settled the Kuwait payment');
    }

    /**
     * THE CASE THE WHOLE UNIT TURNS ON: company A's own secret, at company A's own address, naming company B's payment.
     *
     * Nothing about this delivery is forged. It is signed with the Saudi account's own webhook secret and posted to the Saudi account's own address, so
     * it is the Saudi company's delivery by every test the route can make of its sender. What it names is a payment taken through the KUWAIT account,
     * and the identifier it names it by carries no company at all. It is refused, with the flat 401 every other refusal uses, and the Kuwait payment is
     * neither settled nor touched.
     */
    public function test_a_companys_own_secret_at_its_own_address_cannot_settle_another_companys_payment(): void
    {
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $before = $this->attemptRow();

        $this->deliverTo($this->saudi, $payload, $this->signatureFor($payload, self::SAUDI_WEBHOOK_SECRET))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertSame([], $this->paidPayments(), 'the Kuwait payment is not paid by the Saudi address');
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, MyFatoorahWebhookEvent::count(), 'nothing of the other company was even recorded');
        $this->assertEquals($before, $this->attemptRow(), 'and not one column of the other company\'s attempt was written');
    }

    /**
     * The same refusal when the delivery would not have settled anything either way.
     *
     * A notification whose payment the gateway reports at a different amount answers "mismatch" and writes the verification onto the attempt — a write
     * on another company's row is a cross-tenant write whether or not money moved, so this is refused at the same point and leaves the row alone.
     */
    public function test_a_delivery_at_another_companys_address_writes_nothing_even_when_it_would_not_settle(): void
    {
        $this->fakePaid(amount: '999.00');
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $before = $this->attemptRow();

        $this->deliverTo($this->saudi, $payload, $this->signatureFor($payload, self::SAUDI_WEBHOOK_SECRET))->assertStatus(401);

        $this->assertEquals($before, $this->attemptRow());
        $this->assertSame(0, MyFatoorahWebhookEvent::count());
    }

    /**
     * An account of another gateway does not take this gateway's notifications — and this is the case the SHIPPED default exposes.
     *
     * `gcc.storefronts.payment_accounts.gateways` ships as `['paymob_accept']`, so Paymob is the one gateway an account may be configured for, and a
     * Paymob account's webhook secret is stored in the same column a MyFatoorah account's is.
     *
     * Nothing else about this delivery is wrong, which is the point: it is signed with the Paymob account's own secret, posted to the Paymob account's
     * own address, and it names a payment stamped with THAT account — so the secret and the stamp both check out and the gateway is the only objection
     * left. An account is edited in place, so a gateway retyped on an account that has already taken payments reaches exactly this state.
     */
    public function test_an_account_of_another_gateway_does_not_take_this_gateways_notifications(): void
    {
        config(['gcc.storefronts.payment_accounts.gateways' => ['paymob_accept']]);
        $paymob = $this->anAccountFor(country: 'QA', webhookSecret: 'qatar-paymob-webhook-secret', token: 'qatar-paymob-token', gateway: 'paymob_accept');
        $itsOwnAttempt = $this->anAttemptStampedWith($paymob);
        $this->fakePaid(attempt: $itsOwnAttempt);
        $payload = $this->webhookPayload($itsOwnAttempt->external_identifier);

        $this->deliverTo($paymob, $payload, $this->signatureFor($payload, 'qatar-paymob-webhook-secret'))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertSame([], $this->paidPayments());
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, MyFatoorahWebhookEvent::count());
    }

    /**
     * THE SHIPPED CONFIGURATION: a gateway the owner has not listed per company has no notification address at all.
     *
     * `gcc.storefronts.payment_accounts.gateways` is a hardcoded list with no environment override, and this gateway is NOT on it as the branch ships —
     * only Paymob is, because Paymob is the one gateway wired end to end. A server on the day the master flag is turned on is therefore in exactly this
     * configuration, and in it this address must answer nothing: the owner's list is the switch, and a gateway off the list is read from the one
     * platform row everywhere, notification address included.
     *
     * This test sets the list back to the SHIPPED value rather than to a list of its own, and every other thing about the delivery is right: the account's
     * own secret, its own address, its own stamped payment, an event its scope takes. The owner's list is the only objection left, so it has to carry the
     * refusal on its own. (The stamp was written while the gateway was listed, which is the state a server reaches by narrowing the list after payments
     * have been taken — an account and its list are both edited in place.)
     */
    public function test_a_company_address_answers_nothing_while_its_gateway_is_not_one_the_owner_lists(): void
    {
        $this->assertNotContains(
            GatewayConfigRepository::KEY,
            $this->shippedGatewayList,
            'this test measures the shipped list, and the shipped list has grown to include this gateway: the premise is gone, and whoever added it owes'
            . ' this file a test of the state that replaces it',
        );

        config(['gcc.storefronts.payment_accounts.gateways' => $this->shippedGatewayList]);
        app(GatewayAccountRegistry::class)->flush();

        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $before = $this->attemptRow();

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertSame([], $this->paidPayments(), 'a gateway the owner has not listed settles nothing at a company address');
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, MyFatoorahWebhookEvent::count(), 'and records nothing');
        $this->assertEquals($before, $this->attemptRow(), 'and writes no column of the attempt');
    }

    /**
     * A payment that carries NO stamp belongs to no company account, and a company's address refuses it rather than treating it as its own.
     *
     * This is every payment placed before the switch was thrown: the stamp is written when a payment starts, so a payment that started earlier has none
     * and belongs to the platform-wide address. The delivery here is otherwise perfect — this account's own secret, its own address — and an unstamped
     * payment is the one thing wrong with it, so the question has to fail CLOSED on a missing stamp and not open.
     */
    public function test_a_delivery_naming_a_payment_that_carries_no_stamp_is_refused(): void
    {
        $unstamped = $this->anAttemptOfAPaymentWithNoStamp();
        $this->fakePaid(attempt: $unstamped);
        $payload = $this->webhookPayload($unstamped->external_identifier);
        $before = $this->attemptRowOf($unstamped);

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertSame([], $this->paidPayments(), 'an unstamped payment is not this account\'s to settle');
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, MyFatoorahWebhookEvent::count());
        $this->assertEquals($before, $this->attemptRowOf($unstamped), 'and not one column of it was written');
    }

    /**
     * The other shape of a missing stamp: the attempt is there and the payment row it names is not.
     *
     * There is then nothing to read a stamp from, so the account the payment was taken through is unknowable rather than this one, and the answer is the
     * same refusal. Reached by a payment row deleted or pruned after its attempt was created, and it has to fail closed for the same reason: an unknown
     * owner is not this account.
     */
    public function test_a_delivery_naming_an_attempt_whose_payment_row_is_gone_is_refused(): void
    {
        $this->fakePaid();
        DB::table('payment_requests')->where('id', $this->attempt->payment_request_id)->delete();
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $before = $this->attemptRow();

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, MyFatoorahWebhookEvent::count(), 'nothing of an attempt whose owner cannot be read was recorded');
        $this->assertEquals($before, $this->attemptRow(), 'and not one column of it was written');
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

    /**
     * An address is the bytes of its token, and a token that differs only in case is a different address.
     *
     * The route pattern accepts any letter case ([A-Za-z0-9_-]), so the case of the token in the URL is the sender's to choose, and this address is the
     * one place an account is resolved from something a stranger types. The resolution must therefore be byte for byte: the column is ascii_bin and the
     * lookup compares the bytes in PHP as well, so neither the engine's collation nor a future change of it decides who is authenticated.
     *
     * Everything else about this delivery is this account's own — its own secret, its own payment, its own event shape — so the case of the token is the
     * only objection left, and it has to be enough on its own.
     */
    public function test_an_address_whose_token_differs_only_in_case_is_not_this_accounts_address(): void
    {
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $signature = $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET);
        $itsOwnToken = (string) $this->kuwait->webhook_path_token;
        $before = $this->attemptRow();

        $shouted = strtoupper($itsOwnToken);
        $this->assertNotSame($itsOwnToken, $shouted, 'the token under test has to have a case to change');

        $this->postJson('/api/payments/account/' . $shouted . '/webhook', $payload, ['MyFatoorah-Signature' => $signature])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertSame([], $this->paidPayments(), 'a token of the wrong case settles nothing');
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, MyFatoorahWebhookEvent::count(), 'and records nothing');
        $this->assertEquals($before, $this->attemptRow(), 'and writes no column of the attempt');

        $this->deliverTo($this->kuwait, $payload, $signature)->assertOk()->assertJsonPath('status', 'settled');
        $this->assertSame([$this->attempt->payment_request_id], $this->paidPayments(), 'while the address as it was issued still works');
    }

    /**
     * An event shape this gateway does not send is refused, whoever signed it.
     *
     * The signature is computed over the fields of a named event type, so an event type this gateway has no published field list for cannot be signed
     * and cannot be verified — and an unverifiable event is not acted on. The shape and the gateway are the two halves of the same question: whether
     * what arrived is a notification of the gateway the addressed account is registered for.
     */
    public function test_an_event_shape_this_gateway_does_not_send_is_refused(): void
    {
        $this->fakePaid();
        $payload = $this->webhookPayload($this->attempt->external_identifier);
        $payload['Event']['Code'] = 99;

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertSame(0, MyFatoorahWebhookEvent::count());
        $this->assertSame([], $this->paidPayments());
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

    /**
     * D2-09: 'Webhook dedupe keys are unique per account'.
     *
     * The delivery both companies get is one neither of them owns: an invoice of another installation on the same merchant login, which is the real
     * reason two accounts can be sent the same body. It names no attempt of ours, so each address records it and acts on nothing, and the two records
     * prove the keys do not collide. A delivery naming one company's payment is NOT how this is measured, because the other company's address refuses
     * such a delivery outright.
     */
    public function test_the_same_delivery_at_two_companies_addresses_is_two_events_and_at_one_address_is_one(): void
    {
        $this->fakePaid();
        $payload = $this->webhookPayload('EXT-OF-ANOTHER-INSTALLATION');

        $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET))
            ->assertOk()
            ->assertJsonPath('status', 'no_attempt');
        $this->deliverTo($this->saudi, $payload, $this->signatureFor($payload, self::SAUDI_WEBHOOK_SECRET))
            ->assertOk()
            ->assertJsonPath('status', 'no_attempt');

        $this->assertSame(2, MyFatoorahWebhookEvent::count(), 'two accounts, two deliveries, two keys that do not collide');

        $repeated = $this->deliverTo($this->kuwait, $payload, $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET));
        $repeated->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(2, MyFatoorahWebhookEvent::count(), 'the repeat at the same address was recognised');
        $this->assertSame([], $this->paidPayments(), 'and nobody\'s payment was settled by a delivery naming nobody\'s payment');
        $this->assertSame(0, $this->successHookCalls());
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

    /**
     * A value out of the database is not a translation key.
     *
     * translate() WRITES any key it does not know into `resources/lang/<locale>/new-messages.php`, and that file is included on every request. Handing
     * it a stored column therefore lets a row an operator typed edit a shipped language file permanently — and it is the mechanism behind the stray
     * language keys that have turned up in the working tree of this project more than once. The screen still shows the value; it just does not look it
     * up. (The files are put back by RestoresLanguageFiles whatever this test finds, which is why the assertion reads them during the test.)
     */
    public function test_the_screen_does_not_write_an_accounts_stored_value_into_the_language_files(): void
    {
        $this->kuwait->forceFill(['environment' => self::AN_ENVIRONMENT_NOBODY_TRANSLATED])->save();
        app(GatewayAccountRegistry::class)->flush();

        $rendered = $this->renderTheAccountsBlock();

        $this->assertStringNotContainsString(self::AN_ENVIRONMENT_NOBODY_TRANSLATED, $this->languageFileContents(), 'a stored value must not be written into a file every request includes');
        $this->assertStringContainsString(self::AN_ENVIRONMENT_NOBODY_TRANSLATED, $rendered, 'and the operator is still shown what the row says');
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
    private function fakePaid(string $amount = '150.00', ?MyFatoorahPaymentAttempt $attempt = null): void
    {
        Http::fake([
            '*/v3/invoices/*' => Http::response(['IsSuccess' => true, 'Data' => ['Transaction' => ['PaymentId' => 'PAY-1', 'Status' => 'SUCCESS']]]),
            '*/v3/payments/*' => Http::response($this->paymentDetails(($attempt ?? $this->attempt)->external_identifier, amount: $amount)),
        ]);
    }

    /**
     * A MyFatoorah attempt whose payment is stamped with THIS account, whatever gateway the account is registered for.
     *
     * Both halves written by production: the stamp by App\Services\Payments\PaymentAccountBinding, the attempt by the real initiation service.
     */
    private function anAttemptStampedWith(PaymentGatewayAccount $account): MyFatoorahPaymentAttempt
    {
        $payment = $this->makePaymentRequest([
            'additional_data' => $this->stampedForTheMarketOf($account, gateway: (string) $account->gateway),
        ]);
        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        return MyFatoorahPaymentAttempt::query()->where('payment_request_id', $payment)->firstOrFail();
    }

    /**
     * The additional data a payment of this account's market carries, written by the production writer inside that market's storefront.
     *
     * App\Services\Payments\PaymentAccountBinding is the same writer every gateway's payment goes through (App\Traits\Payment::generate_link calls
     * it), so the stamp these tests rest on is the one production writes and not a shape invented here.
     */
    private function stampedForTheMarketOf(PaymentGatewayAccount $account, string $gateway = GatewayConfigRepository::KEY): string
    {
        $stamped = StorefrontResolver::fromConfig()
            ->context(explicitChoice: (string) $account->storefront_country)
            ->serve(static fn (): array => app(PaymentAccountBinding::class)->record(
                additionalData: ['customer_id' => 7, 'order_note' => null],
                gateway: $gateway,
                currencyCode: 'SAR',
            ));

        $this->assertSame(
            (int) $account->id,
            app(PaymentAccountBinding::class)->of(payment: ['additional_data' => $stamped])?->accountId,
            'the stamp the production writer produced names the account this payment is meant to belong to',
        );

        return (string) json_encode($stamped);
    }

    /** @return list<string> the payments marked paid, so a test can say WHOSE payment was acted on and not only how many were */
    private function paidPayments(): array
    {
        return DB::table('payment_requests')->where('is_paid', 1)->orderBy('id')->pluck('id')->all();
    }

    /** Every tracked language file, end to end, so "this value was not written into one" is a single assertion. */
    private function languageFileContents(): string
    {
        $contents = '';

        foreach (['en', 'sa', 'ae'] as $locale) {
            foreach (['messages', 'new-messages'] as $file) {
                $contents .= (string) file_get_contents(base_path("resources/lang/{$locale}/{$file}.php"));
            }
        }

        return $contents;
    }

    /**
     * An attempt of a payment that carries no stamp at all, created by the real initiation service.
     *
     * `makePaymentRequest()` writes the additional data a payment of this installation carried before per-company accounts existed, so the absence of the
     * stamp is the default state of the table and not something switched off here.
     */
    private function anAttemptOfAPaymentWithNoStamp(): MyFatoorahPaymentAttempt
    {
        $payment = $this->makePaymentRequest();
        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        $this->assertNull(
            app(PaymentAccountBinding::class)->of(payment: (array) DB::table('payment_requests')->where('id', $payment)->first()),
            'the payment under test has to carry no stamp at all',
        );

        return MyFatoorahPaymentAttempt::query()->where('payment_request_id', $payment)->firstOrFail();
    }

    /** Every column of the attempt under test, so "not one column was written" is a single comparison. */
    private function attemptRow(): object
    {
        return DB::table('myfatoorah_payment_attempts')->where('id', $this->attempt->id)->firstOrFail();
    }

    /** Every column of any attempt, for the tests that work on a second one. */
    private function attemptRowOf(MyFatoorahPaymentAttempt $attempt): object
    {
        return DB::table('myfatoorah_payment_attempts')->where('id', $attempt->id)->firstOrFail();
    }
}
