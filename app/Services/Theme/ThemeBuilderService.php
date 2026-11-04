<?php

namespace App\Services\Theme;

use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Mutations the visual Theme Builder performs on a DRAFT version (Phase 1.2).
 *
 * Guard rail: every mutation refuses to touch a published version. Editing is always done on a
 * draft, and going live is an explicit publish — that is what makes the
 * draft -> preview -> publish workflow safe (the live storefront can never be edited by accident).
 */
class ThemeBuilderService
{
    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly SectionReadiness $readiness,
    )
    {
    }

    /** Thrown-free guard: is this version editable? */
    public function isEditable(ThemeVersion $version): bool
    {
        return $version->status === ThemeVersion::STATUS_DRAFT;
    }

    /** Append a section to a page, at the end of the current order. */
    public function addSection(ThemeVersion $version, string $page, string $type, array $settings = []): ?ThemeSection
    {
        if (!$this->isEditable($version) || !$this->registry->has($type)) {
            return null;
        }

        $nextOrder = (int) ThemeSection::where('theme_version_id', $version->id)
            ->where('page', $page)->max('sort_order');

        $section = ThemeSection::create([
            'theme_version_id' => $version->id,
            'page'             => $page,
            'type'             => $type,
            'sort_order'       => $nextOrder + 1,
            'is_visible'       => true,
            'settings'         => $this->registry->normalizeSettings($type, $settings),
            // The page row beside the slug: the slug is what renders, the id is what lets the page
            // be renamed or disabled later without touching a section. Column-guarded like every
            // other newer field here — the builder runs against databases this migration has not
            // reached, and against the hand-built schemas the tests stand up.
            ...$this->pageReference($version, $page),
        ]);

        app(AuditLogger::class)->record(
            action: 'theme.section_added',
            subject: $section,
            after: ['type' => $type, 'page' => $page],
            context: ['theme_version_id' => $version->id],
        );

        return $section;
    }

    /** Update a section's settings (normalized against its schema — unknown keys are dropped). */
    public function updateSection(ThemeSection $section, array $settings): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }

        $before = $section->settings;
        $section->settings = $this->registry->normalizeSettings($section->type, $settings);
        $saved = $section->save();

        if ($saved) {
            app(AuditLogger::class)->record(
                action: 'theme.section_updated',
                subject: $section,
                before: ['settings' => $before],
                after: ['settings' => $section->settings],
            );
        }

        return $saved;
    }

    /**
     * A section's delivery rules: when it runs, and who it runs for.
     *
     * Everything normalizes to "no restriction" rather than erroring: an empty date clears the
     * bound, an unknown platform token is dropped, and an end before the start clears BOTH dates —
     * a window that can never open would silently hide the section, which is precisely the state
     * these rules exist to make impossible to reach by accident.
     *
     * @param  array{starts_at?: ?string, ends_at?: ?string, platforms?: mixed, audience?: mixed, channels?: mixed}  $rules
     */
    public function setDeliveryRules(ThemeSection $section, array $rules): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }

        // Column-guarded like the copy paths: the builder can run against a database the delivery
        // migration has not reached yet, and saving must not become the thing that 500s it.
        if (!\Illuminate\Support\Facades\Schema::hasColumn($section->getTable(), 'starts_at')) {
            return true;
        }

        $startsAt = $this->parseRuleTime($rules['starts_at'] ?? null);
        $endsAt = $this->parseRuleTime($rules['ends_at'] ?? null);
        if ($startsAt !== null && $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            $startsAt = $endsAt = null;
        }

        $section->starts_at = $startsAt;
        $section->ends_at = $endsAt;
        $before = [
            'starts_at' => $section->getOriginal('starts_at'),
            'ends_at'   => $section->getOriginal('ends_at'),
            'platforms' => $section->getOriginal('platforms'),
            'audience'  => $section->getOriginal('audience'),
            'channels'  => $section->getOriginal('channels'),
        ];

        $section->platforms = $this->ruleTokens(
            $rules['platforms'] ?? null,
            [...\App\Services\Theme\ViewerContext::PLATFORMS, ...\App\Services\Theme\ViewerContext::DEVICES],
        );
        // Audience admits guest/customer AND segment keys (Phase 3.4) — one union list. Validated
        // against every key ON RECORD (allKeys, not the serving list): a save made while the
        // commerce engine is off, or before its migration ran, must never strip a segment token
        // and silently widen the section to everyone. Tokens the section already carried are
        // preserved for the same reason — dropping what this save cannot re-validate is a
        // decision, and it is not this method's to make.
        $previous = array_filter(
            is_array($section->getOriginal('audience')) ? $section->getOriginal('audience') : [],
            'is_string',
        );
        $section->audience = $this->ruleTokens($rules['audience'] ?? null, array_unique([
            ...\App\Services\Theme\ViewerContext::AUDIENCES,
            ...app(\App\Services\Commerce\SegmentResolver::class)->allKeys(),
            ...$previous,
        ]));

        // Only written where the column exists — the channels migration is newer than the rest of
        // the delivery rules, and a builder running ahead of it must still save the others.
        if (\Illuminate\Support\Facades\Schema::hasColumn($section->getTable(), 'channels')) {
            $section->channels = $this->ruleTokens($rules['channels'] ?? null, Channel::ALL);
        }

        $saved = $section->save();

        if ($saved) {
            app(AuditLogger::class)->record(
                action: 'theme.delivery_rules_updated',
                subject: $section,
                before: $before,
                after: $this->deliverySummary($section),
            );
        }

        return $saved;
    }

    /**
     * The page row a new section belongs to, as a spreadable attribute list.
     *
     * Empty when the column does not exist yet, or when the theme has no row for this slug — both
     * mean "resolve by slug", which is what every reader already does.
     *
     * @return array<string, int|null>
     */
    private function pageReference(ThemeVersion $version, string $page): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn((new ThemeSection())->getTable(), 'experience_page_id')) {
            return [];
        }

        return ['experience_page_id' => app(ExperiencePageService::class)->idFor($version->theme_id, $page)];
    }

    private function parseRuleTime(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A submitted rule list reduced to known tokens; empty (= no restriction) is stored as null so
     * an untouched section and a cleared one are the same row.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>|null
     */
    private function ruleTokens(mixed $value, array $allowed): ?array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return null;
        }

        $tokens = array_values(array_intersect(
            array_map(fn ($token) => is_string($token) ? trim($token) : '', $value),
            $allowed,
        ));

        return $tokens === [] ? null : $tokens;
    }

    public function setSectionVisibility(ThemeSection $section, bool $visible): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }
        $section->is_visible = $visible;
        return $section->save();
    }

    /**
     * Reorder a page's sections to match $orderedIds. Ids not belonging to this version/page are
     * ignored, and any section omitted from the list keeps a stable position after the listed ones,
     * so a partial payload can never silently drop a section.
     */
    public function reorderSections(ThemeVersion $version, string $page, array $orderedIds): bool
    {
        if (!$this->isEditable($version)) {
            return false;
        }

        DB::transaction(function () use ($version, $page, $orderedIds) {
            $sections = ThemeSection::where('theme_version_id', $version->id)
                ->where('page', $page)->orderBy('sort_order')->get();

            $byId = $sections->keyBy('id');
            $order = 1;

            foreach ($orderedIds as $id) {
                $section = $byId->get((int) $id);
                if ($section) {
                    $section->sort_order = $order++;
                    $section->save();
                    $byId->forget((int) $id);
                }
            }
            // anything not mentioned keeps its relative order, appended after the listed ones
            foreach ($byId as $section) {
                $section->sort_order = $order++;
                $section->save();
            }

            return true;
        });

        app(AuditLogger::class)->record(
            action: 'theme.sections_reordered',
            subject: $version,
            after: ['page' => $page, 'order' => array_values(array_map('intval', $orderedIds))],
        );

        return true;
    }

    /** Duplicate a section (with its blocks) directly after the original. */
    public function duplicateSection(ThemeSection $section): ?ThemeSection
    {
        if (!$this->isEditable($section->version)) {
            return null;
        }

        return DB::transaction(function () use ($section) {
            // push following sections down to make room
            ThemeSection::where('theme_version_id', $section->theme_version_id)
                ->where('page', $section->page)
                ->where('sort_order', '>', $section->sort_order)
                ->increment('sort_order');

            $copy = ThemeSection::create([
                'theme_version_id' => $section->theme_version_id,
                'page'             => $section->page,
                'type'             => $section->type,
                'sort_order'       => $section->sort_order + 1,
                'is_visible'       => $section->is_visible,
                'settings'         => $section->settings,
                // No uuid: an explicit duplicate is a NEW section, so the model mints a fresh
                // identity — unlike a version draft, where the copy stays the same section. The
                // merchant duplicating a scheduled campaign banner expects the schedule though.
                ...$section->copyableDeliveryRules(keepUuid: false),
            ]);

            foreach ($section->blocks as $block) {
                ThemeBlock::create([
                    'theme_section_id' => $copy->id,
                    'type'             => $block->type,
                    'sort_order'       => $block->sort_order,
                    'is_visible'       => $block->is_visible,
                    'settings'         => $block->settings,
                ]);
            }

            return $copy;
        });
    }

    /** Delete a section and close the gap in the ordering. */
    public function deleteSection(ThemeSection $section): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }

        $identity = ['id' => $section->id, 'type' => $section->type, 'page' => $section->page];

        $deleted = DB::transaction(function () use ($section) {
            $versionId = $section->theme_version_id;
            $page = $section->page;
            $order = $section->sort_order;

            $section->delete();

            ThemeSection::where('theme_version_id', $versionId)
                ->where('page', $page)
                ->where('sort_order', '>', $order)
                ->decrement('sort_order');

            return true;
        });

        if ($deleted) {
            app(AuditLogger::class)->record(
                action: 'theme.section_deleted',
                subject: ['type' => \App\Models\ThemeSection::class, 'id' => $identity['id']],
                before: $identity,
            );
        }

        return $deleted;
    }

    // -----------------------------------------------------------------------------------------
    // Blocks — the repeatable children of a section (hero slides, promo tiles, footer columns).
    //
    // Same guard rails as sections: draft-only, schema-normalized settings, and a type must be one
    // the parent section actually declares, so a crafted payload cannot graft a footer column into
    // a hero carousel.
    // -----------------------------------------------------------------------------------------

    /** Append a block to a section. Returns null when the section is not editable or rejects the type. */
    public function addBlock(ThemeSection $section, string $type, array $settings = []): ?ThemeBlock
    {
        if (!$this->isEditable($section->version) || !$this->registry->hasBlockType($section->type, $type)) {
            return null;
        }

        if ($section->blocks()->count() >= SectionRegistry::MAX_BLOCKS_PER_SECTION) {
            return null;
        }

        $nextOrder = (int) ThemeBlock::where('theme_section_id', $section->id)->max('sort_order');

        return ThemeBlock::create([
            'theme_section_id' => $section->id,
            'type'             => $type,
            'sort_order'       => $nextOrder + 1,
            'is_visible'       => true,
            'settings'         => $this->registry->normalizeBlockSettings($type, $settings),
        ]);
    }

    public function updateBlock(ThemeBlock $block, array $settings): bool
    {
        if (!$block->section || !$this->isEditable($block->section->version)) {
            return false;
        }

        // Captured before the overwrite: the smart link pushes builder edits into the linked
        // Banner Setup row, and only a diff against these can say WHICH fields this save changed.
        $previousSettings = $block->settings ?? [];

        $block->settings = $this->registry->normalizeBlockSettings($block->type, $settings);
        $saved = $block->save();

        // A banner image uploaded straight in the builder is registered in Promotion -> Banners
        // and linked back — and edits to an already-linked block travel into its Theme Banner row,
        // so Banner Setup and the builder stay two views of one record (see ThemeBannerLink).
        if ($saved) {
            app(ThemeBannerLink::class)->syncBlock($block, $previousSettings);
        }

        return $saved;
    }

    public function setBlockVisibility(ThemeBlock $block, bool $visible): bool
    {
        if (!$block->section || !$this->isEditable($block->section->version)) {
            return false;
        }

        $block->is_visible = $visible;
        return $block->save();
    }

    /** Reorder a section's blocks; ids not belonging to it are ignored, omitted ones keep their tail order. */
    public function reorderBlocks(ThemeSection $section, array $orderedIds): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }

        return DB::transaction(function () use ($section, $orderedIds) {
            $byId = ThemeBlock::where('theme_section_id', $section->id)
                ->orderBy('sort_order')->get()->keyBy('id');
            $order = 1;

            foreach ($orderedIds as $id) {
                $block = $byId->get((int) $id);
                if ($block) {
                    $block->sort_order = $order++;
                    $block->save();
                    $byId->forget((int) $id);
                }
            }
            foreach ($byId as $block) {
                $block->sort_order = $order++;
                $block->save();
            }

            return true;
        });
    }

    public function duplicateBlock(ThemeBlock $block): ?ThemeBlock
    {
        $section = $block->section;
        if (!$section || !$this->isEditable($section->version)) {
            return null;
        }

        if ($section->blocks()->count() >= SectionRegistry::MAX_BLOCKS_PER_SECTION) {
            return null;
        }

        return DB::transaction(function () use ($block, $section) {
            ThemeBlock::where('theme_section_id', $section->id)
                ->where('sort_order', '>', $block->sort_order)
                ->increment('sort_order');

            return ThemeBlock::create([
                'theme_section_id' => $section->id,
                'type'             => $block->type,
                'sort_order'       => $block->sort_order + 1,
                'is_visible'       => $block->is_visible,
                'settings'         => $block->settings,
            ]);
        });
    }

    public function deleteBlock(ThemeBlock $block): bool
    {
        $section = $block->section;
        if (!$section || !$this->isEditable($section->version)) {
            return false;
        }

        return DB::transaction(function () use ($block, $section) {
            $order = $block->sort_order;
            $block->delete();

            ThemeBlock::where('theme_section_id', $section->id)
                ->where('sort_order', '>', $order)
                ->decrement('sort_order');

            return true;
        });
    }

    /** The block list of one section, shaped for the builder panel. */
    public function getBlocks(ThemeSection $section): array
    {
        return $section->blocks()->orderBy('sort_order')->get()
            ->map(fn (ThemeBlock $block) => $this->presentBlock($block))
            ->all();
    }

    private function presentBlock(ThemeBlock $block): array
    {
        $settings = $block->settings ?? [];
        $imageKey = $this->registry->blockImageKey($block->type);
        $image = $imageKey ? ($settings[$imageKey] ?? null) : null;
        $label = $this->registry->blockLabel($block->type, $settings);

        // A banner-linked block previews its LINKED banner in the panel, same as it renders.
        if (!empty($settings['banner_id'])) {
            $linked = app(ThemeBannerLink::class)->cardOverrides([(int) $settings['banner_id']]);
            $linked = $linked[(int) $settings['banner_id']] ?? null;
            if ($linked) {
                $image = $linked['image'] ?: $image;
                $label = $linked['title'] ?: $label;
            }
        }

        return [
            'id'         => $block->id,
            'type'       => $block->type,
            'label'      => $label,
            'image'      => $image,
            'sort_order' => $block->sort_order,
            'is_visible' => $block->is_visible,
            'settings'   => $settings,
        ];
    }

    /** The full ordered structure of a page — what the builder's left panel renders. */
    public function getPageStructure(ThemeVersion $version, string $page): array
    {
        return ThemeSection::with('blocks')
            ->where('theme_version_id', $version->id)
            ->where('page', $page)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ThemeSection $s) => [
                'id'         => $s->id,
                'type'       => $s->type,
                'label'      => $this->registry->types()[$s->type]['label'] ?? $s->type,
                'sort_order' => $s->sort_order,
                'is_visible' => $s->is_visible,
                'settings'   => $s->settings,
                // For the structure panel's small badges: a scheduled or targeted section should
                // not look identical to one that always runs everywhere.
                'delivery'   => $this->deliverySummary($s),
                // Whether the customer app can draw this type at all — the per-section half of
                // the compatibility card, shown where the merchant is actually arranging sections.
                'app_safe'   => app(\App\Services\Theme\ComponentCapabilityRegistry::class)->isAppSafe($s->type),
                // The channels the merchant limited this section to, if any — the other half of
                // "will the app get this": one is what the app CAN draw, this is what it is ALLOWED
                // to draw. Empty means every channel.
                'channels'   => (array) ($s->channels ?? []),
                'accepts'    => $this->registry->blockTypesFor($s->type),
                // Whether this section will actually appear, decided by the same object the
                // storefront skips on. A section that will render nothing used to look exactly
                // like one that works — added, visible, and invisible on the site.
                'readiness'  => $this->readiness->verdict(
                    $s->type,
                    $this->registry->normalizeSettings($s->type, (array) ($s->settings ?? [])),
                    $s->blocks->map(fn (ThemeBlock $b) => ['settings' => $this->registry->normalizeBlockSettings($b->type, (array) ($b->settings ?? []))])->all(),
                ),
                'blocks'     => $s->blocks->map(fn (ThemeBlock $b) => $this->presentBlock($b))->all(),
            ])->all();
    }

    /**
     * The delivery rules as the builder edits them: ISO datetimes the datetime-local input can
     * hold, and plain token lists.
     *
     * @return array{starts_at: ?string, ends_at: ?string, platforms: array<int, string>, audience: array<int, string>, scheduled: bool, targeted: bool}
     */
    public function deliverySummary(ThemeSection $section): array
    {
        $platforms = is_array($section->platforms) ? $section->platforms : [];
        $audience = is_array($section->audience) ? $section->audience : [];
        $channels = is_array($section->channels) ? $section->channels : [];

        return [
            'starts_at' => $section->starts_at?->format('Y-m-d\TH:i'),
            'ends_at'   => $section->ends_at?->format('Y-m-d\TH:i'),
            'platforms' => $platforms,
            'audience'  => $audience,
            'channels'  => $channels,
            'scheduled' => $section->starts_at !== null || $section->ends_at !== null,
            'targeted'  => $platforms !== [] || $audience !== [] || $channels !== [],
        ];
    }
}
