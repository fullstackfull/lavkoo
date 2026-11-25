<?php

namespace Tests\Feature\Storefront;

use App\Models\Banner;
use App\Models\Theme;
use App\Models\ThemeAsset;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontVisibility;
use App\Services\Theme\StorefrontThemeResolver;
use App\Services\Theme\ThemeCopyToMarket;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-11: copying one market's theme into another gives that market a DRAFT of its own.
 *
 * The export/import pair already moved a theme's sections through a file, and using it to start a second market was wrong in four ways
 * that a shopper would have found before an operator did:
 *
 *  - the imported theme named NO country, and a theme that names none is the DEFAULT storefront's, so "copy Saudi Arabia to the
 *    Emirates" produced a second theme of Saudi Arabia;
 *  - every section kept its `uuid`, which is deliberate for a round trip of the same theme and wrong for a copy: two markets' sections
 *    sharing one identity means one market's analytics and one market's per-section client state answer for both;
 *  - the banner rows did not travel, and `banners` is shared by every storefront but ASSIGNED per market, so a copied block pointing at
 *    the source market's row renders NOTHING in the target market;
 *  - the assets did not travel, so the copy had no logo and no section backgrounds.
 *
 * And nothing reported what the copy could not bring. A section pointing at a product the Emirates does not sell renders an empty rail.
 *
 * The asset half carries a constraint worth asserting rather than trusting: `ThemeAssetService::delete()` removes the FILE with the row,
 * so a copied row pointing at the source theme's file would mean deleting the Emirati logo takes the Saudi one with it. The copy writes
 * its own file, and the test checks the two paths differ and both exist.
 */
class ThemeCopyToMarketTest extends TestCase
{
    use BuildsThemeWorld;

    private const ADMIN_ID = 7;

    private Theme $saudi;

    private ThemeVersion $saudiVersion;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->buildThemeWorld();
        $this->buildTheCatalogueTheCopyChecks();
        config(['gcc.storefronts.themes.enabled' => true]);
        $this->openEveryStorefront();
        $this->forgetWhatTheThemeResolverLearnt();
        StorefrontVisibility::flush();
        StorefrontCatalog::flush();

        $this->saudi = $this->giveTheme(name: 'Saudi storefront', country: 'SA', active: true);
        $this->saudiVersion = $this->givePublishedVersion(theme: $this->saudi, revision: 1, spacerHeight: 40);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('storages');
        Schema::dropIfExists('theme_assets');
        Schema::dropIfExists(StorefrontVisibility::TABLE);
        Schema::dropIfExists('banners');
        Schema::dropIfExists('products');
        // The table `Banner`'s own `saved` hook writes the disk of a photo into. Built rather than left out, because left out it makes
        // every `Banner::create()` throw INSIDE the hook, AFTER the insert -- see the orphan-row test.
        Schema::dropIfExists('storages');
        Schema::create('storages', static function (Blueprint $table): void {
            $table->id();
            $table->string('data_type')->nullable();
            $table->unsignedBigInteger('data_id')->nullable();
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('categories');
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /** The first test of the switch: while themes do not belong to storefronts there is one theme and the question does not arise. */
    public function test_while_themes_do_not_belong_to_storefronts_a_copy_is_refused(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $this->forgetWhatTheThemeResolverLearnt();

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $this->assertSame('themes_do_not_belong_to_storefronts_yet', $copied['refused']);
        $this->assertNull($copied['theme']);
        $this->assertSame(1, Theme::query()->count(), 'a theme was created although the copy was refused');
    }

    public function test_a_copy_is_a_draft_owned_by_the_target_market(): void
    {
        $copied = $this->copier()->copy($this->saudiVersion, 'AE', adminId: self::ADMIN_ID);

        $this->assertNull($copied['refused']);
        $this->assertSame('AE', $copied['theme']->storefront_country);
        $this->assertSame('AE', app(StorefrontThemeResolver::class)->storefrontOf(theme: $copied['theme']));
        $this->assertFalse((bool) $copied['theme']->is_active, 'the copy was activated');
        $this->assertSame(ThemeVersion::STATUS_DRAFT, $copied['version']->status);
        // And the market it was copied FROM is untouched: still active, still Saudi, still published.
        $this->assertTrue((bool) $this->saudi->refresh()->is_active);
        $this->assertSame('SA', $this->saudi->storefront_country);
    }

    public function test_every_section_of_a_copy_gets_a_fresh_identity(): void
    {
        $source = ThemeSection::query()->where('theme_version_id', $this->saudiVersion->id)->pluck('uuid');
        $this->assertNotEmpty(array_filter($source->all()), 'the source sections carry no uuid, so this test proves nothing');

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');
        $copy = ThemeSection::query()->where('theme_version_id', $copied['version']->id)->pluck('uuid');

        $this->assertCount($source->count(), $copy);
        $this->assertEmpty(array_filter($copy->all(), static fn (?string $uuid): bool => $uuid === null), 'a copied section has no identity at all');
        $this->assertSame([], array_intersect($source->all(), $copy->all()), 'a copied section kept the source market\'s identity');
    }

    /** The export/import round trip of ONE theme must still keep its uuids: that is why the strip belongs to the copy and not to import. */
    public function test_a_plain_import_still_keeps_the_identities_the_export_carried(): void
    {
        $payload = app(\App\Services\Theme\ThemePortabilityService::class)->export($this->saudiVersion);
        $imported = app(\App\Services\Theme\ThemePortabilityService::class)->import($payload, 'Round trip');

        $this->assertSame(
            ThemeSection::query()->where('theme_version_id', $this->saudiVersion->id)->pluck('uuid')->all(),
            ThemeSection::query()->where('theme_version_id', $imported['version']->id)->pluck('uuid')->all(),
        );
    }

    public function test_a_banner_row_is_duplicated_and_assigned_to_the_target_market(): void
    {
        $banner = $this->giveBanner(title: 'Saudi hero');
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: $banner->id, countries: ['SA']);
        $this->giveBannerBackedSection($this->saudiVersion, $banner->id);

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $copiedId = (int) $this->blockSettingsOf($copied['version'])['banner_id'];
        $this->assertNotSame($banner->id, $copiedId, 'the copy points at the source market\'s banner row');
        $this->assertSame(['AE'], StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::BANNER, entityId: $copiedId));
        $this->assertSame(['SA'], StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::BANNER, entityId: $banner->id), 'the source market\'s banner was re-assigned');
        $this->assertSame('Saudi hero', Banner::query()->find($copiedId)->title);
        $this->assertSame([$copiedId], $copied['report']['banners']['duplicated']);
    }

    /** The other answer to Q-19, one setting away: one row, shown in both markets, so a single edit reaches both. */
    public function test_with_sharing_chosen_one_banner_row_is_assigned_to_both_markets(): void
    {
        config(['gcc.storefronts.themes.copy.banners' => ThemeCopyToMarket::BANNERS_SHARE]);

        $banner = $this->giveBanner(title: 'Gulf hero');
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: $banner->id, countries: ['SA']);
        $this->giveBannerBackedSection($this->saudiVersion, $banner->id);

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $this->assertSame($banner->id, (int) $this->blockSettingsOf($copied['version'])['banner_id']);
        $this->assertEqualsCanonicalizing(
            ['SA', 'AE'],
            StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::BANNER, entityId: $banner->id),
        );
        $this->assertSame([$banner->id], $copied['report']['banners']['shared']);
    }

    public function test_a_banner_row_that_is_gone_is_reported_rather_than_invented(): void
    {
        $this->giveBannerBackedSection($this->saudiVersion, 404);

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $this->assertSame([404], $copied['report']['banners']['missing']);
        $this->assertSame(404, (int) $this->blockSettingsOf($copied['version'])['banner_id'], 'a replacement banner was invented');
    }

    /**
     * A banner copy that cannot be completed leaves NO row behind.
     *
     * `Banner`'s `saved` hook writes into `storages`, and on an installation where that table is absent the hook throws AFTER the insert
     * has happened. Without a transaction the copy's `catch` would report the banner as "missing" while a row for it existed in
     * `banners` -- assigned to no market, pointing at nothing, and invisible to the operator who was told the copy was fine. That is the
     * defect shape this programme has hit before: a hook failing after the write, a caller's try/catch eating it, and everything after
     * the create silently skipped.
     *
     * The root cause is in the model and is reported rather than fixed here; what is fixed is that this copy cannot be the one that
     * leaves the orphan.
     */
    public function test_a_banner_copy_that_cannot_finish_leaves_no_row_behind(): void
    {
        $banner = $this->giveBanner(title: 'Saudi hero');
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: $banner->id, countries: ['SA']);
        $this->giveBannerBackedSection($this->saudiVersion, $banner->id);

        // The state an installation that has not run the storages migration is in.
        Schema::dropIfExists('storages');

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $this->assertSame([$banner->id], $copied['report']['banners']['missing'], 'the copy claimed a banner it could not write');
        $this->assertSame(1, Banner::query()->count(), 'the copy left a banner row nobody owns');
        $this->assertSame($banner->id, (int) $this->blockSettingsOf($copied['version'])['banner_id'], 'the copied block was pointed at a row that does not exist');
    }

    /**
     * The asset constraint: a copied row must point at its OWN file, because deleting an asset deletes its file.
     */
    public function test_an_asset_is_copied_file_and_all_so_deleting_the_copy_cannot_reach_the_source(): void
    {
        $asset = $this->giveAsset($this->saudi, 'logo.png');

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');
        $copy = ThemeAsset::query()->where('theme_id', $copied['theme']->id)->firstOrFail();

        $this->assertNotSame($asset->path, $copy->path, 'the copy points at the source theme\'s file');
        $this->assertTrue(Storage::disk('public')->exists($copy->path));
        $this->assertTrue(Storage::disk('public')->exists($asset->path));
        $this->assertSame('logo', $copy->label);
        $this->assertSame(1, $copied['report']['assets']['duplicated']);
    }

    /** A row pointing at a file that is not there is the one outcome that must not happen, so none is written. */
    public function test_an_asset_whose_file_has_gone_is_reported_and_no_row_is_written(): void
    {
        $asset = $this->giveAsset($this->saudi, 'logo.png');
        Storage::disk('public')->delete($asset->path);

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $this->assertSame(0, ThemeAsset::query()->where('theme_id', $copied['theme']->id)->count());
        $this->assertSame(['logo'], $copied['report']['assets']['not_copied']);
    }

    public function test_the_copy_reports_the_catalogue_ids_the_target_market_does_not_offer(): void
    {
        // A rail of two products by hand: one the Emirates sells, one it does not.
        ThemeSection::query()->create([
            'theme_version_id' => $this->saudiVersion->id,
            'page' => 'home',
            'type' => 'product_slider',
            'sort_order' => 2,
            'settings' => ['source' => 'manual', 'product_ids' => [1, 2]],
        ]);

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $this->assertSame([1], $copied['report']['catalogue_missing']['product'], 'the Saudi-only product was not reported');
    }

    public function test_the_copy_reports_a_category_the_target_market_hides(): void
    {
        // A category is visible everywhere until a row HIDES it in one storefront (the opposite default from a banner), so the row goes
        // on the category the Emirates does not show. Both categories are on the section, so the report has to tell them apart rather
        // than list whatever it finds.
        StorefrontVisibility::set(entityType: StorefrontVisibility::CATEGORY, entityId: 9, countries: ['AE']);
        ThemeSection::query()->create([
            'theme_version_id' => $this->saudiVersion->id,
            'page' => 'home',
            'type' => 'category_grid',
            'sort_order' => 2,
            'settings' => ['category_ids' => [5, 9]],
        ]);

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $this->assertSame([9], $copied['report']['catalogue_missing']['category']);
    }

    /** A vendor shop has no market on this platform, so the copy says so rather than saying it checked. */
    public function test_a_reference_nothing_can_place_in_a_market_is_reported_as_unchecked(): void
    {
        ThemeSection::query()->create([
            'theme_version_id' => $this->saudiVersion->id,
            'page' => 'home',
            'type' => 'vendor_showcase',
            'sort_order' => 2,
            'settings' => ['shop_id' => 3],
        ]);

        $copied = $this->copier()->copy($this->saudiVersion, 'AE');

        $this->assertSame([3], $copied['report']['catalogue_not_checked']['shop']);
        $this->assertArrayNotHasKey('shop', $copied['report']['catalogue_missing']);
    }

    public function test_a_market_the_platform_does_not_serve_is_refused(): void
    {
        $this->assertSame('that_is_not_a_market_this_platform_serves', $this->copier()->copy($this->saudiVersion, 'ZZ')['refused']);
    }

    public function test_copying_a_theme_into_its_own_market_is_refused(): void
    {
        $this->assertSame('that_theme_already_belongs_to_that_market', $this->copier()->copy($this->saudiVersion, 'SA')['refused']);
    }

    /** The markets the screen offers never include the one the theme already belongs to. */
    public function test_the_markets_offered_exclude_the_themes_own(): void
    {
        $offered = $this->copier()->marketsFor($this->saudiVersion);

        $this->assertNotContains('SA', $offered);
        $this->assertContains('AE', $offered);
    }

    // ---------------------------------------------------------------------------------------

    private function copier(): ThemeCopyToMarket
    {
        return app(ThemeCopyToMarket::class);
    }

    /** @return array<string, mixed> */
    private function blockSettingsOf(ThemeVersion $version): array
    {
        return ThemeBlock::query()
            ->whereIn('theme_section_id', ThemeSection::query()->where('theme_version_id', $version->id)->select('id'))
            ->firstOrFail()
            ->settings ?? [];
    }

    /**
     * A banner WITH A PHOTO, and that is not decoration.
     *
     * `Banner::boot()` hangs a `saved` hook on the model that writes the disk of `photo` into `storages` whenever that attribute is
     * dirty and non-empty. A banner with no photo never fires it, so a test that made one would prove the copy works on a row no real
     * installation has -- every real banner has a picture. With one, the copy's `save()` runs the hook for real, which is how the
     * orphan-row case below is reachable at all.
     */
    private function giveBanner(string $title): Banner
    {
        $id = (int) DB::table('banners')->insertGetId([
            'title' => $title,
            'photo' => '2026-11-25-hero.webp',
            'banner_type' => 'Theme Banner',
            'theme' => 'default',
            'published' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Banner::query()->findOrFail($id);
    }

    private function giveBannerBackedSection(ThemeVersion $version, int $bannerId): void
    {
        $section = ThemeSection::query()->create([
            'theme_version_id' => $version->id,
            'page' => 'home',
            'type' => 'promotional_banner',
            'sort_order' => 3,
            'settings' => [],
        ]);

        ThemeBlock::query()->create([
            'theme_section_id' => $section->id,
            'type' => 'banner',
            'sort_order' => 1,
            'settings' => ['banner_id' => $bannerId],
        ]);
    }

    private function giveAsset(Theme $theme, string $name): ThemeAsset
    {
        return app(\App\Services\Theme\ThemeAssetService::class)
            ->upload($theme, UploadedFile::fake()->image($name, 10, 10), label: pathinfo($name, PATHINFO_FILENAME), adminId: self::ADMIN_ID)['asset'];
    }

    /**
     * The tables the copy's report reads: the assets of a theme, the banner rows its blocks point at, the mapping that places a
     * category or a banner in a market, and the products with their own market.
     */
    private function buildTheCatalogueTheCopyChecks(): void
    {
        Schema::dropIfExists('theme_assets');
        Schema::create('theme_assets', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('theme_id');
            $table->string('label')->nullable();
            $table->string('path');
            $table->string('disk')->default('public');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('uploaded_by_type')->nullable();
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('banners');
        Schema::create('banners', static function (Blueprint $table): void {
            $table->id();
            $table->string('photo')->nullable();
            $table->string('banner_type')->nullable();
            $table->string('theme')->nullable();
            $table->boolean('published')->default(true);
            $table->integer('priority')->default(0);
            $table->string('url')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('title')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('button_text')->nullable();
            $table->timestamps();
        });

        // The table `Banner`'s own `saved` hook writes the disk of a photo into. Built rather than left out, because left out it makes
        // every `Banner::create()` throw INSIDE the hook, AFTER the insert -- see the orphan-row test.
        Schema::dropIfExists('storages');
        Schema::create('storages', static function (Blueprint $table): void {
            $table->id();
            $table->string('data_type')->nullable();
            $table->unsignedBigInteger('data_id')->nullable();
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('categories');
        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
        DB::table('categories')->insert([
            ['id' => 5, 'name' => 'Emirati only', 'slug' => 'emirati-only'],
            ['id' => 9, 'name' => 'Saudi only', 'slug' => 'saudi-only'],
        ]);

        Schema::dropIfExists('products');
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Saudi only', StorefrontCatalog::COLUMN => 'SA'],
            ['id' => 2, 'name' => 'Emirati', StorefrontCatalog::COLUMN => 'AE'],
        ]);

        Schema::dropIfExists(StorefrontVisibility::TABLE);
        (require database_path('migrations/2026_12_08_100000_create_storefront_entity_visibility_table.php'))->up();
        StorefrontVisibility::flush();
    }
}
