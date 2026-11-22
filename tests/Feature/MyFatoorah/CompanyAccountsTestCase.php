<?php

namespace Tests\Feature\MyFatoorah;

use App\Models\LegalEntity;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\PaymentGatewayAccount;
use App\Models\PaymentRequest;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\PaymentInitiationService;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Payments\PaymentAccountBinding;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Base for the tests of per-company gateway accounts on this gateway (D2-09): the two tables from their real migrations, the registry switched on, and
 * an account of one company with a secret and an address of its own.
 *
 * Shared by the notification-address tests and the admin-screen tests, which need the same world and ask different questions of it. Nothing is written
 * by hand that production writes: the credentials go in through App\Services\Payments\GatewayAccountSecrets, and the account rows come from the real
 * migration rather than from a schema invented here.
 */
abstract class CompanyAccountsTestCase extends MyFatoorahTestCase
{
    /**
     * The gateways config/gcc.php SHIPS as configurable per company, read before any test widens it.
     *
     * A server on the day the master flag is turned on is in exactly this configuration, so a test that wants to measure the shipped behaviour sets the
     * list back to this rather than to a list of its own.
     *
     * @var list<string>
     */
    protected array $shippedGatewayList = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shippedGatewayList = array_values((array) config('gcc.storefronts.payment_accounts.gateways'));
    }

    protected function installTheCompanyAndAccountTables(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
        Schema::dropIfExists('legal_entities');

        foreach (['2026_11_27_100000_create_legal_entities_table.php', '2026_11_28_100000_create_payment_gateway_accounts_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
    }

    protected function dropTheCompanyAndAccountTables(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
        Schema::dropIfExists('legal_entities');
        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();
    }

    /** Six markets served, companies registered, and per-company accounts switched on for this gateway and for Paymob. */
    protected function turnCompanyAccountsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.payment_accounts.enabled' => true,
            'gcc.storefronts.payment_accounts.gateways' => [GatewayConfigRepository::KEY, 'paymob_accept'],
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
        ]);
        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();
    }

    protected function anAccountFor(string $country, ?string $webhookSecret, string $token, string $gateway = GatewayConfigRepository::KEY): PaymentGatewayAccount
    {
        $company = LegalEntity::create([
            'country' => $country,
            'legal_name' => "Lavkoo {$country} Trading Co.",
            'invoice_series_key' => "INV-{$country}",
            'status' => true,
        ]);

        $account = PaymentGatewayAccount::create([
            'storefront_country' => $country,
            'gateway' => $gateway,
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

    /**
     * A MyFatoorah attempt whose payment is stamped with THIS account, whatever gateway the account is registered for.
     *
     * Both halves written by production: the stamp by App\Services\Payments\PaymentAccountBinding, the attempt by the real initiation service.
     */
    protected function anAttemptStampedWith(PaymentGatewayAccount $account): MyFatoorahPaymentAttempt
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
    protected function stampedForTheMarketOf(PaymentGatewayAccount $account, string $gateway = GatewayConfigRepository::KEY): string
    {
        $stamped = $this->theProductionWriterRunFor($account, gateway: $gateway);

        $this->assertSame(
            (int) $account->id,
            app(PaymentAccountBinding::class)->of(payment: ['additional_data' => $stamped])?->accountId,
            'the stamp the production writer produced names the account this payment is meant to belong to',
        );

        return $stamped;
    }

    /**
     * The same production writer run, with nothing assumed about whether it wrote a stamp.
     *
     * Separate from `stampedForTheMarketOf()` because a configuration in which the writer records NOTHING is a state worth measuring too — a gateway
     * the owner has not listed, a market with no account — and a helper that asserts a stamp cannot be used to measure its absence.
     */
    protected function theProductionWriterRunFor(PaymentGatewayAccount $account, string $gateway = GatewayConfigRepository::KEY): string
    {
        return (string) json_encode(StorefrontResolver::fromConfig()
            ->context(explicitChoice: (string) $account->storefront_country)
            ->serve(static fn (): array => app(PaymentAccountBinding::class)->record(
                additionalData: ['customer_id' => 7, 'order_note' => null],
                gateway: $gateway,
                currencyCode: 'SAR',
            )));
    }
}
