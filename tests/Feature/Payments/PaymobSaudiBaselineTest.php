<?php

namespace Tests\Feature\Payments;

use App\Http\Controllers\Payment_Methods\PaymobController;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsMyFatoorahSchema;
use Tests\TestCase;

/**
 * Paymob, the Saudi baseline, pinned (D2-01).
 *
 * This is the gateway the live Saudi store takes card payments through, and it had no test of any
 * kind. The three things recorded about it -- that the outgoing payment takes its amount and currency
 * from the STORED payment row rather than from the request, that an unauthentic return settles nothing
 * and runs the failure hook, and that a valid return settles -- were all read off the source.
 *
 * A characterisation suite, so the expansion cannot move Saudi behaviour without the move being
 * visible. Where today's behaviour looks wrong it is pinned under a name saying so (`..._currently_...`)
 * rather than quietly corrected: this suite's job is to say what the platform does, and changing any of
 * it is a separate decision with its own evidence.
 */
class PaymobSaudiBaselineTest extends TestCase
{
    use BuildsMyFatoorahSchema {
        makePaymentRequest as makeSharedPaymentRequest;
    }

    /** The key this shop stores Paymob's settings and its payments under. */
    private const GATEWAY = 'paymob_accept';

    /**
     * A payment OF THIS GATEWAY. The shared fixture makes a `fatoorah` payment, which was good enough while the callback
     * settled whatever the session named; a Paymob confirmation now settles only a Paymob payment
     * (GatewayConfirmations::thePaymentItSettles), which is the point of the fix, so these tests make their own
     * gateway's payments. The shared fixture's default is untouched: four MyFatoorah suites rely on it.
     */
    protected function makePaymentRequest(array $overrides = []): string
    {
        return $this->makeSharedPaymentRequest(array_merge(['payment_method' => self::GATEWAY], $overrides));
    }

    private const HMAC_SECRET = 'paymob-test-hmac-secret';

    /** The fields Paymob signs, in the order the controller concatenates them (it ksorts the payload). */
    private const SIGNED_FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id',
        'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
        'is_standalone_payment', 'is_voided', 'order', 'owner', 'pending', 'source_data_pan',
        'source_data_sub_type', 'source_data_type', 'success',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->buildMyFatoorahSchema();
        require_once __DIR__ . '/../../Support/myfatoorah_test_hooks.php';
        $GLOBALS['myfatoorah_effects'] = [];
        $GLOBALS['myfatoorah_failure_calls'] = [];
        $GLOBALS['myfatoorah_success_throw_after'] = null;

        $this->paymobIsConfigured();
    }

    private function paymobIsConfigured(string $country = 'KSA'): void
    {
        $values = json_encode([
            'status' => 1,
            'mode' => 'live',
            'secret_key' => 'paymob-secret-key',
            'public_key' => 'paymob-public-key',
            'integration_id' => '4242',
            'hmac' => self::HMAC_SECRET,
            'supported_country' => $country,
        ]);

        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => 'paymob_accept', 'settings_type' => 'payment_config'],
            ['id' => (string) Str::uuid(), 'mode' => 'live', 'is_active' => 1, 'live_values' => $values, 'test_values' => $values, 'additional_data' => json_encode(['gateway_title' => 'Paymob']), 'updated_at' => now()],
        );
        Cache::flush();
    }

    /**
     * The real controller with its one outbound call captured.
     *
     * The outbound call is raw curl, so Http::fake() cannot see it. Overriding the one protected
     * method it goes through is what lets the REAL credit() body run -- the amount, the currency, the
     * items, the billing block -- and still have the payload read back rather than sent to Paymob.
     */
    private function controllerCapturing(): PaymobController
    {
        return new class(app(PaymentRequest::class), app(User::class)) extends PaymobController {
            /** @var list<array{url: string, json: array}> */
            public array $captured = [];

            protected function postIntention(string $url, array $data, string $token): ?array
            {
                $this->captured[] = ['url' => $url, 'json' => $data, 'token' => $token];

                return ['client_secret' => 'a-client-secret'];
            }
        };
    }

    /** A signed return, as Paymob sends it. */
    private function aSignedReturn(array $overrides = []): array
    {
        $payload = array_merge([
            'amount_cents' => '15000',
            'created_at' => '2026-11-22T10:00:00',
            'currency' => 'SAR',
            'error_occured' => 'false',
            'has_parent_transaction' => 'false',
            'id' => '987654',
            'integration_id' => '4242',
            'is_3d_secure' => 'true',
            'is_auth' => 'false',
            'is_capture' => 'false',
            'is_refunded' => 'false',
            'is_standalone_payment' => 'true',
            'is_voided' => 'false',
            'order' => '123456',
            'owner' => '55',
            'pending' => 'false',
            'source_data_pan' => '2346',
            'source_data_sub_type' => 'MasterCard',
            'source_data_type' => 'card',
            'success' => 'true',
        ], $overrides);

        ksort($payload);
        $connected = '';
        foreach ($payload as $key => $value) {
            if (in_array($key, self::SIGNED_FIELDS, true)) {
                $connected .= $value;
            }
        }
        $payload['hmac'] = hash_hmac('sha512', $connected, self::HMAC_SECRET);

        return $payload;
    }

    /**
     * What the decision is FOR: the hook is a side effect on a customer's order, and this address is public.
     *
     * A stranger who guesses a payment id and posts an unsigned return must not be able to fire that payment's failure hook. The
     * same payment's own, correctly signed return still runs its success hook, so the decision costs the genuine path nothing --
     * which is the pair of facts worth holding together in one case.
     */
    public function test_a_stranger_cannot_fire_the_failure_hook_of_a_payment_but_its_real_return_still_settles_it(): void
    {
        $id = $this->makePaymentRequest();

        $this->theReturnArrivesFor($id, ['id' => '987654', 'success' => 'true'])->assertRedirectContains('payment-fail');

        $this->assertNotContains($id, $GLOBALS['myfatoorah_failure_calls'], 'an unsigned return fired the failure hook of a payment it cannot prove it owns');
        $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $id)->value('is_paid'), 'and it must not have settled it either');
        $this->assertSame(0, myfatoorah_test_effect_count('order'));

        $this->theReturnArrivesFor($id, $this->aSignedReturn())->assertRedirectContains('payment-success');

        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $id)->value('is_paid'), 'the payment\'s own signed return still settles it');
    }

    private function theReturnArrivesFor(string $paymentId, array $payload)
    {
        return $this->withSession(['payment_id' => $paymentId])->get('/payment/paymob/callback?' . http_build_query($payload));
    }

    // ---------------------------------------------------------------- the outgoing payment

    public function test_the_amount_and_currency_sent_to_paymob_come_from_the_stored_row_and_not_the_request(): void
    {
        $id = $this->makePaymentRequest(['payment_amount' => 150.00, 'currency_code' => 'SAR']);

        $controller = $this->controllerCapturing();

        // The request carries a different amount and a different currency on purpose. Neither may be
        // read: the amount a customer is charged is decided on the server from the cart, and a request
        // that could move it would be a way to pay less than the order is worth.
        $controller->credit(Request::create('/payment/paymob/pay', 'GET', [
            'payment_id' => $id,
            'amount' => '1.00',
            'payment_amount' => '1.00',
            'currency' => 'USD',
            'currency_code' => 'USD',
        ]));

        $sent = $controller->captured;
        $this->assertCount(1, $sent, 'the intention was not created');

        // round() answers a float, so the amount is 15000.0 in memory -- but json_encode writes it as
        // 15000, and the JSON is what Paymob reads. Both are asserted so neither can drift unseen.
        $this->assertEqualsWithDelta(15000, $sent[0]['json']['amount'], 0.0, 'the amount did not come from the stored row');
        $this->assertStringContainsString('"amount":15000,', json_encode($sent[0]['json']), 'the amount on the wire is not an integer number of minor units');

        $this->assertSame('SAR', $sent[0]['json']['currency'], 'the currency did not come from the stored row');
        $this->assertEqualsWithDelta(15000, $sent[0]['json']['items'][0]['amount'], 0.0, 'the item line and the order total disagree');
        $this->assertSame([4242], $sent[0]['json']['payment_methods'], 'the integration id is not the configured one, as an integer');
        $this->assertSame('paymob-secret-key', $sent[0]['token'], 'the call was not authorised with the configured secret key');
    }

    public function test_the_amount_is_sent_in_two_decimal_minor_units(): void
    {
        // Pinned rather than corrected. Paymob declares only SAR and AED, and both take two decimals,
        // so multiplying by 100 is right for every currency this gateway says it can accept. It is NOT
        // right for KWD, BHD or OMR, which take three -- so if Paymob's declared list ever grows to
        // include one of them, this test is the one that must fail first.
        foreach ([['150.00', 15000], ['0.05', 5], ['99.99', 9999], ['1234.50', 123450]] as [$stored, $minorUnits]) {
            $id = $this->makePaymentRequest(['payment_amount' => $stored, 'currency_code' => 'SAR']);
            $controller = $this->controllerCapturing();
            $controller->credit(Request::create('/payment/paymob/pay', 'GET', ['payment_id' => $id]));

            $this->assertEqualsWithDelta($minorUnits, $controller->captured[0]['json']['amount'], 0.0, "{$stored} was not sent as {$minorUnits}");
            $this->assertStringContainsString('"amount":' . $minorUnits . ',', json_encode($controller->captured[0]['json']), "{$stored} is not an integer number of minor units on the wire");
        }
    }

    public function test_a_payment_that_is_already_paid_starts_nothing(): void
    {
        $id = $this->makePaymentRequest(['is_paid' => true]);

        $controller = $this->controllerCapturing();
        $controller->credit(Request::create('/payment/paymob/pay', 'GET', ['payment_id' => $id]));

        $this->assertSame([], $controller->captured, 'a paid payment was sent to the gateway a second time');
    }

    public function test_the_saudi_store_talks_to_the_saudi_host(): void
    {
        $controller = $this->controllerCapturing();
        $controller->credit(Request::create('/payment/paymob/pay', 'GET', ['payment_id' => $this->makePaymentRequest()]));
        $this->assertStringStartsWith('https://ksa.paymob.com/', $controller->captured[0]['url'], 'a Saudi payment did not go to the Saudi host');

        // A configuration naming a country this integration does not serve falls back to the default
        // host rather than throwing in the constructor, which Laravel runs whenever it gathers this
        // route's middleware.
        $this->paymobIsConfigured(country: 'Narnia');
        $fallback = $this->controllerCapturing();
        $fallback->credit(Request::create('/payment/paymob/pay', 'GET', ['payment_id' => $this->makePaymentRequest()]));
        $this->assertStringStartsWith('https://accept.paymob.com/', $fallback->captured[0]['url']);
    }

    // ---------------------------------------------------------------- the return

    public function test_a_valid_return_marks_the_payment_paid_and_runs_the_success_hook_once(): void
    {
        $id = $this->makePaymentRequest();

        $this->theReturnArrivesFor($id, $this->aSignedReturn())->assertRedirectContains('payment-success');

        $row = DB::table('payment_requests')->where('id', $id)->first();
        $this->assertSame(1, (int) $row->is_paid);
        $this->assertSame('paymob_accept', $row->payment_method);
        $this->assertSame(1, myfatoorah_test_effect_count('order'), 'the success hook did not create exactly one order');
        $this->assertSame([], $GLOBALS['myfatoorah_failure_calls'], 'the failure hook ran on a valid return');
    }

    /**
     * Owner's decision: a return the shop cannot authenticate settles nothing AND runs no hook.
     *
     * The platform used to run the failure hook here, and this case was written for that. It is a change to Saudi behaviour, taken
     * deliberately: this address is public, a payment id is guessable, and the hook is a side effect on somebody else's order. A
     * request that cannot prove it is Paymob's decides NOTHING about a payment -- it does not settle it and it does not fail it
     * either. The customer still gets the failure page, so nothing they see has changed.
     *
     * What this costs is that an order's hold is not released by an unauthentic return. It is released by the authentic one, by the
     * server-to-server confirmation where that is on, and by the recovery that settles payments the return never reached.
     */
    public function test_a_return_whose_signature_is_wrong_settles_nothing_and_runs_no_hook(): void
    {
        foreach ([
            'a signature from another secret' => ['hmac' => hash_hmac('sha512', 'whatever', 'the-wrong-secret')],
            'a signature of the right shape but one character changed' => null,
            'an empty signature' => ['hmac' => ''],
            'no signature at all' => 'drop',
        ] as $case => $change) {
            $id = $this->makePaymentRequest();
            $payload = $this->aSignedReturn();

            if ($change === null) {
                $payload['hmac'] = substr($payload['hmac'], 0, -1) . (str_ends_with($payload['hmac'], 'a') ? 'b' : 'a');
            } elseif ($change === 'drop') {
                unset($payload['hmac']);
            } else {
                $payload = array_merge($payload, $change);
            }

            $response = $this->theReturnArrivesFor($id, $payload);

            $response->assertRedirectContains('payment-fail');
            $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $id)->value('is_paid'), "{$case} marked the payment paid");
            $this->assertNotContains($id, $GLOBALS['myfatoorah_failure_calls'], "{$case} ran the failure hook on a return the shop cannot authenticate");
        }

        $this->assertSame(0, myfatoorah_test_effect_count('order'), 'an unauthentic return created an order');
    }

    public function test_a_return_that_does_not_say_success_settles_nothing_and_runs_the_failure_hook(): void
    {
        foreach (['false', 'TRUE', '1', '', 'yes'] as $success) {
            $id = $this->makePaymentRequest();

            // Signed correctly for this very payload, so the ONLY objection left is the success flag.
            $this->theReturnArrivesFor($id, $this->aSignedReturn(['success' => $success]))
                ->assertRedirectContains('payment-fail');

            $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $id)->value('is_paid'), "success={$success} marked the payment paid");
            $this->assertContains($id, $GLOBALS['myfatoorah_failure_calls'], "success={$success} did not run the failure hook");
        }

        $this->assertSame(0, myfatoorah_test_effect_count('order'));
    }

    public function test_a_return_with_no_success_field_at_all_is_refused_rather_than_raising_an_error(): void
    {
        $id = $this->makePaymentRequest();
        $payload = $this->aSignedReturn();
        unset($payload['success']);

        $this->theReturnArrivesFor($id, $payload)->assertRedirectContains('payment-fail');

        $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $id)->value('is_paid'));
    }

    public function test_a_valid_return_opened_twice_settles_once_and_runs_the_success_hook_once(): void
    {
        /*
         * REWRITTEN DELIBERATELY, which is what the characterisation above it asked for. The replay guard has landed:
         * GatewayConfirmations registers the gateway's own transaction reference under a unique key, inside the same
         * database transaction as the settlement, so the second arrival of one confirmation settles nothing and runs no
         * success hook. The first still settles and still answers payment-success; the second answers a failure, because
         * it decided nothing. (Cherry-picked from the hotfix line: d7b340ad.)
         */
        $id = $this->makePaymentRequest();
        $payload = $this->aSignedReturn();

        $this->theReturnArrivesFor($id, $payload)->assertRedirectContains('payment-success');
        $this->theReturnArrivesFor($id, $payload)->assertRedirectContains('payment-fail');

        $this->assertSame(1, myfatoorah_test_effect_count('order'), 'one confirmation of one transaction settles one payment, once');
        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $id)->value('is_paid'));
    }

    public function test_the_transaction_id_recorded_is_paymobs_own_reference(): void
    {
        /*
         * THE GAP THIS PINNED IS CLOSED, so the pin now records the answer rather than the gap. The callback used to
         * write `transaction_id` = the payment's own id, which linked a settled payment to nothing on the provider's
         * side and left a reconciliation (D2-16) no reference to work from. It now stores what Paymob calls its own
         * transaction -- `id` in the return, 987654 here. (Cherry-picked from the hotfix line: d7b340ad.)
         */
        $id = $this->makePaymentRequest();

        $this->theReturnArrivesFor($id, $this->aSignedReturn(['id' => '987654']));

        $this->assertSame('987654', DB::table('payment_requests')->where('id', $id)->value('transaction_id'));
        $this->assertNotSame($id, DB::table('payment_requests')->where('id', $id)->value('transaction_id'));
    }
}
