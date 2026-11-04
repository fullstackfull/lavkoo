<?php

namespace App\View\Composers;

use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoTagRenderer;
use App\Services\Seo\StructuredDataBuilder;
use Illuminate\View\View;

/**
 * Supplies the storefront <head> SEO block (Phase 1.6).
 *
 * Opt-in by design: a theme includes `seo.head` where it wants the tags, passing the entity being
 * displayed. Nothing in the existing head markup is modified, so themes that don't include the
 * partial are completely unaffected — and duplicated tags are avoided because the theme author
 * decides placement.
 */
class SeoHeadComposer
{
    public function __construct(
        private readonly SeoMetaResolver       $resolver,
        private readonly SeoTagRenderer        $tags,
        private readonly StructuredDataBuilder $schema,
    )
    {
    }

    public function compose(View $view): void
    {
        $data = $view->getData();

        $seoableType = $data['seoableType'] ?? null;
        $seoableId   = (int) ($data['seoableId'] ?? 0);
        $entityType  = (string) ($data['entityType'] ?? 'page');
        $language    = (string) ($data['language'] ?? $this->currentLanguage());

        $meta = ($seoableType && $seoableId)
            ? $this->resolver->resolve($seoableType, $seoableId, $language, $entityType, $data['templateData'] ?? [])
            : $this->emptyMeta($language);

        $view->with([
            'seoTags'   => $this->tags->render(
                $meta,
                $data['alternates'] ?? [],
                [
                    'site_name' => $data['siteName'] ?? null,
                    'og_type'   => $entityType === 'product' ? 'product' : 'website',
                    'url'       => $data['currentUrl'] ?? null,
                ]
            ),
            'seoSchema' => $this->tags->renderJsonLd($data['schema'] ?? []),
            'seoMeta'   => $meta,
        ]);
    }

    private function currentLanguage(): string
    {
        try {
            return function_exists('getDefaultLanguage') ? (string) getDefaultLanguage() : 'en';
        } catch (\Throwable) {
            return 'en';
        }
    }

    private function emptyMeta(string $language): array
    {
        return [
            'title' => '', 'description' => '', 'keywords' => null, 'canonical_url' => null,
            'og_title' => '', 'og_description' => '', 'og_image' => null,
            'is_indexable' => true, 'is_followable' => true,
            'language_code' => $language, 'source' => [],
        ];
    }
}
