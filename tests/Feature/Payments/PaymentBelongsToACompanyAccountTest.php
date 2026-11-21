<?php

namespace Tests\Feature\Payments;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\PaymentGatewayAccount;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\PaymentAccountBinding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsCompanyGatewayAccounts;
use Tests\TestCase;

/**
 * A payment is taken through the account of the company that sells in its market, and keeps that account (D2-07), with the market, the company, the
 * account and the exchange rate written down as they were when it started (D2-10).
 *
 * Every payment here is started by App\Traits\Payment::generate_link(), the one place all fourteen gateways start a payment, so what is asserted is
 * what production wrote and not what the test arranged.
 */
class PaymentBelongsToACompanyAccountTest extends TestCase
{
    use BuildsCompanyGatewayAccounts;

    private const KUWAIT_SECRET_KEY = 'kuwait-account-secret-key';

    private const SAUDI_SECRET_KEY = 'saudi-account-secret-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheCompanyAndAccountTables();
        $this->thePlatformPaymobRow();
        $this->aCurrency(code: 'KWD', rate: 0.307);
        $this->turnCompanyAccountsOn();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();

        parent::tearDown();
    }

    /** D2-07: 'A Kuwait payment starts against the Kuwait account's credentials and never the Saudi account's'. */
    public function test_a_kuwait_payment_starts_against_the_kuwait_accounts_credentials_and_never_the_saudi_accounts(): void
    {
        $kuwait = $this->aCompanyIn('KW');
        $saudi = $this->aCompanyIn('SA');
        $kuwaitAccount = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::KUWAIT_SECRET_KEY, 'public_key' => 'kuwait-public', 'integration_id' => '2222', 'hmac' => 'kuwait-hmac'], company: (int) $kuwait->id);
        $this->anAccountFor(country: 'SA', secrets: ['secret_key' => self::SAUDI_SECRET_KEY, 'public_key' => 'saudi-public', 'integration_id' => '3333', 'hmac' => 'saudi-hmac'], company: (int) $saudi->id);

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5);

        $this->assertSame((int) $kuwaitAccount->id, $this->theRecordOf($payment)?->accountId, 'the Kuwait payment must name the Kuwait account');

        Http::fake(['*' => Http::response(['client_secret' => 'a-client-secret'], 200)]);

        $this->get('/payment/paymob/pay?payment_id=' . $payment->id)->assertRedirectContains('unifiedcheckout');

        Http::assertSent(static fn ($request): bool => str_contains((string) $request->header('Authorization')[0], self::KUWAIT_SECRET_KEY));
        Http::assertNotSent(static fn ($request): bool => str_contains((string) $request->header('Authorization')[0], self::SAUDI_SECRET_KEY));
        Http::assertNotSent(static fn ($request): bool => str_contains((string) $request->header('Authorization')[0], 'platform-secret-key'));
    }

    /**
     * Two payments started in the same second are two payments at Paymob, and each is findable there by our own identifier.
     *
     * The reference sent was `time()`: two customers in one second shared it, and Paymob holds it unique, so the second intention was refused. It
     * also named nothing of ours, so a payment paid at Paymob and never returned from could not be found there at all.
     */
    public function test_two_payments_started_in_the_same_second_name_themselves_to_the_gateway(): void
    {
        $this->aCompanyIn('KW');
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::KUWAIT_SECRET_KEY, 'public_key' => 'kuwait-public', 'integration_id' => '2222']);
        Http::fake(['*' => Http::response(['client_secret' => 'a-client-secret'], 200)]);

        $first = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5);
        $second = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5);

        $this->get('/payment/paymob/pay?payment_id=' . $first->id)->assertRedirectContains('unifiedcheckout');
        $this->get('/payment/paymob/pay?payment_id=' . $second->id)->assertRedirectContains('unifiedcheckout');

        $references = array_map(static fn ($request): mixed => $request->data()['special_reference'] ?? null, Http::recorded()->map(static fn (array $pair): mixed => $pair[0])->all());

        $this->assertSame([$first->id, $second->id], $references, 'each intention names its own payment');
        $this->assertCount(2, array_unique($references), 'and two payments of one second are two references');
    }

    /** D2-07: 'An attempt keeps its account after the account is edited or disabled; verification, settlement and refund use that account'. */
    public function test_an_attempt_keeps_its_account_after_the_account_is_edited_or_disabled(): void
    {
        $kuwait = $this->aCompanyIn('KW');
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::KUWAIT_SECRET_KEY, 'hmac' => 'kuwait-hmac'], company: (int) $kuwait->id);

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5);
        $recordedAccount = $this->theRecordOf($payment)?->accountId;

        $account->forceFill(['status' => false, 'label' => 'renamed after the customer left for the gateway'])->save();
        $this->forgetWhatWasRemembered();

        $this->assertNull(
            app(GatewayAccountRegistry::class)->forStorefront(country: 'KW', gateway: self::PAYMOB, environment: 'test'),
            'a disabled account is no longer the one a NEW payment resolves to',
        );
        $this->assertSame($recordedAccount, $this->theRecordOf($payment)?->accountId, 'the payment already taken keeps the account it was taken through');
        $this->assertSame(
            (int) $account->id,
            app(PaymentAccountBinding::class)->accountOf(payment: (array) DB::table('payment_requests')->where('id', $payment->id)->first())?->id,
            'settlement, verification and refund read the stamped account even once it is disabled',
        );
    }

    /** D2-07: 'The existing Saudi row works unchanged as the SA account with no data change'. */
    public function test_the_existing_saudi_row_works_unchanged_as_the_sa_account_with_no_data_change(): void
    {
        $settingsBefore = DB::table('addon_settings')->where('key_name', self::PAYMOB)->first();

        $payment = $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: 100);

        $this->assertNull($this->theRecordOf($payment), 'with no account registered, a Saudi payment carries no stamp at all');
        $this->assertEquals($settingsBefore, DB::table('addon_settings')->where('key_name', self::PAYMOB)->first(), 'nothing was written to the platform settings row');

        Http::fake(['*' => Http::response(['client_secret' => 'a-client-secret'], 200)]);

        $this->get('/payment/paymob/pay?payment_id=' . $payment->id)->assertRedirectContains('unifiedcheckout');

        Http::assertSent(static fn ($request): bool => str_contains((string) $request->header('Authorization')[0], 'platform-secret-key'));
    }

    /** D2-07: 'A storefront has at most one live account per gateway and environment (unique key)'. */
    public function test_a_storefront_has_at_most_one_account_per_gateway_and_environment(): void
    {
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::KUWAIT_SECRET_KEY], environment: 'live');

        try {
            PaymentGatewayAccount::create(['storefront_country' => 'KW', 'gateway' => self::PAYMOB, 'environment' => 'live', 'status' => true]);
            $this->fail('A second live Kuwait account for the same gateway must be refused by the database.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, PaymentGatewayAccount::query()->where('storefront_country', 'KW')->where('environment', 'live')->count());

        // The test environment of the same market is a different account, not a second live one.
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-sandbox-key'], environment: 'test');

        $this->assertSame(2, PaymentGatewayAccount::query()->where('storefront_country', 'KW')->count());
    }

    /** D2-10: 'A payment names its selling company and gateway account', with the market and the rate in force beside them. */
    public function test_a_payment_names_its_market_its_company_its_account_and_the_rate_in_force(): void
    {
        $kuwait = $this->aCompanyIn('KW');
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::KUWAIT_SECRET_KEY], company: (int) $kuwait->id);

        $record = $this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5));

        $this->assertNotNull($record);
        $this->assertSame('KW', $record->market);
        $this->assertSame((int) $kuwait->id, $record->companyId);
        $this->assertSame((int) $account->id, $record->accountId);
        $this->assertSame('0.307', $record->exchangeRate);
        $this->assertSame('KWD', $record->currency);
        $this->assertSame(self::PAYMOB, $record->gateway);
        $this->assertSame('test', $record->environment);
        $this->assertTrue($record->isComplete(), 'the market, the company, the account and the rate are all four on the record');
        $this->assertSame('Lavkoo KW Trading Co.', $record->company['legal_name'] ?? null, 'the company is frozen by name, not only by id');
    }

    /**
     * D2-10, and the point of the whole requirement: the record is read as stored.
     *
     * Everything that produced it moves — the company is renamed, the account is disabled, the rate is retyped, the market is switched off — and the
     * payment still reads as the payment it was. A figure re-derived at read time is the defect this is here to catch.
     */
    public function test_the_record_still_reads_the_same_after_the_settings_that_produced_it_have_changed(): void
    {
        $kuwait = $this->aCompanyIn('KW');
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::KUWAIT_SECRET_KEY], company: (int) $kuwait->id);
        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5);
        $before = $this->theRecordOf($payment);

        $kuwait->forceFill(['legal_name' => 'A Different Company Entirely', 'tax_registration_number' => 'TAX-CHANGED'])->save();
        $account->forceFill(['status' => false])->save();
        DB::table('currencies')->where('code', 'KWD')->update(['exchange_rate' => 9.99]);
        config(['gcc.storefronts.countries.KW.active' => false]);
        $this->forgetWhatWasRemembered();

        $after = $this->theRecordOf($payment);

        $this->assertEquals($before, $after, 'the record of a payment is what it was when the payment started');
        $this->assertSame('0.307', $after?->exchangeRate, 'the rate in force when the payment started, not the one typed afterwards');
        $this->assertSame('Lavkoo KW Trading Co.', $after?->company['legal_name'] ?? null, 'the company as it was named when it took the money');
    }

    /** A market with no account of its own leaves a payment unstamped, so it is taken exactly as it is taken today. */
    public function test_a_market_with_no_account_of_its_own_stamps_nothing(): void
    {
        $this->aCompanyIn('AE');
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::KUWAIT_SECRET_KEY]);

        $this->assertNull($this->theRecordOf($this->aPaymentStartedIn(country: 'AE', currency: 'SAR', amount: 50)));
    }

    /** A gateway the owner has not listed is never read from an account, whatever the table holds. */
    public function test_a_gateway_the_owner_has_not_listed_is_not_taken_through_an_account(): void
    {
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => self::KUWAIT_SECRET_KEY]);
        config(['gcc.storefronts.payment_accounts.gateways' => ['fatoorah']]);
        $this->forgetWhatWasRemembered();

        $this->assertNull($this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5)));
    }
}
