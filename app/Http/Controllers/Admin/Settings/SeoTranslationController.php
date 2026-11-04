<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\SeoMetaTranslation;
use App\Services\Seo\SeoMetaResolver;
use App\Services\Seo\SeoTemplateRenderer;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-entity, per-language SEO editor (Phase 1.6 gap).
 *
 * The bilingual tables, resolver, templates and tag renderer all shipped earlier, but there was no
 * way to actually enter per-language SEO — so the feature could not be used end to end. This is the
 * data-entry surface.
 *
 * Entity types are whitelisted (not taken from the request) so the polymorphic seoable_type can
 * never be set to an arbitrary class from user input.
 */
class SeoTranslationController extends BaseController
{
    /** Supported entities, mapped to their model class. The request may only name a key here. */
    private const ENTITY_TYPES = [
        'product'  => \App\Models\Product::class,
        'category' => \App\Models\Category::class,
        'brand'    => \App\Models\Brand::class,
        'vendor'   => \App\Models\Shop::class,
        'page'     => \App\Models\BusinessPage::class,
    ];

    public function __construct(
        private readonly SeoMetaResolver     $resolver,
        private readonly SeoTemplateRenderer $renderer,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $entityType = $this->resolveEntityType($request?->get('entity_type'));
        $entityId   = (int) ($request?->get('entity_id') ?? 0);
        $language   = (string) ($request?->get('language') ?? 'en');

        $existing = null;
        $resolved = null;
        if ($entityType && $entityId > 0) {
            $existing = SeoMetaTranslation::where('seoable_type', self::ENTITY_TYPES[$entityType])
                ->where('seoable_id', $entityId)
                ->where('language_code', $language)
                ->first();

            $resolved = $this->resolver->resolve(
                self::ENTITY_TYPES[$entityType], $entityId, $language, $entityType
            );
        }

        return view('admin-views.seo-settings.translations', [
            'entityTypes' => array_keys(self::ENTITY_TYPES),
            'entityType'  => $entityType,
            'entityId'    => $entityId ?: null,
            'language'    => $language,
            'languages'   => $this->languageOptions(),
            'existing'    => $existing,
            'resolved'    => $resolved,
            'titleAdvice' => $resolved ? $this->renderer->lengthAdvice($resolved['title'], 'title') : null,
            'descAdvice'  => $resolved ? $this->renderer->lengthAdvice($resolved['description'], 'description') : null,
            'recent'      => SeoMetaTranslation::orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return back();
        }

        $validated = $request->validate([
            'entity_type'    => ['required', 'string'],
            'entity_id'      => ['required', 'integer', 'min:1'],
            'language_code'  => ['required', 'string', 'max:10'],
            'title'          => ['nullable', 'string', 'max:191'],
            'description'    => ['nullable', 'string', 'max:1000'],
            'keywords'       => ['nullable', 'string', 'max:500'],
            'canonical_url'  => ['nullable', 'string', 'max:2048'],
            'og_title'       => ['nullable', 'string', 'max:191'],
            'og_description' => ['nullable', 'string', 'max:1000'],
            'og_image'       => ['nullable', 'string', 'max:2048'],
        ]);

        $entityType = $this->resolveEntityType($validated['entity_type']);
        if (!$entityType) {
            ToastMagic::error(translate('unsupported_entity_type') . '!');
            return back()->withInput();
        }

        SeoMetaTranslation::updateOrCreate(
            [
                'seoable_type'  => self::ENTITY_TYPES[$entityType],
                'seoable_id'    => $validated['entity_id'],
                'language_code' => $validated['language_code'],
            ],
            [
                'title'          => $validated['title'] ?? null,
                'description'    => $validated['description'] ?? null,
                'keywords'       => $validated['keywords'] ?? null,
                'canonical_url'  => $validated['canonical_url'] ?? null,
                'og_title'       => $validated['og_title'] ?? null,
                'og_description' => $validated['og_description'] ?? null,
                'og_image'       => $validated['og_image'] ?? null,
                'is_indexable'   => $request->boolean('is_indexable', true),
                'is_followable'  => $request->boolean('is_followable', true),
            ]
        );

        ToastMagic::success(translate('seo_saved_successfully'));

        return redirect()->route('admin.seo-settings.translations.index', [
            'entity_type' => $entityType,
            'entity_id'   => $validated['entity_id'],
            'language'    => $validated['language_code'],
        ]);
    }

    public function delete(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return back();
        }

        SeoMetaTranslation::where('id', $request['id'])->delete();
        ToastMagic::success(translate('seo_entry_deleted_successfully'));

        return back();
    }

    /** Only a whitelisted key resolves; anything else returns null. */
    private function resolveEntityType(?string $key): ?string
    {
        return $key !== null && array_key_exists($key, self::ENTITY_TYPES) ? $key : null;
    }

    private function languageOptions(): array
    {
        $languages = getWebConfig(name: 'pnc_language');
        if (is_array($languages) && $languages !== []) {
            return array_values(array_filter(array_map(
                fn ($l) => is_array($l) ? ($l['code'] ?? null) : (is_string($l) ? $l : null),
                $languages
            )));
        }
        return ['en', 'ar'];
    }
}
