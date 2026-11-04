<?php

namespace App\Services\Theme;

use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What is wrong with a version, asked before it becomes what every customer sees.
 *
 * Publishing is the one irreversible-feeling action in the builder: a draft is private and a
 * published version is the shop. Everything needed to catch a broken publish already existed — the
 * readiness rule knows a section will not render, the compatibility report knows the app will not
 * receive it — but nothing asked them at the moment it mattered, so a merchant found out by
 * looking at the live site, or did not find out at all.
 *
 * Two severities, and the line between them is who can fix it:
 *
 *   blocking — the merchant left something unset. The section will silently not appear, and one
 *              click in the panel they are already in resolves it. Publishing waits.
 *   warning  — the configuration is right and the world is not: no coupon is live, the app has no
 *              renderer for this type, the schedule has passed. Publishing proceeds; the merchant
 *              is told what they are shipping.
 *
 * Nothing here re-derives an answer that already has an owner. {@see SectionReadiness} decides
 * whether a section renders and {@see ThemeCompatibilityReport} decides what the app receives;
 * this decides what to do about it.
 */
class PublishValidator
{
    public const BLOCKING = 'blocking';
    public const WARNING  = 'warning';

    /** How long a verdict may lag the shop it describes. */
    private const CACHE_TTL = 300;

    private const CACHE_PREFIX = 'theme_publish_check_';

    public function __construct(
        private readonly SectionReadiness $readiness,
        private readonly SectionRegistry $registry,
        private readonly ComponentCapabilityRegistry $capabilities,
    ) {
    }

    /**
     * Every finding against a version.
     *
     * Cached, because answering costs a query per section and the two screens that ask are an admin
     * index listing every theme and a builder that reloads on every save. Editing a section drops
     * the entry outright ({@see forget()}, called from the section and block models), so a merchant
     * never reads a stale verdict about a fix they just made. Only the shop's own state — a coupon
     * expiring, a product selling out — may lag, and that only ever moves a warning.
     *
     * @return array{blocking: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function inspect(ThemeVersion $version): array
    {
        return Cache::remember(
            self::CACHE_PREFIX . $version->id,
            self::CACHE_TTL,
            fn () => $this->run($version),
        );
    }

    /** Drop a version's cached verdict — its sections have changed. */
    public static function forget(int|string|null $versionId): void
    {
        if ($versionId !== null) {
            Cache::forget(self::CACHE_PREFIX . $versionId);
        }
    }

    /**
     * @return array{blocking: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    private function run(ThemeVersion $version): array
    {
        $sections = ThemeSection::query()
            ->with('blocks')
            ->where('theme_version_id', $version->id)
            ->orderBy('page')
            ->orderBy('sort_order')
            ->get();

        $findings = [];

        foreach ($sections->where('is_visible', true) as $section) {
            foreach ($this->inspectSection($section) as $finding) {
                $findings[] = $finding;
            }
        }

        // A hidden section is not a problem: hiding one IS the merchant's fix for a section they
        // are not ready to publish, and flagging it would make the fix look like a new fault.

        foreach ($this->inspectPages($sections) as $finding) {
            $findings[] = $finding;
        }

        return [
            'blocking' => array_values(array_filter($findings, static fn (array $f) => $f['severity'] === self::BLOCKING)),
            'warnings' => array_values(array_filter($findings, static fn (array $f) => $f['severity'] === self::WARNING)),
        ];
    }

    /** Whether this version may go live as it stands. */
    public function passes(ThemeVersion $version): bool
    {
        return $this->inspect($version)['blocking'] === [];
    }

    // ---------------------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function inspectSection(ThemeSection $section): array
    {
        $types = $this->registry->types();

        // A type this server does not have is not a misconfiguration the merchant made: it is a
        // version authored against a different build (an import, or a downgrade). It renders
        // nothing anywhere, so it is the one thing worth stopping a publish over unconditionally.
        if (!isset($types[$section->type])) {
            return [$this->finding($section, self::BLOCKING,
                'this_section_type_does_not_exist_on_this_server_so_it_will_not_appear_anywhere',
                'remove_it_or_hide_it_before_publishing')];
        }

        $findings = [];
        $verdict = $this->readiness->verdict(
            $section->type,
            $section->settings ?? [],
            $section->blocks->where('is_visible', true)->map(fn ($block) => [
                'type' => $block->type,
                'settings' => $block->settings ?? [],
            ])->values()->all(),
        );

        if ($verdict['state'] === SectionReadiness::NEEDS_CHOICE) {
            $findings[] = $this->finding($section, self::BLOCKING, $verdict['reason_key'],
                'open_this_section_and_make_the_choice_or_hide_it');
        } elseif ($verdict['state'] !== SectionReadiness::READY) {
            $findings[] = $this->finding($section, self::WARNING, $verdict['reason_key'],
                'it_will_appear_on_its_own_once_there_is_something_to_show');
        }

        // A section sourced from a collection that no longer exists (or was switched off) would
        // fall into the generic "nothing to show" warning above — true, but useless: the fix is
        // not "add products", it is "this reference is broken". Say so by name.
        $source = ContentSource::fromSettings($section->settings ?? []);
        if ($source->kind === 'collection' && $source->id !== null) {
            $resolver = app(\App\Services\Commerce\CollectionResolver::class);
            if ($resolver->ready() && $resolver->enabled() && $resolver->find($source->id) === null) {
                $findings[] = $this->finding($section, self::WARNING,
                    'the_collection_this_section_is_sourced_from_was_deleted_or_disabled',
                    'pick_another_collection_or_another_source');
            }
        }

        if (!$this->capabilities->isAppSafe($section->type)) {
            $findings[] = $this->finding($section, self::WARNING,
                $this->capabilities->exclusionReason($section->type) ?? 'no_app_renderer_exists_for_this_section',
                'it_will_show_on_the_website_and_not_in_the_mobile_app');
        }

        if ($section->ends_at !== null && Carbon::now()->greaterThan($section->ends_at)) {
            $findings[] = $this->finding($section, self::WARNING,
                'this_sections_schedule_has_already_ended_so_it_will_not_appear',
                'clear_its_end_date_or_hide_it');
        }

        if ($this->reachesNoChannel($section)) {
            $findings[] = $this->finding($section, self::WARNING,
                'this_section_is_limited_to_a_channel_that_does_not_render_themes_yet',
                'add_the_website_or_the_customer_app_to_its_channels');
        }

        return $findings;
    }

    /**
     * Findings about a page as a whole rather than about one section on it.
     *
     * @param  \Illuminate\Support\Collection<int, ThemeSection>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function inspectPages($sections): array
    {
        $visibleHome = $sections->where('is_visible', true)->where('page', 'home');

        if ($sections->where('page', 'home')->isNotEmpty() && $visibleHome->isEmpty()) {
            // Not blocking: the storefront falls back to its built-in home, which is a working
            // page. But a merchant who hid their last section did not mean to publish that.
            return [[
                'severity'   => self::WARNING,
                'section_id' => null,
                'uuid'       => null,
                'type'       => null,
                'label'      => 'home',
                'page'       => 'home',
                'reason_key' => 'every_section_on_the_home_page_is_hidden_so_the_built_in_home_page_will_be_shown',
                'fix_key'    => 'show_at_least_one_section_to_publish_your_own_home_page',
            ]];
        }

        return [];
    }

    /**
     * A section limited to channels that nothing renders — visible in the builder, nowhere else.
     *
     * An empty or absent list means "every channel", which is the default and always reaches one.
     */
    private function reachesNoChannel(ThemeSection $section): bool
    {
        $channels = $section->channels ?? [];

        return $channels !== [] && array_intersect($channels, Channel::RENDERABLE) === [];
    }

    /** @return array<string, mixed> */
    private function finding(ThemeSection $section, string $severity, ?string $reasonKey, string $fixKey): array
    {
        return [
            'severity'   => $severity,
            'section_id' => $section->id,
            'uuid'       => $section->uuid,
            'type'       => $section->type,
            'label'      => $this->registry->types()[$section->type]['label'] ?? $section->type,
            'page'       => $section->page,
            'reason_key' => $reasonKey ?? 'this_section_will_not_appear',
            'fix_key'    => $fixKey,
        ];
    }
}
