<?php

namespace App\Services\Seo;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontUrl;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The sitemap of one storefront (B-17).
 *
 * A storefront lists its own products under its own addresses and nobody else's: a crawler told that a Kuwaiti product lives at the
 * platform's bare address would index it as the Saudi shop's. The products come from the catalogue narrowed to that storefront — the
 * same narrowing the storefront's own pages use, not a second query that could disagree — and the addresses from {@see StorefrontUrl}.
 *
 * A storefront that is not live gets no file and appears in nobody else's: {@see StorefrontSeo::indexableCountries()} decides, so this
 * and the robots header cannot disagree about which markets a crawler may see. What a product entry's hreflang set names is a second
 * question — which markets carry that product — and {@see StorefrontSeo::countriesCarrying()} answers it from the same narrowed
 * catalogue the entries themselves come from.
 *
 * The default storefront is not here. Its sitemap is the platform file the panel has always generated, with the addresses it has
 * always had; while storefronts are switched off that file is the only one, exactly as today.
 */
final class StorefrontSitemap
{
    private const CHANGE_FREQUENCY = 'weekly';

    private const PRIORITY = 0.8;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepo,
        private readonly StorefrontSeo $seo,
        private readonly StorefrontUrl $urls,
        private readonly SitemapHreflangGenerator $xml,
    ) {
    }

    /**
     * One sitemap for each indexable storefront other than the default one, keyed by file name. Empty while storefronts are switched
     * off and whenever no other market is live, so the panel writes exactly the one file it writes today.
     *
     * @return array<string, string> file name => sitemap XML
     */
    public function files(CarbonInterface $at, string $stamp): array
    {
        $files = [];

        foreach ($this->countries() as $country) {
            $files['sitemap-' . $stamp . '-' . $this->urls->pathOf(country: $country) . '.xml'] = $this->xml->generate(
                entries: $this->entriesOf(country: $country, at: $at),
            );
        }

        return $files;
    }

    /**
     * The indexable storefronts that keep a sitemap of their own: every live market except the default one, whose sitemap is the
     * platform file.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        $defaultCountry = StorefrontResolver::fromConfig()->defaultCountry();

        return array_values(array_filter(
            $this->seo->indexableCountries(),
            static fn (string $country): bool => $country !== $defaultCountry,
        ));
    }

    /**
     * What one storefront's sitemap holds: its own landing page and each of its own products, every address that storefront's own and
     * every entry grouped with the same page in the other live markets and languages.
     *
     * @return list<array{loc: string, alternates: array<string, string>, lastmod: string, changefreq: string, priority: float}>
     */
    public function entriesOf(string $country, CarbonInterface $at): array
    {
        $languageCodes = $this->languageCodes();
        // Asked once for the whole file: whether each market may sell is a database question, and a catalogue has many products.
        $indexable = $this->seo->indexableCountries();

        // Every market has a landing page of its own, so every indexable market carries this one.
        $entries = [$this->entry(url: route('home'), country: $country, languageCodes: $languageCodes, carriedBy: $indexable, at: $at)];

        foreach ($this->productsOf(country: $country) as $product) {
            $slug = (string) ($product->slug ?? '');

            if ($slug === '') {
                continue;
            }

            $entries[] = $this->entry(
                url: route('product', ['slug' => $slug]),
                country: $country,
                languageCodes: $languageCodes,
                // A product's page belongs to the markets whose catalogue holds it, which is not the same set as the markets that may
                // be indexed: declaring the second would advertise this product to a market whose own shop cannot show it.
                carriedBy: $this->seo->countriesCarrying(product: $product, countries: $indexable),
                at: $at,
            );
        }

        return $entries;
    }

    /**
     * The slugs the platform file lists: the default storefront's own products while storefronts are on, and every active product, as
     * today, while they are off.
     *
     * @return list<string>
     */
    public function defaultStorefrontSlugs(): array
    {
        return $this->productsOf(country: StorefrontResolver::fromConfig()->defaultCountry())
            ->pluck('slug')
            ->filter()
            ->map(strval(...))
            ->values()
            ->all();
    }

    /**
     * The products one storefront sells, read through the catalogue narrowed to it, so the sitemap can hold no product the
     * storefront's own pages would not show.
     *
     * @return Collection<int, Model>
     */
    private function productsOf(string $country): Collection
    {
        $context = StorefrontResolver::fromConfig()->contextOfRecordedCountry(countryCode: $country);

        if ($context === null) {
            return new Collection();
        }

        return $context->serve(callback: fn (): Collection => Collection::make(
            $this->productRepo->getWebListWithScope(scope: 'active', dataLimit: 'all'),
        ));
    }

    /**
     * @param list<string> $languageCodes
     * @param list<string> $carriedBy the markets whose catalogue holds this page
     *
     * @return array{loc: string, alternates: array<string, string>, lastmod: string, changefreq: string, priority: float}
     */
    private function entry(string $url, string $country, array $languageCodes, array $carriedBy, CarbonInterface $at): array
    {
        return [
            'loc' => $this->urls->of(url: $url, country: $country),
            'alternates' => $this->seo->alternates(url: $url, languageCodes: $languageCodes, carriedBy: $carriedBy),
            'lastmod' => $at->toAtomString(),
            'changefreq' => self::CHANGE_FREQUENCY,
            'priority' => self::PRIORITY,
        ];
    }

    /** @return list<string> */
    private function languageCodes(): array
    {
        $languages = getWebConfig('language');

        if (!is_iterable($languages)) {
            return [];
        }

        $codes = [];

        foreach ($languages as $language) {
            $code = $language['code'] ?? null;

            if ($code) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }
}
