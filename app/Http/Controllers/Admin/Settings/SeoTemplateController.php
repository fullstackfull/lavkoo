<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\SeoTemplate;
use App\Services\Seo\SeoTemplateRenderer;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin management for global SEO templates (Phase 1.6).
 *
 * Templates are the lowest-priority layer in the resolution chain, so editing them can never
 * override SEO an admin set explicitly on an entity — they only fill gaps.
 */
class SeoTemplateController extends BaseController
{
    public function __construct(private readonly SeoTemplateRenderer $renderer)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        return view('admin-views.seo-settings.templates', [
            'templates' => SeoTemplate::query()->orderBy('entity_type')->orderBy('language_code')->get(),
            'variables' => SeoTemplateRenderer::AVAILABLE_VARIABLES,
            'languages' => $this->languageOptions(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return back();
        }

        $validated = $request->validate([
            'entity_type'          => ['required', 'string', 'max:60'],
            'language_code'        => ['required', 'string', 'max:10'],
            'title_template'       => ['nullable', 'string', 'max:191'],
            'description_template' => ['nullable', 'string', 'max:1000'],
        ]);

        SeoTemplate::updateOrCreate(
            ['entity_type' => $validated['entity_type'], 'language_code' => $validated['language_code']],
            [
                'title_template'       => $validated['title_template'] ?? null,
                'description_template' => $validated['description_template'] ?? null,
                'is_active'            => $request->boolean('is_active', true),
            ]
        );

        ToastMagic::success(translate('seo_template_saved_successfully'));
        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return back();
        }

        SeoTemplate::where('id', $request['id'])->delete();
        ToastMagic::success(translate('seo_template_deleted_successfully'));

        return back();
    }

    /** Live preview of a template with sample data (AJAX from the admin page). */
    public function preview(Request $request)
    {
        $rendered = $this->renderer->render($request->get('template'), [
            'product_name'  => $request->get('sample', 'Sample Product'),
            'brand_name'    => 'Sample Brand',
            'category_name' => 'Sample Category',
            'store_name'    => getWebConfig(name: 'company_name') ?: 'Store',
        ]);

        return response()->json([
            'rendered' => $rendered,
            'advice'   => $this->renderer->lengthAdvice($rendered, $request->get('type', 'title')),
        ]);
    }

    /** Language options: the installed locales, falling back to en/ar. */
    private function languageOptions(): array
    {
        $languages = getWebConfig(name: 'pnc_language');
        if (is_array($languages) && $languages !== []) {
            return $languages;
        }
        return ['en', 'ar'];
    }
}
