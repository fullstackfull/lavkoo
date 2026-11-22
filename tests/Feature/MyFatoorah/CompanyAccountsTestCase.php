<?php

namespace Tests\Feature\MyFatoorah;

use App\Models\LegalEntity;
use App\Models\PaymentGatewayAccount;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Storefront\LegalEntityRegistry;
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
}
