<?php

namespace App\Services\Storefront;

use App\Services\Theme\LocalisedSettings;

/**
 * The list an app reads before it offers a storefront. While storefronts are on, each listed storefront says whether it may sell yet
 * (GCC-11, GCC-05), which state of its lifecycle it is in and, when it is paused, the mode, the message for its customers and the end of the stop (I2-13, I2-14, I2-15),
 * which is how an app learns that a storefront is in maintenance or that its customers are sent to the default one. While they are off the list keeps its original shape,
 * so Saudi clients see no change.
 *
 * Each listed storefront also says which LANGUAGES it presents, which of them it writes in by default and which way that one reads (I1-16),
 * so an app can offer a market's own languages instead of the platform's and can keep a customer's language across a market switch where the
 * new market presents it. A market that names no list of its own is listed with the platform's languages, which is every market today.
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
                + $this->languagesOf(country: $storefront['country'])
                + $this->lifecycleOf(country: $storefront['country']),
            $this->resolver->activeStorefronts(),
        );
    }

    /**
     * The languages one storefront presents, the one it writes in by default and the direction that one reads (I1-16).
     *
     * A market that names no list of its own is answered the PLATFORM's languages and default, not an empty list: an app that read an empty
     * list would offer no language at all, and "this market has not been given a list yet" means "it presents what the shop presents". The
     * direction comes from the platform's language list entry and is never derived from the language code, which is the rule the website
     * already follows.
     *
     * @return array{languages: list<string>, default_language: ?string, direction: string}
     */
    private function languagesOf(string $country): array
    {
        $languages = MarketLanguage::listOf($country);
        $languages = $languages === [] ? LocalisedSettings::activeLocales() : $languages;
        $default = MarketLanguage::of($country) ?? ($languages[0] ?? null);

        return [
            'languages' => $languages,
            'default_language' => $default,
            'direction' => $default === null ? 'ltr' : MarketLanguage::directionOfLocale($default),
        ];
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
