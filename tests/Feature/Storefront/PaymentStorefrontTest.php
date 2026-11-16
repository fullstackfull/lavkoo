<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\PaymentStorefront;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use RuntimeException;
use Tests\TestCase;

/**
 * What a payment remembers about its storefront, and how a callback uses it. While storefronts are off, or when the request is not
 * narrowed to a storefront, a payment records nothing and a callback binds nothing.
 */
class PaymentStorefrontTest extends TestCase
{
    private function switchStorefrontsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
    }

    private function serveRequestFrom(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    public function test_a_payment_started_while_storefronts_are_off_records_nothing(): void
    {
        $this->serveRequestFrom('KW');

        $this->assertSame(['amount' => 10], PaymentStorefront::record(additionalData: ['amount' => 10]));
    }

    public function test_a_payment_records_the_storefront_the_request_was_served_from(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('KW');

        $this->assertSame(['amount' => 10, 'storefront' => 'KW'], PaymentStorefront::record(additionalData: ['amount' => 10]));
    }

    public function test_the_default_storefront_is_recorded_too(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');

        $this->assertSame(['storefront' => 'SA'], PaymentStorefront::record(additionalData: []));
    }

    public function test_a_storefront_that_was_already_recorded_is_kept(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');

        $this->assertSame(['storefront' => 'KW'], PaymentStorefront::record(additionalData: ['storefront' => 'KW']));
    }

    public function test_a_request_that_is_not_narrowed_to_a_storefront_records_nothing(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame(['amount' => 10], PaymentStorefront::record(additionalData: ['amount' => 10]));
    }

    public function test_the_callback_runs_in_the_recorded_storefront_and_returns_what_it_returns(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');

        $answer = PaymentStorefront::within(additionalData: ['storefront' => 'KW'], callback: fn () => StorefrontContext::current()->country);

        $this->assertSame('KW', $answer);
    }

    public function test_a_storefront_switched_off_since_the_payment_started_is_still_the_one_it_is_finished_in(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => false]);

        $answer = PaymentStorefront::within(additionalData: ['storefront' => 'KW'], callback: fn () => StorefrontContext::current()->country);

        $this->assertSame('KW', $answer);
    }

    public function test_the_requests_own_storefront_is_put_back_afterwards(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');

        PaymentStorefront::within(additionalData: ['storefront' => 'KW'], callback: fn () => null);

        $this->assertSame('SA', StorefrontContext::current()->country);
    }

    public function test_a_request_that_had_no_storefront_has_none_afterwards(): void
    {
        $this->switchStorefrontsOn();

        PaymentStorefront::within(additionalData: ['storefront' => 'KW'], callback: fn () => null);

        $this->assertFalse(app()->bound(StorefrontContext::class));
    }

    public function test_the_requests_own_storefront_is_put_back_when_the_callback_fails(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');

        try {
            PaymentStorefront::within(additionalData: ['storefront' => 'KW'], callback: fn () => throw new RuntimeException('the order could not be built'));
            $this->fail('the failure was swallowed');
        } catch (RuntimeException) {
            $this->assertSame('SA', StorefrontContext::current()->country);
        }
    }

    public function test_while_storefronts_are_off_the_callback_binds_nothing(): void
    {
        $answer = PaymentStorefront::within(additionalData: ['storefront' => 'KW'], callback: fn () => app()->bound(StorefrontContext::class));

        $this->assertFalse($answer);
    }

    public function test_a_payment_with_no_storefront_recorded_binds_nothing(): void
    {
        $this->switchStorefrontsOn();

        $answer = PaymentStorefront::within(additionalData: [], callback: fn () => app()->bound(StorefrontContext::class));

        $this->assertFalse($answer);
    }

    public function test_a_storefront_that_is_not_configured_binds_nothing(): void
    {
        $this->switchStorefrontsOn();

        $answer = PaymentStorefront::within(additionalData: ['storefront' => 'ZZ'], callback: fn () => app()->bound(StorefrontContext::class));

        $this->assertFalse($answer);
    }

    public function test_the_resolver_names_a_recorded_country_whether_or_not_it_is_active(): void
    {
        $resolver = StorefrontResolver::fromConfig();

        $this->assertSame('KW', $resolver->contextOfRecordedCountry(' kw ')?->country);
        $this->assertSame('KWD', $resolver->contextOfRecordedCountry('KW')?->currency);
        $this->assertNull($resolver->contextOfRecordedCountry('ZZ'));
        $this->assertNull($resolver->contextOfRecordedCountry(null));
        $this->assertNull($resolver->contextOfRecordedCountry(''));
    }
}
