<?php

namespace App\Services\Seo;

/**
 * Builds schema.org structured data (Phase 1.6).
 *
 * Pure array building — no DB, no framework — so every graph shape is unit-testable and callers
 * decide what data to feed it. Invalid/empty inputs yield an empty array rather than a malformed
 * graph, because invalid schema is worse than none (Google penalises it).
 */
class StructuredDataBuilder
{
    private const CONTEXT = 'https://schema.org';

    /** Organization — site-wide identity. */
    public function organization(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $graph = [
            '@context' => self::CONTEXT,
            '@type'    => 'Organization',
            'name'     => $name,
        ];

        $this->setIfPresent($graph, 'url', $data['url'] ?? null);
        $this->setIfPresent($graph, 'logo', $data['logo'] ?? null);
        $this->setIfPresent($graph, 'description', $data['description'] ?? null);

        $sameAs = array_values(array_filter($data['social_links'] ?? [], fn ($v) => is_string($v) && $v !== ''));
        if ($sameAs !== []) {
            $graph['sameAs'] = $sameAs;
        }

        if (!empty($data['phone'])) {
            $graph['contactPoint'] = [
                '@type'       => 'ContactPoint',
                'telephone'   => (string) $data['phone'],
                'contactType' => 'customer service',
            ];
        }

        return $graph;
    }

    /** WebSite (+ SearchAction when a search URL template is supplied). */
    public function website(array $data): array
    {
        $url = trim((string) ($data['url'] ?? ''));
        if ($url === '') {
            return [];
        }

        $graph = [
            '@context' => self::CONTEXT,
            '@type'    => 'WebSite',
            'url'      => $url,
        ];
        $this->setIfPresent($graph, 'name', $data['name'] ?? null);

        if (!empty($data['search_url'])) {
            $graph['potentialAction'] = [
                '@type'       => 'SearchAction',
                'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => (string) $data['search_url']],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $graph;
    }

    /**
     * BreadcrumbList. Items without a name are skipped; an empty result returns [].
     * @param array<int, array{name:string, url?:string}> $items
     */
    public function breadcrumbs(array $items): array
    {
        $elements = [];
        $position = 1;

        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $element = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $name,
            ];
            $this->setIfPresent($element, 'item', $item['url'] ?? null);
            $elements[] = $element;
        }

        if ($elements === []) {
            return [];
        }

        return [
            '@context'        => self::CONTEXT,
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * Product with Offer, and AggregateRating/Review when review data exists.
     * Availability follows schema.org's InStock/OutOfStock vocabulary.
     */
    public function product(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $graph = [
            '@context' => self::CONTEXT,
            '@type'    => 'Product',
            'name'     => $name,
        ];

        $this->setIfPresent($graph, 'description', $data['description'] ?? null);
        $this->setIfPresent($graph, 'sku', $data['sku'] ?? null);
        $this->setIfPresent($graph, 'gtin', $data['gtin'] ?? null);
        $this->setIfPresent($graph, 'url', $data['url'] ?? null);

        $images = array_values(array_filter($data['images'] ?? [], fn ($v) => is_string($v) && $v !== ''));
        if ($images !== []) {
            $graph['image'] = $images;
        }

        if (!empty($data['brand'])) {
            $graph['brand'] = ['@type' => 'Brand', 'name' => (string) $data['brand']];
        }

        // Offer — only when a usable price is present.
        if (isset($data['price']) && is_numeric($data['price'])) {
            $offer = [
                '@type'         => 'Offer',
                'price'         => (string) round((float) $data['price'], 2),
                'priceCurrency' => (string) ($data['currency'] ?? 'USD'),
                'availability'  => !empty($data['in_stock'])
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ];
            $this->setIfPresent($offer, 'url', $data['url'] ?? null);
            $graph['offers'] = $offer;
        }

        // AggregateRating — requires both a rating and a positive count to be valid.
        $ratingValue = $data['rating'] ?? null;
        $reviewCount = (int) ($data['review_count'] ?? 0);
        if (is_numeric($ratingValue) && $reviewCount > 0) {
            $graph['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) round((float) $ratingValue, 2),
                'reviewCount' => $reviewCount,
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }

        $reviews = [];
        foreach ($data['reviews'] ?? [] as $review) {
            $author = trim((string) ($review['author'] ?? ''));
            $body   = trim((string) ($review['body'] ?? ''));
            if ($author === '' && $body === '') {
                continue;
            }
            $entry = ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $author ?: 'Customer']];
            $this->setIfPresent($entry, 'reviewBody', $body ?: null);
            if (isset($review['rating']) && is_numeric($review['rating'])) {
                $entry['reviewRating'] = [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) round((float) $review['rating'], 2),
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ];
            }
            $reviews[] = $entry;
        }
        if ($reviews !== []) {
            $graph['review'] = $reviews;
        }

        return $graph;
    }

    /** Store / LocalBusiness for a vendor shop page. */
    public function store(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $graph = [
            '@context' => self::CONTEXT,
            '@type'    => 'Store',
            'name'     => $name,
        ];

        $this->setIfPresent($graph, 'url', $data['url'] ?? null);
        $this->setIfPresent($graph, 'image', $data['image'] ?? null);
        $this->setIfPresent($graph, 'telephone', $data['phone'] ?? null);

        if (!empty($data['address'])) {
            $graph['address'] = ['@type' => 'PostalAddress', 'streetAddress' => (string) $data['address']];
            $this->setIfPresent($graph['address'], 'addressCountry', $data['country'] ?? null);
            $this->setIfPresent($graph['address'], 'addressLocality', $data['city'] ?? null);
        }

        return $graph;
    }

    private function setIfPresent(array &$target, string $key, mixed $value): void
    {
        if (is_scalar($value) && trim((string) $value) !== '') {
            $target[$key] = (string) $value;
        }
    }
}
