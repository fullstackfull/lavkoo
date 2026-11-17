<?php

namespace App\Services\Storefront;

/**
 * The list an app reads before it offers a storefront. While storefronts are on, each listed storefront says whether it may sell yet
 * (GCC-11). While they are off the list keeps its original shape, so Saudi clients see no change.
 */
final class StorefrontListing
{
    public function __construct(
        private readonly StorefrontResolver $resolver,
        private readonly StorefrontLegalReadiness $legalReadiness,
    ) {}

    public static function fromConfig(StorefrontLegalReadiness $legalReadiness): self
    {
        return new self(resolver: StorefrontResolver::fromConfig(), legalReadiness: $legalReadiness);
    }

    /**
     * @return array{enabled: bool, default_country: string, storefronts: list<array<string, mixed>>}
     */
    public function payload(): array
    {
        return [
            'enabled' => $this->resolver->isEnabled(),
            'default_country' => $this->resolver->defaultCountry(),
            'storefronts' => $this->resolver->isEnabled() ? $this->withSellability() : $this->resolver->activeStorefronts(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function withSellability(): array
    {
        return array_map(
            fn (array $storefront): array => $storefront + ['sellable' => $this->legalReadiness->isReady($storefront['country'])],
            $this->resolver->activeStorefronts(),
        );
    }
}
