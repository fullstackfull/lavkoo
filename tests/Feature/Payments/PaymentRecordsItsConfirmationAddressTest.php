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
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\PaymentAccountBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsCompanyGatewayAccounts;
use Tests\TestCase;

/**
 * A payment records the address its confirmation is expected at, when it starts (GCC-21, stage A).
 *
 * Nothing refuses anything on the strength of this record: the platform address consulting it is a later stage the owner ordered to come after the
 * initiation side is bound. What is asserted here is only what is WRITTEN DOWN and how it is READ BACK.
 *
 * Every payment is started by App\Traits\Payment::generate_link(), the one place all fourteen gateways start a payment, so the address asserted is the
 * one the production writer recorded and not one arranged by the test.
 *
 * The platform address is a SENTINEL here, not the real route. The reader takes the platform address from its caller — each gateway has its own public
 * notification address, or none — so passing a value no route could produce is what proves the reader answers the caller's address rather than
 * deriving one of its own. The real route value is asserted on the initiation side, where it is really sent
 * (Tests\Feature\MyFatoorah\InitiationSendsTheRecordedConfirmationAddressTest).
 */
class PaymentRecordsItsConfirmationAddressTest extends TestCase
{
    use BuildsCompanyGatewayAccounts;

    /** A value no route in this application can produce, so answering it can only mean "the address my caller gave me". */
    private const THE_PLATFORM_ADDRESS = 'https://platform.example.invalid/the-one-public-address';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheCompanyAndAccountTables();
        $this->thePlatformPaymobRow();
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

    /** A payment started against a company account records THAT ACCOUNT's own notification address. */
    public function test_a_payment_started_against_a_company_account_records_that_accounts_own_address(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-key'], webhookToken: 'the-kuwait-token');

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'SAR', amount: 12.5);

        $this->assertSame(
            app(GatewayAccountDirectory::class)->webhookUrlOf(account: $account),
            $this->theRecordOf($payment)?->confirmationAddress,
            'the payment records the address the account is notified at',
        );
        $this->assertSame(route('payments.account.webhook', ['account' => 'the-kuwait-token']), $this->theRecordOf($payment)?->confirmationAddress);
        $this->assertSame($this->theRecordOf($payment)?->confirmationAddress, $this->theAddressExpectedBy($payment), 'and is read as expecting it');
        $this->assertNotSame(self::THE_PLATFORM_ADDRESS, $this->theAddressExpectedBy($payment), 'not the platform address');
    }

    /**
     * The case the whole shape of this record exists for: the account is EDITED, DISABLED and then DELETED, and what the payment recorded does not
     * move by one byte.
     *
     * Deleting the row is the one that matters. `accountOf()` answers null for a deleted account, so an address re-derived through the account at read
     * time would answer the platform address — and a payment that began under a company address would become platform-confirmable because somebody
     * deleted a row. That is the wrong direction to fail in, and it is why the ADDRESS is recorded and not the account id.
     */
    public function test_an_account_edited_disabled_and_deleted_leaves_the_recorded_address_byte_for_byte_unchanged(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-key'], webhookToken: 'the-kuwait-token');
        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'SAR', amount: 12.5);
        $recorded = (string) $this->theRecordOf($payment)?->confirmationAddress;
        $storedBefore = (string) DB::table('payment_requests')->where('id', $payment->id)->value('additional_data');

        $this->assertNotSame('', $recorded, 'there is an address to keep, or the rest of this test proves nothing');

        $account->forceFill(['webhook_path_token' => 'a-token-typed-later', 'label' => 'renamed', 'status' => false])->save();
        $this->forgetWhatWasRemembered();

        $this->assertSame($recorded, $this->theRecordOf($payment)?->confirmationAddress, 'an edited and disabled account does not move it');

        PaymentGatewayAccount::query()->whereKey($account->id)->delete();
        $this->forgetWhatWasRemembered();

        $this->assertNull(app(PaymentAccountBinding::class)->accountOf(payment: $this->rowOf($payment)), 'the account row really is gone');
        $this->assertSame($recorded, $this->theRecordOf($payment)?->confirmationAddress, 'and a deleted account does not move it either');
        $this->assertSame($storedBefore, (string) DB::table('payment_requests')->where('id', $payment->id)->value('additional_data'), 'byte for byte');
        $this->assertSame($recorded, $this->theAddressExpectedBy($payment), 'a company-bound payment does not become platform-confirmable by a deletion');
    }

    /** Write-once, as the rest of the stamp is: a second pass over a payment that already carries one changes nothing, whatever the account says now. */
    public function test_the_recorded_address_is_written_once_and_a_second_pass_does_not_move_it(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-key'], webhookToken: 'the-kuwait-token');
        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'SAR', amount: 12.5);
        $stored = (array) json_decode((string) DB::table('payment_requests')->where('id', $payment->id)->value('additional_data'), true);

        $account->forceFill(['webhook_path_token' => 'a-token-typed-later'])->save();
        $this->forgetWhatWasRemembered();

        $again = $this->inStorefront('KW', static fn (): array => app(PaymentAccountBinding::class)->record(
            additionalData: $stored,
            gateway: self::PAYMOB,
            currencyCode: 'SAR',
        ));

        $this->assertSame($stored, $again, 'the writer leaves a payment that already carries a stamp exactly as it is');
        $this->assertSame(
            route('payments.account.webhook', ['account' => 'the-kuwait-token']),
            $stored[PaymentAccountBinding::KEY]['confirmation_address'] ?? null,
            'the address recorded at creation, not the token typed afterwards',
        );
    }

    /** Accounts off, which is every shipped installation: nothing is recorded, and the payment is read as expecting the platform address. */
    public function test_a_payment_started_while_accounts_are_off_records_no_address_and_reads_as_the_platform_address(): void
    {
        config(['gcc.storefronts.payment_accounts.enabled' => false]);
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-key'], webhookToken: 'the-kuwait-token');
        $this->forgetWhatWasRemembered();

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'SAR', amount: 12.5);

        $this->assertNull($this->theRecordOf($payment), 'a dormant payment carries no stamp at all');
        $this->assertSame(self::THE_PLATFORM_ADDRESS, $this->theAddressExpectedBy($payment), 'and expects the platform address');
    }

    /** A market with no account of its own: stamped with nothing, so the platform address, which is what a Saudi payment is today. */
    public function test_a_market_with_no_account_of_its_own_expects_the_platform_address(): void
    {
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-key'], webhookToken: 'the-kuwait-token');

        $payment = $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: 100);

        $this->assertNull($this->theRecordOf($payment));
        $this->assertSame(self::THE_PLATFORM_ADDRESS, $this->theAddressExpectedBy($payment));
    }

    /** Every payment placed before any of this existed: no key in its stored data at all, and read as the platform address rather than as nothing. */
    public function test_a_legacy_payment_that_carries_no_stamp_reads_as_the_platform_address(): void
    {
        $legacy = ['additional_data' => json_encode(['payment_mode' => 'web'])];

        $this->assertStringNotContainsString(PaymentAccountBinding::KEY, (string) $legacy['additional_data']);
        $this->assertSame(self::THE_PLATFORM_ADDRESS, $this->theAddressExpectedByRow($legacy));
        $this->assertSame(self::THE_PLATFORM_ADDRESS, $this->theAddressExpectedByRow(['additional_data' => null]));
        $this->assertSame(self::THE_PLATFORM_ADDRESS, app(PaymentAccountBinding::class)->confirmationAddressOf(payment: null, platformAddress: self::THE_PLATFORM_ADDRESS));
    }

    /**
     * An account with no address of its own receives no notifications, so a payment through it records none and expects the platform address.
     *
     * The account is still the account the payment was taken through — the stamp names it, and the credentials are its — and the confirmation is still
     * the platform's, because there is no company address for the gateway to be told about.
     */
    public function test_an_account_with_no_address_of_its_own_records_none_and_expects_the_platform_address(): void
    {
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-key'], webhookToken: 'a-token-that-is-about-to-go');
        PaymentGatewayAccount::query()->whereKey($account->id)->update(['webhook_path_token' => '']);
        $this->forgetWhatWasRemembered();

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'SAR', amount: 12.5);

        $this->assertSame((int) $account->id, $this->theRecordOf($payment)?->accountId, 'the payment is still taken through the account');
        $this->assertNull($this->theRecordOf($payment)?->confirmationAddress, 'and records no address of its own');
        $this->assertSame(self::THE_PLATFORM_ADDRESS, $this->theAddressExpectedBy($payment));
    }

    /** Two companies' payments record two different addresses, so the record is per account and not per installation. */
    public function test_two_companies_payments_record_their_own_two_addresses(): void
    {
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-key'], webhookToken: 'the-kuwait-token');
        $this->anAccountFor(country: 'AE', secrets: ['secret_key' => 'emirates-key'], webhookToken: 'the-emirates-token');

        $kuwait = $this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'SAR', amount: 12.5))?->confirmationAddress;
        $emirates = $this->theRecordOf($this->aPaymentStartedIn(country: 'AE', currency: 'SAR', amount: 12.5))?->confirmationAddress;

        $this->assertSame(route('payments.account.webhook', ['account' => 'the-kuwait-token']), $kuwait);
        $this->assertSame(route('payments.account.webhook', ['account' => 'the-emirates-token']), $emirates);
        $this->assertNotSame($kuwait, $emirates);
    }

    /** The one unchanged thing worth pinning: the record of a payment that names no address is still a complete D2-10 record. */
    public function test_a_payment_that_records_no_address_is_still_a_complete_record(): void
    {
        $company = $this->aCompanyIn('KW');
        $this->aCurrency(code: 'KWD', rate: 0.307);
        $account = $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-key'], company: (int) $company->id, webhookToken: '');

        $record = $this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5));

        $this->assertSame((int) $account->id, $record?->accountId);
        $this->assertNull($record?->confirmationAddress);
        $this->assertTrue((bool) $record?->isComplete(), 'the market, the company, the account and the rate are what D2-10 asks for');
    }

    /** @return array<string, mixed> */
    private function rowOf(object|string $payment): array
    {
        return (array) DB::table('payment_requests')->where('id', is_string($payment) ? $payment : $payment->id)->first();
    }

    /** What the production reader answers for this payment, given the platform address its caller holds. */
    private function theAddressExpectedBy(object|string $payment): string
    {
        return $this->theAddressExpectedByRow($this->rowOf($payment));
    }

    /** @param array<string, mixed> $row */
    private function theAddressExpectedByRow(array $row): string
    {
        return app(PaymentAccountBinding::class)->confirmationAddressOf(payment: $row, platformAddress: self::THE_PLATFORM_ADDRESS);
    }
}
