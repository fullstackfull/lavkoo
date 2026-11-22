<?php

namespace Tests\Feature\MyFatoorah;

use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\PaymentInitiationService;
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\PaymentAccountBinding;
use App\Services\Payments\PaymentTransactionRecord;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Initiation tells the gateway the address the payment RECORDED, and not one resolved afresh (GCC-21, stage B).
 *
 * The value under test is the OUTGOING one: `IntegrationUrls.Webhook` of the body MyFatoorah is actually sent, read out of the recorded request rather
 * than out of the code that built it. Three cases, and the first of them is the one that must not move:
 *
 *   a payment carrying no stamp sends EXACTLY the platform address it sent before this change, byte for byte. That is every payment on a shipped
 *   configuration, because `config('gcc.storefronts.payment_accounts.gateways')` ships as Paymob alone and a gateway that is not on it stamps nothing;
 *
 *   a payment stamped with a company account OF THIS GATEWAY sends THAT account's own address, and not the platform one;
 *
 *   a payment stamped with an account of ANOTHER gateway sends the platform address, because an account is notified at an address of its own only at
 *   the gateway that address serves — and on the shipped list the only account an installation can have is of another gateway;
 *
 *   and with the gateway list the repository actually ships, this gateway is stamped with nothing at all — asserted, not assumed.
 */
class InitiationSendsTheRecordedConfirmationAddressTest extends CompanyAccountsTestCase
{
    private const KUWAIT_TOKEN = 'the-kuwait-notification-token';

    /** Every top-level key of the body this gateway is sent, so a value added or dropped beside the address under test cannot pass unseen. */
    private const THE_BODY_KEYS = ['Order', 'IntegrationUrls', 'Language', 'NotificationOption', 'PaymentExpiry', 'MetaData', 'Customer'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
        $this->installTheCompanyAndAccountTables();
        $this->turnCompanyAccountsOn();

        Http::fake(['*/v3/payments' => Http::response([
            'IsSuccess' => true,
            'Data' => ['InvoiceId' => 'INV-1', 'PaymentId' => null, 'PaymentURL' => 'https://sa.myfatoorah.com/KSA/ie/1'],
        ], 201)]);
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();

        parent::tearDown();
    }

    /** The case that must not move: a payment with no stamp is told the platform address, byte for byte what this line sent before. */
    public function test_a_payment_that_recorded_no_address_sends_exactly_the_platform_address(): void
    {
        $payment = $this->makePaymentRequest();

        $this->assertNull($this->theRecordOf($payment), 'this payment carries no stamp, which is the case under test');

        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        $this->assertSame(route('myfatoorah.webhook'), $this->theAddressSent());
        $this->assertSame('http://localhost/api/myfatoorah/webhook', $this->theAddressSent(), 'the public platform address, spelled out');
    }

    /** A payment stamped with a company account is told that account's own address, and the platform address is not sent at all. */
    public function test_a_payment_stamped_with_a_company_account_sends_that_accounts_own_address(): void
    {
        $account = $this->anAccountFor(country: 'KW', webhookSecret: 'kuwait-webhook-secret', token: self::KUWAIT_TOKEN);
        $payment = $this->makePaymentRequest(['additional_data' => $this->stampedForTheMarketOf($account)]);

        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        $this->assertSame(app(GatewayAccountDirectory::class)->webhookUrlOf(account: $account), $this->theAddressSent());
        $this->assertSame(route('payments.account.webhook', ['account' => self::KUWAIT_TOKEN]), $this->theAddressSent());
        $this->assertNotSame(route('myfatoorah.webhook'), $this->theAddressSent(), 'the platform address was not what the gateway was told');
    }

    /** Two companies' payments are told two different addresses, so what is sent is the payment's and not the installation's. */
    public function test_two_companies_payments_send_their_own_two_addresses(): void
    {
        $kuwait = $this->anAccountFor(country: 'KW', webhookSecret: 'kuwait-webhook-secret', token: self::KUWAIT_TOKEN);
        $emirates = $this->anAccountFor(country: 'AE', webhookSecret: 'emirates-webhook-secret', token: 'the-emirates-notification-token');

        app(PaymentInitiationService::class)->start(PaymentRequest::find($this->makePaymentRequest(['additional_data' => $this->stampedForTheMarketOf($kuwait)])));
        app(PaymentInitiationService::class)->start(PaymentRequest::find($this->makePaymentRequest(['additional_data' => $this->stampedForTheMarketOf($emirates)])));

        $this->assertSame(
            [route('payments.account.webhook', ['account' => self::KUWAIT_TOKEN]), route('payments.account.webhook', ['account' => 'the-emirates-notification-token'])],
            $this->theAddressesSent(),
        );
    }

    /**
     * What the payment recorded, and nothing fresher: the account's token is retyped and the account disabled after the payment started, and the
     * gateway is still told the address the payment began under.
     */
    public function test_an_account_retyped_after_the_payment_started_does_not_change_what_is_sent(): void
    {
        $account = $this->anAccountFor(country: 'KW', webhookSecret: 'kuwait-webhook-secret', token: self::KUWAIT_TOKEN);
        $payment = $this->makePaymentRequest(['additional_data' => $this->stampedForTheMarketOf($account)]);

        $account->forceFill(['webhook_path_token' => 'a-token-typed-later', 'status' => false])->save();
        app(GatewayAccountRegistry::class)->flush();

        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        $this->assertSame(route('payments.account.webhook', ['account' => self::KUWAIT_TOKEN]), $this->theAddressSent());
    }

    /** And a deleted account does not turn the payment back into a platform-confirmable one on the way out either. */
    public function test_a_deleted_account_does_not_turn_the_outgoing_address_back_into_the_platform_one(): void
    {
        $account = $this->anAccountFor(country: 'KW', webhookSecret: 'kuwait-webhook-secret', token: self::KUWAIT_TOKEN);
        $payment = $this->makePaymentRequest(['additional_data' => $this->stampedForTheMarketOf($account)]);

        PaymentGatewayAccount::query()->whereKey($account->id)->delete();
        app(GatewayAccountRegistry::class)->flush();

        $this->assertNull(
            app(PaymentAccountBinding::class)->accountOf(payment: (array) DB::table('payment_requests')->where('id', $payment)->first()),
            'the account row is gone, which is the state that would re-derive the wrong address',
        );

        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        $this->assertSame(route('payments.account.webhook', ['account' => self::KUWAIT_TOKEN]), $this->theAddressSent());
        $this->assertNotSame(route('myfatoorah.webhook'), $this->theAddressSent());
    }

    /**
     * A payment stamped with an account of ANOTHER gateway is told the platform address, and never that account's.
     *
     * An account is notified at an address of its own only at the gateway that address serves, so a payment taken through an account of any other
     * gateway records none and this gateway's initiation sends the platform address. Without that, a payment stamped with a PAYMOB account — and
     * Paymob is the one gateway the owner's shipped list holds, so it is the only account a shipped installation can have — would have had this
     * gateway's invoice created with the Paymob account's address on it. That address answers 401 to every delivery of this gateway, by construction
     * and not by configuration, so the confirmation of that payment could never arrive anywhere.
     */
    public function test_a_payment_stamped_with_another_gateways_account_sends_the_platform_address(): void
    {
        $account = $this->anAccountFor(country: 'KW', webhookSecret: 'kuwait-webhook-secret', token: 'the-paymob-account-token', gateway: 'paymob_accept');
        $payment = $this->makePaymentRequest(['additional_data' => $this->stampedForTheMarketOf($account, gateway: 'paymob_accept')]);

        $this->assertSame((int) $account->id, $this->theRecordOf($payment)?->accountId, 'the payment is stamped with the other gateway account');
        $this->assertNull($this->theRecordOf($payment)?->confirmationAddress, 'which is notified at no address of its own, so none was recorded');

        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        $this->assertSame(route('myfatoorah.webhook'), $this->theAddressSent());
        $this->assertStringNotContainsString('the-paymob-account-token', (string) $this->theAddressSent(), 'the other account address was not what the gateway was told');
    }

    /**
     * The dormancy pin, asserted rather than assumed: with the gateway list config/gcc.php SHIPS, this gateway is configured from the one platform
     * settings row, a payment of it is stamped with nothing, and the platform address is what the gateway is told.
     *
     * That list is Paymob alone. Until this gateway joins it — which, as the config says, is the same change that makes it read its api_key from the
     * account — this gateway's payments are stamped with nothing whatsoever. That is one of the two halves of "no company address is sent on a shipped
     * installation"; the other is the test above it, where a PAYMOB account IS stamped on a payment and is still notified at no address of its own.
     */
    public function test_with_the_gateway_list_the_repository_ships_a_payment_sends_the_platform_address(): void
    {
        $account = $this->anAccountFor(country: 'KW', webhookSecret: 'kuwait-webhook-secret', token: self::KUWAIT_TOKEN);
        config(['gcc.storefronts.payment_accounts.gateways' => $this->shippedGatewayList]);
        app(GatewayAccountRegistry::class)->flush();

        $this->assertNotContains(GatewayConfigRepository::KEY, $this->shippedGatewayList, 'the shipped list does not cover this gateway');

        $stamped = StorefrontResolver::fromConfig()
            ->context(explicitChoice: (string) $account->storefront_country)
            ->serve(static fn (): array => app(PaymentAccountBinding::class)->record(additionalData: ['customer_id' => 7], gateway: GatewayConfigRepository::KEY, currencyCode: 'SAR'));

        $this->assertSame(['customer_id' => 7], $stamped, 'the production writer stamps nothing for a gateway the owner has not listed');

        $payment = $this->makePaymentRequest(['additional_data' => json_encode($stamped)]);
        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        $this->assertSame(route('myfatoorah.webhook'), $this->theAddressSent());
    }

    /** The rest of the body is what it was: changing the notification address changed the notification address and nothing beside it. */
    public function test_nothing_else_in_the_outgoing_body_changed(): void
    {
        $payment = $this->makePaymentRequest();

        app(PaymentInitiationService::class)->start(PaymentRequest::find($payment));

        $body = Http::recorded()->map(static fn (array $pair): array => $pair[0]->data())->last();

        $this->assertSame(self::THE_BODY_KEYS, array_keys($body), 'the whole body, key for key and in the order it was in');
        $this->assertSame(['Redirection', 'Webhook'], array_keys($body['IntegrationUrls']), 'the two addresses, in the order they were in');
        $this->assertStringContainsString('myfatoorah/callback', (string) $body['IntegrationUrls']['Redirection']);
        $this->assertSame(150.0, $body['Order']['Amount']);
        $this->assertSame('SAR', $body['Order']['Currency']);
        $this->assertSame('EN', $body['Language'], 'and every other value the same line sends beside the address');
        $this->assertSame('LINK', $body['NotificationOption']);
        $this->assertSame(['UDF1', 'UDF2', 'UDF3'], array_keys($body['MetaData']));
    }

    /** The outgoing notification address of the last request MyFatoorah was sent. */
    private function theAddressSent(): ?string
    {
        $sent = $this->theAddressesSent();

        return $sent === [] ? null : (string) end($sent);
    }

    /** @return list<string> the outgoing notification address of every request MyFatoorah was sent, in order */
    private function theAddressesSent(): array
    {
        return Http::recorded()
            ->map(static fn (array $pair): mixed => $pair[0]->data()['IntegrationUrls']['Webhook'] ?? null)
            ->filter(static fn (mixed $address): bool => is_string($address))
            ->values()
            ->all();
    }

    /** What the production reader makes of the stamp of a payment row. */
    private function theRecordOf(string $payment): ?PaymentTransactionRecord
    {
        return app(PaymentAccountBinding::class)->of(payment: (array) DB::table('payment_requests')->where('id', $payment)->first());
    }

    /**
     * The additional data a payment of this account's market carries, written by the production writer inside that market's storefront.
     *
     * App\Services\Payments\PaymentAccountBinding is the same writer every gateway's payment goes through (App\Traits\Payment::generate_link calls it),
     * so the stamp these tests rest on is the one production writes and not a shape invented here.
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
}
