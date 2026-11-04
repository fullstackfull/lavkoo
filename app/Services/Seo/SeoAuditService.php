<?php

namespace App\Services\Seo;

/**
 * Internal SEO analyzer (Phase 1.6).
 *
 * Reports concrete, actionable problems — never a meaningless score. Each issue carries a code, a
 * severity, the human explanation, and the recommended fix, so the admin UI can render guidance
 * rather than a number.
 *
 * Pure: it analyzes a plain array describing an entity, so it is fully unit-testable and can be run
 * over any source (a product, a category, a rendered page).
 */
class SeoAuditService
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_NOTICE   = 'notice';

    public function __construct(private readonly SeoTemplateRenderer $renderer)
    {
    }

    /**
     * @param array $entity  ['title','description','h1','canonical_url','images'=>[['alt'=>..]],
     *                        'is_indexable','schema'=>array,'url']
     * @param array $context ['duplicate_titles'=>[..], 'duplicate_descriptions'=>[..]]
     * @return array<int, array{code:string, severity:string, message:string, fix:string}>
     */
    public function analyze(array $entity, array $context = []): array
    {
        $issues = [];

        $title = trim((string) ($entity['title'] ?? ''));
        $desc  = trim((string) ($entity['description'] ?? ''));

        if ($title === '') {
            $issues[] = $this->issue('missing_title', self::SEVERITY_CRITICAL,
                'The page has no SEO title.',
                'Set an SEO title, or configure a title template for this entity type.');
        } else {
            $advice = $this->renderer->lengthAdvice($title, 'title');
            if ($advice['status'] === 'long') {
                $issues[] = $this->issue('title_too_long', self::SEVERITY_WARNING,
                    "The SEO title is {$advice['length']} characters; search engines truncate around 60.",
                    'Shorten the title so the important words appear first.');
            } elseif ($advice['status'] === 'short') {
                $issues[] = $this->issue('title_too_short', self::SEVERITY_NOTICE,
                    "The SEO title is only {$advice['length']} characters.",
                    'Add descriptive keywords; aim for roughly 30-60 characters.');
            }
        }

        if ($desc === '') {
            $issues[] = $this->issue('missing_description', self::SEVERITY_CRITICAL,
                'The page has no meta description.',
                'Write a description, or configure a description template for this entity type.');
        } else {
            $advice = $this->renderer->lengthAdvice($desc, 'description');
            if ($advice['status'] === 'long') {
                $issues[] = $this->issue('description_too_long', self::SEVERITY_WARNING,
                    "The meta description is {$advice['length']} characters; search engines truncate around 160.",
                    'Trim the description to roughly 70-160 characters.');
            } elseif ($advice['status'] === 'short') {
                $issues[] = $this->issue('description_too_short', self::SEVERITY_NOTICE,
                    "The meta description is only {$advice['length']} characters.",
                    'Expand it to roughly 70-160 characters.');
            }
        }

        if (trim((string) ($entity['h1'] ?? '')) === '') {
            $issues[] = $this->issue('missing_h1', self::SEVERITY_WARNING,
                'The page has no H1 heading.',
                'Add exactly one H1 that states what the page is about.');
        }

        if (trim((string) ($entity['canonical_url'] ?? '')) === '') {
            $issues[] = $this->issue('missing_canonical', self::SEVERITY_NOTICE,
                'No canonical URL is set.',
                'Set a canonical URL so duplicate/filtered variants consolidate into one page.');
        }

        $images = $entity['images'] ?? [];
        if (is_array($images)) {
            $missingAlt = 0;
            foreach ($images as $image) {
                $alt = is_array($image) ? ($image['alt'] ?? '') : '';
                if (trim((string) $alt) === '') {
                    $missingAlt++;
                }
            }
            if ($missingAlt > 0) {
                $issues[] = $this->issue('missing_image_alt', self::SEVERITY_WARNING,
                    "{$missingAlt} image(s) have no ALT text.",
                    'Describe each image in ALT text — it drives image search and accessibility.');
            }
        }

        if (array_key_exists('is_indexable', $entity) && !$entity['is_indexable']) {
            $issues[] = $this->issue('not_indexable', self::SEVERITY_CRITICAL,
                'This page is set to noindex, so it will not appear in search results.',
                'Remove noindex unless the page is intentionally hidden from search engines.');
        }

        if (empty($entity['schema'])) {
            $issues[] = $this->issue('missing_schema', self::SEVERITY_NOTICE,
                'No structured data (schema.org) is attached to this page.',
                'Add Product/Breadcrumb structured data to qualify for rich results.');
        }

        // Duplicates are detected by the caller (which can query across entities) and passed in.
        // Both sides are normalized: a stray trailing space must not defeat duplicate detection.
        $normalize = static fn (array $values): array => array_map(
            static fn ($v) => trim((string) $v),
            $values
        );
        $context['duplicate_titles'] = $normalize($context['duplicate_titles'] ?? []);
        $context['duplicate_descriptions'] = $normalize($context['duplicate_descriptions'] ?? []);

        if ($title !== '' && in_array($title, $context['duplicate_titles'] ?? [], true)) {
            $issues[] = $this->issue('duplicate_title', self::SEVERITY_WARNING,
                'Another page uses this exact SEO title.',
                'Make each title unique so pages do not compete with each other.');
        }
        if ($desc !== '' && in_array($desc, $context['duplicate_descriptions'] ?? [], true)) {
            $issues[] = $this->issue('duplicate_description', self::SEVERITY_NOTICE,
                'Another page uses this exact meta description.',
                'Write a distinct description for each page.');
        }

        return $issues;
    }

    /** Counts by severity, for an at-a-glance admin summary (not a vanity score). */
    public function summarize(array $issues): array
    {
        $summary = [self::SEVERITY_CRITICAL => 0, self::SEVERITY_WARNING => 0, self::SEVERITY_NOTICE => 0];
        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? self::SEVERITY_NOTICE;
            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }
        }
        $summary['total'] = count($issues);
        return $summary;
    }

    private function issue(string $code, string $severity, string $message, string $fix): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message, 'fix' => $fix];
    }
}
