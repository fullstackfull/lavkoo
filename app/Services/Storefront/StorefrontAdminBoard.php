<?php

namespace App\Services\Storefront;

/**
 * What the lifecycle screen shows about each configured storefront: the state it is in, who last changed it and why, and which of the four things it needs before it can sell
 * are still unmet. It reads what StorefrontLifecycle and StorefrontPreflight answer and decides nothing itself, so the screen and the console command cannot disagree.
 */
final class StorefrontAdminBoard
{
    public function __construct(
        private readonly StorefrontLifecycle $lifecycle,
        private readonly StorefrontPreflight $preflight,
    ) {
    }

    public function storefrontsAreOn(): bool
    {
        return StorefrontResolver::fromConfig()->isEnabled();
    }

    /**
     * @return list<array{country: string, currency: string, is_default: bool, status: array<string, mixed>, unmet: list<string>}>
     */
    public function rows(): array
    {
        $resolver = StorefrontResolver::fromConfig();

        return array_map(
            fn (string $country): array => [
                'country' => $country,
                'currency' => $resolver->currencyOf($country),
                'is_default' => $country === $resolver->defaultCountry(),
                'status' => $this->lifecycle->statusOf($country),
                'unmet' => $this->preflight->unmet(country: $country, currencyCode: $resolver->currencyOf($country)),
            ],
            $resolver->configuredCountries(),
        );
    }
}
