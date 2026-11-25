<?php

namespace Tests\Feature\Storefront;

use App\Models\Banner;
use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontVisibility;
use App\Services\Theme\ThemeBannerLink;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * F-02 and F-15: the banners table is shared by every storefront and a theme is not, so a builder edit may not reach out of its own market.
 *
 * `ThemeBannerLink` is the bridge between the Theme Builder's banner-shaped blocks and Promotion -> Banners, and it writes into that shared
 * table in two places. Both were unscoped, and both were wrong in a way a merchant would have seen on a live page:
 *
 *  - a banner MINTED from a draft was an unassigned row, and an unassigned banner is shown by EVERY storefront. So a picture dropped into an
 *    Emirati draft that nobody had published appeared on the Saudi storefront the moment the builder autosaved;
 *  - a builder edit PUSHED into a linked row rewrote the title, the link and the picture of that row for every storefront that renders it.
 *    Editing the Emirati draft changed what a Saudi section showed.
 *
 * Each test states which of those a merchant would have seen. The last two are the switch: with themes not belonging to storefronts there is
 * one theme and the question does not arise, and the platform writes exactly what it writes today.
 */
class ThemeBannerWritesStayInsideTheMarketTest extends TestCase
{
    use BuildsThemeWorld;

    private const PICTURE = 'uploads/draft-picture.webp';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->buildBannersAndTheirVisibility();
        Storage::fake('public');
        Storage::disk('public')->put(self::PICTURE, 'a picture');
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.themes.enabled' => true, 'gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.countries.AE.active' => true]);
        StorefrontVisibility::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('banners');
        Schema::dropIfExists('storages');
        Schema::dropIfExists(StorefrontVisibility::TABLE);
        $this->dropThemeWorld();
        StorefrontVisibility::flush();

        parent::tearDown();
    }

    /** What a merchant saw: a picture dropped into an Emirati draft showing on the Saudi storefront. */
    public function test_a_banner_minted_from_another_markets_draft_is_assigned_to_that_market_alone(): void
    {
        $block = $this->aDraftBlockOf(market: 'AE', settings: ['image' => $this->urlOfThePicture(), 'title' => 'Emirati offer']);

        app(ThemeBannerLink::class)->syncBlock($block);

        $banner = Banner::withoutGlobalScope('storefront_visibility')->firstOrFail();
        $this->assertSame(
            ['AE'],
            StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::BANNER, entityId: $banner->id),
            'the minted banner is unassigned, so every storefront shows it',
        );
        $this->assertSame('Emirati offer', $banner->title, 'and it is still registered in Banner Setup');
    }

    /** The default market keeps writing the unassigned rows it has always written, because that is what every banner on this platform is. */
    public function test_a_banner_minted_from_the_default_markets_draft_stays_unassigned(): void
    {
        $block = $this->aDraftBlockOf(market: 'SA', settings: ['image' => $this->urlOfThePicture(), 'title' => 'Saudi offer']);

        app(ThemeBannerLink::class)->syncBlock($block);

        $banner = Banner::withoutGlobalScope('storefront_visibility')->firstOrFail();
        $this->assertSame([], StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::BANNER, entityId: $banner->id));
    }

    /** What a merchant saw: editing the Emirati draft rewrote the headline of a banner the Saudi storefront renders. */
    public function test_an_edit_in_one_markets_draft_does_not_rewrite_a_banner_another_market_shows(): void
    {
        $banner = $this->aThemeBanner(title: 'Saudi headline');
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: $banner->id, countries: ['SA']);
        $block = $this->aDraftBlockOf(market: 'AE', settings: ['banner_id' => $banner->id, 'title' => 'Emirati headline']);

        app(ThemeBannerLink::class)->syncBlock($block, ['banner_id' => $banner->id, 'title' => 'Saudi headline']);

        $this->assertSame('Saudi headline', $banner->fresh()->title, "the Emirati draft rewrote the Saudi storefront's banner");
    }

    /** And an edit in the market the row belongs to still lands, which is the behaviour the bridge exists for. */
    public function test_an_edit_in_the_markets_own_draft_still_reaches_its_own_banner(): void
    {
        $banner = $this->aThemeBanner(title: 'Old headline');
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: $banner->id, countries: ['AE']);
        $block = $this->aDraftBlockOf(market: 'AE', settings: ['banner_id' => $banner->id, 'title' => 'New headline']);

        app(ThemeBannerLink::class)->syncBlock($block, ['banner_id' => $banner->id, 'title' => 'Old headline']);

        $this->assertSame('New headline', $banner->fresh()->title, 'the market may not edit its own banner');
    }

    /** A row every storefront shows is nobody's to rewrite from one market's draft. */
    public function test_an_unassigned_banner_is_not_rewritten_from_another_markets_draft(): void
    {
        $banner = $this->aThemeBanner(title: 'Shown everywhere');
        $block = $this->aDraftBlockOf(market: 'AE', settings: ['banner_id' => $banner->id, 'title' => 'Emirati headline']);

        app(ThemeBannerLink::class)->syncBlock($block, ['banner_id' => $banner->id, 'title' => 'Shown everywhere']);

        $this->assertSame('Shown everywhere', $banner->fresh()->title, 'a banner every storefront shows was rewritten from one market\'s draft');
    }

    /** The switch: with themes not per storefront there is one theme, and the edit lands exactly as it does today. */
    public function test_while_themes_do_not_belong_to_storefronts_an_edit_lands_as_it_does_today(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $banner = $this->aThemeBanner(title: 'Old headline');
        StorefrontVisibility::set(entityType: StorefrontVisibility::BANNER, entityId: $banner->id, countries: ['SA']);
        $block = $this->aDraftBlockOf(market: 'AE', settings: ['banner_id' => $banner->id, 'title' => 'New headline']);

        app(ThemeBannerLink::class)->syncBlock($block, ['banner_id' => $banner->id, 'title' => 'Old headline']);

        $this->assertSame('New headline', $banner->fresh()->title);
    }

    public function test_while_themes_do_not_belong_to_storefronts_a_minted_banner_stays_unassigned(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $block = $this->aDraftBlockOf(market: 'AE', settings: ['image' => $this->urlOfThePicture(), 'title' => 'Emirati offer']);

        app(ThemeBannerLink::class)->syncBlock($block);

        $banner = Banner::withoutGlobalScope('storefront_visibility')->firstOrFail();
        $this->assertSame([], StorefrontVisibility::storefrontsOf(entityType: StorefrontVisibility::BANNER, entityId: $banner->id));
    }

    /** @param array<string, mixed> $settings */
    private function aDraftBlockOf(string $market, array $settings): ThemeBlock
    {
        $theme = $this->giveTheme(name: 'Theme of ' . $market, country: $market);
        $version = ThemeVersion::query()->create([
            'theme_id' => $theme->id,
            'label' => 'Draft',
            'status' => ThemeVersion::STATUS_DRAFT,
            'revision' => 1,
            'checksum' => 'draft-of-' . $market,
        ]);
        $section = ThemeSection::query()->create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'slideshow', 'sort_order' => 1, 'settings' => []]);

        return ThemeBlock::query()->create(['theme_section_id' => $section->id, 'type' => 'slide', 'sort_order' => 1, 'settings' => $settings]);
    }

    private function aThemeBanner(string $title): Banner
    {
        return Banner::query()->create([
            'photo' => 'existing.webp',
            'banner_type' => ThemeBannerLink::THEME_BANNER_TYPE,
            'theme' => theme_root_path(),
            'published' => 1,
            'title' => $title,
            'resource_type' => 'custom',
        ]);
    }

    private function urlOfThePicture(): string
    {
        return Storage::disk('public')->url(self::PICTURE);
    }

    private function buildBannersAndTheirVisibility(): void
    {
        Schema::dropIfExists('banners');
        Schema::create('banners', static function (Blueprint $table): void {
            $table->id();
            $table->string('theme')->nullable();
            $table->string('photo')->nullable();
            $table->string('url')->nullable();
            $table->string('title')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('button_text')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('banner_type')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        // The Banner model's `saved` hook records which disk each picture is on; without this table every create throws inside the hook,
        // the bridge's own try/catch swallows it, and the row exists with nothing after it done.
        Schema::dropIfExists('storages');
        Schema::create('storages', static function (Blueprint $table): void {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists(StorefrontVisibility::TABLE);
        (require database_path('migrations/2026_12_08_100000_create_storefront_entity_visibility_table.php'))->up();
    }
}
