<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\PaymentReturnsToItsStorefront;
use App\Services\Storefront\PaymentStorefront;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * After the gateway, the customer lands on the page of the storefront they PAID in (D2-14).
 *
 * A payment is already finished in the storefront it started in -- that half is built and proved, whatever request
 * the gateway's callback arrives as. The page the customer is then sent to was not: `payment-success` and
 * `payment-fail` are unprefixed routes, so a Kuwaiti shopper who has just paid in dinars was answered the default
 * market's page, with its money, its legal texts and its catalogue.
 *
 * The storefront comes from the PAYMENT'S OWN RECORD and never from the request, which is the whole point of this
 * part of the platform: the gateway's return carries nothing about the shop, and the browser following it may be a
 * new session.
 *
 * The first two cases are the dormancy claim: the form ships `unchanged` and the URL is the one it has always been.
 */
class ACustomerLandsInTheStorefrontTheyPaidInTest extends TestCase
{
    private const MARKET = 'KW';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('payment_requests');
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->text('additional_data')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_platform')->nullable();
            $table->string('transaction_id')->nullable();
            $table->text('external_redirect_link')->nullable();
            $table->timestamps();
        });

        // `payment_response` looks up the orders the payment built, by its transaction reference.
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_ref')->nullable();
            $table->timestamps();
        });

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.payment_return.url_form' => PaymentReturnsToItsStorefront::UNCHANGED,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('orders');

        parent::tearDown();
    }

    /** A payment as `Payment::generate_link` leaves one: its storefront recorded in its own additional data. */
    private function aPaymentStartedIn(?string $market): array
    {
        $additionalData = $market === null ? [] : [PaymentStorefront::KEY => $market];

        return ['id' => (string) Str::uuid(), 'additional_data' => json_encode($additionalData)];
    }

    private function returns(): PaymentReturnsToItsStorefront
    {
        return app(PaymentReturnsToItsStorefront::class);
    }

    // ---------------------------------------------------------------- unchanged as it ships

    public function test_as_it_ships_the_return_page_is_the_url_it_has_always_been(): void
    {
        $this->assertSame(PaymentReturnsToItsStorefront::UNCHANGED, $this->returns()->form());
        $this->assertSame([], $this->returns()->parametersFor(payment: $this->aPaymentStartedIn(self::MARKET)));
    }

    /** The path-prefix form is the other half of the open decision and is not built, so it reads as unchanged. */
    public function test_a_form_nobody_built_reads_as_unchanged(): void
    {
        foreach (['path_prefix', 'PREFIX', '', 'whatever_somebody_typed', null] as $typed) {
            config(['gcc.storefronts.payment_return.url_form' => $typed]);

            $this->assertSame(PaymentReturnsToItsStorefront::UNCHANGED, $this->returns()->form(), var_export($typed, true));
            $this->assertSame([], $this->returns()->parametersFor(payment: $this->aPaymentStartedIn(self::MARKET)));
        }
    }

    // ---------------------------------------------------------------- the storefront that was paid in

    public function test_the_return_carries_the_storefront_the_payment_recorded(): void
    {
        config(['gcc.storefronts.payment_return.url_form' => PaymentReturnsToItsStorefront::QUERY]);

        $this->assertSame(['storefront' => self::MARKET], $this->returns()->parametersFor(payment: $this->aPaymentStartedIn(self::MARKET)));
    }

    /** Every payment while storefronts are off records none, and such a payment must not be sent anywhere new. */
    public function test_a_payment_that_recorded_no_storefront_carries_nothing(): void
    {
        config(['gcc.storefronts.payment_return.url_form' => PaymentReturnsToItsStorefront::QUERY]);

        $this->assertSame([], $this->returns()->parametersFor(payment: $this->aPaymentStartedIn(null)));
        $this->assertSame([], $this->returns()->parametersFor(payment: null));
    }

    /** Whatever is in that key, only a country code is used: it reaches a URL, so it is validated and never echoed. */
    public function test_a_recorded_value_that_is_not_a_country_code_carries_nothing(): void
    {
        config(['gcc.storefronts.payment_return.url_form' => PaymentReturnsToItsStorefront::QUERY]);

        foreach (['', 'KWT', 'k', '../../etc', '<script>', 12, ['KW']] as $notACode) {
            $payment = ['id' => 'p', 'additional_data' => json_encode([PaymentStorefront::KEY => $notACode])];

            $this->assertSame([], $this->returns()->parametersFor(payment: $payment), var_export($notACode, true) . ' reached a URL');
        }
    }

    /** A market the configuration no longer serves is still where the payment was made, and is still where the customer goes back to. */
    public function test_a_storefront_removed_from_the_configuration_since_is_still_the_one_returned_to(): void
    {
        // KW taken out of the map, SA left in it: the resolver will not build at all without its default market,
        // so an empty map is not a configuration anybody can be in and emptying it would prove nothing.
        $countries = (array) config('gcc.storefronts.countries');
        unset($countries[self::MARKET]);
        config(['gcc.storefronts.payment_return.url_form' => PaymentReturnsToItsStorefront::QUERY, 'gcc.storefronts.countries' => $countries]);

        $this->assertSame(['storefront' => self::MARKET], $this->returns()->parametersFor(payment: $this->aPaymentStartedIn(self::MARKET)));
    }

    // ---------------------------------------------------------------- the URL the customer is actually sent to

    /**
     * THE CALLER, not the service. Every gateway controller answers a customer through
     * `Processor::payment_response`, and a test of the service alone would not notice if that method stopped
     * asking -- which is the mistake this repository has already paid for on the routing guard of the payment
     * start. So the URL is read off the redirect `payment_response` returns.
     */
    public function test_the_url_the_customer_is_sent_to_carries_the_storefront_they_paid_in(): void
    {
        config(['gcc.storefronts.payment_return.url_form' => PaymentReturnsToItsStorefront::QUERY]);

        $url = $this->theUrlACustomerIsSentTo(market: self::MARKET);

        $this->assertStringContainsString('storefront=' . self::MARKET, $url, 'the customer was sent back to no market in particular');
        $this->assertStringContainsString('payment-success', $url);
        $this->assertStringContainsString('token=', $url, 'the parameters the page has always been given are still there');
    }

    public function test_as_it_ships_the_url_the_customer_is_sent_to_names_no_storefront(): void
    {
        $url = $this->theUrlACustomerIsSentTo(market: self::MARKET);

        $this->assertStringNotContainsString('storefront', $url, 'the URL changed although the form ships unchanged');
        $this->assertStringContainsString('payment-success', $url);
    }

    public function test_a_payment_that_recorded_no_storefront_is_sent_to_the_url_it_always_was(): void
    {
        config(['gcc.storefronts.payment_return.url_form' => PaymentReturnsToItsStorefront::QUERY]);

        $this->assertStringNotContainsString('storefront', $this->theUrlACustomerIsSentTo(market: null));
    }

    /** The redirect `Processor::payment_response` answers, which is what every gateway controller returns. */
    private function theUrlACustomerIsSentTo(?string $market): string
    {
        $payment = $this->aPaymentStartedIn($market);
        DB::table('payment_requests')->insert($payment + [
            'payment_method' => 'paytabs',
            'payment_platform' => 'web',
            'transaction_id' => 'TX-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $processor = new class {
            use \App\Traits\Processor;
        };

        return $processor->payment_response(\App\Models\PaymentRequest::query()->findOrFail($payment['id']), 'success')->getTargetUrl();
    }

    public function test_the_additional_data_of_a_model_row_is_read_the_same_way_as_an_array(): void
    {
        config(['gcc.storefronts.payment_return.url_form' => PaymentReturnsToItsStorefront::QUERY]);
        $payment = $this->aPaymentStartedIn(self::MARKET);
        DB::table('payment_requests')->insert($payment + ['created_at' => now(), 'updated_at' => now()]);

        $row = \App\Models\PaymentRequest::query()->findOrFail($payment['id']);

        $this->assertSame(self::MARKET, $this->returns()->countryOf(payment: $row));
    }
}
