<?php

namespace App\Services\Seo;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontSellingGate;
use App\Services\Storefront\StorefrontUrl;
use Illuminate\Container\Attributes\Singleton;

/**
 * What a search engine is told about one page of one storefront (B-17, B-03).
 *
 * Three answers, one owner:
 *
 *  - the canonical address of the page, which is the page's address in the storefront that served it ({@see StorefrontUrl});
 *  - the hreflang alternates, which are the same page in every indexable storefront and every published language;
 *  - whether the storefront may be indexed at all.
 *
 * The money a page states is not here. It has one owner too, {@see StorefrontCurrency::money()}, and the offer this builds reads it,
 * so the price in a JSON-LD offer is the number the page prints and the API answers, never a second calculation of it.
 *
 * A storefront is indexable once it is switched on and may sell: legal texts approved and the currency it trades in present
 * ({@see StorefrontSellingGate}). A market being prepared is reachable to whoever is preparing it and invisible to a crawler.
 * While storefronts are switched off every answer is what the page carries today.
 */
#[Singleton]
final class StorefrontSeo
{
    public function __construct(
        private readonly StorefrontUrl $urls,
        private readonly StorefrontCurrency $currency,
        private readonly StorefrontSellingGate $sellingGate,
    ) {
    }

    /** The address a page asks search engines to treat as its own: its address in the storefront that served it. */
    public function canonical(string $url, ?string $country = null): string
    {
        return $this->urls->of(url: $url, country: $country);
    }

    /**
     * The same page in every indexable storefront and every published language.
     *
     * One storefront — which is every installation while they are switched off, and one with only the default market configured —
     * keeps the language-only alternates the page carries today, down to the key and the query string.
     *
     * @param list<string> $languageCodes
     *
     * @return array<string, string> hreflang => absolute URL
     */
    public function alternates(string $url, array $languageCodes): array
    {
        $languages = array_values(array_filter(array_map(strval(...), $languageCodes), static fn (string $code): bool => trim($code) !== ''));
        $countries = $this->indexableCountries();

        if (count($countries) < 2) {
            return count($languages) > 1 ? $this->languageOnly(url: $url, languages: $languages) : [];
        }

        $alternates = [];

        foreach ($countries as $country) {
            $address = $this->urls->of(url: $url, country: $country);

            foreach ($languages as $language) {
                $alternates[strtolower($language) . '-' . strtoupper($country)] = $address . '?language=' . $language;
            }
        }

        return $alternates;
    }

    /**
     * The price and the currency a storefront states for an amount, as a schema.org offer carries them. The same two values the page
     * prints and the customer API answers, because all three read {@see StorefrontCurrency::money()}.
     *
     * The precision travels with them: a dinar price published to hundredths is a price nobody can pay.
     *
     * @return array{price: float|string, currency: string, decimals: int}
     */
    public function offer(string|int|float|null $amount): array
    {
        $money = $this->currency->money(amount: $amount);

        return ['price' => $money['amount'], 'currency' => $money['currency'], 'decimals' => $money['decimals']];
    }

    /** Whether a storefront may be indexed: it is switched on, and it may sell. The whole platform is indexable while storefronts are off. */
    public function isIndexable(?string $country = null): bool
    {
        $resolver = StorefrontResolver::fromConfig();

        if (!$resolver->isEnabled()) {
            return true;
        }

        $code = strtoupper(trim((string) ($country ?? StorefrontContext::current()->country)));

        foreach ($resolver->activeStorefronts() as $storefront) {
            if ($storefront['country'] === $code) {
                return $this->sellingGate->maySell(country: $code, currencyCode: $storefront['currency']);
            }
        }

        return false;
    }

    /**
     * The storefronts a crawler may be told about, in configuration order.
     *
     * @return list<string>
     */
    public function indexableCountries(): array
    {
        $resolver = StorefrontResolver::fromConfig();

        return array_values(array_filter(
            array_column($resolver->activeStorefronts(), 'country'),
            fn (string $country): bool => $this->isIndexable(country: $country),
        ));
    }

    /**
     * @param list<string> $languages
     *
     * @return array<string, string>
     */
    private function languageOnly(string $url, array $languages): array
    {
        $alternates = [];

        foreach ($languages as $language) {
            $alternates[$language] = $url . '?language=' . $language;
        }

        return $alternates;
    }
}
