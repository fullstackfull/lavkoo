<?php

namespace Tests\Unit\Storefront;

use App\Services\Storefront\StorefrontResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StorefrontResolverTest extends TestCase
{
    private const COUNTRIES = [
        'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true],
        'AE' => ['path' => 'ae', 'currency' => 'AED', 'active' => true],
        'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => true],
        'QA' => ['path' => 'qa', 'currency' => 'QAR', 'active' => true],
        'BH' => ['path' => 'bh', 'currency' => 'BHD', 'active' => true],
        'OM' => ['path' => 'om', 'currency' => 'OMR', 'active' => true],
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

    #[DataProvider('invalidChoices')]
    public function test_an_invalid_choice_falls_back_to_the_path_prefix(string $explicitChoice): void
    {
        $resolver = $this->resolver(countries: $this->withInactive('AE'));

        $this->assertSame('KW', $resolver->resolve(explicitChoice: $explicitChoice, path: '/kw/cart'));
    }

    #[DataProvider('invalidChoices')]
    public function test_an_invalid_choice_without_a_path_falls_back_to_the_default(string $explicitChoice): void
    {
        $resolver = $this->resolver(countries: $this->withInactive('AE'));

        $this->assertSame('SA', $resolver->resolve(explicitChoice: $explicitChoice));
        $this->assertSame('SA', $resolver->context(explicitChoice: $explicitChoice, path: '/')->country);
    }

    public static function invalidChoices(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ["  \t\n "],
            'unknown code' => ['XX'],
            'inactive code' => ['AE'],
            'very long string' => [str_repeat('SA', 5000)],
            'NUL byte inside a valid code' => ["S\0A"],
        ];
    }

    public function test_a_lowercase_valid_choice_resolves_to_its_country_code(): void
    {
        $this->assertSame('SA', $this->resolver(defaultCountry: 'KW')->resolve(explicitChoice: 'sa'));
    }

    public function test_a_valid_choice_with_surrounding_whitespace_resolves_to_its_country_code(): void
    {
        $this->assertSame('SA', $this->resolver(defaultCountry: 'KW')->resolve(explicitChoice: " \tsa\n "));
    }

    public function test_a_lowercase_configured_default_resolves_to_its_country_code_while_switched_off(): void
    {
        $resolver = $this->resolver(enabled: false, defaultCountry: ' sa ');

        $this->assertSame('SA', $resolver->resolve(explicitChoice: 'KW', path: '/ae/cart'));
        $this->assertSame('SA', $resolver->context()->country);
        $this->assertSame('SAR', $resolver->context()->currency);
    }

    public function test_a_lowercase_configured_default_resolves_to_its_country_code_while_switched_on(): void
    {
        $resolver = $this->resolver(defaultCountry: 'sa');

        $this->assertSame('SA', $resolver->resolve());
        $this->assertSame('SA', $resolver->resolve(explicitChoice: 'XX', path: '/xx/offers'));
        $this->assertSame('SA', $resolver->context(path: '/')->country);
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

    public function test_an_inactive_country_is_ignored_by_path_and_choice(): void
    {
        $resolver = $this->resolver(countries: $this->withInactive('AE'));

        $this->assertSame('SA', $resolver->resolve(explicitChoice: 'AE'));
        $this->assertSame('SA', $resolver->resolve(path: '/ae/cart'));
        $this->assertSame('KW', $resolver->resolve(explicitChoice: 'AE', path: '/kw/cart'));
    }

    public function test_a_country_without_an_active_key_is_treated_as_inactive(): void
    {
        $countries = self::COUNTRIES;
        unset($countries['AE']['active']);

        $this->assertSame('SA', $this->resolver(countries: $countries)->resolve(path: '/ae/cart'));
    }

    public function test_an_inactive_default_country_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver(countries: $this->withInactive('SA'));
    }

    public function test_an_inactive_default_country_is_refused_even_while_switched_off(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver(enabled: false, countries: $this->withInactive('SA'));
    }

    public function test_the_context_carries_the_resolved_country_and_its_currency(): void
    {
        $context = $this->resolver()->context(path: '/kw/cart');

        $this->assertSame('KW', $context->country);
        $this->assertSame('KWD', $context->currency);
    }

    public function test_the_context_is_saudi_while_switched_off(): void
    {
        $context = $this->resolver(enabled: false)->context(explicitChoice: 'AE', path: '/ae');

        $this->assertSame('SA', $context->country);
        $this->assertSame('SAR', $context->currency);
    }

    public function test_a_header_choice_applies_when_there_is_no_explicit_choice(): void
    {
        $this->assertSame('KW', $this->resolver()->resolve(path: '/ae/cart', headerChoice: 'kw'));
    }

    public function test_an_explicit_choice_beats_the_header_choice(): void
    {
        $this->assertSame('QA', $this->resolver()->resolve(explicitChoice: 'QA', path: '/ae/cart', headerChoice: 'KW'));
    }

    public function test_an_invalid_explicit_choice_falls_back_to_the_header_choice(): void
    {
        $resolver = $this->resolver(countries: $this->withInactive('AE'));

        $this->assertSame('KW', $resolver->resolve(explicitChoice: 'AE', path: '/qa/cart', headerChoice: 'KW'));
    }

    public function test_the_header_choice_is_ignored_while_switched_off(): void
    {
        $this->assertSame('SA', $this->resolver(enabled: false)->resolve(headerChoice: 'KW'));
    }

    public function test_active_storefronts_returns_only_the_default_while_disabled(): void
    {
        $this->assertSame(
            [['country' => 'SA', 'path' => 'sa', 'currency' => 'SAR', 'is_default' => true]],
            $this->resolver(enabled: false)->activeStorefronts(),
        );
    }

    public function test_active_storefronts_lists_active_countries_in_config_order(): void
    {
        $countries = [
            'KW' => ['path' => 'kw', 'currency' => 'KWD', 'active' => true],
            'SA' => ['path' => 'sa', 'currency' => 'SAR', 'active' => true],
            'AE' => ['path' => 'ae', 'currency' => 'AED', 'active' => true],
        ];

        $this->assertSame([
            ['country' => 'KW', 'path' => 'kw', 'currency' => 'KWD', 'is_default' => false],
            ['country' => 'SA', 'path' => 'sa', 'currency' => 'SAR', 'is_default' => true],
            ['country' => 'AE', 'path' => 'ae', 'currency' => 'AED', 'is_default' => false],
        ], $this->resolver(countries: $countries)->activeStorefronts());
    }

    public function test_inactive_countries_are_never_listed(): void
    {
        $countries = $this->withInactive('AE');
        $countries['QA']['active'] = false;
        unset($countries['OM']['active']);

        $listedCountries = array_column($this->resolver(countries: $countries)->activeStorefronts(), 'country');

        $this->assertSame(['SA', 'KW', 'BH'], $listedCountries);
        $this->assertSame(['SA'], array_column($this->resolver(enabled: false, countries: $countries)->activeStorefronts(), 'country'));
    }

    public function test_only_the_default_storefront_is_marked_as_default(): void
    {
        $storefronts = $this->resolver(defaultCountry: 'kw')->activeStorefronts();

        $this->assertSame(['KW'], array_column(array_filter($storefronts, fn (array $storefront) => $storefront['is_default']), 'country'));
        $this->assertSame('KW', $this->resolver(defaultCountry: 'kw')->defaultCountry());
    }

    private function withInactive(string $countryCode): array
    {
        $countries = self::COUNTRIES;
        $countries[$countryCode]['active'] = false;

        return $countries;
    }

    private function resolver(bool $enabled = true, string $defaultCountry = 'SA', array $countries = self::COUNTRIES): StorefrontResolver
    {
        return new StorefrontResolver(enabled: $enabled, defaultCountry: $defaultCountry, countries: $countries);
    }
}
