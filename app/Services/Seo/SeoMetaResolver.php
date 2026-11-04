<?php

namespace App\Services\Seo;

use App\Models\SeoMetaInfo;
use App\Models\SeoMetaTranslation;
use App\Models\SeoTemplate;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the effective SEO metadata for an entity in a given language.
 *
 * Resolution chain (first non-empty wins, per field):
 *   1. per-language override  (seo_meta_translations)
 *   2. existing single-language row (seo_meta_info)   <- current behavior, untouched
 *   3. global template for the entity type + language (seo_templates)
 *
 * This ordering is what makes the feature additive: installs with no translations and no templates
 * resolve exactly as they do today.
 */
class SeoMetaResolver
{
    public function __construct(private readonly SeoTemplateRenderer $renderer)
    {
    }

    /**
     * @param  string $seoableType  FQCN of the entity (e.g. App\Models\Product)
     * @param  array<string, mixed> $templateData values for template placeholders
     * @return array{title:string, description:string, keywords:?string, canonical_url:?string,
     *               og_title:string, og_description:string, og_image:?string,
     *               is_indexable:bool, is_followable:bool, language_code:string, source:array<string,string>}
     */
    public function resolve(string $seoableType, int $seoableId, string $languageCode, string $entityType = 'product', array $templateData = []): array
    {
        $translation = $this->findTranslation($seoableType, $seoableId, $languageCode);
        $base        = $this->findBase($seoableType, $seoableId);
        $template    = $this->findTemplate($entityType, $languageCode);

        $source = [];

        $title = $this->firstNonEmpty([
            'translation' => $translation?->title,
            'base'        => $base?->title,
            'template'    => $this->renderer->render($template?->title_template, $templateData),
        ], $source, 'title');

        $description = $this->firstNonEmpty([
            'translation' => $translation?->description,
            'base'        => $base?->description,
            'template'    => $this->renderer->render($template?->description_template, $templateData),
        ], $source, 'description');

        return [
            'title'          => $title,
            'description'    => $description,
            'keywords'       => $translation?->keywords,
            'canonical_url'  => $translation?->canonical_url,
            'og_title'       => $translation?->og_title ?: $title,
            'og_description' => $translation?->og_description ?: $description,
            'og_image'       => $translation?->og_image ?: ($base?->image ?: null),
            'is_indexable'   => $translation?->is_indexable ?? true,
            'is_followable'  => $translation?->is_followable ?? true,
            'language_code'  => $languageCode,
            'source'         => $source,
        ];
    }

    /**
     * @param array<string, ?string> $candidates ordered by precedence
     * @param array<string, string>  $source     out-param recording which layer supplied the field
     */
    private function firstNonEmpty(array $candidates, array &$source, string $field): string
    {
        foreach ($candidates as $layer => $value) {
            if (is_string($value) && trim($value) !== '') {
                $source[$field] = $layer;
                return trim($value);
            }
        }
        $source[$field] = 'none';
        return '';
    }

    private function findTranslation(string $type, int $id, string $language): ?SeoMetaTranslation
    {
        if (!Schema::hasTable('seo_meta_translations')) {
            return null;
        }
        return SeoMetaTranslation::query()
            ->where('seoable_type', $type)
            ->where('seoable_id', $id)
            ->where('language_code', $language)
            ->first();
    }

    private function findBase(string $type, int $id): ?SeoMetaInfo
    {
        if (!Schema::hasTable('seo_meta_info')) {
            return null;
        }
        return SeoMetaInfo::query()
            ->where('seoable_type', $type)
            ->where('seoable_id', $id)
            ->first();
    }

    private function findTemplate(string $entityType, string $language): ?SeoTemplate
    {
        if (!Schema::hasTable('seo_templates')) {
            return null;
        }
        return SeoTemplate::query()
            ->where('entity_type', $entityType)
            ->where('language_code', $language)
            ->where('is_active', true)
            ->first();
    }
}
