<?php

namespace App\Services\Theme;

use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The pages an experience is made of.
 *
 * Everything here is written so that a shop whose database has not run the page migration yet
 * behaves exactly as it did before: every method is column-guarded and every failure answers
 * "there is no page row", which the callers already treat as "resolve by slug". That is what lets
 * this ship ahead of the UI that uses it.
 *
 * Pages are per theme, because a theme is what gets published, duplicated and rolled back. Two
 * themes may both have a `home` and they are two different pages with two different section lists.
 */
class ExperiencePageService
{
    /** Slug rules: what can safely live in a URL, a cache key and an API path segment. */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9\-]{0,58}[a-z0-9]$/';

    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    public function isReady(): bool
    {
        try {
            return Schema::hasTable('experience_pages');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every page of a theme, system pages first, in the merchant's order.
     *
     * @return Collection<int, ExperiencePage>
     */
    public function forTheme(int $themeId, bool $onlyEnabled = false): Collection
    {
        if (!$this->isReady()) {
            return collect();
        }

        return ExperiencePage::query()
            ->where('theme_id', $themeId)
            ->when($onlyEnabled, fn ($query) => $query->enabled())
            ->orderByRaw("CASE WHEN kind = ? THEN 0 ELSE 1 END", [ExperiencePage::KIND_SYSTEM])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The pages one channel can show, in the order the builder lists them.
     *
     * A shared page belongs to every channel: home, header and footer are one arrangement the web
     * and the app both read, and duplicating them per channel would be duplicating the engine —
     * the thing the whole design exists to avoid. A page created FOR a channel is that channel's
     * alone, which is what makes an app-only "Offers" screen possible without inventing a second
     * theme for it.
     *
     * Falls back to the guaranteed system slugs when the pages table has not been migrated yet, so
     * a mid-migration install keeps editing its home page.
     *
     * @return array<int, array{id: ?int, slug: string, title: string, kind: string, enabled: bool, channel: string}>
     */
    public function forChannel(int $themeId, string $channel): array
    {
        if (!$this->isReady()) {
            return array_map(static fn (string $slug) => [
                // No id: without the table there is no row to act on, and the screen offers only
                // the actions a system page has anyway.
                'id' => null, 'slug' => $slug, 'title' => ucfirst($slug),
                'kind' => ExperiencePage::KIND_SYSTEM,
                'enabled' => true, 'channel' => ExperiencePage::CHANNEL_SHARED,
            ], ExperiencePage::SYSTEM_SLUGS);
        }

        return $this->forTheme($themeId)
            ->filter(fn (ExperiencePage $page) => $page->channel === ExperiencePage::CHANNEL_SHARED
                || $page->channel === $channel)
            ->map(fn (ExperiencePage $page) => [
                'id'      => $page->id,
                'slug'    => $page->slug,
                'title'   => $page->displayTitle(),
                'kind'    => $page->kind,
                'enabled' => (bool) $page->is_enabled,
                'channel' => $page->channel,
            ])
            ->values()
            ->all();
    }

    /** The slugs a channel may actually be served, which is the enabled half of the above. */
    public function servableSlugs(int $themeId, string $channel): array
    {
        return array_values(array_map(
            static fn (array $page) => $page['slug'],
            array_filter($this->forChannel($themeId, $channel), static fn (array $page) => $page['enabled']),
        ));
    }

    /** The page a slug names, or null when this theme has no such page. */
    public function find(int $themeId, string $slug): ?ExperiencePage
    {
        if (!$this->isReady()) {
            return null;
        }

        return ExperiencePage::query()
            ->where('theme_id', $themeId)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Make sure a theme has the pages the engine guarantees.
     *
     * Called when a theme is created and again whenever the builder opens, so a theme that predates
     * this feature — or one restored from an export — gains its pages the first time somebody looks
     * at it, rather than needing a command nobody runs.
     */
    public function ensureSystemPages(Theme $theme): void
    {
        if (!$this->isReady()) {
            return;
        }

        foreach (ExperiencePage::SYSTEM_SLUGS as $order => $slug) {
            ExperiencePage::query()->firstOrCreate(
                [
                    'theme_id' => $theme->id,
                    'channel'  => ExperiencePage::CHANNEL_SHARED,
                    'slug'     => $slug,
                ],
                [
                    'title'      => ucfirst($slug),
                    'kind'       => ExperiencePage::KIND_SYSTEM,
                    'is_enabled' => true,
                    'sort_order' => $order,
                ],
            );
        }
    }

    /**
     * Create a page the merchant asked for.
     *
     * Returns null rather than throwing on a bad slug or a duplicate: this is called from a form,
     * and the caller turns null into a message.
     */
    public function create(
        Theme $theme,
        string $title,
        ?string $slug = null,
        string $channel = ExperiencePage::CHANNEL_SHARED,
    ): ?ExperiencePage {
        if (!$this->isReady()) {
            return null;
        }

        $slug = $this->normaliseSlug($slug ?: $title);

        if ($slug === null || $this->find($theme->id, $slug) !== null) {
            return null;
        }

        $page = ExperiencePage::create([
            'theme_id'   => $theme->id,
            // A page made for one channel is that channel's; the default keeps the three system
            // pages shared, which is what makes one arrangement serve the web and the app.
            'channel'    => in_array($channel, Channel::RENDERABLE, true)
                ? $channel
                : ExperiencePage::CHANNEL_SHARED,
            'slug'       => $slug,
            'title'      => trim($title) ?: ucfirst($slug),
            'kind'       => ExperiencePage::KIND_CUSTOM,
            'is_enabled' => true,
            'sort_order' => (int) ExperiencePage::where('theme_id', $theme->id)->max('sort_order') + 1,
        ]);

        $this->audit?->record(
            action: 'experience.page_created',
            subject: $page,
            after: ['slug' => $page->slug, 'title' => $page->title],
            context: ['theme_id' => $theme->id],
        );

        return $page;
    }

    /**
     * Rename a page, or switch it on and off. A system page can be renamed but never disabled.
     *
     * Returns false when the page refuses what was asked rather than saving what it can and
     * reporting success: silently dropping the one change a merchant made, and then telling them
     * it was applied, is worse than refusing it out loud. Nothing half-applies either — a call
     * that asks to rename AND disable a built-in page does neither.
     */
    public function update(ExperiencePage $page, ?string $title = null, ?bool $enabled = null): bool
    {
        if (!$this->isReady()) {
            return false;
        }

        if ($enabled !== null && $page->isSystem() && (bool) $page->is_enabled !== $enabled) {
            return false;
        }

        if ($title !== null && trim($title) !== '') {
            $page->title = trim($title);
        }

        if ($enabled !== null && !$page->isSystem()) {
            $page->is_enabled = $enabled;
        }

        $before = [
            'title'      => $page->getOriginal('title'),
            'is_enabled' => (bool) $page->getOriginal('is_enabled'),
        ];

        $saved = $page->save();

        if ($saved) {
            // Disabling a page removes it from every client that could reach it — a change worth
            // the same trail its creation and deletion already leave.
            $this->audit?->record(
                action: 'experience.page_updated',
                subject: $page,
                before: $before,
                after: ['title' => $page->title, 'is_enabled' => (bool) $page->is_enabled],
                context: ['theme_id' => $page->theme_id],
            );
        }

        return $saved;
    }

    /**
     * Delete a custom page and everything placed on it.
     *
     * A system page is refused: home, header and footer are screens the clients ask for by name,
     * and a shop without a home page is a broken shop, not an empty one.
     */
    public function delete(ExperiencePage $page): bool
    {
        if (!$this->isReady() || $page->isSystem()) {
            return false;
        }

        $slug = $page->slug;
        $themeId = $page->theme_id;

        ThemeSection::where('experience_page_id', $page->id)->delete();
        $page->delete();

        $this->audit?->record(
            action: 'experience.page_deleted',
            subject: null,
            before: ['slug' => $slug],
            context: ['theme_id' => $themeId],
        );

        return true;
    }

    /**
     * The page id a section on this slug belongs to, creating nothing.
     *
     * This is the one method the existing builder path needs: when a section is added, it stamps
     * the id beside the slug it was already writing.
     */
    public function idFor(int $themeId, string $slug): ?int
    {
        return $this->find($themeId, $slug)?->id;
    }

    /** A slug that is safe in a URL, a cache key and a path segment — or null. */
    public function normaliseSlug(string $value): ?string
    {
        $slug = Str::slug(trim($value));

        return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : null;
    }
}
