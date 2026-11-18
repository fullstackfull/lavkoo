<?php

namespace App\Services\Storefront;

use App\Models\BusinessPage;
use App\Models\StorefrontLegalDocument;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * The business pages a storefront shows (I2-04). The default storefront's pages are the platform's own and are never touched. In any other
 * storefront, a page that stands for a legal document type (gcc.storefronts.legal.page_slugs) shows that storefront's current text in the
 * language the visitor reads the site in, or is not shown at all: never another country's text, never the Saudi text as a stand-in, never
 * a text of another language. A page that stands for no legal document (the about page, the pages an admin adds) is the same everywhere.
 *
 * The page keeps its title, banner and publish switch; only its body is the storefront's text. The default storefront, storefronts that are
 * off, and any request that belongs to no storefront (console, panels) get the platform's own pages.
 */
final class StorefrontLegalPages
{
    /** The part of a list's cache key for a visitor whose language the storefront has no texts in. */
    private const NO_LOCALE = 'none';

    public function __construct(private readonly StorefrontLegalReadiness $legalReadiness)
    {
    }

    /** The page as the storefront shows it, or null when the storefront has no text of its own for it. A missing page stays missing. */
    public function shownInStorefront(?BusinessPage $page): ?BusinessPage
    {
        $country = $this->countryWithOwnTexts();

        return $page === null || $country === null ? $page : $this->withStorefrontText(page: $page, texts: $this->visibleTexts($country));
    }

    /**
     * @param Collection<int, BusinessPage> $pages the platform's published pages
     * @return Collection<int, BusinessPage> the pages the storefront shows
     */
    public function listShownInStorefront(Collection $pages): Collection
    {
        $country = $this->countryWithOwnTexts();
        if ($country === null) {
            return $pages;
        }

        $texts = $this->visibleTexts($country);

        return $pages->map(fn (BusinessPage $page) => $this->withStorefrontText(page: $page, texts: $texts))->filter()->values();
    }

    /** Each storefront and each language it serves has a list of its own. The default storefront keeps the key it has always had. */
    public function listCacheKey(): string
    {
        $country = $this->countryWithOwnTexts();

        return $country === null ? CACHE_FOR_BUSINESS_PAGES_LIST : storefrontCacheKey(key: $this->listKeyIn(locale: $this->visitorLocale($country)));
    }

    /** A list is stale from the moment a scheduled text takes effect, so a storefront's list is not kept past that moment. */
    public function listCacheLifetime(): int|Closure
    {
        $country = $this->countryWithOwnTexts();

        return $country === null ? CACHE_FOR_3_HOURS : fn (): int => $this->secondsBeforeListGoesStale(country: $country);
    }

    /** Every storefront's lists, in every language: the default storefront's own list is left alone. Nothing happens while storefronts are off. */
    public function forgetStorefrontLists(): void
    {
        if (!config('gcc.storefronts.enabled')) {
            return;
        }

        foreach ([...$this->everyServedLocale(), self::NO_LOCALE] as $locale) {
            cacheForgetInEveryStorefront(key: $this->listKeyIn(locale: $locale));
        }
    }

    /** The storefront whose own texts the request is shown, or null when the platform's pages apply: storefronts off, no storefront, the default one. */
    private function countryWithOwnTexts(): ?string
    {
        $country = StorefrontCatalog::country();

        return $country !== null && $country !== StorefrontResolver::fromConfig()->defaultCountry() ? $country : null;
    }

    /**
     * The language the visitor reads the site in: the language code of the session or of the API request ('en', or the code of a country's
     * flag such as 'sa'), then the language the platform shows that code in ('ar'). Only a language the storefront serves counts, so a
     * language the platform or the storefront does not know is not given another one.
     */
    private function visitorLocale(string $country): ?string
    {
        $languageCode = strtolower((string) getDefaultLanguage());
        $served = $this->localesOf($country);

        foreach ([$languageCode, $this->languageOfCountryCode($languageCode)] as $locale) {
            if ($locale !== null && in_array($locale, $served, true)) {
                return $locale;
            }
        }

        return null;
    }

    /** The language of the country whose flag the code is ('sa' is 'ar'), by the list of locales the platform keeps; null when it names no country. */
    private function languageOfCountryCode(string $code): ?string
    {
        foreach (getLanguageCodeList() as $locale) {
            $parts = explode('-', $locale);

            if (strtoupper($code) === strtoupper(end($parts))) {
                return $parts[0];
            }
        }

        return null;
    }

    /** @return list<string> the languages the storefront has legal texts in: the ones readiness requires of it */
    private function localesOf(string $country): array
    {
        $locales = config("gcc.storefronts.legal.locales_by_country.{$country}") ?? config('gcc.storefronts.legal.required_locales', []);

        return array_map(strtolower(...), (array) $locales);
    }

    /** @return list<string> */
    private function everyServedLocale(): array
    {
        $locales = (array) config('gcc.storefronts.legal.required_locales', []);
        foreach ((array) config('gcc.storefronts.legal.locales_by_country', []) as $countryLocales) {
            $locales = [...$locales, ...(array) $countryLocales];
        }

        return array_values(array_unique(array_map(strtolower(...), $locales)));
    }

    /** @return array<string, StorefrontLegalDocument> the texts the visitor can read, by document type */
    private function visibleTexts(string $country): array
    {
        $locale = $this->visitorLocale($country);

        return $locale === null ? [] : $this->legalReadiness->visibleTextsFor(countryCode: $country, locale: $locale);
    }

    /** @param array<string, StorefrontLegalDocument> $texts */
    private function withStorefrontText(BusinessPage $page, array $texts): ?BusinessPage
    {
        $documentType = array_search($page->slug, (array) config('gcc.storefronts.legal.page_slugs', []), true);

        if ($documentType === false) {
            return $page;
        }

        return isset($texts[$documentType]) ? $this->withBody(page: $page, body: $texts[$documentType]->body) : null;
    }

    /** A copy of the page that is never saved: the platform's own row, and the list cached for the default storefront, keep their text. */
    private function withBody(BusinessPage $page, string $body): BusinessPage
    {
        $shown = clone $page;
        $shown->setRawAttributes([...$page->getAttributes(), 'description' => $body], sync: true);

        return $shown;
    }

    private function listKeyIn(?string $locale): string
    {
        return CACHE_FOR_BUSINESS_PAGES_LIST . ':' . ($locale ?? self::NO_LOCALE);
    }

    private function secondsBeforeListGoesStale(string $country): int
    {
        $nextTextAt = $this->legalReadiness->nextTextTakesEffectAt(countryCode: $country);

        return $nextTextAt === null ? CACHE_FOR_3_HOURS : max(1, min(CACHE_FOR_3_HOURS, (int) ceil(CarbonImmutable::now()->diffInSeconds($nextTextAt))));
    }
}
