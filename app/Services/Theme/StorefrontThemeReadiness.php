<?php

namespace App\Services\Theme;

use Illuminate\Container\Attributes\Singleton;

/**
 * Whether a storefront has a theme its customers would actually be shown (I2-12).
 *
 * The preflight asked a market about its legal texts, its currency, its selling company, its tax and its gateway, and nothing about
 * whether its pages render. A market opened without a published theme answers a customer with the default storefront's design or with
 * nothing at all, and nobody is told: the first report is a visitor saying the site is blank.
 *
 * **It asks the renderer's own question, through {@see StorefrontThemeResolver::publishedVersionServedBy()}, and deliberately not a query
 * of its own.** Which theme a storefront serves is decided in one place (F-01, and a scan test fails any file that reads the active theme
 * another way), so a readiness check with its own query could report a market ready while the renderer serves it nothing -- which is the
 * same class of defect as the market being unready in the first place, and harder to find.
 *
 * **A market served the DEFAULT storefront's theme is ready, and that is not a loophole.** The resolver falls back to the default
 * storefront's published theme for a market with none of its own, so such a market really does render; refusing it would refuse every
 * market that has not been given a design of its own, which is every market this platform has today.
 *
 * **IT ANSWERS TRUE UNTIL IT IS SWITCHED ON** (`gcc.storefronts.sale_gate.needs_a_theme`, which ships false), for the same reason
 * {@see \App\Services\Payments\StorefrontPaymentReadiness} does: what "ready to sell" includes beyond the legal texts is the owner's open
 * decision on D1-16, and a market must not be refused by a prerequisite nobody has been asked about. With it off this costs one config
 * read and asks the database nothing.
 */
#[Singleton]
final class StorefrontThemeReadiness
{
    public const NOT_ASKED = 'not_asked';

    public const READY = 'ready';

    public const NO_PUBLISHED_THEME = 'no_theme_of_this_storefront_or_of_the_default_storefront_has_a_published_version';

    public function __construct(private readonly StorefrontThemeResolver $themes)
    {
    }

    /** Whether this is asked of a market at all. */
    public function isAsked(): bool
    {
        return (bool) config('gcc.storefronts.sale_gate.needs_a_theme', false);
    }

    public function isReady(string $country): bool
    {
        return $this->reasonFor(country: $country) !== self::NO_PUBLISHED_THEME;
    }

    /** Why this market is or is not ready, in words an operator can act on. */
    public function reasonFor(string $country): string
    {
        if (!$this->isAsked()) {
            return self::NOT_ASKED;
        }

        return $this->themes->publishedVersionServedBy(storefront: strtoupper(trim($country))) === null
            ? self::NO_PUBLISHED_THEME
            : self::READY;
    }
}
