<?php

namespace Tests\Feature\MyFatoorah;

use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahWebhookEvent;
use App\Models\PaymentGatewayAccount;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\Payments\ConfirmationAddresses;
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\PaymentAccountBinding;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A payment expects one confirmation address, and that address, and only it, confirms that payment (GCC-21, stage C).
 *
 * Stage A gave a payment the address its confirmation is expected at, written down when the payment started; stage B sent that address to the gateway
 * instead of a globally computed one. What is measured here is the other end: a payment taken through a company's gateway account was given that
 * company's own address, so the PUBLIC platform-wide address is not where its confirmation is expected, and that address declines it.
 *
 * THE HALF THAT KEEPS MONEY FROM BEING STRANDED, and the reason the question is asked the way it is. The refusal keys on a payment positively
 * expecting SOME OTHER address. It never keys on a payment having recorded none, because for a payment stamped before any of this existed the platform
 * address IS the correct answer — that is where its confirmation was asked for and where it arrives. Three stored shapes read as the platform address
 * and each of them is a payment this address must keep confirming; each has a test of its own below, and
 * App\Services\Payments\PaymentAccountBinding::expectsAnAddressOtherThan() names what each one means.
 *
 * Tests\Support\RestoresLanguageFiles is deliberately NOT used: this file renders no Blade and calls translate() nowhere, and the three tracked
 * `new-messages.php` files are byte-identical before and after it runs (measured, md5). Whoever renders a view from here owes the file that trait,
 * with its restore wired into tearDown.
 *
 * Nothing here is arranged by hand that production writes. The stamps come from App\Services\Payments\PaymentAccountBinding inside the market's own
 * storefront, the attempts from the real initiation service, the signatures from the production verifier, and every delivery is a real POST to the
 * real route. The one exception is named where it occurs: the third stored shape is a shape of the JSON column that no writer of this application
 * produces, and the test that uses it says so.
 */
class ThePlatformAddressConfirmsWhatExpectsItTest extends CompanyAccountsTestCase
{
    private const PLATFORM_WEBHOOK_SECRET = 'test-webhook-secret';

    private const KUWAIT_WEBHOOK_SECRET = 'kuwait-account-webhook-secret';

    private const EMIRATES_WEBHOOK_SECRET = 'emirates-account-webhook-secret';

    private PaymentGatewayAccount $kuwait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway(webhookSecret: self::PLATFORM_WEBHOOK_SECRET);
        $this->installTheCompanyAndAccountTables();
        $this->turnCompanyAccountsOn();
        $this->theOneAddressRuleIsOn();

        $this->kuwait = $this->anAccountFor(country: 'KW', webhookSecret: self::KUWAIT_WEBHOOK_SECRET, token: 'kuwait-webhook-token');
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();

        parent::tearDown();
    }

    /** The address a payment recorded confirms it. Without this the rest of the file would only be proving that nothing confirms anything. */
    public function test_a_payment_is_confirmed_at_the_address_it_recorded(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->fakePaidFor($attempt);

        $this->assertSame(
            app(GatewayAccountDirectory::class)->webhookUrlOf(account: $this->kuwait),
            $this->theAddressExpectedBy($attempt),
            'the payment under test really did record the company address',
        );

        $this->deliverToTheCompanyAddress($this->kuwait, $attempt, self::KUWAIT_WEBHOOK_SECRET)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments());
        $this->assertSame(1, $this->successHookCalls());
    }

    /**
     * OBLIGATION 2. The same payment, the same gateway, the same signed body — offered at the PUBLIC address, which is not the address it expects.
     *
     * The delivery is authentic by every test this address can make of its sender: it carries the platform's own signature, computed by the production
     * verifier with the platform's own secret, so the signature is not what declines it. What declines it is that the payment names a company address
     * of its own, and this is not that address.
     *
     * The payment is left EXACTLY as it was, and that is asserted as a whole-world comparison rather than as a list of guesses: every column of the
     * attempt, every column of the payment row, the webhook event table, the orders table and each recorded side effect of the success hook. The
     * question is asked after the signature and before the processor, and the processor is the only writer of any of them.
     */
    public function test_the_platform_address_does_not_confirm_a_payment_that_expects_a_companys_address(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->fakePaidFor($attempt);
        $before = $this->theWorld($attempt);

        $this->deliverToThePlatformAddress($attempt)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertEquals($before, $this->theWorld($attempt), 'not one column, row, event or side effect moved');
        $this->assertSame([], $this->paidPayments());
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(0, MyFatoorahWebhookEvent::count(), 'and the delivery was not even recorded');
    }

    /**
     * OBLIGATION 3, FIRST STORED SHAPE: a payment with NO STAMP AT ALL is still confirmed at the platform address.
     *
     * This is the shape that matters most, and the one a refusal keyed on an absence would have broken. It is every payment this installation placed
     * before stage A existed, every payment placed while per-company accounts are off, and every payment of a market with no account of its own,
     * Saudi Arabia's included. For all of them the platform address is where the gateway was told to deliver, so it is where the confirmation arrives,
     * and declining it would leave real money unconfirmed.
     *
     * The three legacy spellings of "nothing stored" are each put through the same real route, because a row written before this feature existed can
     * hold any of them.
     *
     * @return array<string, array{mixed}>
     */
    public static function nothingStoredAtAll(): array
    {
        return [
            'the additional data a payment of this installation carries' => [json_encode(['customer_id' => 7, 'order_note' => null])],
            'no additional data at all' => [null],
            'additional data that is not readable as a record' => ['not-json-at-all'],
        ];
    }

    #[DataProvider('nothingStoredAtAll')]
    public function test_a_payment_with_no_stamp_at_all_is_still_confirmed_at_the_platform_address(mixed $additionalData): void
    {
        $attempt = $this->anAttemptOfAPaymentWhoseAdditionalDataIs($additionalData);
        $this->fakePaidFor($attempt);

        $this->assertNull($this->theRecordOf($attempt), 'the payment under test has to carry no stamp at all');

        $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments(), 'an old payment is still confirmed where it was always confirmed');
        $this->assertSame(1, $this->successHookCalls());
        $this->assertSame(1, $this->effectCount('order'));
    }

    /**
     * OBLIGATION 3, SECOND STORED SHAPE: a stamp that carries NO ADDRESS is still confirmed at the platform address.
     *
     * This payment positively belongs to a company's gateway account — the stamp names the account, and the credentials it was taken with are that
     * account's — and it still expects the platform address, because its gateway is notified at no address of its own. That is not an edge case: it is
     * every payment of the one gateway `config/gcc.php` ships on the per-company list, so it is the shape a first installation to turn this on is made
     * of. `toStored()` leaves the key out rather than storing a null, and the account is a Paymob one here for exactly that reason.
     *
     * A refusal keyed on "this payment has a company account" instead of on "this payment expects another address" would strand every one of them.
     */
    public function test_a_payment_whose_stamp_carries_no_address_is_still_confirmed_at_the_platform_address(): void
    {
        $paymob = $this->anAccountFor(country: 'AE', webhookSecret: self::EMIRATES_WEBHOOK_SECRET, token: 'emirates-paymob-token', gateway: 'paymob_accept');
        $attempt = $this->anAttemptStampedWith($paymob);
        $this->fakePaidFor($attempt);

        $this->assertSame((int) $paymob->id, $this->theRecordOf($attempt)?->accountId, 'the payment belongs to a company gateway account');
        $this->assertNull($this->theRecordOf($attempt)?->confirmationAddress, 'and its stamp carries no address');
        $this->assertStringNotContainsString('confirmation_address', (string) $this->paymentRow($attempt)->additional_data, 'the key is absent, not null');

        $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments());
        $this->assertSame(1, $this->successHookCalls());
    }

    /**
     * OBLIGATION 3, THIRD STORED SHAPE: a stamp whose address is stored but is not usable text.
     *
     * CONSTRUCTED, and deliberately so. No writer of this application produces this shape — `GatewayAccountDirectory::webhookUrlOf()` answers a route
     * or null, and `toStored()` drops a null — but `additional_data` is a JSON column this application is not the only writer of, and
     * `PaymentTransactionRecord` reads every one of these values as no address. What the test measures is the direction that reading has to fail in:
     * an address that is not an address is no address, so the payment expects the platform one and is confirmed there. The alternative — treating a
     * stored empty string as a company address — would strand the payment at an address that is not a URL at all.
     *
     * The stamp itself is the production writer's; only the one value under the one key is replaced.
     *
     * @return array<string, array{mixed}>
     */
    public static function anAddressThatIsNotUsableText(): array
    {
        return [
            'an empty string' => [''],
            'a stored null' => [null],
            'a list' => [['https://example.invalid/a']],
            'a boolean' => [false],
        ];
    }

    #[DataProvider('anAddressThatIsNotUsableText')]
    public function test_a_payment_whose_stamp_carries_an_address_that_is_not_usable_text_is_confirmed_at_the_platform_address(mixed $stored): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->replaceTheRecordedAddressOf($attempt, with: $stored);
        $this->fakePaidFor($attempt);

        $this->assertNotNull($this->theRecordOf($attempt), 'the stamp is still there');
        $this->assertNull($this->theRecordOf($attempt)?->confirmationAddress, 'and it reads as no address');

        $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments());
    }

    /** A payment of one company is not confirmed at another company's address either. The addresses are the account's, and the account is the stamp's. */
    public function test_a_payment_of_one_company_is_not_confirmed_at_another_companys_address(): void
    {
        $emirates = $this->anAccountFor(country: 'AE', webhookSecret: self::EMIRATES_WEBHOOK_SECRET, token: 'emirates-webhook-token');
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->fakePaidFor($attempt);
        $before = $this->theWorld($attempt);

        $this->deliverToTheCompanyAddress($emirates, $attempt, self::EMIRATES_WEBHOOK_SECRET)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->assertEquals($before, $this->theWorld($attempt));
        $this->assertSame([], $this->paidPayments());
    }

    /**
     * NOTHING IS STRANDED when per-company accounts are switched OFF with the rule still on.
     *
     * A company's own address resolves no account while the registry is off (`byWebhookToken` answers null before it looks), so turning accounts off
     * turns every company address off with them. A payment that had already recorded one would then have no live address at all, and the public address
     * declining it as well is how money gets left unconfirmed by a configuration change. So enforcement requires the registry to be enabled too, and
     * this is that requirement measured rather than asserted: the recorded address is refused as an address, and the platform address confirms the
     * payment.
     */
    public function test_with_per_company_accounts_switched_off_a_recorded_payment_is_confirmed_at_the_platform_address(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->assertNotNull($this->theRecordOf($attempt)?->confirmationAddress, 'the payment recorded a company address while accounts were on');

        config(['gcc.storefronts.payment_accounts.enabled' => false]);
        app(GatewayAccountRegistry::class)->flush();
        $this->fakePaidFor($attempt);

        $this->assertFalse(app(ConfirmationAddresses::class)->isEnforced(), 'the rule is not in force without the registry');
        $this->deliverToTheCompanyAddress($this->kuwait, $attempt, self::KUWAIT_WEBHOOK_SECRET)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments(), 'the only live address confirms it');
        $this->assertSame(1, $this->successHookCalls());
    }

    /**
     * THE RULE OFF, which is the shipped default: the platform address confirms a company-bound payment exactly as it does on the base branch, and
     * does not pay one read for a question it is not asking.
     *
     * The payment under test is the very one the rule refuses when it is on, so "nothing changed" is measured on the case that changes. The read count
     * is the second claim and the one an argument cannot make: the switch is read before the attempt is looked up, so the delivery issues ONE lookup
     * by reference — the processor's own, the one it issues on the base branch — and not two.
     */
    public function test_with_the_rule_off_the_platform_address_confirms_a_company_payment_and_reads_no_attempt_for_the_question(): void
    {
        config(['gcc.storefronts.payment_accounts.one_confirmation_address' => false]);
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->fakePaidFor($attempt);

        $this->assertFalse(app(ConfirmationAddresses::class)->isEnforced());
        $this->assertNotNull($this->theRecordOf($attempt)?->confirmationAddress, 'the payment does expect a company address');

        $queries = $this->queriesWhile(fn (): TestResponse => $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled'));

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments(), 'confirmed, as it is today');
        $this->assertSame(1, $this->attemptLookupsIn($queries), 'the processor\'s own lookup, and nothing asked on top of it');
    }

    /** THE RULE ON: a refusal costs one lookup by reference and writes nothing — no event row, no column of the attempt, nothing. */
    public function test_with_the_rule_on_a_refusal_costs_one_read_and_writes_nothing(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->fakePaidFor($attempt);

        $queries = $this->queriesWhile(fn (): TestResponse => $this->deliverToThePlatformAddress($attempt)->assertStatus(401));

        $this->assertSame(1, $this->attemptLookupsIn($queries), 'asked once');
        $this->assertSame(
            [],
            array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'insert') || str_starts_with($sql, 'update'))),
            'and answered before anything was written',
        );
    }

    /**
     * THE RULE ON: a payment this address IS expected to confirm is looked up twice — once for the question, once by the processor.
     *
     * This is what makes the single lookup measured with the rule OFF a measurement of the switch rather than an accident of the route: the question
     * really does cost one read, and the read it costs is this one.
     */
    public function test_with_the_rule_on_a_payment_this_address_confirms_is_looked_up_once_more_than_without_it(): void
    {
        $attempt = $this->anAttemptOfAPaymentWhoseAdditionalDataIs(json_encode(['customer_id' => 7, 'order_note' => null]));
        $this->fakePaidFor($attempt);

        $queries = $this->queriesWhile(fn (): TestResponse => $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled'));

        $this->assertSame(2, $this->attemptLookupsIn($queries), 'the question\'s lookup and then the processor\'s');
    }

    /**
     * THE POLICY IS COMPLETE ON ITS OWN while the rule is off, and does not lean on its caller having asked first.
     *
     * The route asks whether the rule is in force before it looks a payment up, so that an installation which has not turned it on pays no read for a
     * question nobody is asking. That is a saving, not the rule. `mayConfirm()` has to answer correctly by itself, because the next caller may not ask
     * first — and a policy that enforced whenever it was called would enforce on an installation that had never turned it on.
     *
     * Measured with the registry ON and only the switch off, so what is isolated is the switch and nothing else, on a payment that positively expects
     * another address.
     */
    public function test_the_policy_answers_yes_on_its_own_while_the_rule_is_off(): void
    {
        config(['gcc.storefronts.payment_accounts.one_confirmation_address' => false]);
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $payment = (array) $this->paymentRow($attempt);

        $this->assertTrue(app(GatewayAccountRegistry::class)->isEnabled(), 'the registry is on, so the switch is the only thing off');
        $this->assertTrue(
            app(PaymentAccountBinding::class)->expectsAnAddressOtherThan(payment: $payment, address: route('myfatoorah.webhook')),
            'the payment positively expects another address',
        );
        $this->assertTrue(
            app(ConfirmationAddresses::class)->mayConfirm(address: route('myfatoorah.webhook'), payment: $payment),
            'and the policy answers yes anyway, with nobody having asked it whether the rule is in force',
        );
    }

    /** A delivery naming no attempt of ours is unaffected: another installation on the same merchant login, recorded and acted on in no way. */
    public function test_a_delivery_that_names_no_payment_of_ours_is_unaffected(): void
    {
        $payload = $this->webhookPayload('an-invoice-of-another-installation');

        $this->postJson('/api/myfatoorah/webhook', $payload, ['MyFatoorah-Signature' => $this->signatureFor($payload, self::PLATFORM_WEBHOOK_SECRET)])
            ->assertOk()
            ->assertJsonPath('status', 'no_attempt');

        $this->assertSame(1, MyFatoorahWebhookEvent::count(), 'recorded, as it is today');
        $this->assertSame(0, $this->successHookCalls());
    }

    /**
     * ON THE SHIPPED GATEWAY LIST this address confirms exactly what it confirms today, with the rule on and with it off.
     *
     * The gateway this address serves is not on the list `config/gcc.php` ships, so a payment of it is stamped with nothing at all even with
     * per-company accounts switched on — and a payment stamped with nothing expects this address. The list is read out of the repository's own file,
     * so a later change that put this gateway on it fails this test rather than quietly making the address refuse live payments.
     *
     * @return array<string, array{bool}>
     */
    public static function theSwitch(): array
    {
        return ['the rule off, which is what ships' => [false], 'the rule on' => [true]];
    }

    #[DataProvider('theSwitch')]
    public function test_on_the_shipped_gateway_list_this_address_confirms_what_it_confirms_today(bool $switch): void
    {
        $this->assertNotContains(GatewayConfigRepository::KEY, $this->shippedGatewayList, 'the premise is the shipped list');

        config([
            'gcc.storefronts.payment_accounts.gateways' => $this->shippedGatewayList,
            'gcc.storefronts.payment_accounts.one_confirmation_address' => $switch,
        ]);
        app(GatewayAccountRegistry::class)->flush();

        $attempt = $this->anAttemptOfAPaymentWhoseAdditionalDataIs($this->theProductionWriterRunFor($this->kuwait));
        $this->fakePaidFor($attempt);

        $this->assertNull($this->theRecordOf($attempt), 'a payment of a gateway off the list is stamped with nothing by the production writer');

        $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments());
        $this->assertSame(1, $this->successHookCalls());
    }

    /**
     * THE ADDRESS THIS ROUTE HOLDS ITSELF TO IS ITS OWN, and that is measured rather than read off the source.
     *
     * A payment whose RECORDED address is this route's own is confirmed here. Nothing else about the delivery differs from the one that is refused
     * above — same account, same signature, same body — so if the route compared the record against any other value, this payment would be refused
     * too. That is what makes the refusal above a statement about which address this is and not merely about a record being present.
     *
     * CONSTRUCTED, for the reason the third stored shape is: no writer of this application records a platform address, because the value only ever
     * comes from a per-account route. The stamp is the production writer's and only the one value under the one key is replaced.
     */
    public function test_a_payment_that_recorded_this_very_address_is_confirmed_here(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->replaceTheRecordedAddressOf($attempt, with: route('myfatoorah.webhook'));
        $this->fakePaidFor($attempt);

        $this->assertSame(route('myfatoorah.webhook'), $this->theAddressExpectedBy($attempt), 'the payment expects this address');
        $this->assertTrue(app(ConfirmationAddresses::class)->isEnforced(), 'with the rule in force');

        $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments());
    }

    /**
     * An attempt whose payment ROW is gone behaves exactly as it does today, with the rule on.
     *
     * The rule reads the payment to find out what address it expects, and there is no payment to read. That is not a payment expecting another
     * address, so this address does not decline it on the rule's account, and what happens to it is whatever happened before: the verification finds no
     * amount to agree with and answers "mismatch", recording the delivery and settling nothing. The same literal answer with the rule on and with it
     * off, which is the claim. A company's own address refuses this case instead, and that asymmetry is right: a public address declining it would be
     * a behaviour change on a path this stage is not about, and there is no company to hand it to.
     *
     * @return array<string, array{bool}>
     */
    #[DataProvider('theSwitch')]
    public function test_an_attempt_whose_payment_row_is_gone_behaves_as_it_does_today(bool $switch): void
    {
        config(['gcc.storefronts.payment_accounts.one_confirmation_address' => $switch]);
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->fakePaidFor($attempt);
        DB::table('payment_requests')->where('id', $attempt->payment_request_id)->delete();

        $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'mismatch');

        $this->assertSame([], $this->paidPayments(), 'there is nothing to pay');
        $this->assertSame(0, $this->successHookCalls());
        $this->assertSame(1, MyFatoorahWebhookEvent::count(), 'recorded, as it is today');
    }

    private function theOneAddressRuleIsOn(): void
    {
        config(['gcc.storefronts.payment_accounts.one_confirmation_address' => true]);
    }

    private function deliverToThePlatformAddress(MyFatoorahPaymentAttempt $attempt): TestResponse
    {
        $payload = $this->webhookPayload((string) $attempt->external_identifier);

        return $this->postJson('/api/myfatoorah/webhook', $payload, ['MyFatoorah-Signature' => $this->signatureFor($payload, self::PLATFORM_WEBHOOK_SECRET)]);
    }

    private function deliverToTheCompanyAddress(PaymentGatewayAccount $account, MyFatoorahPaymentAttempt $attempt, string $secret): TestResponse
    {
        $payload = $this->webhookPayload((string) $attempt->external_identifier);

        return $this->postJson(
            '/api/payments/account/' . $account->webhook_path_token . '/webhook',
            $payload,
            ['MyFatoorah-Signature' => $this->signatureFor($payload, $secret)],
        );
    }

    /** MyFatoorah answers "paid" for this attempt whenever asked, as the settlement tests arrange it. */
    private function fakePaidFor(MyFatoorahPaymentAttempt $attempt, string $amount = '150.00'): void
    {
        Http::fake([
            '*/v3/invoices/*' => Http::response(['IsSuccess' => true, 'Data' => ['Transaction' => ['PaymentId' => 'PAY-1', 'Status' => 'SUCCESS']]]),
            '*/v3/payments/*' => Http::response($this->paymentDetails((string) $attempt->external_identifier, amount: $amount)),
        ]);
    }

    /** An attempt of a payment whose additional data is exactly what is given, created by the real initiation service. */
    private function anAttemptOfAPaymentWhoseAdditionalDataIs(mixed $additionalData): MyFatoorahPaymentAttempt
    {
        $payment = $this->makePaymentRequest(['additional_data' => $additionalData]);
        app(\App\Services\MyFatoorah\PaymentInitiationService::class)->start(\App\Models\PaymentRequest::find($payment));

        return MyFatoorahPaymentAttempt::query()->where('payment_request_id', $payment)->firstOrFail();
    }

    /** Replaces the one value under the one key of a stamp the production writer produced, leaving every other field of it alone. */
    private function replaceTheRecordedAddressOf(MyFatoorahPaymentAttempt $attempt, mixed $with): void
    {
        $stored = (array) json_decode((string) $this->paymentRow($attempt)->additional_data, true);
        $stored[PaymentAccountBinding::KEY]['confirmation_address'] = $with;

        DB::table('payment_requests')->where('id', $attempt->payment_request_id)->update(['additional_data' => json_encode($stored)]);
    }

    private function paymentRow(MyFatoorahPaymentAttempt $attempt): object
    {
        return DB::table('payment_requests')->where('id', $attempt->payment_request_id)->firstOrFail();
    }

    private function theRecordOf(MyFatoorahPaymentAttempt $attempt): ?\App\Services\Payments\PaymentTransactionRecord
    {
        return app(PaymentAccountBinding::class)->of(payment: (array) $this->paymentRow($attempt));
    }

    private function theAddressExpectedBy(MyFatoorahPaymentAttempt $attempt): ?string
    {
        return $this->theRecordOf($attempt)?->confirmationAddress;
    }

    /**
     * Everything a delivery could possibly have moved, in one value: the attempt, the payment row, the recorded events, the orders and each side
     * effect of the success hook. Compared whole, so "exactly as it was" is one assertion and not a list of the things somebody thought to check.
     *
     * @return array<string, mixed>
     */
    private function theWorld(MyFatoorahPaymentAttempt $attempt): array
    {
        return [
            'attempt' => (array) DB::table('myfatoorah_payment_attempts')->where('id', $attempt->id)->firstOrFail(),
            'payment' => (array) $this->paymentRow($attempt),
            'events' => DB::table('myfatoorah_webhook_events')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'orders' => DB::table('orders')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'success_hook_calls' => $this->successHookCalls(),
            'failure_hook_calls' => $this->failureHookCalls(),
            'effects' => [
                'order' => $this->effectCount('order'),
                'stock_reservation' => $this->effectCount('stock_reservation'),
                'shipment' => $this->effectCount('shipment'),
                'wallet_credit' => $this->effectCount('wallet_credit'),
            ],
        ];
    }

    /** @return list<string> the SQL the callback caused, so a claim about what was read or written is a measurement */
    private function queriesWhile(callable $callback): array
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    /**
     * How many times an attempt was looked up BY THE REFERENCE a notification names it by, which is the one read the question costs.
     *
     * Matched on the table and the column rather than on a whole SQL string, because the two engines quote identifiers differently and this count has
     * to mean the same thing on both.
     *
     * @param list<string> $queries
     */
    private function attemptLookupsIn(array $queries): int
    {
        return count(array_filter(
            $queries,
            static fn (string $sql): bool => str_starts_with($sql, 'select')
                && str_contains($sql, 'myfatoorah_payment_attempts')
                && str_contains($sql, 'external_identifier'),
        ));
    }

    /** @return list<string> the payments marked paid, so a test can say WHOSE payment was acted on and not only how many were */
    private function paidPayments(): array
    {
        return DB::table('payment_requests')->where('is_paid', 1)->orderBy('id')->pluck('id')->all();
    }
}
