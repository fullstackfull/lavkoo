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
use App\Services\Payments\ConfirmationAddresses;
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\PaymentAccountBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCompanyGatewayAccounts;
use Tests\TestCase;

/**
 * ON A SHIPPED INSTALLATION THE ONE-ADDRESS RULE CHANGES NOTHING OBSERVABLE, with the switch on and with it off (GCC-21, stage C).
 *
 * Not asserted — measured, and measured against the configuration the repository actually SHIPS rather than against one arranged here. The shipped
 * values are read out of `config/gcc.php` itself in every test below, so a later change to the file that would make this feature live on a shipped
 * server fails these tests instead of shipping quietly.
 *
 * WHY IT IS INERT, which is three separate things and not one:
 *
 *   PER-COMPANY ACCOUNTS ARE OFF. The rule needs the registry enabled as well as its own switch, so on a shipped installation it is not in force
 *   however the switch is set. That is the first and the only one an operator has to undo deliberately.
 *
 *   THE SHIPPED GATEWAY LIST HOLDS ONE GATEWAY, and that gateway is notified at no address of its own: its confirmation is the customer's own return
 *   to a platform-wide address, not a server-to-server call. So a payment taken through a company account of it records NO address, and a payment that
 *   recorded no address expects the platform one. The rule has nothing to refuse even with the registry on.
 *
 *   THE GATEWAY THAT WOULD HAVE AN ADDRESS IS NOT ON THE LIST, so a payment of it is stamped with nothing at all.
 *
 * The second and third are measured here with the registry switched ON and the shipped list in place, which is the configuration a server is in on the
 * day an operator turns markets on. A shipped installation is therefore inert on three counts, and the tests say which is doing the work.
 */
class TheOneAddressRuleIsDormantAsShippedTest extends TestCase
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

    /** @return array<string, array{bool}> the switch, both ways */
    public static function theSwitch(): array
    {
        return ['the rule off, which is what ships' => [false], 'the rule on' => [true]];
    }

    /**
     * What the repository SHIPS: per-company accounts off, the rule off, and one gateway on the per-company list.
     *
     * Read out of `config/gcc.php` rather than out of the running configuration, which other tests mutate. This is the premise every other test in
     * this file rests on, so it is measured first and on its own.
     */
    public function test_what_the_repository_ships(): void
    {
        $shipped = $this->shippedPaymentAccountsConfig();

        $this->assertFalse((bool) $shipped['enabled'], 'per-company accounts are off');
        $this->assertFalse((bool) $shipped['one_confirmation_address'], 'and so is the one-address rule');
        $this->assertSame([self::PAYMOB], array_values((array) $shipped['gateways']), 'one gateway may be configured per company');
    }

    /** With the shipped values in force the rule is not in force, whatever the switch says: it needs the registry, and the registry is off. */
    #[DataProvider('theSwitch')]
    public function test_the_shipped_configuration_does_not_put_the_rule_in_force(bool $switch): void
    {
        $this->inTheShippedConfiguration($switch);

        $this->assertFalse(app(ConfirmationAddresses::class)->isEnforced());
    }

    /**
     * THE REGISTRY HALF IS THE REGISTRY, not the one key next to the switch — and nothing held that.
     *
     * `GatewayAccountRegistry::isEnabled()` requires TWO things: the payment-accounts key AND storefronts being served at all. The test above sets the
     * shipped payment-accounts key to false, which is enough for its own assertion on its own, so the second half of the registry's condition was
     * never isolated. Reviewer B of the stage-C round replaced the registry call with a direct read of that one key and this file stayed green, then
     * measured the mutant answering true where the real method answers false. Here is the configuration that separates them: storefronts off,
     * payment accounts ON, and the switch on.
     *
     * It is the half of the enforcement condition the whole stranding argument rests on, so it is worth a configuration of its own: a server with
     * storefronts off has no per-company address resolving anything, and a public address that also declined an already-recorded payment would leave
     * it with no live address at all.
     */
    public function test_the_rule_is_not_in_force_while_storefronts_are_not_served_at_all(): void
    {
        config([
            'gcc.storefronts.enabled' => false,
            'gcc.storefronts.payment_accounts.enabled' => true,
            'gcc.storefronts.payment_accounts.one_confirmation_address' => true,
        ]);
        $this->forgetWhatWasRemembered();

        $this->assertTrue((bool) config('gcc.storefronts.payment_accounts.enabled'), 'the key beside the switch is on');
        $this->assertTrue((bool) config('gcc.storefronts.payment_accounts.one_confirmation_address'), 'and so is the switch');
        $this->assertFalse(app(GatewayAccountRegistry::class)->isEnabled(), 'the registry still answers no, because storefronts are not served');
        $this->assertFalse(app(ConfirmationAddresses::class)->isEnforced(), 'so the rule is not in force');
    }

    /**
     * A Saudi payment starts, is confirmed by the customer's return and credits the wallet, with the switch either way.
     *
     * The real route, the real signed return, the real credit. The figures are literal in both states rather than compared between two runs, so a
     * change that moved them BOTH would not pass.
     */
    #[DataProvider('theSwitch')]
    public function test_a_saudi_payment_starts_settles_and_credits_the_same_with_the_switch_either_way(bool $switch): void
    {
        $this->inTheShippedConfiguration($switch);

        $payment = $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: 100);
        DB::table('payment_requests')->where('id', $payment->id)->update(['payer_id' => (string) $this->customer, 'attribute' => 'add_fund']);

        $this->assertNull($this->theStampOf($payment->id), 'nothing is stamped');

        $this->withSession(['payment_id' => $payment->id])
            ->get($this->aSignedReturn(transaction: '4455667', amount: 100, secret: self::PLATFORM_SECRET))
            ->assertRedirectContains('payment-success');

        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'));
        $this->assertSame(self::OPENING_BALANCE + 100, $this->walletBalanceOf($this->customer));
    }

    /** And a wrongly signed return still refuses it with the switch either way, so dormancy is not "the checks are off". */
    #[DataProvider('theSwitch')]
    public function test_a_wrongly_signed_return_still_refuses_a_shipped_payment_with_the_switch_either_way(bool $switch): void
    {
        $this->inTheShippedConfiguration($switch);

        $payment = $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: 100);

        $this->withSession(['payment_id' => $payment->id])
            ->get($this->aSignedReturn(transaction: '4455667', amount: 100, secret: 'not-the-platform-secret'))
            ->assertRedirectContains('payment-fail');

        $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'));
        $this->assertSame(self::OPENING_BALANCE, $this->walletBalanceOf($this->customer));
    }

    /**
     * THE DAY AN OPERATOR TURNS MARKETS ON. The registry is switched on, the gateway list is still the shipped one, and the rule still has nothing to
     * refuse — because no payment records an address.
     *
     * An account of the shipped gateway is registered in each market, a payment is taken through it, and what is measured is that the stamp names the
     * account and carries NO address, that the account is named no address of its own, and that the production reader therefore answers "yes" when the
     * platform address asks whether it may confirm the payment. That is the whole of the rule's decision, taken on the real record.
     */
    #[DataProvider('theSwitch')]
    public function test_with_markets_on_and_the_shipped_gateway_list_no_payment_records_an_address(bool $switch): void
    {
        $this->withMarketsOnAndTheShippedGatewayList($switch);
        $platformAddress = 'https://platform.example.invalid/the-one-public-address';

        foreach (['SA', 'KW', 'AE'] as $market) {
            $account = $this->anAccountFor(country: $market, secrets: ['secret_key' => "{$market}-key"], webhookToken: "{$market}-token");
            $payment = $this->aPaymentStartedIn(country: $market, currency: 'SAR', amount: 25);
            $stamp = $this->theStampOf($payment->id);

            $this->assertSame((int) $account->id, $stamp?->accountId, "{$market}: the payment is taken through the company account");
            $this->assertNull($stamp?->confirmationAddress, "{$market}: and records no address of its own");
            $this->assertNull(app(GatewayAccountDirectory::class)->webhookUrlOf(account: $account), "{$market}: the account is named no address");
            $this->assertTrue(
                app(ConfirmationAddresses::class)->mayConfirm(address: $platformAddress, payment: $this->paymentRow($payment->id)),
                "{$market}: so the platform address may confirm it",
            );
        }
    }

    /** The same day, the same list: a payment of the gateway that WOULD have an address is stamped with nothing at all, so there is nothing to read. */
    #[DataProvider('theSwitch')]
    public function test_with_the_shipped_gateway_list_a_payment_of_the_unlisted_gateway_is_stamped_with_nothing(bool $switch): void
    {
        $this->withMarketsOnAndTheShippedGatewayList($switch);
        $this->anAccountFor(country: 'KW', secrets: ['api_key' => 'kw-api-key'], gateway: 'fatoorah', webhookToken: 'kw-fatoorah-token');

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'SAR', amount: 25, gateway: 'fatoorah');

        $this->assertNull($this->theStampOf($payment->id), 'a gateway the owner has not listed is stamped with nothing');
        $this->assertTrue(
            app(ConfirmationAddresses::class)->mayConfirm(address: route('myfatoorah.webhook'), payment: $this->paymentRow($payment->id)),
            'so its own platform address may confirm it, as it does today',
        );
    }

    /** The operator's own screen is unchanged with the switch either way: it reads the directory, and the directory names no address under this list. */
    #[DataProvider('theSwitch')]
    public function test_the_operator_screen_names_no_address_with_the_switch_either_way(bool $switch): void
    {
        $this->withMarketsOnAndTheShippedGatewayList($switch);
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kw-key'], webhookToken: 'kw-token');
        $this->anAccountFor(country: 'KW', secrets: ['api_key' => 'kw-api-key'], gateway: 'fatoorah', webhookToken: 'kw-fatoorah-token');

        $rows = app(GatewayAccountDirectory::class)->rows();

        $this->assertCount(2, $rows, 'both accounts are listed');
        $this->assertSame([null, null], array_column($rows, 'webhook_url'));
    }

    /** @return array<string, mixed> the payment-accounts section exactly as config/gcc.php ships it, not as a running test has left it */
    private function shippedPaymentAccountsConfig(): array
    {
        $shipped = require config_path('gcc.php');

        return $shipped['storefronts']['payment_accounts'];
    }

    /** The running configuration set to the shipped values, with only the rule's own switch moved. */
    private function inTheShippedConfiguration(bool $switch): void
    {
        $shipped = $this->shippedPaymentAccountsConfig();

        config([
            'gcc.storefronts.payment_accounts.enabled' => (bool) $shipped['enabled'],
            'gcc.storefronts.payment_accounts.gateways' => (array) $shipped['gateways'],
            'gcc.storefronts.payment_accounts.one_confirmation_address' => $switch,
        ]);
        $this->forgetWhatWasRemembered();
    }

    /** The shipped values with per-company accounts switched on and the markets served, which is the day an operator turns them on. */
    private function withMarketsOnAndTheShippedGatewayList(bool $switch): void
    {
        $this->installTheCompanyAndAccountTables();
        $this->turnCompanyAccountsOn();
        $this->inTheShippedConfiguration($switch);
        config([
            'gcc.storefronts.payment_accounts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
        $this->forgetWhatWasRemembered();

        $this->assertSame([self::PAYMOB], array_values((array) config('gcc.storefronts.payment_accounts.gateways')), 'the shipped list, not a wider one');
    }

    private function theStampOf(string $payment): ?\App\Services\Payments\PaymentTransactionRecord
    {
        return app(PaymentAccountBinding::class)->of(payment: $this->paymentRow($payment));
    }

    /** @return array<string, mixed> */
    private function paymentRow(string $payment): array
    {
        return (array) DB::table('payment_requests')->where('id', $payment)->first();
    }
}
