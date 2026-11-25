<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Admin\Settings\ThemeBuilderController;
use App\Http\Controllers\Admin\Settings\ThemeManagementController;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontVisibility;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemePermissionService;
use App\Services\Theme\ThemeStorefrontSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\BuildsStorefrontVisibilityWorld;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * F-15 and F-V1: the existing theme screens gain a STOREFRONT, and everything they do is about that one.
 *
 * The admin panel is bound to no storefront on purpose -- that is what keeps a central row reachable there -- so once themes belong to
 * storefronts (F-01) every theme screen was about "the active theme", which is whichever of six rows the database answered first. Four
 * separate consequences, each asserted here:
 *
 *  - the theme LIST showed all six markets' themes together, so publishing, activating or deleting from it was a coin toss;
 *  - the BUILDER opened on `activeThemePublishedVersion()`, i.e. another market's draft, and composed into it;
 *  - the builder's PICKERS offered every market's products, categories, brands and flash deals, so a section composed in the Emirati
 *    builder could be pointed at a Saudi product that the Emirati storefront does not sell and would render nothing there;
 *  - and a MUTATION took a version, a section or a block the controller had loaded by id from the request, with nothing comparing it
 *    with a market -- so one open builder could reorder, retype or delete another country's draft, and the autosave that posts the whole
 *    form would do it without anybody pressing anything.
 *
 * The data half of the permissions is the fifth (F-15, criterion 3). `ThemePermissionService` said WHAT an employee may do and nothing
 * said WHERE, so an employee scoped to the Emirates who held `theme_publish` could publish, activate, delete, schedule, restore, export
 * or re-skin the SAUDI storefront. Every refusal below has its unrestricted twin, because a refusal test alone cannot tell a guard from
 * a wrong argument.
 *
 * The first test is the dormancy one: while themes do not belong to storefronts there is no selector, no narrowing and no refusal.
 */
class AdminThemeStorefrontSelectorTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use BuildsStorefrontVisibilityWorld;
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    private Theme $saudi;

    private Theme $emirati;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStorefrontVisibilityWorld();
        $this->seedStorefrontVisibilityWorld();
        $this->buildThemeWorld();
        $this->buildStaffAdminTables();
        $this->runStaffScopeMigration();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->forgetWhatTheThemeResolverLearnt();

        // A theme that names no country is the DEFAULT storefront's, which is the shape every installation starts from.
        $this->saudi = $this->giveTheme(name: 'Saudi', active: true);
        $this->emirati = $this->giveTheme(name: 'Emirati', country: 'AE', active: true);
        $this->giveTheme(name: 'Kuwaiti', country: 'KW');

        $this->givePublishedVersion(theme: $this->saudi, revision: 1, spacerHeight: 10);
        $this->givePublishedVersion(theme: $this->emirati, revision: 1, spacerHeight: 20);

        // Two products of two markets, so a picker that narrows and a picker that does not give different answers.
        DB::table('products')->where('id', 1)->update(['storefront_country' => 'SA', 'name' => 'Saudi lamp']);
        DB::table('products')->where('id', 2)->update(['storefront_country' => 'AE', 'name' => 'Emirati lamp']);
        DB::table('products')->where('id', 3)->update(['storefront_country' => 'AE', 'status' => 1, 'name' => 'Second Emirati lamp']);
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        $this->rollBackStaffScopeMigration();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- dormancy -------------------------------------------------------------------------------------------------------------------

    public function test_while_themes_do_not_belong_to_storefronts_nothing_is_offered_narrowed_or_refused(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $this->forgetWhatTheThemeResolverLearnt();

        $data = $this->indexData();

        self::assertSame([], $data['storefrontChoices'], 'no selector while there is nothing to select');
        self::assertSame(['Kuwaiti', 'Emirati', 'Saudi'], $this->themeNames($data), 'every theme, newest first, as the list always showed');
        self::assertTrue(app(ThemePermissionService::class)->mayWorkOn($this->emirati), 'and no market refusal');
        self::assertSame(
            ['Second Emirati lamp', 'Emirati lamp', 'Saudi lamp'],
            $this->pickerLabels(resource: 'product'),
            'and the picker offers the whole catalogue',
        );
    }

    // ---- criterion 1: the admin chooses the storefront ------------------------------------------------------------------------------

    public function test_the_theme_list_is_the_selected_storefronts_and_the_selector_offers_every_market(): void
    {
        $saudiList = $this->indexData();

        self::assertSame('SA', $saudiList['selectedStorefront'], 'the default storefront until something says otherwise');
        self::assertSame(['Saudi'], $this->themeNames($saudiList), 'a theme naming no country is the default storefront\'s');
        self::assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_keys($saudiList['storefrontChoices']));

        $emiratiList = $this->indexData(['storefront' => 'AE']);

        self::assertSame('AE', $emiratiList['selectedStorefront']);
        self::assertSame(['Emirati'], $this->themeNames($emiratiList));
    }

    public function test_the_choice_is_remembered_so_the_next_screen_is_about_the_same_market(): void
    {
        $this->indexData(['storefront' => 'KW']);

        self::assertSame('KW', $this->indexData()['selectedStorefront'], 'the list without a parameter stays where the admin put it');
        self::assertSame('KW', app(ThemeStorefrontSelection::class)->current(request: Request::create('/admin/theme')));
    }

    public function test_an_unconfigured_or_unknown_market_never_becomes_the_selection(): void
    {
        self::assertSame('SA', $this->indexData(['storefront' => 'ZZ'])['selectedStorefront']);
        self::assertSame('SA', $this->indexData(['storefront' => ''])['selectedStorefront']);
    }

    public function test_the_builder_opens_on_the_selected_storefronts_draft(): void
    {
        $saudiDraft = $this->builderData()['version'];
        self::assertSame($this->saudi->id, $saudiDraft->theme_id, 'the Saudi storefront\'s own theme');

        $emiratiDraft = $this->builderData(['storefront' => 'AE'])['version'];
        self::assertSame($this->emirati->id, $emiratiDraft->theme_id);
        self::assertNotSame($saudiDraft->id, $emiratiDraft->id, 'two markets, two drafts');
        self::assertSame('AE', $this->builderData(['storefront' => 'AE'])['selectedStorefront']);
    }

    public function test_opening_a_named_version_moves_the_selection_to_its_market(): void
    {
        $this->indexData(['storefront' => 'SA']);
        $emiratiDraft = app(\App\Services\Theme\ThemeManager::class)
            ->createDraftFrom(ThemeVersion::query()->where('theme_id', $this->emirati->id)->published()->firstOrFail());

        $data = $this->builderData(['version' => $emiratiDraft->id]);

        self::assertSame('AE', $data['selectedStorefront'], 'opening a version IS choosing its market');
        self::assertTrue($data['editable'], 'and the page the admin asked for is editable, not read-only');
    }

    public function test_a_theme_created_from_a_markets_list_belongs_to_that_market(): void
    {
        $this->indexData(['storefront' => 'KW']);

        app(ThemeManagementController::class)->store(Request::create('/admin/theme', 'POST', ['name' => 'Kuwaiti second']));

        self::assertSame('KW', Theme::query()->where('name', 'Kuwaiti second')->value('storefront_country'));
    }

    // ---- criterion 2: the pickers --------------------------------------------------------------------------------------------------

    public function test_the_product_picker_offers_only_the_selected_storefronts_products(): void
    {
        self::assertSame(['Saudi lamp'], $this->pickerLabels(resource: 'product'));
        self::assertSame(
            ['Second Emirati lamp', 'Emirati lamp'],
            $this->pickerLabels(resource: 'product', query: ['storefront' => 'AE']),
            'newest first, as the picker always answered',
        );
    }

    public function test_the_category_and_brand_pickers_offer_only_what_the_selected_storefront_shows(): void
    {
        // Categories and brands are MODE_HIDDEN: a mapping row names a market the row is hidden FROM. So this hides both from Saudi
        // Arabia and leaves every other market showing them, which is the asymmetry the two halves below read.
        StorefrontVisibility::set(StorefrontVisibility::CATEGORY, self::MAPPED_CATEGORY, ['SA']);
        StorefrontVisibility::set(StorefrontVisibility::BRAND, self::MAPPED_BRAND, ['SA']);
        StorefrontVisibility::flush();

        self::assertSame(['Everywhere'], $this->pickerLabels(resource: 'category'), 'a category hidden from Saudi Arabia is not offered there');
        self::assertSame(['Everywhere brand'], $this->pickerLabels(resource: 'brand'));

        self::assertSame(
            ['Everywhere', 'Mapped'],
            $this->pickerLabels(resource: 'category', query: ['storefront' => 'AE']),
            'and both are offered in the market that shows both',
        );
        self::assertSame(['Everywhere brand', 'Mapped brand'], $this->pickerLabels(resource: 'brand', query: ['storefront' => 'AE']));
    }

    // ---- F-V1: a mutation acts only on the theme of the storefront being edited -----------------------------------------------------

    public function test_a_builder_mutation_is_refused_on_another_markets_draft(): void
    {
        $this->indexData(['storefront' => 'AE']);
        $saudiDraft = $this->draftOf($this->saudi);

        $before = $this->sectionCountOf($saudiDraft);

        $refused = app(ThemeBuilderService::class)->addSection($saudiDraft, 'home', 'spacer');

        self::assertNull($refused, 'the Emirati builder may not compose into the Saudi draft');
        self::assertSame($before, $this->sectionCountOf($saudiDraft), 'and nothing was added to it');
    }

    public function test_the_same_mutation_is_allowed_on_the_market_being_edited(): void
    {
        $this->indexData(['storefront' => 'SA']);
        $saudiDraft = $this->draftOf($this->saudi);

        $before = $this->sectionCountOf($saudiDraft);

        $added = app(ThemeBuilderService::class)->addSection($saudiDraft, 'home', 'spacer');

        self::assertNotNull($added, 'the unrestricted twin: the same call, the market it belongs to');
        self::assertSame($before + 1, $this->sectionCountOf($saudiDraft));
    }

    public function test_every_builder_mutation_goes_through_the_same_gate(): void
    {
        $this->indexData(['storefront' => 'AE']);
        $saudiDraft = $this->draftOf($this->saudi);
        $section = ThemeSection::query()->create([
            'theme_version_id' => $saudiDraft->id, 'page' => 'home', 'type' => 'spacer', 'sort_order' => 9, 'settings' => ['height' => 1],
        ]);
        $before = $this->sectionCountOf($saudiDraft);
        $builder = app(ThemeBuilderService::class);

        self::assertFalse($builder->updateSection($section, ['height' => 99]));
        self::assertFalse($builder->setSectionVisibility($section, false));
        self::assertFalse($builder->reorderSections($saudiDraft, 'home', [$section->id]));
        self::assertNull($builder->duplicateSection($section));
        self::assertFalse($builder->deleteSection($section));
        self::assertNull($builder->addBlock($section, 'card'));

        self::assertSame(1, (int) $section->fresh()->settings['height'], 'nothing was written');
        self::assertTrue((bool) $section->fresh()->is_visible);
        self::assertSame($before, $this->sectionCountOf($saudiDraft), 'nothing added, nothing duplicated, nothing deleted');
    }

    // ---- criterion 3: a staff member scoped to AE -----------------------------------------------------------------------------------

    public function test_an_employee_scoped_to_one_market_is_offered_and_may_work_on_that_market_only(): void
    {
        $this->signInAsRestrictedEmployee(countries: ['AE']);

        $selection = app(ThemeStorefrontSelection::class);
        $permissions = app(ThemePermissionService::class);

        self::assertSame(['AE'], array_keys($selection->options()), 'the selector offers their own market only');
        self::assertFalse($selection->mayReach('SA'));
        self::assertSame('AE', $selection->current(request: Request::create('/admin/theme')), 'and they start on it, not on the default storefront');
        self::assertSame('AE', $selection->current(request: Request::create('/admin/theme', 'GET', ['storefront' => 'SA'])), 'asking for another market changes nothing');

        self::assertFalse($permissions->mayWorkOn($this->saudi));
        self::assertTrue($permissions->mayWorkOn($this->emirati));
    }

    public function test_an_employee_scoped_to_one_market_cannot_publish_restore_or_edit_another_markets_theme(): void
    {
        $this->signInAsRestrictedEmployee(countries: ['AE']);
        $saudiDraft = $this->draftOf($this->saudi);
        $permissions = app(ThemePermissionService::class);

        self::assertFalse($permissions->canPublishTheme($this->saudi));
        self::assertFalse($permissions->canRestoreTheme($this->saudi));
        self::assertFalse($permissions->canEditTheme($this->saudi));

        app(ThemeManagementController::class)->publishVersion(Request::create('/admin/theme/publish', 'POST', ['version_id' => $saudiDraft->id]));

        self::assertSame(ThemeVersion::STATUS_DRAFT, $saudiDraft->fresh()->status, 'the Saudi draft was not published');
    }

    public function test_the_unrestricted_twin_of_that_refusal_publishes(): void
    {
        $this->signInAsRestrictedEmployee(countries: ['SA']);
        $saudiDraft = $this->draftOf($this->saudi);

        self::assertTrue(app(ThemePermissionService::class)->canPublishTheme($this->saudi));

        app(ThemeManagementController::class)->publishVersion(Request::create('/admin/theme/publish', 'POST', ['version_id' => $saudiDraft->id]));

        self::assertSame(ThemeVersion::STATUS_PUBLISHED, $saudiDraft->fresh()->status, 'the same call, by an employee of that market');
    }

    public function test_a_theme_that_could_not_be_loaded_is_never_workable(): void
    {
        self::assertFalse(app(ThemePermissionService::class)->mayWorkOn(null), 'no theme is not "every theme"');
    }

    /**
     * A theme read that THROWS is a third case, and the two halves must fail in opposite directions.
     *
     * The schedule path tolerates it on purpose (`StorefrontThemeScheduledPublishTest`: the schedule is stored and its audit line names
     * no storefront), so a guard that made the read load-bearing would turn a tolerated failure into a 500 for everybody. An employee
     * the scopes do not restrict is not being judged by market at all and proceeds; a restricted one is refused, because failing open
     * would hand them every market the moment a theme read failed.
     */
    public function test_a_theme_read_that_fails_stops_a_restricted_employee_and_not_an_unrestricted_one(): void
    {
        $version = $this->draftOf($this->saudi);
        Theme::retrieved(static function (): void {
            throw new \RuntimeException('the database went away');
        });

        self::assertTrue(app(ThemePermissionService::class)->mayWorkOnTheThemeOf($version), 'nobody is being judged by market here');

        $this->signInAsRestrictedEmployee(countries: ['AE']);

        self::assertFalse(app(ThemePermissionService::class)->mayWorkOnTheThemeOf($version), 'and a restricted employee is refused rather than let through');
    }

    // ---- helpers --------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function indexData(array $query = []): array
    {
        return app(ThemeManagementController::class)->index(Request::create('/admin/theme', 'GET', $query))->getData();
    }

    /** @return array<string, mixed> */
    private function builderData(array $query = []): array
    {
        return app(ThemeBuilderController::class)->index(Request::create('/admin/theme/builder', 'GET', $query))->getData();
    }

    /** @return list<string> */
    private function themeNames(array $indexData): array
    {
        return array_values(array_map(static fn (Theme $theme): string => (string) $theme->name, iterator_to_array($indexData['themes'])));
    }

    /** @return list<string> the labels the builder's resource picker answers with, which is what the inspector lists */
    private function pickerLabels(string $resource, array $query = []): array
    {
        $response = app(ThemeBuilderController::class)->resources(
            Request::create('/admin/theme/builder/resources', 'GET', ['resource' => $resource] + $query),
        );

        return array_map(
            static fn (array $option): string => explode(' · ', (string) $option['label'])[0],
            $response->getData(true)['options'],
        );
    }

    private function sectionCountOf(ThemeVersion $version): int
    {
        return ThemeSection::query()->where('theme_version_id', $version->id)->count();
    }

    private function draftOf(Theme $theme): ThemeVersion
    {
        return app(\App\Services\Theme\ThemeManager::class)
            ->createDraftFrom(ThemeVersion::query()->where('theme_id', $theme->id)->published()->firstOrFail());
    }

    /** An employee whose role holds the theme module and who is scoped to the given markets. */
    private function signInAsRestrictedEmployee(array $countries): void
    {
        $roleId = DB::table('admin_roles')->insertGetId([
            'name' => 'Theme operator',
            'module_access' => json_encode(['themes_and_addons', ThemePermissionService::PUBLISH, ThemePermissionService::RESTORE]),
            'status' => 1,
        ]);
        $adminId = DB::table('admins')->insertGetId(['name' => 'Theme operator', 'email' => 'themes@example.test', 'admin_role_id' => $roleId, 'status' => 1]);

        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: $adminId, countries: $countries);
        $this->signInAsStaff($adminId);
    }
}
