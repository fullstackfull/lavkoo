<?php

namespace App\Services\Seo;

use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontSellingGate;
use App\Services\Storefront\StorefrontUrl;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Model;

/**
 * What a search engine is told about one page of one storefront (B-17, B-03).
 *
 * Three answers, one owner:
 *
 *  - the canonical address of the page, which is the page's address in the storefront that served it ({@see StorefrontUrl});
 *  - the hreflang alternates, which are the same page in every storefront that CARRIES it and every published language;
 *  - whether the storefront may be indexed at all.
 *
 * The money a page states is not here. It has one owner too, {@see StorefrontCurrency::money()}, and the offer this builds reads it
 * while a storefront trades in its own currency, so there the price in a JSON-LD offer is the number the page prints and the API
 * answers, never a second calculation of it. Where the platform's own currency settings stand the offer is deliberately the one the
 * page has always published, which is not that number: {@see offer()} says why.
 *
 * A storefront is indexable once it is switched on and may sell: legal texts approved and the currency it trades in present
 * ({@see StorefrontSellingGate}). A market being prepared is reachable to whoever is preparing it and invisible to a crawler.
 * While storefronts are switched off every answer is what the page carries today.
 */
#[Singleton]
final class StorefrontSeo
{
    /** What an offer was published with before a storefront could have money of its own, and still is wherever the platform's own settings stand. */
    private const PLATFORM_OFFER_DECIMALS = 2;

    public function __construct(
        private readonly StorefrontUrl $urls,
        private readonly StorefrontCurrency $currency,
        private readonly StorefrontSellingGate $sellingGate,
        private readonly SitemapHreflangGenerator $hreflang,
    ) {
    }

    /** The address a page asks search engines to treat as its own: its address in the storefront that served it. */
    public function canonical(string $url, ?string $country = null): string
    {
        return $this->urls->of(url: $url, country: $country);
    }

    /**
     * The same page in every storefront that carries it and every published language.
     *
     * $carriedBy is the caller's answer to *which markets carry this page*, never *which markets may be indexed*: a market whose
     * catalogue does not hold a product serves no page for it, so an hreflang naming it points a crawler at a redirect, and the
     * x-default built from the first of them would send every unmatched language there. It has no default, because defaulting it to
     * the indexing gate is exactly the mistake: {@see countriesCarrying()} answers it for a product from the catalogue, and a page
     * every market has of its own — a landing page — passes {@see indexableCountries()} deliberately.
     *
     * One market carrying the page keeps the language-only alternates the page carries today, down to the key and the query string,
     * at that market's own address: the bare address for the default storefront and while the markets are switched off.
     *
     * @param list<string> $languageCodes
     * @param list<string> $carriedBy the markets whose catalogue holds this page
     *
     * @return array<string, string> hreflang => absolute URL
     */
    public function alternates(string $url, array $languageCodes, array $carriedBy): array
    {
        $languages = array_values(array_filter(array_map(strval(...), $languageCodes), static fn (string $code): bool => trim($code) !== ''));
        $countries = array_values($carriedBy);

        if ($countries === []) {
            return [];
        }

        if (count($countries) === 1) {
            return count($languages) > 1
                ? $this->languageOnly(url: $this->urls->of(url: $url, country: $countries[0]), languages: $languages)
                : [];
        }

        $alternates = [];

        foreach ($countries as $country) {
            $address = $this->urls->of(url: $url, country: $country);

            foreach ($languages as $language) {
                // One rule for what a valid hreflang is, the one the sitemap markup already applies, so the page head and the sitemap
                // cannot disagree and a language code the store was given cannot produce a tag a crawler rejects.
                $tag = $this->hreflang->languageCode($language . '-' . $country);

                if ($tag !== null) {
                    $alternates[$tag] = $this->withLanguage(url: $address, language: $language);
                }
            }
        }

        return $alternates;
    }

    /**
     * The markets that carry one product's page, out of the markets a crawler may be told about.
     *
     * Asked of the catalogue, through the same narrowing the storefront's own pages use ({@see StorefrontCatalog::offers()}), so the
     * markets the page declares and the markets whose shop can show the product are one answer rather than two that drift. It reads
     * the product's own country in each market's context and makes no query of its own.
     *
     * @param list<string>|null $countries the markets to choose from; the indexable ones when the caller does not say
     *
     * @return list<string>
     */
    public function countriesCarrying(?Model $product, ?array $countries = null): array
    {
        $countries = array_values($countries ?? $this->indexableCountries());
        $resolver = StorefrontResolver::fromConfig();

        if ($product === null || !$resolver->isEnabled()) {
            return $countries;
        }

        return array_values(array_filter(
            $countries,
            static fn (string $country): bool => (bool) $resolver->contextOfRecordedCountry(countryCode: $country)
                ?->serve(callback: static fn (): bool => StorefrontCatalog::offers(product: $product)),
        ));
    }

    /**
     * The price and the currency a storefront states for an amount, as a schema.org offer carries them. While a storefront trades in
     * its own currency these are the two values the page prints and the customer API answers, because all three read
     * {@see StorefrontCurrency::money()}, and the precision travels with them: a dinar price published to hundredths is a price
     * nobody can pay.
     *
     * While the platform's own currency settings stand — the markets switched off, or a request served from the default storefront —
     * this is the offer the page has always published: the amount as it is stored, the platform's default currency and the two
     * decimals {@see StructuredDataBuilder} has always rounded to. Under the multi-currency model that number is not the number the
     * page prints, and that disagreement is older than the markets; correcting it would move the live Saudi page's published price,
     * so it is pinned where it can be seen rather than changed here
     * ({@see \Tests\Feature\Storefront\StorefrontMoneyAndSeoTest::test_the_published_offer_still_disagrees_with_the_printed_price_under_the_multi_currency_model}).
     *
     * @return array{price: float|string|int|null, currency: string, decimals: int}
     */
    public function offer(string|int|float|null $amount): array
    {
        if ($this->currency->currencyCodeInForce() === null) {
            return ['price' => $amount, 'currency' => (string) getCurrencyCode(), 'decimals' => self::PLATFORM_OFFER_DECIMALS];
        }

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
     * The same page in one language. A storefront's landing page has no path of its own, so the separator is kept rather than hanging the
     * query off the host; every other page already has one and is left exactly as it was.
     */
    private function withLanguage(string $url, string $language): string
    {
        $separator = (string) parse_url($url, PHP_URL_PATH) === '' ? '/' : '';

        return $url . $separator . '?language=' . $language;
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
            $alternates[$language] = $this->withLanguage(url: $url, language: $language);
        }

        return $alternates;
    }
}
