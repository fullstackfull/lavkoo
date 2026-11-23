<?php

namespace App\Services\Storefront;

/**
 * The list an app reads before it offers a storefront. While storefronts are on, each listed storefront says whether it may sell yet
 * (GCC-11, GCC-05), which state of its lifecycle it is in and, when it is paused, the mode, the message for its customers and the end of the stop (I2-13, I2-14, I2-15),
 * which is how an app learns that a storefront is in maintenance or that its customers are sent to the default one. While they are off the list keeps its original shape,
 * so Saudi clients see no change.
 */
final class StorefrontListing
{
    public function __construct(
        private readonly StorefrontResolver $resolver,
        private readonly StorefrontSellingGate $sellingGate,
        private readonly StorefrontLifecycle $lifecycle,
    ) {}

    public static function fromConfig(StorefrontSellingGate $sellingGate): self
    {
        return new self(resolver: StorefrontResolver::fromConfig(), sellingGate: $sellingGate, lifecycle: app(StorefrontLifecycle::class));
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
            fn (array $storefront): array => $storefront
                + ['sellable' => $this->sellingGate->maySell(country: $storefront['country'], currencyCode: $storefront['currency'])]
                + $this->lifecycleOf(country: $storefront['country']),
            $this->resolver->activeStorefronts(),
        );
    }

    /**
     * @return array<string, mixed> the state, and for a paused storefront how it is stopped
     */
    private function lifecycleOf(string $country): array
    {
        $status = $this->lifecycle->statusOf($country);

        if ($status['state'] !== StorefrontLifecycle::PAUSED) {
            return ['state' => $status['state']];
        }

        return [
            'state' => $status['state'],
            'stop_mode' => $status['mode'],
            'stop_message' => $status['message'] ?? translate($status['mode'] === StorefrontLifecycle::MAINTENANCE ? 'storefront_is_in_maintenance' : 'storefront_not_open_for_checkout_yet'),
            'stop_until' => $status['until'],
            'redirect_to' => $status['mode'] === StorefrontLifecycle::REDIRECT ? $this->resolver->defaultCountry() : null,
        ];
    }
}
