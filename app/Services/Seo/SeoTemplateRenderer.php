<?php

namespace App\Services\Seo;

/**
 * Renders SEO templates containing {placeholders}.
 *
 * Pure and DB-free so it is fully unit-testable. Unknown placeholders resolve to an empty string
 * and the result is tidied (collapsed separators/whitespace), so a missing brand never leaves
 * "Name |  | Store" in a page title.
 */
class SeoTemplateRenderer
{
    /** Placeholders offered to admins per entity type (surfaced in the UI as available variables). */
    public const AVAILABLE_VARIABLES = [
        'product'  => ['product_name', 'brand_name', 'category_name', 'store_name', 'price', 'sku'],
        'category' => ['category_name', 'parent_category_name', 'store_name'],
        'brand'    => ['brand_name', 'store_name'],
        'vendor'   => ['vendor_name', 'shop_name', 'store_name'],
        'page'     => ['page_title', 'store_name'],
        'blog'     => ['blog_title', 'author_name', 'store_name'],
        'home'     => ['store_name', 'store_tagline'],
    ];

    /**
     * Substitute {placeholders} in $template from $data.
     *
     * @param array<string, mixed> $data
     */
    public function render(?string $template, array $data): string
    {
        if ($template === null || trim($template) === '') {
            return '';
        }

        $rendered = preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($m) use ($data) {
            $key = strtolower($m[1]);
            $value = $data[$key] ?? '';
            return is_scalar($value) ? trim((string) $value) : '';
        }, $template);

        return $this->tidy((string) $rendered);
    }

    /**
     * Clean up artifacts left by empty placeholders: repeated/leading/trailing separators and
     * collapsed whitespace.
     */
    private function tidy(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        // collapse repeated separators, e.g. "A |  | B" -> "A | B", "A - - B" -> "A - B"
        $value = preg_replace('/(\s*[|\-–—]\s*){2,}/u', ' | ', $value) ?? $value;
        // trim separators at both ends
        $value = preg_replace('/^(\s*[|\-–—]\s*)+|(\s*[|\-–—]\s*)+$/u', '', $value) ?? $value;
        return trim($value);
    }

    /**
     * Advisory length check for the admin SEO preview.
     * Google truncates titles around 60 and descriptions around 160 characters.
     *
     * @return array{length:int, status:string} status: ok | short | long
     */
    public function lengthAdvice(string $value, string $type = 'title'): array
    {
        $len = mb_strlen($value);
        [$min, $max] = $type === 'description' ? [70, 160] : [30, 60];

        $status = 'ok';
        if ($len === 0 || $len < $min) {
            $status = 'short';
        } elseif ($len > $max) {
            $status = 'long';
        }

        return ['length' => $len, 'status' => $status];
    }
}
