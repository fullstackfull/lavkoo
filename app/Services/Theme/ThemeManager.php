<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orchestrates the theme lifecycle (Phase 1.1): resolve effective settings, publish a version
 * atomically, and duplicate a version into a fresh draft (for editing / revisions).
 *
 * Publish and duplicate are transactional and non-destructive; settings resolution is a pure
 * deep-merge of baseline defaults with a version's overrides, so it is unit-testable without a DB.
 */
class ThemeManager
{
    /** Baseline theme configuration. A version's `settings` override any subset of these. */
    public function defaultSettings(): array
    {
        return [
            'branding' => [
                'logo' => null, 'mobile_logo' => null, 'favicon' => null,
                'logo_width' => 140, 'logo_height' => 40,
            ],
            // The storefront's design language: a violet -> blue gradient on a light, tinted
            // canvas. Every one of these is editable in Theme Settings; they are only the
            // starting point a fresh theme publishes with.
            'colors' => [
                'primary' => '#7B3FE4', 'secondary' => '#26ABF2', 'accent' => '#BF23CD',
                'background' => '#ffffff', 'surface' => '#F7F5FD', 'text' => '#14082E',
                'muted_text' => '#8B84A3', 'border' => '#E4DFF2',
                'success' => '#1E9E5A', 'warning' => '#d97706', 'error' => '#E23A3A',
            ],
            'typography' => [
                'heading_font' => 'inherit', 'body_font' => 'inherit',
                'heading_font_ar' => 'inherit', 'body_font_ar' => 'inherit',
                'base_font_size' => 16, 'line_height' => 1.6,
            ],
            'layout' => [
                'container_width' => 1200, 'page_spacing' => 24, 'border_radius' => 8,
                'button_style' => 'rounded', 'input_style' => 'outline', 'card_style' => 'shadow',
            ],
        ];
    }

    /** Effective settings for a version (defaults deep-merged with the version's overrides). */
    public function resolveSettings(?ThemeVersion $version): array
    {
        $overrides = $version?->settings ?? [];
        return $this->deepMerge($this->defaultSettings(), is_array($overrides) ? $overrides : []);
    }

    /** Create a theme together with an initial empty draft version. */
    public function createTheme(array $attributes, array $settings = []): Theme
    {
        return DB::transaction(function () use ($attributes, $settings) {
            $theme = Theme::create($attributes);
            ThemeVersion::create([
                'theme_id' => $theme->id,
                'label'    => 'Initial draft',
                'status'   => ThemeVersion::STATUS_DRAFT,
                'settings' => $settings ?: null,
            ]);

            // Home, header and footer exist for every theme from the moment it does — the clients
            // ask for them by name, so they are the engine's guarantee rather than the merchant's
            // first chore.
            app(ExperiencePageService::class)->ensureSystemPages($theme);

            return $theme->refresh();
        });
    }

    /** Make a theme the single active one (deactivates all others). Atomic. */
    public function activate(Theme $theme): Theme
    {
        $activated = DB::transaction(function () use ($theme) {
            Theme::query()
                ->where('id', '!=', $theme->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $theme->is_active = true;
            $theme->save();

            return $theme->refresh();
        });

        // AFTER the commit, and BOTH caches: flushing inside the transaction let a request racing
        // the commit re-cache the old theme, and forgetting only the web renderer left every
        // installed app on the previous theme for a full delivery TTL.
        app(StorefrontThemeRenderer::class)->flush();
        app(ThemeDelivery::class)->flush();

        // After the commit, like every audit line here: a rolled-back activation never happened,
        // and the trail must not say otherwise.
        app(AuditLogger::class)->record(action: 'theme.activated', subject: $activated);

        return $activated;
    }

    /** Publish a version: previous published version of the same theme is archived. Atomic. */
    public function publish(ThemeVersion $version, ?string $changeNote = null): ThemeVersion
    {
        $published = DB::transaction(function () use ($version, $changeNote) {
            ThemeVersion::query()
                ->where('theme_id', $version->theme_id)
                ->where('status', ThemeVersion::STATUS_PUBLISHED)
                ->where('id', '!=', $version->id)
                ->update(['status' => ThemeVersion::STATUS_ARCHIVED]);

            $version->status = ThemeVersion::STATUS_PUBLISHED;
            $version->published_at = now();

            // What this publish was for, in the merchant's words. Written here rather than on the
            // draft so the note describes what went live, and only when the column exists — a
            // deploy is not atomic with its migrations.
            $note = is_string($changeNote) ? trim($changeNote) : '';
            if ($note !== '' && Schema::hasColumn($version->getTable(), 'change_note')) {
                $version->change_note = mb_substr($note, 0, 300);
            }

            $version->save();

            // Whatever goes live is registered in Promotion -> Banners (blocks composed before the
            // smart link included), so Banner Setup always lists what the storefront shows.
            app(ThemeBannerLink::class)->syncVersion($version);

            // Stamp the revision and checksum a client syncs against INSIDE the publishing
            // transaction: a version must never be observable as live without an addressable
            // revision, or a phone would be told to refetch a page it cannot identify.
            app(ThemeDelivery::class)->stampVersion($version);

            // Both caches: the storefront's rendered structure and the app's negotiated payloads.


            return $version->refresh();
        });

        // Flushed AFTER the commit, never inside it: a request racing the commit could re-cache
        // the pre-publish page, and it would then be served for a full TTL.
        app(StorefrontThemeRenderer::class)->flush();
        app(ThemeDelivery::class)->flush();

        // Announced AFTER the commit, never inside it: a client woken by the beacon must find the
        // new revision, and a rolled-back transaction must announce nothing.
        app(ThemeSyncBeacon::class)->announce((int) ($published->revision ?: 1));

        // The system-wide trail (spec §49). Publishing is the action that changes what every
        // customer sees, so it is the one that must always answer "who, and when".
        app(AuditLogger::class)->record(
            action: 'theme.published',
            subject: $published,
            after: array_filter([
                'revision' => $published->revision,
                'label' => $published->label,
                'change_note' => $published->change_note ?? null,
            ], static fn ($value) => $value !== null),
            context: ['theme_id' => $published->theme_id],
        );

        return $published;
    }

    /** Duplicate a version (with its sections + blocks) into a new draft. */
    public function createDraftFrom(ThemeVersion $source, ?string $label = null): ThemeVersion
    {
        return DB::transaction(function () use ($source, $label) {
            $draft = ThemeVersion::create([
                'theme_id'            => $source->theme_id,
                'label'               => $label ?? ('Draft of #' . $source->id),
                'status'              => ThemeVersion::STATUS_DRAFT,
                'settings'            => $source->settings,
                'based_on_version_id' => $source->id,
            ]);

            foreach ($source->sections()->with('blocks')->get() as $section) {
                $newSection = ThemeSection::create([
                    'theme_version_id' => $draft->id,
                    'page'             => $section->page,
                    'type'             => $section->type,
                    'sort_order'       => $section->sort_order,
                    'is_visible'       => $section->is_visible,
                    'settings'         => $section->settings,
                    // The SAME uuid, deliberately: a draft's copy of a section is the same logical
                    // section, and clients key per-section state and checksums by this identity.
                    // Schedules and targeting travel too — a Ramadan schedule that silently
                    // vanished on the next edit would be worse than no scheduling at all.
                    ...$section->copyableDeliveryRules(keepUuid: true),
                ]);

                foreach ($section->blocks as $block) {
                    ThemeBlock::create([
                        'theme_section_id' => $newSection->id,
                        'type'             => $block->type,
                        'sort_order'       => $block->sort_order,
                        'is_visible'       => $block->is_visible,
                        'settings'         => $block->settings,
                    ]);
                }
            }

            return $draft;
        });
    }

    /**
     * Restore a previous version by copying it into a NEW draft (Phase 1 "restore revision").
     *
     * Deliberately non-destructive: it never mutates or deletes the archived version, and never
     * publishes directly. The merchant reviews the restored draft and publishes it explicitly, so a
     * mis-clicked restore can't change the live storefront.
     */
    public function restoreVersion(ThemeVersion $source): ThemeVersion
    {
        $draft = $this->createDraftFrom($source, 'Restored from #' . $source->id);

        app(AuditLogger::class)->record(
            action: 'theme.restored',
            subject: $draft,
            context: ['restored_from_version_id' => $source->id, 'theme_id' => $source->theme_id],
        );

        return $draft;
    }

    /** Version history for a theme, newest first — what the revision list renders. */
    public function versionHistory(int $themeId, int $limit = 30): \Illuminate\Support\Collection
    {
        return ThemeVersion::query()
            ->where('theme_id', $themeId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'label', 'status', 'based_on_version_id', 'published_at', 'created_at']);
    }

    /** The published version of the currently active theme, if any. */
    public function activeThemePublishedVersion(): ?ThemeVersion
    {
        $theme = Theme::query()->where('is_active', true)->first();
        return $theme?->publishedVersion()->first();
    }

    /** Deep-merge $override into $base; associative sub-arrays merge, everything else overrides. */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && $this->isAssoc($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
