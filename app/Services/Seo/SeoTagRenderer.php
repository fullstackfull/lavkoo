<?php

namespace App\Services\Seo;

/**
 * Builds the storefront <head> SEO tags from resolved metadata (Phase 1.6).
 *
 * Pure string building (no DB, no framework helpers) so the exact markup is unit-testable, and so
 * the storefront blade can render it with a single call. Everything is escaped; empty fields are
 * omitted entirely rather than emitted blank.
 */
class SeoTagRenderer
{
    /**
     * @param array $meta        output of SeoMetaResolver::resolve()
     * @param array $alternates  [language_code => absolute_url] for hreflang
     * @param array $options     ['site_name' => string, 'og_type' => string, 'url' => string]
     */
    public function render(array $meta, array $alternates = [], array $options = []): string
    {
        $lines = [];

        $title       = (string) ($meta['title'] ?? '');
        $description = (string) ($meta['description'] ?? '');

        if ($description !== '') {
            $lines[] = $this->tag('meta', ['name' => 'description', 'content' => $description]);
        }
        if (!empty($meta['keywords'])) {
            $lines[] = $this->tag('meta', ['name' => 'keywords', 'content' => (string) $meta['keywords']]);
        }

        // robots: only emit when it differs from the permissive default
        $robots = [];
        if (isset($meta['is_indexable']) && !$meta['is_indexable']) {
            $robots[] = 'noindex';
        }
        if (isset($meta['is_followable']) && !$meta['is_followable']) {
            $robots[] = 'nofollow';
        }
        if ($robots !== []) {
            $lines[] = $this->tag('meta', ['name' => 'robots', 'content' => implode(', ', $robots)]);
        }

        if (!empty($meta['canonical_url'])) {
            $lines[] = $this->tag('link', ['rel' => 'canonical', 'href' => (string) $meta['canonical_url']]);
        }

        // OpenGraph
        $ogTitle = (string) ($meta['og_title'] ?? $title);
        $ogDesc  = (string) ($meta['og_description'] ?? $description);
        if ($ogTitle !== '') {
            $lines[] = $this->tag('meta', ['property' => 'og:title', 'content' => $ogTitle]);
        }
        if ($ogDesc !== '') {
            $lines[] = $this->tag('meta', ['property' => 'og:description', 'content' => $ogDesc]);
        }
        if (!empty($meta['og_image'])) {
            $lines[] = $this->tag('meta', ['property' => 'og:image', 'content' => (string) $meta['og_image']]);
        }
        $lines[] = $this->tag('meta', ['property' => 'og:type', 'content' => (string) ($options['og_type'] ?? 'website')]);
        if (!empty($options['url'])) {
            $lines[] = $this->tag('meta', ['property' => 'og:url', 'content' => (string) $options['url']]);
        }
        if (!empty($options['site_name'])) {
            $lines[] = $this->tag('meta', ['property' => 'og:site_name', 'content' => (string) $options['site_name']]);
        }

        // Twitter card mirrors OG
        $lines[] = $this->tag('meta', ['name' => 'twitter:card', 'content' => !empty($meta['og_image']) ? 'summary_large_image' : 'summary']);

        // hreflang alternates (+ x-default on the first entry)
        $first = true;
        foreach ($alternates as $lang => $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $lines[] = $this->tag('link', ['rel' => 'alternate', 'hreflang' => (string) $lang, 'href' => $url]);
            if ($first) {
                $lines[] = $this->tag('link', ['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $url]);
                $first = false;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Canonical and hreflang only — the tags a theme that already writes its own meta is missing.
     *
     * 6Valley's storefront templates emit title/description/og/twitter/robots themselves, but no
     * canonical and no hreflang. Rendering the full block alongside them would duplicate half the
     * head, and duplicate canonical or og tags are worse than none: search engines treat conflicting
     * signals as untrustworthy. This lets a theme adopt exactly the part it lacks.
     *
     * Separate from render() rather than an option on it, so the existing method keeps its
     * behaviour and its tests unchanged.
     *
     * @param  array<string,string>  $alternates  language code => absolute URL
     */
    public function renderCanonicalAndAlternates(?string $canonicalUrl, array $alternates = []): string
    {
        $lines = [];

        if (!empty($canonicalUrl)) {
            $lines[] = $this->tag('link', ['rel' => 'canonical', 'href' => (string) $canonicalUrl]);
        }

        $first = true;
        foreach ($alternates as $lang => $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $lines[] = $this->tag('link', ['rel' => 'alternate', 'hreflang' => (string) $lang, 'href' => $url]);
            if ($first) {
                $lines[] = $this->tag('link', ['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $url]);
                $first = false;
            }
        }

        return implode("\n", $lines);
    }

    /** JSON-LD structured data. Returns '' for an empty payload so callers can echo unconditionally. */
    public function renderJsonLd(array $data): string
    {
        if ($data === []) {
            return '';
        }
        // JSON_HEX_TAG escapes < and > as < / >, so an embedded </script> in the data
        // cannot break out of the block. JSON parsers decode the escapes transparently.
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        if ($json === false) {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /** @param array<string,string> $attrs */
    private function tag(string $name, array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            $parts[] = $key . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $open = '<' . $name . ' ' . implode(' ', $parts);
        return $name === 'meta' || $name === 'link' ? $open . '>' : $open . '></' . $name . '>';
    }
}
