<?php

namespace App\Services\Storefront;

use App\Enums\GlobalConstant;
use Illuminate\Container\Attributes\Singleton;

/**
 * The market a visitor of the website chose, kept for the pages after it (A1-06).
 *
 * The backend has always let a request name its market -- a `?storefront=` query, the `X-Storefront` header an app sends, and since A1-05
 * the path the page is served under -- but a visitor of the website had no way to say it and nothing kept it: a choice made on one page was
 * gone on the next click. This is the keeping half. It is the session, which is where the visitor's currency and language choices already
 * live, so the choice lasts exactly as long as the rest of what the site remembers about them and no new cookie is set.
 *
 * It is a CHOICE, never an inference: nothing writes it but the visitor asking for it through {@see \App\Http\Controllers\Web\StorefrontChoiceController},
 * and a market the platform does not serve is not kept at all rather than kept and ignored. The resolver reads it below the address the
 * visitor is on, the explicit query and the app's header, and above the default -- so a market in the URL always wins over what was
 * remembered, and the remembered market only decides the pages that name none.
 *
 * Dormant while storefronts are off: nothing is offered, nothing is kept, and nothing is read.
 */
#[Singleton]
final class StorefrontChoice
{
    public const SESSION_KEY = 'storefront_country';

    public function isOffered(): bool
    {
        $resolver = StorefrontResolver::fromConfig();

        return $resolver->isEnabled() && count($resolver->reachableCountries()) > 1;
    }

    /** The market the visitor chose, or null when they have chosen none, when it is no longer served, or while storefronts are off. */
    public function remembered(): ?string
    {
        if (!StorefrontResolver::fromConfig()->isEnabled()) {
            return null;
        }

        $chosen = session(self::SESSION_KEY);

        return is_string($chosen) && $this->isReachable($chosen) ? strtoupper(trim($chosen)) : null;
    }

    /** Keeps the visitor's choice, and answers whether it was kept: a market the platform does not serve is refused rather than stored. */
    public function remember(?string $country): bool
    {
        if (!$this->isOffered() || !is_string($country) || !$this->isReachable($country)) {
            return false;
        }

        session()->put(self::SESSION_KEY, strtoupper(trim($country)));

        return true;
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * The markets to offer, each with the address of this page inside it, so the control is a plain list of links and needs no script.
     *
     * @return list<array{country: string, name: string, chosen: bool}>
     */
    public function offered(): array
    {
        if (!$this->isOffered()) {
            return [];
        }

        $here = StorefrontContext::current()->country;

        return array_map(fn (string $country): array => [
            'country' => $country,
            'name' => self::nameOf($country),
            'chosen' => $country === $here,
        ], StorefrontResolver::fromConfig()->reachableCountries());
    }

    /** The country's name as the platform already spells it (GlobalConstant::COUNTRIES), or the code when it spells it nowhere. */
    public static function nameOf(string $country): string
    {
        $code = strtoupper(trim($country));

        foreach (GlobalConstant::COUNTRIES as $listed) {
            if (($listed['code'] ?? null) === $code) {
                return (string) $listed['name'];
            }
        }

        return $code;
    }

    private function isReachable(string $country): bool
    {
        return in_array(strtoupper(trim($country)), StorefrontResolver::fromConfig()->reachableCountries(), true);
    }
}
