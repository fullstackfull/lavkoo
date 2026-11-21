<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;

/**
 * The address of one page in one storefront (B-17). One web domain carries the six markets under the paths recorded in
 * docs/gcc/DECISIONS.md — `/sa`, `/ae`, `/kw`, `/qa`, `/bh`, `/om` — and the path of each is the `path` of its entry in
 * config('gcc.storefronts.countries'), never a literal written anywhere else.
 *
 * Every surface that has to name a storefront's page asks this one service: the canonical link, the hreflang alternates, the
 * sitemap and the storefront switcher. Nothing else builds such a URL, so the day the prefix changes, one method changes.
 *
 * The default storefront keeps the bare address it has today, here as everywhere else in the storefront code, and while
 * storefronts are switched off every URL is returned exactly as it was given.
 */
#[Singleton]
final class StorefrontUrl
{
    /**
     * The storefront form of a platform URL: the page's own address in that storefront.
     *
     * Only a market a visitor can actually reach gets a segment of its own. A market that is switched off answers nothing under its
     * path, so naming that address would publish a link to a page that does not exist; it takes the platform address, which is what a
     * country this installation does not serve has always taken.
     */
    public function of(string $url, ?string $country = null): string
    {
        $resolver = StorefrontResolver::fromConfig();

        if (!$resolver->isEnabled()) {
            return $url;
        }

        $code = strtoupper(trim((string) ($country ?? StorefrontContext::current()->country)));

        if ($code === $resolver->defaultCountry() || !in_array($code, array_column($resolver->activeStorefronts(), 'country'), true)) {
            return $url;
        }

        return $this->prefixed(url: $url, path: $this->pathOf(country: $code));
    }

    /** The first path segment a storefront answers to, as its configuration records it. */
    public function pathOf(string $country): string
    {
        $countries = (array) config('gcc.storefronts.countries');
        $code = strtoupper(trim($country));

        return (string) ($countries[$code]['path'] ?? strtolower($code));
    }

    /**
     * The same page in each of the given storefronts.
     *
     * @param list<string> $countries
     *
     * @return array<string, string> country code => that storefront's address for the page
     */
    public function inEach(string $url, array $countries): array
    {
        $addresses = [];

        foreach ($countries as $country) {
            $addresses[strtoupper($country)] = $this->of(url: $url, country: $country);
        }

        return $addresses;
    }

    /**
     * Puts the storefront's segment straight after this installation's root, so an installation served from a sub-directory keeps it.
     * A URL that does not belong to this installation, and one that already carries the segment, is left exactly as it is.
     */
    private function prefixed(string $url, string $path): string
    {
        $root = rtrim(url('/'), '/');

        if ($url !== $root && !str_starts_with($url, $root . '/')) {
            return $url;
        }

        $rest = substr($url, strlen($root));

        if ($rest === '/' . $path || str_starts_with($rest, '/' . $path . '/') || str_starts_with($rest, '/' . $path . '?')) {
            return $url;
        }

        return $root . '/' . $path . ($rest === '/' ? '' : $rest);
    }
}
