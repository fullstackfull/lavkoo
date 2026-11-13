<?php

namespace Tests\Unit\Storefront;

use App\Services\Storefront\StorefrontResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StorefrontResolverTest extends TestCase
{
    private const COUNTRIES = [
        'SA' => ['path' => 'sa', 'currency' => 'SAR'],
        'AE' => ['path' => 'ae', 'currency' => 'AED'],
        'KW' => ['path' => 'kw', 'currency' => 'KWD'],
        'QA' => ['path' => 'qa', 'currency' => 'QAR'],
        'BH' => ['path' => 'bh', 'currency' => 'BHD'],
        'OM' => ['path' => 'om', 'currency' => 'OMR'],
    ];

    public function test_while_switched_off_every_request_resolves_to_the_default_country(): void
    {
        $resolver = $this->resolver(enabled: false);

        $this->assertSame('SA', $resolver->resolve(explicitChoice: 'KW', path: '/ae/cart'));
    }

    public function test_the_path_prefix_selects_the_country_when_switched_on(): void
    {
        $this->assertSame('AE', $this->resolver()->resolve(path: '/ae/cart'));
    }

    public function test_an_explicit_choice_beats_the_path_prefix(): void
    {
        $this->assertSame('KW', $this->resolver()->resolve(explicitChoice: 'KW', path: '/ae/cart'));
    }

    public function test_a_choice_or_path_that_is_not_configured_falls_back_to_the_default(): void
    {
        $this->assertSame('SA', $this->resolver()->resolve(explicitChoice: 'XX', path: '/xx/offers'));
    }

    public function test_with_no_choice_and_no_path_the_default_applies(): void
    {
        $this->assertSame('SA', $this->resolver()->resolve());
    }

    public function test_the_configured_default_applies_to_a_visitor_with_no_storefront(): void
    {
        $this->assertSame('AE', $this->resolver(defaultCountry: 'AE')->resolve(path: '/'));
    }

    public function test_choices_and_paths_match_regardless_of_case_and_surrounding_spaces(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('KW', $resolver->resolve(explicitChoice: ' kw '));
        $this->assertSame('QA', $resolver->resolve(path: '/QA/offers'));
    }

    #[DataProvider('currencies')]
    public function test_each_country_has_its_own_currency(string $countryCode, string $currency): void
    {
        $this->assertSame($currency, $this->resolver()->currencyOf($countryCode));
    }

    public static function currencies(): array
    {
        return [
            'Saudi Arabia' => ['SA', 'SAR'],
            'United Arab Emirates' => ['AE', 'AED'],
            'Kuwait' => ['KW', 'KWD'],
            'Qatar' => ['QA', 'QAR'],
            'Bahrain' => ['BH', 'BHD'],
            'Oman' => ['OM', 'OMR'],
        ];
    }

    public function test_a_default_country_that_is_not_configured_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver(defaultCountry: 'XX');
    }

    public function test_the_currency_of_an_unconfigured_country_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver()->currencyOf('XX');
    }

    private function resolver(bool $enabled = true, string $defaultCountry = 'SA'): StorefrontResolver
    {
        return new StorefrontResolver(enabled: $enabled, defaultCountry: $defaultCountry, countries: self::COUNTRIES);
    }
}
