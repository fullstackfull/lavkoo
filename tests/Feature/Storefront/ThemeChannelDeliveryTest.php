<?php

namespace Tests\Feature\Storefront;

use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Commerce\CampaignResolver;
use App\Services\Theme\ThemeDelivery;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-03: what the theme engine sends to a surface depends on the channel that asked, and a channel with no renderer behind it (the seller app) is sent no section at all.
 * The website draws every section, the customer app what its build declares, and the seller app declares nothing: before this, a request that named the seller app's channel was sent
 * the same page as the customer app, from the customer app's cache entry. The stored checksum of a version moves when only the channels of a section change.
 */
class ThemeChannelDeliveryTest extends TestCase
{
    use BuildsThemeWorld;

    private const WITHHELD_REASON = 'no_renderer_exists_for_this_channel';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->openEveryStorefront();
        $this->giveStorefrontTheme(name: 'Saudi', country: null, revision: 3, spacerHeight: 41, page: 'saudi-offers', active: true);
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    private function homeSectionsFor(?string $channel): array
    {
        $headers = $channel === null ? [] : ['X-UI-Channel' => $channel];

        return $this->getJson('/api/v1/theme/home', $headers)->assertOk()->json();
    }

    private function restrictTheSectionTo(array $channels): void
    {
        ThemeSection::query()->firstOrFail()->update(['channels' => $channels]);
    }

    private function restrictTheSectionOf(ThemeVersion $version, array $channels): void
    {
        ThemeSection::query()->where('theme_version_id', $version->id)->firstOrFail()->update(['channels' => $channels]);
    }

    private function aPublishedVersionWithOneSection(array $sectionAttributes = []): ThemeVersion
    {
        $version = ThemeVersion::query()->create(['theme_id' => 1, 'label' => 'Checksummed', 'status' => ThemeVersion::STATUS_DRAFT]);
        ThemeSection::query()->create($sectionAttributes + ['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer', 'sort_order' => 1, 'settings' => ['height' => 10]]);

        return $version;
    }

    private function storedChecksumOf(ThemeVersion $version): string
    {
        return (string) app(ThemeDelivery::class)->stampVersion($version)->checksum;
    }

    /** The checksum as it was computed before channels joined it: the structure of a version, section by section, without them. */
    private function checksumTheOldWay(ThemeVersion $version): string
    {
        $structure = ThemeSection::with('blocks')
            ->where('theme_version_id', $version->id)
            ->orderBy('page')->orderBy('sort_order')
            ->get()
            ->map(fn (ThemeSection $section): array => [
                $section->uuid, $section->page, $section->type, $section->sort_order,
                $section->is_visible, $section->settings,
                $section->starts_at?->toIso8601String(), $section->ends_at?->toIso8601String(),
                $section->platforms, $section->audience,
                $section->blocks->map(fn ($block): array => [$block->type, $block->sort_order, $block->is_visible, $block->settings])->all(),
            ])->all();

        return substr(hash('sha256', json_encode([$version->settings, $structure])), 0, 32);
    }

    // ---- the seller app is sent no section ---------------------------------------------------------------------------------------------------------

    public function test_a_client_that_names_the_seller_apps_channel_is_sent_no_section_and_is_told_why(): void
    {
        $page = $this->homeSectionsFor(channel: 'vendor_app');

        $this->assertSame([], $page['sections']);
        $this->assertSame(['delivered' => 0, 'withheld' => ['spacer' => self::WITHHELD_REASON]], $page['compatibility']);
    }

    public function test_the_channel_may_be_named_in_the_query_as_well_as_in_the_header(): void
    {
        $page = $this->getJson('/api/v1/theme/home?channel=vendor_app')->assertOk()->json();

        $this->assertSame([], $page['sections']);
        $this->assertSame(self::WITHHELD_REASON, $page['compatibility']['withheld']['spacer']);
    }

    public function test_the_customer_app_and_a_client_that_names_no_channel_are_sent_the_page_as_before(): void
    {
        foreach ([null, 'customer_app'] as $channel) {
            $page = $this->homeSectionsFor(channel: $channel);

            $this->assertSame(['spacer'], array_column($page['sections'], 'type'), (string) $channel);
            $this->assertSame(['delivered' => 1, 'withheld' => []], $page['compatibility'], (string) $channel);
        }
    }

    public function test_a_section_built_for_the_seller_app_is_sent_to_nobody_because_nothing_draws_it(): void
    {
        $this->restrictTheSectionTo(channels: ['vendor_app']);

        $this->assertSame([], $this->homeSectionsFor(channel: 'customer_app')['sections']);
        $this->assertSame([], $this->homeSectionsFor(channel: null)['sections']);
        $this->assertSame([], $this->homeSectionsFor(channel: 'vendor_app')['sections']);
    }

    public function test_a_section_built_for_the_customer_app_is_not_sent_to_the_seller_app(): void
    {
        $this->restrictTheSectionTo(channels: ['customer_app']);

        $this->assertSame(['spacer'], array_column($this->homeSectionsFor(channel: 'customer_app')['sections'], 'type'));
        $this->assertSame([], $this->homeSectionsFor(channel: 'vendor_app')['sections']);
    }

    public function test_a_live_campaign_is_spliced_into_the_page_of_the_customer_app_and_not_into_the_seller_apps(): void
    {
        $this->app->instance(CampaignResolver::class, new class extends CampaignResolver
        {
            public function overridesFor(string $page, ?\Illuminate\Support\Carbon $at = null): array
            {
                return [['slot' => 'top', 'section' => ['type' => 'spacer', 'settings' => ['height' => 99]], 'campaign_id' => 7]];
            }

            public function stamp(?string $page = 'home'): ?string
            {
                return null;
            }
        });

        $customerApp = $this->homeSectionsFor(channel: 'customer_app');
        $sellerApp = $this->homeSectionsFor(channel: 'vendor_app');

        $this->assertSame([99, 41], array_map(fn (array $section): int => (int) $section['settings']['height'], $customerApp['sections']));
        $this->assertSame([], $sellerApp['sections']);
        $this->assertSame(['delivered' => 0, 'withheld' => ['spacer' => self::WITHHELD_REASON]], $sellerApp['compatibility']);
    }

    // ---- what one channel caches is not served to another --------------------------------------------------------------------------------------------

    public function test_the_page_the_customer_app_warmed_in_the_cache_is_not_served_to_the_seller_app(): void
    {
        $this->assertCount(1, $this->homeSectionsFor(channel: 'customer_app')['sections']);

        $this->assertSame([], $this->homeSectionsFor(channel: 'vendor_app')['sections']);
        $this->assertCount(1, $this->homeSectionsFor(channel: 'customer_app')['sections']);
    }

    public function test_the_empty_page_the_seller_app_warmed_in_the_cache_is_not_served_to_the_customer_app(): void
    {
        $this->assertSame([], $this->homeSectionsFor(channel: 'vendor_app')['sections']);

        $this->assertCount(1, $this->homeSectionsFor(channel: 'customer_app')['sections']);
    }

    // ---- the checksum of a version -----------------------------------------------------------------------------------------------------------------

    public function test_a_change_of_the_channels_of_a_section_alone_moves_the_stored_checksum(): void
    {
        $version = $this->aPublishedVersionWithOneSection();
        $unrestricted = $this->storedChecksumOf($version);

        $this->restrictTheSectionOf(version: $version, channels: ['web']);
        $restrictedToTheWeb = $this->storedChecksumOf($version);

        $this->restrictTheSectionOf(version: $version, channels: ['customer_app']);
        $restrictedToTheApp = $this->storedChecksumOf($version);

        $this->assertCount(3, array_unique([$unrestricted, $restrictedToTheWeb, $restrictedToTheApp]));
    }

    public function test_naming_the_same_channels_in_another_order_or_twice_is_not_a_change(): void
    {
        $version = $this->aPublishedVersionWithOneSection(['channels' => ['web', 'customer_app']]);
        $checksum = $this->storedChecksumOf($version);

        $this->restrictTheSectionOf(version: $version, channels: ['customer_app', 'web', 'web']);

        $this->assertSame($checksum, $this->storedChecksumOf($version));
    }

    public function test_a_section_that_is_not_restricted_keeps_the_checksum_it_always_gave(): void
    {
        foreach ([null, []] as $channels) {
            $version = $this->aPublishedVersionWithOneSection(['channels' => $channels]);

            $this->assertSame($this->checksumTheOldWay($version), $this->storedChecksumOf($version), 'channels: ' . json_encode($channels));
        }
    }

    public function test_a_channel_the_engine_does_not_know_is_no_restriction_at_all(): void
    {
        $version = $this->aPublishedVersionWithOneSection(['channels' => ['a_channel_nobody_has_heard_of']]);

        $this->assertSame($this->checksumTheOldWay($version), $this->storedChecksumOf($version));
    }
}
