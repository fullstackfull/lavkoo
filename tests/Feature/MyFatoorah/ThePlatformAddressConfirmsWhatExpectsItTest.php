<?php

namespace Tests\Feature\MyFatoorah;

use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahWebhookEvent;
use App\Models\PaymentGatewayAccount;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\Payments\ConfirmationAddresses;
use App\Services\Payments\GatewayAccountDirectory;
use App\Services\Payments\GatewayAccountRegistry;
use App\Services\Payments\GatewayAccountSecrets;
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
     * Five spellings of "nothing a record can be read out of" are each put through the same real route, because a row written before this feature
     * existed, or by something that is not this application, can hold any of them: the additional data a payment of this installation carries, no
     * additional data at all, text that is not readable at all, a list where a record would be, and the key itself present but holding something that
     * is not a record. `PaymentAccountBinding::of()` answers null for every one, and null is an OLD payment.
     *
     * @return array<string, array{mixed}>
     */
    public static function nothingStoredAtAll(): array
    {
        return [
            'the additional data a payment of this installation carries' => [json_encode(['customer_id' => 7, 'order_note' => null])],
            'no additional data at all' => [null],
            'additional data that is not readable as a record' => ['not-json-at-all'],
            'a list where a record would be' => [json_encode([1, 2])],
            'the key present but holding no record' => [json_encode(['gcc_payment_account' => 'not-a-record'])],
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
     *
     * THE COMBINATION IS CONSTRUCTED, and the shape is not. The stamp is a Paymob account's, because that is the only writer of this application that
     * produces a record with no address in it; the delivery is driven at THIS gateway's address, which a Paymob payment never reaches. What makes the
     * conclusion hold anyway is that the rule reads the record and not the gateway, so the shape is what is under test and the route is only how it is
     * reached. The file labels its other two constructed cases and did not label this one; reviewer B of the stage-C round found that.
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

    /**
     * A STORED VALUE THAT IS NOT TEXT BUT IS NOT EMPTY EITHER — a number — reads as an address, and this is what that costs.
     *
     * `PaymentTransactionRecord::text()` casts an int or a float to a string, which the exchange rate it shares that helper with needs; so a stored
     * `0` under this key reads as the address "0" rather than as no address. No writer of this application produces it. CONSTRUCTED, therefore, and
     * pinned rather than guessed — in both directions, because the direction is the whole question.
     *
     * "0" is an address this payment positively expects, so the first of the two facts a refusal needs is present. The second is not: "0" is not the
     * address this payment's account is named at, so it is not an address this payment can be confirmed at, and the platform address confirms it as
     * the address of last resort. Its own company address confirms it too, because a company's address keys on the ACCOUNT. Either way the payment is
     * not stranded, which is the claim.
     *
     * Before the liveness condition the public address REFUSED this payment, and the only thing then standing between it and being stranded was its
     * company address still resolving. Reviewer A of the stage-C round measured that this single assertion was the whole price of un-stranding six
     * ordinary administrative acts, and it was the right price to pay: this is the safer answer for a shape nothing writes.
     *
     * `text()` is still not changed, and that is still deliberate: changing it would change how the exchange rate is read on a financial record.
     */
    public function test_a_stored_number_reads_as_an_address_and_neither_address_strands_the_payment(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->replaceTheRecordedAddressOf($attempt, with: 0);
        $this->fakePaidFor($attempt);

        $this->assertSame('0', $this->theAddressExpectedBy($attempt), 'a number reads as an address, not as none');
        $this->assertNotSame(
            '0',
            app(GatewayAccountDirectory::class)->webhookUrlOf(account: $this->kuwait),
            'and it is not the address this payment\'s account is named at, so it is not an address it can be confirmed at',
        );

        $this->deliverToThePlatformAddress($attempt)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments(), 'the address of last resort confirms it');

        $this->deliverToTheCompanyAddress($this->kuwait, $attempt, self::KUWAIT_WEBHOOK_SECRET)
            ->assertOk()
            ->assertJsonPath('status', 'already_settled');
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
     * WHY A COMPANY'S OWN ADDRESS KEEPS KEYING ON THE ACCOUNT and not on the recorded address text. The asymmetry, measured instead of argued.
     *
     * An account's webhook token is editable in place. Retype it after a payment has started and the address the payment RECORDED names a token that
     * no longer resolves — while the account it was taken through is the same account. So at a company's address the durable question is the account,
     * and `PaymentAccountWebhookController` asks that one; at a public address the durable question is the address, because the alternative there is a
     * public address acting on a payment it was never given.
     *
     * Here is the case that decides it: the token is retyped, the delivery arrives at the NEW address, and it settles — with the rule in force and the
     * recorded address still naming the old token. Had the company address been held to the recorded text as well, this payment would have been
     * refused at the only address that now resolves, and refused at the platform address too. That is the shape of a stranded payment, and it is why
     * this stage changes one of the two addresses and not both.
     *
     * AND THE DELIVERY THE GATEWAY ACTUALLY MAKES, which is the half the first round of this stage left out. Stage B sends the RECORDED address to
     * the gateway, so a retyped token means the gateway goes on delivering to the OLD one. Measuring a manual replay at the new address said nothing
     * about that delivery: reviewer A of the stage-C round measured that it was refused at the old address AND at the platform address, which is a
     * stranded payment and is now the lever provider's `retyped` row. Both deliveries are measured here, and the claim the file makes about the
     * asymmetry is the one that is measured.
     *
     * The 401 at the old address is read directly as well as asserted. `PaymentAccountWebhookController` answers the same flat 401 for an unresolved
     * token and for a bad signature, so an assertion on the status alone passes whichever applies while its message names one; reviewer B found that.
     * `byWebhookToken()` answering null is the reason, measured.
     */
    public function test_a_company_address_still_confirms_its_payment_after_its_token_is_retyped(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $recorded = (string) $this->theAddressExpectedBy($attempt);
        $this->fakePaidFor($attempt);

        PaymentGatewayAccount::query()->whereKey($this->kuwait->id)->update(['webhook_path_token' => 'a-token-typed-later']);
        app(GatewayAccountRegistry::class)->flush();

        $this->assertTrue(app(ConfirmationAddresses::class)->isEnforced(), 'with the rule in force');
        $this->assertSame($recorded, $this->theAddressExpectedBy($attempt), 'the record still names the old token');
        $this->assertStringContainsString('kuwait-webhook-token', $recorded);
        $this->assertNull(
            app(GatewayAccountRegistry::class)->byWebhookToken(token: 'kuwait-webhook-token'),
            'the old token resolves no account, which is WHY the old address refuses',
        );
        $this->deliverToTheAddressTheGatewayWasGiven($recorded, $attempt)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized request');

        $this->deliverToTheCompanyAddress($this->kuwait->refresh(), $attempt, self::KUWAIT_WEBHOOK_SECRET)
            ->assertOk()
            ->assertJsonPath('status', 'settled');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments(), 'the account is the durable question at a company address');
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

    /**
     * THE RULE ON: a refusal costs ONE ATTEMPT LOOKUP and writes nothing — no event row, no column of the attempt, nothing.
     *
     * One attempt lookup, not one query: a refusal issues three, the addon settings, the attempt by its external identifier, and the payment row the
     * question reads to find out what address it expects. The assertion counts the lookup by reference, which is the read the question costs and the
     * one the rule being off saves. The name said "one read" and reviewer B of the stage-C round was right that a published test name is API.
     */
    public function test_with_the_rule_on_a_refusal_costs_one_attempt_lookup_and_writes_nothing(): void
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
     * THE QUESTION IS ASKED AFTER THE SIGNATURE, and that half of the ordering claim was held by nothing.
     *
     * The docblock on the route says the question is asked after the signature and before the processor. The "before the processor" half is held: take
     * the question away and this file goes red. The "after the signature" half was not — reviewer A of the stage-C round moved the question above the
     * signature block and every test of this branch stayed green.
     *
     * It matters because the question is two database reads. Asked before the signature, an unsigned delivery naming any reference at all would cost
     * an attempt lookup and a payment-row read before anything had authenticated it, so the ordering is the thing that keeps an unauthenticated
     * delivery from reading a row. Measured as the count the question is made of: a WRONGLY signed delivery naming a company-bound payment issues ZERO
     * attempt lookups, with the rule in force.
     */
    public function test_a_wrongly_signed_delivery_reads_no_attempt_at_all(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->fakePaidFor($attempt);
        $before = $this->theWorld($attempt);

        $this->assertTrue(app(ConfirmationAddresses::class)->isEnforced(), 'with the rule in force');
        $this->assertNotNull($this->theRecordOf($attempt)?->confirmationAddress, 'on a payment the question would refuse');

        $payload = $this->webhookPayload((string) $attempt->external_identifier);
        $queries = $this->queriesWhile(fn (): TestResponse => $this->postJson(
            '/api/myfatoorah/webhook',
            $payload,
            ['MyFatoorah-Signature' => $this->signatureFor($payload, 'a-secret-that-is-not-ours')],
        )->assertStatus(401));

        $this->assertSame(0, $this->attemptLookupsIn($queries), 'nothing of ours is read before the delivery has authenticated');
        $this->assertEquals($before, $this->theWorld($attempt));
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
     *
     * TWO ASSERTIONS ARE ON THE POLICY METHOD and not only on the route, because the liveness condition makes the route's own answer less sensitive
     * to the comparison. Measured: dropping the address comparison from `expectsAnAddressOtherThan()` leaves every route-level test of this branch
     * green, because a recorded value that is not a live account's address is handed back to this address anyway. The comparison still has to be
     * right, and these two assertions are what holds it: of THIS address the method answers no, and of another address yes.
     *
     * THE BATTERY BEHIND THIS FILE is 33 mutations, one line at a time, of which 31 are red and 2 are declared below. (Commit b041f061's own
     * message says thirty-four and thirty-two; it counted the one mutation that was run twice, before and after its test was fixed, as two. The
     * numbers here are the ones a `grep` of the driver gives, and the message is left as it was pushed rather than rewritten.)
     *
     * TWO MUTANTS ARE DECLARED RATHER THAN KILLED, with their reasons measured. Pointing the route's question at a per-account address for a token
     * that resolves nothing leaves this file green, and is equivalent within everything reachable: a recorded value is either absent, a live
     * account's address, or neither, and in all three the outcome is the same whichever of those two addresses is compared. Pointing it at the
     * account's REAL token is the discriminating form of the same mutation and is red at four failures. The other is the early return in
     * `theAddressItExpectsIsLive()` rewritten as a `&&` chain, which is the same expression written twice.
     */
    public function test_a_payment_that_recorded_this_very_address_is_confirmed_here(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->replaceTheRecordedAddressOf($attempt, with: route('myfatoorah.webhook'));
        $this->fakePaidFor($attempt);

        $this->assertSame(route('myfatoorah.webhook'), $this->theAddressExpectedBy($attempt), 'the payment expects this address');
        $this->assertTrue(app(ConfirmationAddresses::class)->isEnforced(), 'with the rule in force');
        $this->assertFalse(
            app(PaymentAccountBinding::class)->expectsAnAddressOtherThan(payment: (array) $this->paymentRow($attempt), address: route('myfatoorah.webhook')),
            'and the policy says so of THIS address, by comparing the record against it',
        );
        $this->assertTrue(
            app(PaymentAccountBinding::class)->expectsAnAddressOtherThan(
                payment: (array) $this->paymentRow($attempt),
                address: route('payments.account.webhook', ['account' => (string) $this->kuwait->webhook_path_token]),
            ),
            'and of any other address, no',
        );

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

    /**
     * OBLIGATION 3, THE HALF THE FIRST ROUND OF THIS STAGE DID NOT HOLD: a recorded address that is no longer live does not strand its payment.
     *
     * The first round keyed the refusal on the recorded address text alone, and leant for safety on one fact — that turning per-company accounts off
     * turns every company address off with them, so enforcement requires the registry to be enabled. True, and not enough. Six further things make a
     * recorded address stop reading deliveries, every one of them an ordinary administrative act on a live account, and for each of them the base
     * branch confirms and PAYS the payment while the first round of this stage confirmed it nowhere at all.
     *
     * Each lever is applied AFTER the payment started, through the real model or the real configuration, and then measured on both routes: the
     * address the gateway was actually given (the recorded one), and the platform address. The assertion is the one the owner's decision asks for —
     * the payment ends up PAID — and the control is the one lever-free case, which must still be refused at the platform address or this provider
     * would prove nothing.
     *
     * The base branch's answer for each was measured in a second worktree at 4504a41a before this was written: six disturbances, platform 200,
     * settled, paid, in every case. Found by reviewer A and reviewer B of the stage-C round; two of the six (no webhook secret, and the account-id
     * dropped from the stamp) were found measuring their findings out.
     *
     * @return array<string, array{string, bool}>
     */
    public static function levers(): array
    {
        return [
            'the account is deactivated' => ['deactivated', true],
            'the account row is deleted' => ['deleted', true],
            'the owner takes its gateway off the covered list' => ['unlisted', true],
            'its webhook token is retyped' => ['retyped', true],
            'it holds no webhook secret' => ['no-secret', true],
            'the stamp names no account, address kept' => ['no-account-id', true],
            'CONTROL: nothing is disturbed' => ['none', false],
        ];
    }

    #[DataProvider('levers')]
    public function test_a_recorded_address_that_is_no_longer_live_does_not_strand_its_payment(string $lever, bool $expectPaidAtThePlatformAddress): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $recorded = (string) $this->theAddressExpectedBy($attempt);
        $this->assertStringContainsString('kuwait-webhook-token', $recorded, 'the payment recorded its own company address');
        $this->fakePaidFor($attempt);

        $this->applyTheLever($lever, $attempt);

        $this->assertTrue(app(ConfirmationAddresses::class)->isEnforced(), 'with the rule in force');
        $this->deliverToTheAddressTheGatewayWasGiven($recorded, $attempt)
            ->assertStatus($lever === 'none' ? 200 : 401, 'the address the gateway was given either still reads deliveries or does not');

        $atThePlatformAddress = $this->deliverToThePlatformAddress($attempt);

        if ($expectPaidAtThePlatformAddress) {
            $atThePlatformAddress->assertOk();
            $this->assertSame([$attempt->payment_request_id], $this->paidPayments(), 'no money is left unconfirmed by this change');
            $this->assertSame(1, $this->successHookCalls());

            return;
        }

        $atThePlatformAddress->assertStatus(401);
        $this->assertSame([$attempt->payment_request_id], $this->paidPayments(), 'the control was paid at its own live address and nowhere else');
    }

    /**
     * THE CONTROL OF THE CONTROL: with NO lever applied and the account in good order, the platform address still refuses a payment whose address IS live.
     *
     * Without this, the provider above could be passed by a method that simply never refuses anything. The one lever-free row of the provider says
     * the same thing, and this says it about the account's own state rather than about the absence of a disturbance: the account is active, listed,
     * holds its secret and keeps its token, so the address the payment recorded reads deliveries, and this address is not it.
     */
    public function test_the_platform_address_still_refuses_a_payment_whose_recorded_address_is_live(): void
    {
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $recorded = (string) $this->theAddressExpectedBy($attempt);
        $this->fakePaidFor($attempt);
        $before = $this->theWorld($attempt);

        $this->assertSame(
            $recorded,
            app(GatewayAccountDirectory::class)->webhookUrlOf(account: $this->kuwait),
            'the recorded address is the address this account is named at now',
        );
        $this->assertNotNull(
            app(GatewayAccountSecrets::class)->webhookSecret(account: $this->kuwait),
            'and the account holds the secret that address authenticates with',
        );

        $this->deliverToThePlatformAddress($attempt)->assertStatus(401);

        $this->assertEquals($before, $this->theWorld($attempt), 'and nothing moved');
        $this->deliverToTheAddressTheGatewayWasGiven($recorded, $attempt)->assertOk()->assertJsonPath('status', 'settled');
        $this->assertSame([$attempt->payment_request_id], $this->paidPayments());
    }

    /**
     * An ordinary administrative act on an account, applied after a payment of it has started.
     *
     * Each one goes through the real model or the real configuration key an operator reaches, never through a shape invented here, and each is a
     * supported act: `PaymentGatewayAccountUpsertCommand` offers --activate/--deactivate and --new-webhook-token, creates an account before any
     * secret is given to it, and `config/gcc.php`'s own note on the covered list names removing this gateway from it as the rollback.
     */
    private function applyTheLever(string $lever, MyFatoorahPaymentAttempt $attempt): void
    {
        match ($lever) {
            'deactivated' => PaymentGatewayAccount::query()->whereKey($this->kuwait->id)->update(['status' => false]),
            'deleted' => PaymentGatewayAccount::query()->whereKey($this->kuwait->id)->delete(),
            'unlisted' => config(['gcc.storefronts.payment_accounts.gateways' => $this->shippedGatewayList]),
            'retyped' => PaymentGatewayAccount::query()->whereKey($this->kuwait->id)->update(['webhook_path_token' => 'a-token-typed-later']),
            'no-secret' => app(GatewayAccountSecrets::class)->put(
                account: $this->kuwait,
                secrets: [],
                clear: [GatewayAccountSecrets::WEBHOOK_SECRET],
            ),
            'no-account-id' => $this->dropTheAccountIdFromTheStampOf($attempt),
            'none' => null,
        };

        app(GatewayAccountRegistry::class)->flush();
    }

    /**
     * Removes the account id from a stamp the production writer produced, leaving its recorded address alone.
     *
     * CONSTRUCTED, and the one lever that is: `additional_data` is a JSON column this application is not the only writer of, and the shape matters
     * because a recorded address with no account behind it is the shape with no account to hand the payment back to. It must read as an old payment's
     * address rather than as a live company one.
     */
    private function dropTheAccountIdFromTheStampOf(MyFatoorahPaymentAttempt $attempt): void
    {
        $stored = (array) json_decode((string) $this->paymentRow($attempt)->additional_data, true);
        unset($stored[PaymentAccountBinding::KEY]['account_id']);

        DB::table('payment_requests')->where('id', $attempt->payment_request_id)->update(['additional_data' => json_encode($stored)]);
    }

    /** A delivery to the address initiation actually gave the gateway for this payment, whatever that address resolves to now. */
    private function deliverToTheAddressTheGatewayWasGiven(string $recorded, MyFatoorahPaymentAttempt $attempt): TestResponse
    {
        $payload = $this->webhookPayload((string) $attempt->external_identifier);

        return $this->postJson(
            (string) parse_url($recorded, PHP_URL_PATH),
            $payload,
            ['MyFatoorah-Signature' => $this->signatureFor($payload, self::KUWAIT_WEBHOOK_SECRET)],
        );
    }

    /**
     * THE LIMIT OF OBLIGATION 2 ON THIS GATEWAY, pinned so that it is visible and so that changing it is deliberate.
     *
     * This gateway has TWO platform-wide confirmation paths, not one: the server-to-server notification this stage holds to the one-address rule, and
     * the customer's own return — `GET /payment/myfatoorah/callback`, and `/payment/fatoorah/callback` beside it, both the same method. The return's
     * authority is a server-to-server verification and then settlement, and it does not ask which address the payment expects. So a company-bound
     * payment IS still confirmed by its customer's return, with the rule on and with it off, identically. Measured here rather than described.
     *
     * NOT CLOSED HERE, and the reason is the half of the owner's decision about stranding. There is no per-company RETURN address at all:
     * `GatewayAccountDirectory::webhookUrlOf()` produces notification addresses and nothing else, so the return's own URL is a value no payment ever
     * records. Holding the return to the same equality would therefore refuse EVERY company-bound return, and a payment whose notification never
     * arrives — the portal's address not configured, the notification lost, a gateway that notifies at no address — would have nothing left to
     * confirm it. That is a bigger stranding than the isolation it would buy, and it is the owner's call, not this stage's.
     *
     * Reviewer A of the stage-C round found this and measured it, and judged that bolting the question on as it stands would make the stranding worse.
     * The branch agrees, pins the behaviour, and carries it as a named obligation-2 limitation rather than as a silence.
     *
     * @return array<string, array{bool}>
     */
    #[DataProvider('theSwitch')]
    public function test_a_company_payment_is_still_confirmed_by_its_customers_own_return(bool $switch): void
    {
        config(['gcc.storefronts.payment_accounts.one_confirmation_address' => $switch]);
        $attempt = $this->anAttemptStampedWith($this->kuwait);
        $this->fakePaidFor($attempt);

        $this->assertNotNull($this->theRecordOf($attempt)?->confirmationAddress, 'the payment expects a company notification address');
        $this->assertNotSame(
            route('myfatoorah.callback'),
            $this->theAddressExpectedBy($attempt),
            'and the return\'s own URL is not a value any payment records, which is why the same equality cannot be applied to it',
        );

        $landedOn = $this->get(route('myfatoorah.callback') . '?reference=' . $attempt->external_identifier . '&paymentId=PAY-1')
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertSame(route('payment-success'), strtok((string) $landedOn, '?'), 'the customer is sent to the success page');

        $this->assertSame([$attempt->payment_request_id], $this->paidPayments(), 'the customer\'s return confirms it, with the switch either way');
        $this->assertSame(1, $this->successHookCalls());
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
