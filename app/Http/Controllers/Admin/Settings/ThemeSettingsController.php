<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\ThemeAsset;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemePermissionService;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\StorefrontThemeRenderer;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Global theme settings — branding, colors, typography, layout (Phase 1.2).
 *
 * ThemeManager already defined the settings schema and merge semantics, but there was no way for a
 * merchant to actually edit them, which made the whole global-settings feature unusable. This is
 * that editor.
 *
 * Settings are always written to a DRAFT version: the live storefront changes only on publish,
 * consistent with the rest of the builder.
 */
class ThemeSettingsController extends BaseController
{
    public function __construct(
        private readonly ThemeManager            $themeManager,
        private readonly StorefrontThemeRenderer $storefront,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $version = $this->resolveDraft($request?->get('version'));

        return view('admin-views.theme.settings', [
            'version'  => $version,
            'settings' => $this->themeManager->resolveSettings($version),
            'defaults' => $this->themeManager->defaultSettings(),
            'editable' => $version?->status === ThemeVersion::STATUS_DRAFT,
            // Uploaded images for this theme, so branding fields can be filled by clicking a
            // thumbnail instead of hand-copying a URL from the theme list.
            'assets'   => $this->themeAssets($version),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return back();
        }

        // The colours and type of every page at once — separable from arranging one section, so a
        // shop can let staff compose without letting them repaint the storefront.
        if (!app(ThemePermissionService::class)->canManageStyles()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_manage_global_styles') . '!');
            return back();
        }

        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return back();
        }

        // Editing a published version directly would change the live storefront with no review
        // step, so it is refused here exactly as in the builder.
        if ($version->status !== ThemeVersion::STATUS_DRAFT) {
            ToastMagic::error(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
            return back();
        }

        $version->settings = $this->sanitize(
            (array) $request->get('settings', []),
            $this->themeManager->defaultSettings()
        );
        $version->save();

        $this->storefront->flush();
        ToastMagic::success(translate('theme_settings_saved_successfully'));

        return back();
    }

    /**
     * Keep only keys that exist in the schema, and coerce each value to the default's type, so the
     * form can never write arbitrary structures into the settings JSON.
     */
    private function sanitize(array $input, array $schema): array
    {
        $clean = [];

        foreach ($schema as $group => $fields) {
            if (!is_array($fields) || !isset($input[$group]) || !is_array($input[$group])) {
                continue;
            }

            foreach ($fields as $key => $default) {
                if (!array_key_exists($key, $input[$group])) {
                    continue;
                }
                $value = $input[$group][$key];

                if (is_int($default)) {
                    $clean[$group][$key] = is_numeric($value) ? (int) $value : $default;
                } elseif (is_float($default)) {
                    $clean[$group][$key] = is_numeric($value) ? (float) $value : $default;
                } else {
                    $clean[$group][$key] = is_scalar($value) ? trim((string) $value) : $default;
                }
            }
        }

        return $clean;
    }

    /**
     * Images uploaded for the version's theme.
     *
     * Guarded by hasTable because theme_assets shipped in a later migration than themes: a partially
     * migrated installation must still be able to open theme settings.
     *
     * @return \Illuminate\Support\Collection<int, ThemeAsset>
     */
    private function themeAssets(?ThemeVersion $version): Collection
    {
        if (!$version || !Schema::hasTable('theme_assets')) {
            return collect();
        }

        try {
            return ThemeAsset::where('theme_id', $version->theme_id)->latest('id')->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /** The active theme's newest draft, creating one from the published version when needed. */
    private function resolveDraft(?string $versionId): ?ThemeVersion
    {
        if ($versionId) {
            return ThemeVersion::find($versionId);
        }

        $published = $this->themeManager->activeThemePublishedVersion();
        if ($published) {
            return ThemeVersion::where('theme_id', $published->theme_id)
                ->where('status', ThemeVersion::STATUS_DRAFT)
                ->latest('id')->first()
                ?: $this->themeManager->createDraftFrom($published);
        }

        return ThemeVersion::where('status', ThemeVersion::STATUS_DRAFT)->latest('id')->first();
    }
}
