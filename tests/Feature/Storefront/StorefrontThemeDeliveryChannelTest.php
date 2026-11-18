<?php

namespace Tests\Feature\Storefront;

use App\Models\ThemeSection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-09.1 (found while proving it): the channel a client names in X-UI-Channel decides which sections it is sent, so it has to be part of what keys the delivered page in the cache. It is not
 * (ThemeDelivery::fingerprint holds the platform, the device, the audience, the segments, the language, the engine, the schema and the components, and no channel), while the response already says
 * `Vary: X-UI-Channel`. Two clients that differ only in their channel are served one cached page: the one that asked first decides what the other gets.
 */
class StorefrontThemeDeliveryChannelTest extends TestCase
{
    use BuildsThemeWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $theme = $this->giveTheme(name: 'Saudi', active: true);
        $version = $this->givePublishedVersion(theme: $theme, revision: 3, spacerHeight: 41);
        ThemeSection::query()->create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'custom_html', 'sort_order' => 2, 'settings' => ['content' => 'for the seller app'], 'channels' => ['vendor_app'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /** @return array<string, array{0: list<string>}> the order in which the two clients ask */
    public static function ordersOfChannels(): array
    {
        return ['the customer app first' => [['customer_app', 'vendor_app']], 'the seller app first' => [['vendor_app', 'customer_app']]];
    }

    /** @param list<string> $order */
    #[DataProvider('ordersOfChannels')]
    public function test_a_page_cached_for_one_channel_is_never_served_to_another(array $order): void
    {
        foreach ($order as $channel) {
            $home = $this->getJson('/api/v1/theme/home', ['X-UI-Channel' => $channel])->assertOk();

            $this->assertSame($channel === 'vendor_app' ? ['spacer', 'custom_html'] : ['spacer'], array_column($home->json('sections'), 'type'), $channel);
        }
    }
}
