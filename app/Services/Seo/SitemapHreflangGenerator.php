<?php

namespace App\Services\Seo;

/**
 * Generates sitemap XML with hreflang alternates (Phase 1.6 technical SEO).
 *
 * The existing sitemap output lists URLs with no language signals, so search engines can't tell
 * that the Arabic and English versions of a page are the same content — they compete instead of
 * being grouped. This emits the `xhtml:link rel="alternate"` set Google expects.
 *
 * Pure string building (no DB, no HTTP), so the XML shape is unit-testable. Callers supply the
 * entries; this owns correctness of the markup.
 */
class SitemapHreflangGenerator
{
    private const XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const XMLNS_XHTML = 'http://www.w3.org/1999/xhtml';

    /**
     * @param array<int, array{
     *     loc:string,
     *     alternates?:array<string,string>,
     *     lastmod?:string|null,
     *     changefreq?:string|null,
     *     priority?:float|string|null
     * }> $entries
     */
    public function generate(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="' . self::XMLNS . '" xmlns:xhtml="' . self::XMLNS_XHTML . '">' . "\n";

        foreach ($entries as $entry) {
            $loc = $this->url($entry['loc'] ?? '');
            if ($loc === null) {
                continue; // skip entries without a usable absolute URL rather than emit invalid XML
            }

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $this->esc($loc) . "</loc>\n";

            // Every alternate set must ALSO reference the page itself — Google requires the
            // grouping to be reciprocal and self-referential, or it ignores the annotations.
            $alternates = $entry['alternates'] ?? [];
            if (is_array($alternates) && $alternates !== []) {
                $emitted = [];
                foreach ($alternates as $lang => $href) {
                    $hrefUrl = $this->url((string) $href);
                    $langCode = $this->languageCode((string) $lang);
                    if ($hrefUrl === null || $langCode === null || isset($emitted[$langCode])) {
                        continue;
                    }
                    $emitted[$langCode] = true;
                    $xml .= '    <xhtml:link rel="alternate" hreflang="' . $this->esc($langCode)
                        . '" href="' . $this->esc($hrefUrl) . '"/>' . "\n";
                }

                // x-default points at the fallback for unmatched languages.
                $default = $entry['x_default'] ?? reset($alternates);
                $defaultUrl = is_string($default) ? $this->url($default) : null;
                if ($defaultUrl !== null) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                        . $this->esc($defaultUrl) . '"/>' . "\n";
                }
            }

            if (!empty($entry['lastmod'])) {
                $xml .= '    <lastmod>' . $this->esc((string) $entry['lastmod']) . "</lastmod>\n";
            }
            if (!empty($entry['changefreq'])) {
                $xml .= '    <changefreq>' . $this->esc((string) $entry['changefreq']) . "</changefreq>\n";
            }
            if (isset($entry['priority']) && is_numeric($entry['priority'])) {
                $xml .= '    <priority>' . number_format((float) $entry['priority'], 1) . "</priority>\n";
            }

            $xml .= "  </url>\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    /**
     * Normalize a language code to the hreflang form: lowercase language, uppercase region
     * ("ar-kw" -> "ar-KW"). 6Valley stores country-ish codes such as "sa"/"sy", which are passed
     * through as-is; mapping those to real locales is the caller's business decision.
     */
    public function languageCode(string $code): ?string
    {
        $code = trim(str_replace('_', '-', $code));
        if ($code === '' || !preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $code)) {
            return null;
        }

        $parts = explode('-', $code, 2);
        return count($parts) === 2
            ? strtolower($parts[0]) . '-' . strtoupper($parts[1])
            : strtolower($parts[0]);
    }

    /** Only absolute http(s) URLs belong in a sitemap. */
    private function url(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
