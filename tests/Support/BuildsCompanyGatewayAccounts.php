<?php

namespace Tests\Support;

use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Models\LegalEntity;
use App\Models\PaymentGatewayAccount;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use App\Traits\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Two companies with two gateway accounts, from the real migrations, and payments started through the real choke point every gateway passes through
 * (App\Traits\Payment::generate_link). Nothing here writes a stamp, a secret or a confirmation by hand: the production code does, or the test proves
 * nothing about the production code.
 */
trait BuildsCompanyGatewayAccounts
{
    use BuildsWalletTopUpWorld;

    /** The gateway these accounts are for, and the key its settings and its payments are stored under. */
    protected const PAYMOB = 'paymob_accept';

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
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();
    }

    /** Six markets served, companies registered, and per-company gateway accounts switched on. */
    protected function turnCompanyAccountsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.legal_entities.required_to_sell' => false,
            'gcc.storefronts.payment_accounts.enabled' => true,
            'gcc.storefronts.payment_accounts.gateways' => [self::PAYMOB, 'fatoorah'],
            'gcc.storefronts.payment_accounts.confirm_returns' => false,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);
        $this->forgetWhatWasRemembered();
    }

    protected function forgetWhatWasRemembered(): void
    {
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
        app(GatewayAccountRegistry::class)->flush();
    }

    protected function aCompanyIn(string $country): LegalEntity
    {
        $entity = LegalEntity::create([
            'country' => $country,
            'legal_name' => "Lavkoo {$country} Trading Co.",
            'trade_name' => "Lavkoo {$country}",
            'tax_registration_number' => "TAX-{$country}-001",
            'commercial_registration_number' => "CR-{$country}-777",
            'invoice_series_key' => "INV-{$country}",
            'address' => "1 Example Street, {$country}",
            'status' => true,
        ]);
        $this->forgetWhatWasRemembered();

        return $entity;
    }

    /**
     * One company's gateway account, with its credentials written by the production writer so the test cannot store them in a shape the application
     * does not read.
     *
     * @param array<string, string> $secrets
     */
    protected function anAccountFor(
        string $country,
        array $secrets,
        string $gateway = self::PAYMOB,
        string $environment = 'test',
        bool $active = true,
        ?int $company = null,
        ?string $webhookToken = null,
    ): PaymentGatewayAccount {
        $account = PaymentGatewayAccount::create([
            'storefront_country' => $country,
            'gateway' => $gateway,
            'environment' => $environment,
            'legal_entity_id' => $company,
            'label' => "{$country} {$gateway} {$environment}",
            'webhook_path_token' => $webhookToken ?? Str::random(48),
            'status' => $active,
        ]);

        app(GatewayAccountSecrets::class)->put(account: $account, secrets: $secrets);
        $this->forgetWhatWasRemembered();

        return $account->refresh();
    }

    /** The one platform-wide Paymob settings row, which is what every payment uses today and what the Saudi configuration is. */
    protected function thePlatformPaymobRow(string $hmac = 'the-platform-signing-secret', string $mode = 'test'): void
    {
        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => self::PAYMOB, 'settings_type' => 'payment_config'],
            [
                'id' => 'paymob-accept-of-the-test',
                'mode' => $mode,
                'is_active' => 1,
                'test_values' => json_encode(['hmac' => $hmac, 'secret_key' => 'platform-secret-key', 'public_key' => 'platform-public-key', 'integration_id' => '1111', 'supported_country' => 'KSA']),
                'live_values' => json_encode(['hmac' => $hmac, 'secret_key' => 'platform-secret-key', 'public_key' => 'platform-public-key', 'integration_id' => '1111', 'supported_country' => 'KSA']),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /** A currency row with the rate the platform holds for it, so a payment of that currency has a rate in force to record. */
    protected function aCurrency(string $code, float $rate): void
    {
        DB::table('currencies')->updateOrInsert(['code' => $code], ['name' => $code, 'symbol' => $code, 'exchange_rate' => $rate, 'status' => 1, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Runs the callback as a request served from that storefront, the way the resolver narrows a real request. */
    protected function inStorefront(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }

    /**
     * Starts a payment the way every one of the fourteen gateways starts one, and answers the row it created.
     *
     * The stamp on that row is written by App\Services\Payments\PaymentAccountBinding from inside generate_link(): the test asks for a payment and
     * reads what production wrote, rather than writing the stamp it then asserts.
     */
    protected function aPaymentStartedIn(string $country, string $currency, float $amount, string $gateway = self::PAYMOB): object
    {
        $before = DB::table('payment_requests')->pluck('id')->all();

        $this->inStorefront($country, static function () use ($gateway, $currency, $amount): void {
            $info = new PaymentInfo(
                success_hook: 'add_fund_to_wallet_success',
                failure_hook: 'add_fund_to_wallet_fail',
                currency_code: $currency,
                payment_method: $gateway,
                payment_platform: 'web',
                payer_id: '1',
                receiver_id: '100',
                additional_data: ['payment_mode' => 'web'],
                payment_amount: $amount,
                attribute: 'add_funds_to_wallet',
                attribute_id: '1',
            );

            Payment::generate_link(new Payer('A Customer', 'customer@example.invalid', '', ''), $info, new Receiver('receiver_name', 'example.png'));
        });

        return DB::table('payment_requests')->whereNotIn('id', $before)->firstOrFail();
    }

    /** What the production reader makes of a payment's stamp. */
    protected function theRecordOf(object|string $payment): ?\App\Services\Payments\PaymentTransactionRecord
    {
        $id = is_string($payment) ? $payment : $payment->id;
        $row = DB::table('payment_requests')->where('id', $id)->first();

        return app(\App\Services\Payments\PaymentAccountBinding::class)->of(payment: (array) $row);
    }

    /** The return address as Paymob builds it: the signature is over the values of the fields it names, in the order of their names. */
    protected function aSignedReturn(string $transaction, float $amount, string $secret, string $currency = 'SAR', string $success = 'true'): string
    {
        $result = ['amount_cents' => (string) (int) round($amount * 100), 'currency' => $currency, 'id' => $transaction, 'order' => '998877', 'pending' => 'false', 'success' => $success];
        ksort($result);

        return '/payment/paymob/callback?' . http_build_query($result + ['hmac' => hash_hmac('sha512', implode('', $result), $secret)]);
    }
}
