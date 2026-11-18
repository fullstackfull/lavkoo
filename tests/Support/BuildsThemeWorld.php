<?php

namespace Tests\Support;

use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\StorefrontThemeResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The theme tables, which no migration of this repository creates, and a few helpers that fill them. The column the storefront themes add is appended by the
 * migration itself, so a test that builds the world without it sees the database before the migration has run.
 *
 * A theme is told apart by the revision of its published version and by the height of the one spacer on its home page: both are numbers the test chooses.
 */
trait BuildsThemeWorld
{
    /** The tables the theme code reads, in the order that drops them. */
    private const THEME_TABLES = ['experience_pages', 'theme_blocks', 'theme_sections', 'theme_versions', 'themes'];

    protected function buildThemeWorld(bool $withCountryColumn = true): void
    {
        $this->dropThemeWorld();

        Schema::create('themes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('preview_image')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('theme_id');
            $table->string('label')->nullable();
            $table->string('change_note', 300)->nullable();
            $table->string('status')->default(ThemeVersion::STATUS_DRAFT);
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->unsignedInteger('revision')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('theme_version_id');
            $table->unsignedBigInteger('experience_page_id')->nullable();
            $table->string('uuid')->nullable();
            $table->string('page')->default('home');
            $table->string('type');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable();
            $table->json('audience')->nullable();
            $table->json('channels')->nullable();
            $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('theme_section_id');
            $table->string('type');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('experience_pages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('theme_id');
            $table->string('channel')->default(ExperiencePage::CHANNEL_SHARED);
            $table->string('slug');
            $table->string('title')->nullable();
            $table->string('kind')->default(ExperiencePage::KIND_CUSTOM);
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        if ($withCountryColumn) {
            $this->runThemeStorefrontMigration();
        }

        $this->forgetWhatTheThemeResolverLearnt();
        Cache::flush();
    }

    protected function dropThemeWorld(): void
    {
        foreach (self::THEME_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Adds the column the migration adds, the way the migration adds it. */
    protected function runThemeStorefrontMigration(): void
    {
        (require base_path('database/migrations/2026_12_04_100000_add_storefront_country_to_themes_table.php'))->up();
    }

    /** The resolver remembers whether the column exists, so a test that adds or drops it in the middle makes it ask again. */
    protected function forgetWhatTheThemeResolverLearnt(): void
    {
        $this->app->forgetInstance(StorefrontThemeResolver::class);
    }

    protected function switchStorefrontThemesOn(): void
    {
        config(['gcc.storefronts.themes.enabled' => true]);
    }

    /** The storefronts as the platform will run them: switched on, with the five other countries open. */
    protected function openEveryStorefront(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            config(["gcc.storefronts.countries.{$country}.active" => true]);
        }
    }

    protected function giveTheme(string $name, ?string $country = null, bool $active = false): Theme
    {
        $attributes = ['name' => $name, 'slug' => str($name)->slug()->value(), 'is_active' => $active];

        if (Schema::hasColumn('themes', 'storefront_country')) {
            $attributes['storefront_country'] = $country;
        }

        return Theme::query()->create($attributes);
    }

    /** A published version with one spacer on its home page; the height tells the theme apart. */
    protected function givePublishedVersion(Theme $theme, int $revision, int $spacerHeight): ThemeVersion
    {
        $version = ThemeVersion::query()->create([
            'theme_id' => $theme->id,
            'label' => "{$theme->name} v{$revision}",
            'status' => ThemeVersion::STATUS_PUBLISHED,
            'published_at' => now(),
            'revision' => $revision,
            'checksum' => "checksum-of-{$theme->slug}",
        ]);

        ThemeSection::query()->create([
            'theme_version_id' => $version->id,
            'page' => 'home',
            'type' => 'spacer',
            'sort_order' => 1,
            'settings' => ['height' => $spacerHeight],
        ]);

        return $version;
    }

    /** A theme with a published version, the home page every theme has, and one page of its own, which only that theme can serve. */
    protected function giveStorefrontTheme(string $name, ?string $country, int $revision, int $spacerHeight, string $page, bool $active = false): Theme
    {
        $theme = $this->giveTheme(name: $name, country: $country, active: $active);
        $this->givePublishedVersion(theme: $theme, revision: $revision, spacerHeight: $spacerHeight);
        $this->givePage(theme: $theme, slug: 'home', kind: ExperiencePage::KIND_SYSTEM);
        $this->givePage(theme: $theme, slug: $page);

        return $theme;
    }

    /** A page of a theme, which only the theme that owns it can serve: the merchant's own unless it is told to be a system page. */
    protected function givePage(Theme $theme, string $slug, string $kind = ExperiencePage::KIND_CUSTOM, string $channel = ExperiencePage::CHANNEL_SHARED): ExperiencePage
    {
        return ExperiencePage::query()->create(['theme_id' => $theme->id, 'slug' => $slug, 'title' => ucfirst($slug), 'kind' => $kind, 'channel' => $channel]);
    }

    /** The height of the spacer the storefront renderer drew for the home page of the storefront the request is served from, or null when nothing was drawn. */
    protected function drawnSpacerHeight(): ?int
    {
        $sections = app(StorefrontThemeRenderer::class)->sectionsFor('home');

        return $sections === null ? null : (int) $sections[0]['settings']['height'];
    }

    /** Serves the rest of the test from one storefront, the way the middleware binds a request. */
    protected function serveRequestFromStorefront(string $country): void
    {
        $this->app->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }
}
