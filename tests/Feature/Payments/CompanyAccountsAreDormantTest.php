<?php

namespace Tests\Feature\Payments;

use App\Events\AddFundToWalletEvent;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\PaymentAccountBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCompanyGatewayAccounts;
use Tests\TestCase;

/**
 * With the markets off, and with them on but only Saudi Arabia configured, every payment path, figure, response and rendered surface is what it is
 * today (GCC-19).
 *
 * Three states are each put through the same real route and compared with each other, rather than with an argument about what the code would do:
 *
 *   1. the markets off, which is main;
 *   2. the markets on with per-company accounts off;
 *   3. everything on, with only Saudi Arabia configured and no account registered.
 *
 * In all three the payment row, the amount stored, the answer of the gateway's return and the rendered webhook block have to agree.
 */
class CompanyAccountsAreDormantTest extends TestCase
{
    use BuildsCompanyGatewayAccounts;

    private const OPENING_BALANCE = 10.0;

    private const PLATFORM_SECRET = 'the-platform-signing-secret';

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->installTheConfirmationRegister();
        $this->thePlatformPaymobRow(hmac: self::PLATFORM_SECRET);
        $this->customer = $this->aCustomer(balance: self::OPENING_BALANCE);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        Event::fake([AddFundToWalletEvent::class]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function dormantStates(): array
    {
        return [
            'the markets off, which is main' => ['off'],
            'the markets on, per-company accounts off' => ['markets_only'],
            'everything on, only Saudi Arabia configured, no account registered' => ['saudi_only'],
        ];
    }

    #[DataProvider('dormantStates')]
    public function test_a_saudi_payment_starts_settles_and_credits_exactly_as_it_does_today(string $state): void
    {
        $this->inState($state);

        $payment = $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: 100);
        DB::table('payment_requests')->where('id', $payment->id)->update(['payer_id' => (string) $this->customer, 'attribute' => 'add_fund']);

        $this->assertNull(app(PaymentAccountBinding::class)->of(payment: (array) DB::table('payment_requests')->where('id', $payment->id)->first()), 'nothing is stamped');
        $this->assertSame(100.0, (float) DB::table('payment_requests')->where('id', $payment->id)->value('payment_amount'));

        $return = $this->aSignedReturn(transaction: '4455667', amount: 100, secret: self::PLATFORM_SECRET);

        $this->withSession(['payment_id' => $payment->id])->get($return)->assertRedirectContains('payment-success');

        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'));
        $this->assertSame(self::OPENING_BALANCE + 100, $this->walletBalanceOf($this->customer), 'no stored amount changed');
    }

    /**
     * The additional data of a dormant payment carries no key of this work.
     *
     * With the markets off it is exactly what the caller handed it. With the markets on it also carries the storefront, which
     * App\Services\Storefront\PaymentStorefront already records on the base of this branch and is not this work; what matters here is that the
     * account stamp is not there in any of the three states.
     */
    #[DataProvider('dormantStates')]
    public function test_the_payment_row_carries_no_account_stamp(string $state): void
    {
        $this->inState($state);

        $stored = (string) DB::table('payment_requests')->where('id', $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: 100)->id)->value('additional_data');

        $this->assertStringNotContainsString(PaymentAccountBinding::KEY, $stored);
        $this->assertSame(
            $state === 'off' ? ['payment_mode' => 'web'] : ['payment_mode' => 'web', 'storefront' => 'SA'],
            json_decode($stored, true),
            'what the caller handed it, plus only what the markets feature already recorded',
        );
    }

    /** A return the platform secret signs settles the payment, and the account machinery refuses nothing. */
    #[DataProvider('dormantStates')]
    public function test_the_platform_secret_still_settles_a_dormant_payment(string $state): void
    {
        $this->inState($state);

        $payment = $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: 100);

        $this->withSession(['payment_id' => $payment->id])
            ->get($this->aSignedReturn(transaction: '4455667', amount: 100, secret: self::PLATFORM_SECRET) . '&payment_id=' . $payment->id)
            ->assertRedirectContains('payment-success');

        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'));
    }

    /** And a wrong secret still refuses it, so dormancy is not "the checks are off". */
    #[DataProvider('dormantStates')]
    public function test_a_wrongly_signed_return_still_refuses_a_dormant_payment(string $state): void
    {
        $this->inState($state);

        $payment = $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: 100);

        $this->withSession(['payment_id' => $payment->id])
            ->get($this->aSignedReturn(transaction: '4455667', amount: 100, secret: 'not-the-platform-secret'))
            ->assertRedirectContains('payment-fail');

        $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'));
        $this->assertSame(self::OPENING_BALANCE, $this->walletBalanceOf($this->customer));
    }

    /** The rendered webhook block of the gateway screen: empty in every dormant state, so the screen is what it is today. */
    #[DataProvider('dormantStates')]
    public function test_the_rendered_webhook_block_is_empty(string $state): void
    {
        $this->inState($state);

        $this->assertSame([], app(GatewayAccountDirectory::class)->rows());
        $this->assertSame('', trim(view('admin-views.third-party.payment-method.partials._company-gateway-accounts', [
            'gatewayAccounts' => app(GatewayAccountDirectory::class)->rows(),
        ])->render()));
    }

    /** The company's own webhook address answers nothing while there is no company account to answer for. */
    #[DataProvider('dormantStates')]
    public function test_a_company_webhook_address_answers_nothing(string $state): void
    {
        $this->inState($state);

        $this->postJson('/api/payments/account/any-token-at-all/webhook', ['Event' => ['Code' => 1]], ['MyFatoorah-Signature' => 'anything'])
            ->assertStatus(401);
    }

    /** Before the migration has run the code is simply inert, which is the state a shop is in between a deploy and a migration. */
    public function test_with_the_switch_on_but_the_table_not_there_nothing_is_stamped_and_nothing_fails(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.payment_accounts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        $this->forgetWhatWasRemembered();

        $this->assertFalse(app(GatewayAccountRegistry::class)->isMigrated());

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'SAR', amount: 100);

        $this->assertNull(app(PaymentAccountBinding::class)->of(payment: (array) DB::table('payment_requests')->where('id', $payment->id)->first()));
        $this->assertSame([], app(GatewayAccountDirectory::class)->rows());

        $this->withSession(['payment_id' => $payment->id])
            ->get($this->aSignedReturn(transaction: '4455667', amount: 100, secret: self::PLATFORM_SECRET))
            ->assertRedirectContains('payment-success');
    }

    private function inState(string $state): void
    {
        if ($state === 'off') {
            // No table and no switch: this is exactly what the base branch is.
            Schema::dropIfExists('payment_gateway_accounts');
            config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.payment_accounts.enabled' => false]);
            $this->forgetWhatWasRemembered();

            return;
        }

        $this->installTheCompanyAndAccountTables();
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.payment_accounts.enabled' => $state === 'saudi_only',
            'gcc.storefronts.payment_accounts.gateways' => [self::PAYMOB, 'fatoorah'],
        ]);
        $this->forgetWhatWasRemembered();
    }
}
