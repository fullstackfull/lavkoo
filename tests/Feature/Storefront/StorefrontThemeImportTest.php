<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemePortabilityService;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\ReadsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-11: importing a theme file creates a new theme that is not active, with one draft version holding the sections of the file. It never overwrites a theme, whatever name the file gives it, never
 * activates one, and so never changes which theme a storefront is served. A file is untrusted: a section of a type the registry does not know is skipped with a warning, and the settings of every
 * section and block that is kept come back in the normal form the registry gives them, never as the file wrote them.
 */
class StorefrontThemeImportTest extends TestCase
{
    use BuildsThemeWorld;
    use ReadsThemeWorld;

    private const ADMIN_ID = 5;

    private const UNKNOWN_TYPE = 'ghost_section';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /**
     * The live theme of the default storefront, with a hero of two slides, a product rail and a spacer, exported the way the admin downloads it and read back from the file.
     *
     * @return array<string, mixed>
     */
    private function exportedTheme(): array
    {
        $theme = $this->giveTheme(name: 'Glossy', active: true);
        $version = $this->givePublishedVersion(theme: $theme, revision: 3, spacerHeight: 60);
        $version->update(['settings' => ['colors' => ['primary' => '#0f766e'], 'layout' => ['border_radius' => 12]]]);
        $hero = ThemeSection::query()->create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 2, 'settings' => ['height' => 300]]);
        foreach (['first', 'second'] as $position => $title) {
            ThemeBlock::query()->create(['theme_section_id' => $hero->id, 'type' => 'slide', 'sort_order' => $position + 1, 'is_visible' => $title === 'first', 'settings' => ['title' => $title]]);
        }
        ThemeSection::query()->create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider', 'sort_order' => 3, 'is_visible' => false, 'settings' => ['source' => 'new_arrival', 'limit' => 6]]);

        return json_decode((string) json_encode(app(ThemePortabilityService::class)->export(version: $version)), true);
    }

    /**
     * A file of the current format holding these sections.
     *
     * @param list<array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    private function fileOf(array $sections, string $name = 'Handmade'): array
    {
        return ['format_version' => ThemePortabilityService::FORMAT_VERSION, 'theme' => ['name' => $name], 'settings' => [], 'sections' => $sections];
    }

    /** @return list<string> the names of the themes that are active, whatever storefront they are for */
    private function activeThemeNames(): array
    {
        return Theme::query()->where('is_active', true)->orderBy('id')->pluck('name')->all();
    }

    /** @return array<string, ThemeSection> the sections of a version by their type, for a version that holds no type twice */
    private function sectionsOf(ThemeVersion $version): array
    {
        return $version->sections()->get()->keyBy('type')->all();
    }

    // ---- importing creates a new inactive theme with one draft (F-11.0) ----------------------------------------------------------------------

    public function test_importing_an_exported_theme_creates_a_new_inactive_theme_with_one_draft_holding_its_sections(): void
    {
        $file = $this->exportedTheme();
        $source = ThemeVersion::query()->published()->firstOrFail();

        $imported = app(ThemePortabilityService::class)->import(payload: $file, nameOverride: 'Spring copy', adminId: self::ADMIN_ID);

        $theme = $imported['theme'];
        $draft = $imported['version'];
        $this->assertSame(2, Theme::query()->count());
        $this->assertSame(['Spring copy', false, false, 'admin'], [$theme->name, $theme->is_active, $theme->is_system, $theme->created_by_type]);
        $this->assertSame(self::ADMIN_ID, (int) $theme->created_by_id);
        $this->assertSame([$draft->id], $theme->versions()->pluck('id')->all(), 'one version, and it is the one the import returned');
        $stored = $draft->fresh();
        $this->assertSame([ThemeVersion::STATUS_DRAFT, 'Imported', null, null, null, null], [$stored->status, $stored->label, $stored->published_at, $stored->publish_at, $stored->revision, $stored->checksum]);
        $this->assertEquals($source->settings, $draft->settings);
        $this->assertSame(0, ThemeVersion::query()->where('theme_id', $theme->id)->published()->count(), 'nothing is live');

        $this->assertSame(['spacer', 'hero_banner', 'product_slider'], $draft->sections()->pluck('type')->all(), 'the sections of the file, in its order');
        $registry = new SectionRegistry();
        foreach ($draft->sections()->get() as $position => $section) {
            $this->assertEquals($registry->normalizeSettings(type: $file['sections'][$position]['type'], settings: $file['sections'][$position]['settings']), $section->settings, $section->type);
        }
        $sections = $this->sectionsOf(version: $draft);
        $this->assertFalse($sections['product_slider']->is_visible);
        $this->assertSame([['slide', true, 'first'], ['slide', false, 'second']], $sections['hero_banner']->blocks->map(static fn (ThemeBlock $block): array => [$block->type, $block->is_visible, $block->settings['title']])->all());
    }

    public function test_importing_a_file_with_the_name_of_an_existing_theme_creates_another_theme_and_leaves_the_existing_one_as_it_was(): void
    {
        $file = $this->exportedTheme();
        $original = Theme::query()->where('slug', 'glossy')->firstOrFail();
        $before = $this->rowsOfTheThemeTables();
        $service = app(ThemePortabilityService::class);

        $fromTheName = $service->import(payload: $file);
        $fromTheOverride = $service->import(payload: $this->fileOf(sections: $file['sections'], name: 'Another'), nameOverride: 'Glossy');

        $after = $this->rowsOfTheThemeTables();
        foreach (['themes', 'theme_versions', 'theme_sections', 'theme_blocks'] as $table) {
            $this->assertEquals($before[$table], array_slice($after[$table], 0, count($before[$table])), "A row of {$table} that existed before the import was changed.");
        }
        $this->assertSame(3, Theme::query()->count());
        $this->assertSame(['Glossy', 'Glossy', 'Glossy'], Theme::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame(['glossy', 'glossy-2', 'glossy-3'], Theme::query()->orderBy('id')->pluck('slug')->all(), 'each theme keeps a slug of its own');
        $this->assertNotSame($fromTheName['theme']->id, $fromTheOverride['theme']->id);
        $this->assertSame(1, $original->versions()->count(), 'the existing theme was given no version');
    }

    public function test_importing_never_activates_the_theme_and_changes_the_active_theme_of_no_storefront(): void
    {
        $file = $this->exportedTheme();
        $this->giveTheme(name: 'Emirati', country: 'AE', active: true);
        $servedBefore = $this->themeServedToEachStorefront();
        $file['theme'] += ['is_active' => true, 'is_system' => true, 'slug' => 'glossy', 'storefront_country' => 'AE'];
        $file['status'] = ThemeVersion::STATUS_PUBLISHED;

        $imported = app(ThemePortabilityService::class)->import(payload: $file, adminId: self::ADMIN_ID);

        $this->assertSame(['SA' => 'Glossy', 'AE' => 'Emirati', 'KW' => 'Glossy', 'QA' => 'Glossy', 'BH' => 'Glossy', 'OM' => 'Glossy'], $servedBefore);
        $this->assertSame($servedBefore, $this->themeServedToEachStorefront());
        $this->assertSame(['Glossy', 'Emirati'], $this->activeThemeNames(), 'the imported theme is not among the active ones');
        $this->assertFalse($imported['theme']->fresh()->is_active);
        $this->assertFalse($imported['theme']->fresh()->is_system);
        $this->assertNull($imported['theme']->fresh()->storefront_country, 'the storefront of the file is not taken either');
        $this->assertSame([ThemeVersion::STATUS_DRAFT], ThemeVersion::query()->where('theme_id', $imported['theme']->id)->pluck('status')->all());
    }

    // ---- a section of an unknown type is skipped with a warning, and settings are normalised (F-11.1) ---------------------------------------------

    public function test_a_section_of_an_unknown_type_is_skipped_and_reported_as_a_warning(): void
    {
        $file = $this->fileOf(sections: [
            ['page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'settings' => []],
            ['page' => 'home', 'type' => self::UNKNOWN_TYPE, 'sort_order' => 5, 'settings' => ['title' => 'Boo']],
            ['page' => 'home', 'type' => 'product_slider', 'sort_order' => 9, 'settings' => []],
            ['page' => 'home', 'settings' => ['title' => 'No type']],
        ]);
        $service = app(ThemePortabilityService::class);
        $warnings = ['unknown_section_type_skipped: ' . self::UNKNOWN_TYPE, 'a_section_without_a_type_was_skipped'];

        $validated = $service->validate(payload: $file);
        $imported = $service->import(payload: $file);

        $this->assertSame(['valid' => true, 'errors' => [], 'warnings' => $warnings, 'section_count' => 2], $validated);
        $this->assertSame(['theme', 'version', 'result'], array_keys($imported));
        $this->assertSame($validated, $imported['result']);
        $this->assertSame(['hero_banner', 'product_slider'], $imported['version']->sections()->pluck('type')->all());
        $this->assertSame([1, 2], $imported['version']->sections()->pluck('sort_order')->all(), 'the sections that were kept are numbered without a gap');
        $this->assertSame(0, ThemeSection::query()->where('type', self::UNKNOWN_TYPE)->count());
        $this->assertSame(2, ThemeSection::query()->count());
    }

    public function test_a_file_whose_sections_are_all_of_unknown_types_imports_nothing(): void
    {
        $file = $this->fileOf(sections: [['page' => 'home', 'type' => self::UNKNOWN_TYPE], ['page' => 'home', 'type' => 'another_ghost']]);

        $imported = app(ThemePortabilityService::class)->import(payload: $file);

        $this->assertNull($imported['theme']);
        $this->assertNull($imported['version']);
        $this->assertSame(
            ['valid' => false, 'errors' => ['no_importable_sections_found'], 'warnings' => ['unknown_section_type_skipped: ghost_section', 'unknown_section_type_skipped: another_ghost'], 'section_count' => 0],
            $imported['result'],
        );
        $this->assertSame([0, 0, 0, 0], [Theme::query()->count(), ThemeVersion::query()->count(), ThemeSection::query()->count(), ThemeBlock::query()->count()]);
    }

    public function test_the_settings_of_a_section_come_back_in_the_normal_form_the_registry_gives_them(): void
    {
        $hero = ['evil' => 'x', 'height' => 'tall', 'autoplay' => 'nope', 'interval' => '7000', 'padding_top' => '12', 'width' => 'wide', 'background' => 'javascript:alert(1)', 'height_mobile' => '300'];
        $rail = ['source' => 'nonsense', 'limit' => '12', 'product_ids' => '3, abc, 5, -2, 3', 'columns' => '6x', 'view_all' => '0', 'style' => 'grid', 'title' => str_repeat('t', 1100)];
        $file = $this->fileOf(sections: [
            ['page' => 'home', 'type' => 'hero_banner', 'settings' => $hero],
            ['page' => 'home', 'type' => 'product_slider', 'settings' => $rail],
        ]);

        $imported = app(ThemePortabilityService::class)->import(payload: $file);

        $stored = $this->sectionsOf(version: $imported['version']);
        $registry = new SectionRegistry();
        $this->assertEquals($registry->normalizeSettings(type: 'hero_banner', settings: $hero), $stored['hero_banner']->settings);
        $this->assertEquals($registry->normalizeSettings(type: 'product_slider', settings: $rail), $stored['product_slider']->settings);

        $heroSettings = $stored['hero_banner']->settings;
        $this->assertArrayNotHasKey('evil', $heroSettings, 'a key the schema does not have is dropped');
        $this->assertArrayNotHasKey('height_tablet', $heroSettings, 'a breakpoint the file did not set stays unset');
        $this->assertSame([420, true, 7000, 12, 'container', null, 300], [$heroSettings['height'], $heroSettings['autoplay'], $heroSettings['interval'], $heroSettings['padding_top'], $heroSettings['width'], $heroSettings['background'], $heroSettings['height_mobile']]);
        $railSettings = $stored['product_slider']->settings;
        $this->assertSame(['featured', 12, '3,5', 5, false, 'grid', 1000, 40], [$railSettings['source'], $railSettings['limit'], $railSettings['product_ids'], $railSettings['columns'], $railSettings['view_all'], $railSettings['style'], mb_strlen($railSettings['title']), $railSettings['padding_top']]);
    }

    public function test_the_settings_of_a_block_come_back_in_the_normal_form_the_registry_gives_them_and_a_block_the_section_does_not_declare_is_dropped(): void
    {
        $slide = ['title' => 'Hello', 'link' => 'javascript:alert(1)', 'image' => '/storage/a.png', 'align' => 'diagonal', 'overlay' => 'x', 'evil' => 1];
        $file = $this->fileOf(sections: [['page' => 'home', 'type' => 'hero_banner', 'blocks' => [
            ['type' => 'slide', 'settings' => $slide],
            ['type' => 'tab', 'settings' => ['label' => 'A tab of another section']],
        ]]]);

        $imported = app(ThemePortabilityService::class)->import(payload: $file);

        $blocks = $imported['version']->sections()->firstOrFail()->blocks;
        $this->assertSame(['slide'], $blocks->pluck('type')->all());
        $this->assertEquals((new SectionRegistry())->normalizeBlockSettings(blockType: 'slide', settings: $slide), $blocks[0]->settings);
        $this->assertSame(['Hello', null, '/storage/a.png', 'start', 35], [$blocks[0]->settings['title'], $blocks[0]->settings['link'], $blocks[0]->settings['image'], $blocks[0]->settings['align'], $blocks[0]->settings['overlay']]);
        $this->assertArrayNotHasKey('evil', $blocks[0]->settings);
    }
}
