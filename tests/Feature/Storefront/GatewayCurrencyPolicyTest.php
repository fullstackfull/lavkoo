<?php

namespace Tests\Feature\Storefront;

use App\Exceptions\StorefrontPaymentNotSupportedException;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Services\Storefront\GatewayCurrencyPolicy;
use App\Services\Storefront\StorefrontResolver;
use App\Traits\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC-05: a gateway takes the money of a storefront with its own currency only in that very currency, only if its list has it, and, for a
 * currency with three decimals, only once somebody has verified the gateway for it. Every payment starts in Payment::generate_link().
 */
class GatewayCurrencyPolicyTest extends TestCase
{
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        foreach (['payment_requests', 'currencies', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->decimal('payment_amount', 24, 3)->default(0);
            $table->string('gateway_callback_url')->nullable();
            $table->string('success_hook')->nullable();
            $table->string('failure_hook')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('currency_code', 10)->default('USD');
            $table->string('payment_method')->nullable();
            $table->text('additional_data')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->text('payer_information')->nullable();
            $table->text('external_redirect_link')->nullable();
            $table->text('receiver_information')->nullable();
            $table->string('attribute_id')->nullable();
            $table->string('attribute')->nullable();
            $table->string('payment_platform')->nullable();
            $table->timestamps();
        });

        foreach (['SAR' => 3.75, 'AED' => 3.6725, 'KWD' => 0.307, 'QAR' => 3.64, 'BHD' => 0.376, 'OMR' => 0.385, 'USD' => 1.0] as $code => $rate) {
            DB::table('currencies')->insert(['name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1]);
        }
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => 'single_currency'],
            ['type' => 'system_default_currency', 'value' => (string) DB::table('currencies')->where('code', 'SAR')->value('id')],
        ]);

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.currency.verified_three_decimal_gateways' => [],
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.QA.active' => true,
            'gcc.storefronts.countries.BH.active' => true,
            'gcc.storefronts.countries.OM.active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['payment_requests', 'currencies', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function inStorefront(string $country, callable $callback): mixed
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $country)->serve($callback);
    }

    private function policy(): GatewayCurrencyPolicy
    {
        return app(GatewayCurrencyPolicy::class);
    }

    private function acceptable(string $country, string $gateway, string $currency): bool
    {
        try {
            $this->inStorefront($country, fn () => $this->policy()->assertAcceptable(gateway: $gateway, currencyCode: $currency));

            return true;
        } catch (StorefrontPaymentNotSupportedException) {
            return false;
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: bool}> */
    public static function pairs(): array
    {
        return [
            'two decimals, listed gateway and currency' => ['AE', 'foloosi', 'AED', true],
            'the gateway lists no dirham' => ['AE', 'paymob_accept', 'USD', false],
            'a gateway that has no list for the currency' => ['AE', 'bkash', 'AED', false],
            'payment in dollars of a dirham storefront' => ['AE', 'hyper_pay', 'USD', false],
            'payment in the riyal of another storefront' => ['AE', 'hyper_pay', 'SAR', false],
            'Qatar, two decimals, listed' => ['QA', 'tap', 'QAR', true],
            'Qatar, a gateway without the riyal of Qatar' => ['QA', 'foloosi', 'QAR', false],
            'Kuwait, three decimals, not verified' => ['KW', 'tap', 'KWD', false],
            'Bahrain, three decimals, not verified' => ['BH', 'paytabs', 'BHD', false],
            'Oman, three decimals, not verified' => ['OM', 'thawani', 'OMR', false],
            'the Saudi storefront takes whatever it took' => ['SA', 'bkash', 'USD', true],
        ];
    }

    #[DataProvider('pairs')]
    public function test_a_gateway_takes_only_the_currency_of_the_storefront_that_it_lists_with_two_decimals(string $country, string $gateway, string $currency, bool $accepted): void
    {
        $this->assertSame($accepted, $this->acceptable($country, $gateway, $currency));
    }

    public function test_a_three_decimal_currency_goes_to_a_gateway_that_was_verified_for_it_and_only_to_that_one(): void
    {
        config(['gcc.storefronts.currency.verified_three_decimal_gateways' => ['tap']]);

        $this->assertTrue($this->acceptable('KW', 'tap', 'KWD'));
        $this->assertFalse($this->acceptable('KW', 'hyper_pay', 'KWD'));
        $this->assertFalse($this->acceptable('KW', 'tap', 'USD'), 'verified for the dinar, not for dollars');
        $this->assertFalse($this->acceptable('BH', 'tap', 'KWD'), 'the dinar of Kuwait is not the currency of Bahrain');
    }

    public function test_the_second_name_of_myfatoorah_is_judged_like_the_first(): void
    {
        $this->assertFalse($this->acceptable('KW', 'my_fatoorah', 'KWD'));
        $this->assertFalse($this->acceptable('KW', 'fatoorah', 'KWD'));

        config(['gcc.storefronts.currency.verified_three_decimal_gateways' => ['fatoorah']]);

        $this->assertTrue($this->acceptable('KW', 'my_fatoorah', 'KWD'));
        $this->assertTrue($this->acceptable('KW', 'fatoorah', 'KWD'));
    }

    public function test_nothing_is_refused_while_storefront_currencies_are_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertTrue($this->acceptable('KW', 'bkash', 'USD'));
    }

    public function test_nothing_is_refused_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->assertTrue($this->acceptable('KW', 'bkash', 'USD'));
    }

    public function test_nothing_is_refused_for_a_request_that_is_not_served_from_a_storefront(): void
    {
        $this->policy()->assertAcceptable(gateway: 'bkash', currencyCode: 'USD');

        $this->addToAssertionCount(1);
    }

    private function startAPayment(string $gateway, string $currency, float $amount): mixed
    {
        $paymentInfo = new PaymentInfo(
            success_hook: 'digital_payment_success',
            failure_hook: 'digital_payment_fail',
            currency_code: $currency,
            payment_method: $gateway,
            payment_platform: 'web',
            payer_id: '1',
            receiver_id: '100',
            additional_data: [],
            payment_amount: $amount,
            attribute: 'order',
            attribute_id: '1',
        );

        return Payment::generate_link(new Payer('A Customer', 'customer@example.invalid', '', ''), $paymentInfo, new Receiver('receiver_name', 'example.png'));
    }

    public function test_a_payment_the_gateway_cannot_take_never_gets_a_row_and_a_payment_it_can_take_does(): void
    {
        $link = $this->inStorefront('AE', fn () => $this->startAPayment(gateway: 'foloosi', currency: 'AED', amount: 125.5));

        $this->assertIsString($link);
        $this->assertSame(1, DB::table('payment_requests')->count());
        $this->assertSame('AED', DB::table('payment_requests')->value('currency_code'));

        try {
            $this->inStorefront('KW', fn () => $this->startAPayment(gateway: 'tap', currency: 'KWD', amount: 12.345));
            $this->fail('A payment in a three-decimal currency of an unverified gateway must be refused before it starts.');
        } catch (StorefrontPaymentNotSupportedException $refusal) {
            $this->assertSame('tap', $refusal->gateway);
            $this->assertSame('KWD', $refusal->currencyCode);
        }

        $this->assertSame(1, DB::table('payment_requests')->count(), 'the refused payment left no row behind');
    }

    public function test_the_refusal_answers_422_with_its_own_code_to_an_app_and_goes_back_with_a_message_to_a_browser(): void
    {
        $exception = new StorefrontPaymentNotSupportedException(gateway: 'tap', currencyCode: 'KWD');

        $json = $exception->render(request()->duplicate(server: ['HTTP_ACCEPT' => 'application/json']));

        $this->assertSame(422, $json->getStatusCode());
        $this->assertSame('storefront_payment_not_supported', $json->getData(true)['code']);
        $this->assertSame('This payment method is not available in the currency of this storefront.', $json->getData(true)['message']);

        $web = $exception->render(request());

        $this->assertInstanceOf(RedirectResponse::class, $web);
        $this->assertSame('This payment method is not available in the currency of this storefront.', $web->getSession()->get('error'));
    }

    public function test_the_default_storefront_starts_the_payments_it_always_started(): void
    {
        $link = $this->inStorefront('SA', fn () => $this->startAPayment(gateway: 'paymob_accept', currency: 'SAR', amount: 100));

        $this->assertIsString($link);
        $this->assertSame(1, DB::table('payment_requests')->count());
    }
}
