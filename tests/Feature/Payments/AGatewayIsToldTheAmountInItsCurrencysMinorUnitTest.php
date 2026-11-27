<?php

namespace Tests\Feature\Payments;

use App\Http\Controllers\Payment_Methods\PaymobController;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Payments\CurrencyMinorUnit;
use App\Services\Payments\GatewayConfirmations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMyFatoorahSchema;
use Tests\TestCase;

/**
 * D2-V1: the amount a gateway is told, and the confirmation that amount is checked against, counted in the smallest coin the order's
 * currency actually has.
 *
 * A gateway is never handed an amount in riyals or dinars. It is handed an integer number of the currency's smallest coin, and the SENDER
 * has to know how many of those make one unit. Every outbound amount on this platform multiplied by 100, which is the riyal's answer, the
 * dirham's, the Qatari riyal's and the dollar's. Three of the six markets price in thousandths -- 1 KWD is 1,000 fils, 1 BHD 1,000 fils,
 * 1 OMR 1,000 baisa, which is ISO 4217 and what `gcc.storefronts.currency.decimals` says -- so an order of 10.234 dinars went out as
 * 1,023 fils: the customer is charged 1.023 dinars for a basket of 10.234 and the shop collects a TENTH of what it sold.
 *
 * `PaymobSaudiBaselineTest::test_the_amount_is_sent_in_two_decimal_minor_units` pinned that rather than correcting it, and said in as many
 * words what would have to happen first: "It is NOT right for KWD, BHD or OMR, which take three". That test is untouched and still green,
 * because the riyal has two places and is multiplied by 100 under every value of the switch below.
 *
 * **THE CHARGE AND ITS CHECK MOVE IN ONE STEP, AND THAT IS THE POINT OF THE SHAPE HERE.** {@see GatewayConfirmations} asks whether the
 * amount a gateway says it took is the payment's, and it asked by multiplying the stored amount by 100 as well. Correcting only the
 * outgoing half would charge 10,234 fils and compare it with 1,023, so no three-decimal payment could ever settle: the gateway holding the
 * customer's money with the order never placed, which is a worse failure than the undercharge it set out to fix. One authority
 * ({@see CurrencyMinorUnit}), one switch, both readers.
 *
 * **The first test is the dormancy and it is the one that matters most.** `gcc.payments.amount_in_the_currencys_minor_unit` ships off, and
 * with it off a Kuwaiti payment goes out at 100 to the unit exactly as it does today -- the same figure, the same PHP type, the same keys
 * in the same payload, and the same answer from the confirmation. The type is asserted as well as the value: `round()` answers a float and
 * that float is what has always been handed to `json_encode`, so a silent change to `int` would be a change in the shape of what a gateway
 * is sent even where the wire bytes agree.
 *
 * A second gate stands in front of all of this in production either way: `GatewayCurrencyPolicy` lets a three-decimal currency reach only a
 * gateway named in `gcc.storefronts.currency.verified_three_decimal_gateways`, which is empty on every head. These tests drive the gateway
 * controller directly, which is how the arithmetic can be measured at all while that list is empty.
 */
class AGatewayIsToldTheAmountInItsCurrencysMinorUnitTest extends TestCase
{
    use BuildsMyFatoorahSchema {
        makePaymentRequest as makeSharedPaymentRequest;
    }

    private const GATEWAY = 'paymob_accept';

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
        (require database_path('migrations/2026_11_23_100001_create_gateway_confirmations_table.php'))->up();
    }

    /* ---------------------------------------------------------------- dormancy */

    /**
     * Off, which is how it ships: a dinar payment is multiplied by 100, as it is today, down to the type of the figure.
     *
     * 10.230 KWD goes out as 1,023 fils -- 1.023 dinars charged for a basket of 10.23 -- and that is asserted here as the behaviour
     * which does NOT move until somebody turns the switch on.
     */
    public function test_with_the_switch_off_a_three_decimal_payment_is_sent_in_hundredths_exactly_as_today(): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => false]);

        $sent = $this->intentionFor(amount: '10.230', currency: 'KWD');

        $this->assertEqualsWithDelta(1023.0, $sent['amount'], 0.0, 'the dormant path did not send round(10.23 * 100)');
        $this->assertIsFloat($sent['amount'], 'the figure handed to json_encode is no longer the float round() answers');
        $this->assertEqualsWithDelta(1023.0, $sent['items'][0]['amount'], 0.0, 'the item line and the order total disagree');
        $this->assertSame('KWD', $sent['currency'], 'the currency did not come from the stored row');
    }

    /** Off: the payload a gateway receives has the same keys it has always had. A key that appeared is a different answer from one that did not. */
    public function test_with_the_switch_off_the_payload_a_gateway_receives_has_exactly_the_keys_it_always_had(): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => false]);

        $keys = array_keys($this->intentionFor(amount: '10.230', currency: 'KWD'));
        sort($keys);

        $this->assertSame(
            ['amount', 'billing_data', 'currency', 'customer', 'extras', 'items', 'payment_methods', 'redirection_url', 'special_reference'],
            $keys,
            'the intention payload gained or lost a key',
        );
    }

    /** Off: every currency is a hundred to the unit and two places, including the three that have three. */
    #[DataProvider('everyCurrencyOfTheProgramme')]
    public function test_with_the_switch_off_every_currency_is_a_hundred_to_the_unit(string $currency): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => false]);

        $this->assertSame(100, CurrencyMinorUnit::inOneUnitOf($currency), "{$currency} is no longer read as a hundred to the unit");
        $this->assertSame(2, CurrencyMinorUnit::places($currency), "{$currency} is no longer read at two places");
    }

    /** Off: the confirmation compares in hundredths, which is the comparison that shipped. */
    public function test_with_the_switch_off_a_confirmation_of_a_dinar_payment_is_compared_in_hundredths(): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => false]);

        $payment = $this->makePaymentRequest(['payment_amount' => '10.230', 'currency_code' => 'KWD']);

        $this->assertNotNull($this->settles(payment: $payment, minorUnits: '1023', currency: 'KWD'), 'the hundredths comparison stopped settling');
        $this->assertNull($this->settles(payment: $payment, minorUnits: '10230', currency: 'KWD'), 'the dormant path accepted a thousandths confirmation');
    }

    /* ------------------------------------------------------------------- on it */

    /** On: a currency with two places is untouched, which is Saudi Arabia, the Emirates and Qatar. */
    #[DataProvider('twoPlaceCurrencies')]
    public function test_a_two_place_currency_is_sent_in_hundredths_whatever_the_switch_says(string $currency): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => true]);

        $sent = $this->intentionFor(amount: '150.00', currency: $currency);

        $this->assertSame(15000, $sent['amount'], "{$currency} moved away from hundredths");
        $this->assertSame(100, CurrencyMinorUnit::inOneUnitOf($currency), "{$currency} is not a hundred to the unit");
    }

    /** On: the three currencies that price in thousandths are charged in thousandths. */
    #[DataProvider('threeDecimalAmounts')]
    public function test_a_three_decimal_currency_is_sent_in_thousandths(string $currency, string $amount, int $minorUnits): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => true]);

        $sent = $this->intentionFor(amount: $amount, currency: $currency);

        $this->assertSame($minorUnits, $sent['amount'], "{$amount} {$currency} was not sent as {$minorUnits}");
        $this->assertSame($minorUnits, $sent['items'][0]['amount'], 'the item line and the order total disagree');
        $this->assertStringContainsString(
            '"amount":' . $minorUnits . ',',
            json_encode($sent),
            'the amount on the wire is not an integer number of minor units',
        );
    }

    /** On: what was charged is what the confirmation has to state, and the figure the old arithmetic would have produced settles nothing. */
    public function test_a_confirmation_settles_only_the_amount_the_charge_actually_asked_for(): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => true]);

        $payment = $this->makePaymentRequest(['payment_amount' => '10.230', 'currency_code' => 'KWD']);
        $charged = $this->intentionFor(amount: '10.230', currency: 'KWD')['amount'];

        $this->assertSame(10230, $charged, 'the charge is not in thousandths, so this case is not measuring the round trip');
        $this->assertNotNull($this->settles(payment: $payment, minorUnits: (string) $charged, currency: 'KWD'), 'the amount that was charged does not settle its own payment');
        $this->assertNull($this->settles(payment: $payment, minorUnits: '1023', currency: 'KWD'), 'the hundredths figure still settles a thousandths payment');
        $this->assertNull($this->settles(payment: $payment, minorUnits: '10231', currency: 'KWD'), 'one fils more settles the payment');
    }

    /**
     * On: the charge is the STORED amount in the currency's own smallest coin, whatever the money column can hold -- which is where the
     * rest of this criterion still is.
     *
     * `payment_requests.payment_amount` is DECIMAL(24,2) on the schema the installation backup ships, and
     * `2026_05_07_000001_update_all_decimal_columns_to_40_20` widens it. On the narrow one a payment of 10.234 dinars is 10.23 before any
     * gateway is told anything: four fils are lost in the INSERT, and no arithmetic downstream can put them back. Read the stored row and
     * assert against that, so this measures the unit the charge is counted in and not the scale of a column -- and so the day the column is
     * widened this test keeps saying the same true thing.
     */
    public function test_the_charge_is_the_stored_amount_in_its_currencys_minor_unit_whatever_the_column_can_hold(): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => true]);

        $id = $this->makePaymentRequest(['payment_amount' => '10.234', 'currency_code' => 'KWD']);
        $stored = (string) DB::table('payment_requests')->where('id', $id)->value('payment_amount');

        $this->assertSame(
            CurrencyMinorUnit::of(amount: $stored, currencyCode: 'KWD'),
            $this->intentionFor(amount: '10.234', currency: 'KWD')['amount'],
            'the charge is not the stored amount counted in the currency it is stored in',
        );
    }

    /** On: a payment whose currency nobody recorded is two places. Guessing a scale for money with no currency is how a riyal is charged as a dinar. */
    public function test_a_payment_with_no_recorded_currency_is_counted_at_two_places(): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => true]);

        $this->assertSame(100, CurrencyMinorUnit::inOneUnitOf(''), 'an unrecorded currency was given a scale');
        $this->assertSame(100, CurrencyMinorUnit::inOneUnitOf(null), 'a null currency was given a scale');
        $this->assertSame(2, CurrencyMinorUnit::places(''), 'an unrecorded currency was given places');
    }

    /** On: the conversion is exact decimal arithmetic, so a figure binary floating point cannot hold is not charged a coin out. */
    #[DataProvider('amountsFloatingPointGetsWrong')]
    public function test_the_conversion_is_exact_rather_than_a_float_multiplication(string $amount, string $currency, int $minorUnits): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => true]);

        $this->assertSame($minorUnits, CurrencyMinorUnit::of(amount: $amount, currencyCode: $currency), "{$amount} {$currency} is not {$minorUnits}");
        $this->assertSame($minorUnits, CurrencyMinorUnit::of(amount: (float) $amount, currencyCode: $currency), "{$amount} {$currency} as a float is not {$minorUnits}");
    }

    /** On: a fraction of the smallest coin is rounded half up, because this is the figure the customer is CHARGED and not a refund ceiling. */
    public function test_a_fraction_of_the_smallest_coin_is_rounded_half_up_because_this_is_a_charge(): void
    {
        config(['gcc.payments.amount_in_the_currencys_minor_unit' => true]);

        $this->assertSame(10235, CurrencyMinorUnit::of(amount: '10.2345', currencyCode: 'KWD'), 'half a fils did not round up');
        $this->assertSame(10234, CurrencyMinorUnit::of(amount: '10.2344', currencyCode: 'KWD'), 'less than half a fils rounded up');
    }

    /* ------------------------------------------------------------------ providers */

    public static function everyCurrencyOfTheProgramme(): array
    {
        return [['SAR'], ['AED'], ['QAR'], ['KWD'], ['BHD'], ['OMR']];
    }

    public static function twoPlaceCurrencies(): array
    {
        return [['SAR'], ['AED'], ['QAR']];
    }

    public static function threeDecimalAmounts(): array
    {
        return [
            // Every amount here has a third decimal of zero ON PURPOSE: `payment_requests.payment_amount` is DECIMAL(24,2) until
            // `2026_05_07_000001_update_all_decimal_columns_to_40_20` has run, so a stored third decimal survives on one schema and not
            // on the other, and a case whose answer depends on which schema the suite runs against measures the column and not the unit.
            // What a column that cannot hold a fils does to a dinar payment has its own test below.
            'the measured defect, in dinars' => ['KWD', '10.230', 10230],
            'a whole dinar' => ['KWD', '1.000', 1000],
            'ten fils' => ['KWD', '0.010', 10],
            'Bahraini dinars' => ['BHD', '25.500', 25500],
            'Omani rials' => ['OMR', '99.990', 99990],
        ];
    }

    public static function amountsFloatingPointGetsWrong(): array
    {
        return [
            ['1.005', 'KWD', 1005],
            ['8.175', 'KWD', 8175],
            ['0.07', 'SAR', 7],
            ['1.115', 'SAR', 112],
        ];
    }

    /* --------------------------------------------------------------------- world */

    /** The intention payload the real `credit()` body built, with the one outbound call captured rather than sent. */
    private function intentionFor(string $amount, string $currency): array
    {
        $id = $this->makePaymentRequest(['payment_amount' => $amount, 'currency_code' => $currency]);

        $controller = new class(app(PaymentRequest::class), app(User::class)) extends PaymobController {
            /** @var list<array> */
            public array $captured = [];

            protected function postIntention(string $url, array $data, string $token): ?array
            {
                $this->captured[] = $data;

                return ['client_secret' => 'a-client-secret'];
            }
        };

        $controller->credit(Request::create('/payment/paymob/pay', 'GET', ['payment_id' => $id]));

        $this->assertCount(1, $controller->captured, 'the intention was not created, so there is no payload to read');

        return $controller->captured[0];
    }

    private function settles(string $payment, string $minorUnits, string $currency): mixed
    {
        return app(GatewayConfirmations::class)->thePaymentItSettles(
            gateway: self::GATEWAY,
            paymentId: $payment,
            reference: 'ref-' . Str::random(6),
            minorUnits: $minorUnits,
            currency: $currency,
        );
    }

    protected function makePaymentRequest(array $overrides = []): string
    {
        return $this->makeSharedPaymentRequest(array_merge(['payment_method' => self::GATEWAY], $overrides));
    }

    private function paymobIsConfigured(): void
    {
        $values = json_encode([
            'status' => 1,
            'mode' => 'live',
            'secret_key' => 'paymob-secret-key',
            'public_key' => 'paymob-public-key',
            'integration_id' => '4242',
            'hmac' => 'paymob-test-hmac-secret',
            'supported_country' => 'KSA',
        ]);

        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => self::GATEWAY, 'settings_type' => 'payment_config'],
            [
                'id' => (string) Str::uuid(),
                'mode' => 'live',
                'is_active' => 1,
                'live_values' => $values,
                'test_values' => $values,
                'additional_data' => json_encode(['gateway_title' => 'Paymob']),
                'updated_at' => now(),
            ],
        );
        Cache::flush();
    }
}
