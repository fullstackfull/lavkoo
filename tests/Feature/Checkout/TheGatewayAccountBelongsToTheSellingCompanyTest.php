<?php

namespace Tests\Feature\Checkout;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Payments\AccountOfTheSellingCompany;
use App\Services\Payments\PaymentAccountBinding;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsCompanyGatewayAccounts;
use Tests\TestCase;

/**
 * The third clause of "one checkout names one selling legal entity, recorded on the order and USED TO PICK THE GATEWAY ACCOUNT".
 *
 * The first two clauses hold already and are pinned elsewhere: `StorefrontCheckoutSingleCompanyTest` refuses a checkout whose rows name two
 * selling companies, and `EachSubOrderCarriesItsOwnMarketTest` places real orders and reads the company off each of them. The third was not
 * asked anywhere. `payment_gateway_accounts.legal_entity_id` is a free column, so the Kuwait Paymob account could be filed under the Saudi
 * company and every Kuwaiti sale would be collected into that company's merchant account while the order said Kuwait's -- the order naming
 * one company and the money going to another, with nothing comparing the two.
 *
 * What is asserted here is the pick, through the real choke point every one of the fourteen gateways starts a payment at
 * (`App\Traits\Payment::generate_link`), reading the stamp production wrote. The credentials a payment is taken with follow that stamp
 * (`PaymobController` resolves them through `PaymentAccountBinding::accountOf()`), so the stamp is what decides whose account takes the
 * money, and `GatewayAccountRegistry::forStorefront()` has exactly one caller: the binding this test drives.
 *
 * Saudi Arabia and every market today are the FIRST test: the switch is absent from the configuration, so it is off, and the pick is the
 * pick that was made before this existed -- the mis-registered account included, because changing what an existing installation does is
 * the owner's decision and not this track's.
 */
class TheGatewayAccountBelongsToTheSellingCompanyTest extends TestCase
{
    use BuildsCompanyGatewayAccounts;

    private const SWITCH = 'gcc.storefronts.payment_accounts.account_belongs_to_the_selling_company';

    private const KUWAIT_SECRETS = ['secret_key' => 'kuwait-account-secret-key', 'public_key' => 'kuwait-public', 'integration_id' => '2222', 'hmac' => 'kuwait-hmac'];

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

    private function sellingCompanyAccounts(): AccountOfTheSellingCompany
    {
        return app(AccountOfTheSellingCompany::class);
    }

    /** As shipped the switch is not in the configuration at all, so nothing about any pick changes. */
    public function test_the_switch_is_off_as_shipped(): void
    {
        $this->assertFalse($this->sellingCompanyAccounts()->isEnforced());
        $this->assertFalse((bool) config(self::SWITCH), 'the switch must ship off -- absent from config/gcc.php, or declared there as false');
    }

    /**
     * THE CONTROL CASE, and the one that keeps an existing installation byte for byte: with the switch off, a Kuwait payment is still taken
     * through the account registered for Kuwait even when that account is filed under the Saudi company.
     */
    public function test_with_the_switch_off_a_mis_registered_account_still_takes_the_payment_exactly_as_today(): void
    {
        $this->aCompanyIn('KW');
        $saudi = $this->aCompanyIn('SA');
        $account = $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: (int) $saudi->id);

        $record = $this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5));

        $this->assertNotNull($record, 'with the switch off the pick is the registry\'s own answer, which is the account of the market');
        $this->assertSame((int) $account->id, $record->accountId);
        $this->assertSame((int) $saudi->id, $record->companyId);
    }

    public function test_a_payment_is_taken_through_the_account_of_the_company_that_sells_in_its_market(): void
    {
        config([self::SWITCH => true]);
        $kuwait = $this->aCompanyIn('KW');
        $account = $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: (int) $kuwait->id);

        $record = $this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5));

        $this->assertNotNull($record);
        $this->assertSame((int) $account->id, $record->accountId);
        $this->assertSame((int) $kuwait->id, $record->companyId);
        $this->assertSame('KW', $record->market);
    }

    /** The defect this closes: the market's account belongs to another company, so it does not answer for this market's sales. */
    public function test_an_account_of_another_company_does_not_take_this_markets_sale(): void
    {
        config([self::SWITCH => true]);
        $this->aCompanyIn('KW');
        $saudi = $this->aCompanyIn('SA');
        $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: (int) $saudi->id);

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5);

        $this->assertNull(
            $this->theRecordOf($payment),
            'a Kuwaiti sale must not be collected into another company\'s merchant account',
        );
        $this->assertNull(app(PaymentAccountBinding::class)->accountOf(payment: (array) $payment));
    }

    /** An account that names no company is already read as the market's own, so it is not a disagreement and keeps taking the payment. */
    public function test_an_account_that_names_no_company_is_the_markets_own_and_still_takes_the_payment(): void
    {
        config([self::SWITCH => true]);
        $kuwait = $this->aCompanyIn('KW');
        $account = $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: null);

        $record = $this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5));

        $this->assertNotNull($record);
        $this->assertSame((int) $account->id, $record->accountId);
        $this->assertSame((int) $kuwait->id, $record->companyId);
    }

    /** Silence is not disagreement: a market with no registered company has nothing for the account to disagree with. */
    public function test_a_market_with_no_registered_company_is_not_refused_its_account(): void
    {
        config([self::SWITCH => true]);
        $saudi = $this->aCompanyIn('SA');
        $account = $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: (int) $saudi->id);

        $record = $this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5));

        $this->assertNotNull($record);
        $this->assertSame((int) $account->id, $record->accountId);
    }

    /** The reader the registry's own answer is taken from, asked directly, so the pick is not only visible through a payment. */
    public function test_the_reader_answers_the_market_account_only_while_it_is_the_selling_companys(): void
    {
        $kuwait = $this->aCompanyIn('KW');
        $saudi = $this->aCompanyIn('SA');
        $account = $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: (int) $kuwait->id);
        config([self::SWITCH => true]);

        $this->assertSame(
            (int) $account->id,
            (int) $this->sellingCompanyAccounts()->of(country: 'KW', gateway: self::PAYMOB, environment: 'test')?->id,
        );

        $account->update(['legal_entity_id' => (int) $saudi->id]);
        $this->forgetWhatWasRemembered();

        $this->assertNull($this->sellingCompanyAccounts()->of(country: 'KW', gateway: self::PAYMOB, environment: 'test'));
    }

    /** A report an operator reads before a market opens names both companies, whatever the switch says. */
    public function test_a_disagreement_names_the_account_s_company_and_the_selling_one(): void
    {
        $kuwait = $this->aCompanyIn('KW');
        $saudi = $this->aCompanyIn('SA');
        $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: (int) $saudi->id);

        $this->assertSame(
            ['account' => (int) $saudi->id, 'selling' => (int) $kuwait->id],
            $this->sellingCompanyAccounts()->disagreement(country: 'KW', gateway: self::PAYMOB, environment: 'test'),
        );
        $this->assertFalse($this->sellingCompanyAccounts()->isEnforced(), 'the report is answered without the switch, because a disagreement nobody acts on is still worth seeing');
    }

    public function test_a_market_whose_account_is_its_own_companys_reports_no_disagreement(): void
    {
        $kuwait = $this->aCompanyIn('KW');
        $this->aCompanyIn('SA');
        $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: (int) $kuwait->id);

        $this->assertNull($this->sellingCompanyAccounts()->disagreement(country: 'KW', gateway: self::PAYMOB, environment: 'test'));
    }

    /** A market with no account of its own has no disagreement to report and no account to pick, switch or no switch. */
    public function test_a_market_with_no_account_reports_nothing(): void
    {
        config([self::SWITCH => true]);
        $this->aCompanyIn('KW');

        $this->assertNull($this->sellingCompanyAccounts()->disagreement(country: 'KW', gateway: self::PAYMOB, environment: 'test'));
        $this->assertNull($this->sellingCompanyAccounts()->of(country: 'KW', gateway: self::PAYMOB, environment: 'test'));
    }

    /** While per-company accounts are off altogether, nothing is picked and nothing is reported: the platform settings row takes every payment. */
    public function test_nothing_is_picked_while_per_company_accounts_are_off(): void
    {
        config([self::SWITCH => true, 'gcc.storefronts.payment_accounts.enabled' => false]);
        $kuwait = $this->aCompanyIn('KW');
        $this->anAccountFor(country: 'KW', secrets: self::KUWAIT_SECRETS, company: (int) $kuwait->id);
        $this->forgetWhatWasRemembered();

        $this->assertNull($this->sellingCompanyAccounts()->of(country: 'KW', gateway: self::PAYMOB, environment: 'test'));
        $this->assertNull($this->theRecordOf($this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5)));
    }
}
