<?php

namespace Tests\Feature\Storefront;

use App\Models\ThemeSection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-09.1: the channel a client names in X-UI-Channel decides which sections it is sent, so it has to be part of what keys the delivered page in the cache. It was not, while the response already
 * said `Vary: X-UI-Channel`, so two clients that differed only in their channel were served one cached page and the one that asked first decided what the other got. It is part of the key now, and
 * this test asks in both orders and reads what each channel is actually sent.
 *
 * What each is sent has also CHANGED since this was written, deliberately and by another lane (F-03 criterion 0): the seller app declares no renderer, so a channel with no renderer is sent no
 * section at all rather than sections it cannot draw, and every type it would have been sent is named in the compatibility report instead. So the two channels are still told apart here -- which is
 * what this test is for -- but by the seller app's empty list and its named withholdings against the customer app's one drawable section, not by two different lists of sections.
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

            if ($channel === 'vendor_app') {
                // No renderer, so no section, and both types named with the reason rather than quietly dropped.
                $this->assertSame([], array_column($home->json('sections'), 'type'), $channel);
                $this->assertSame(0, $home->json('compatibility.delivered'), $channel);
                $this->assertSame(
                    ['spacer' => 'no_renderer_exists_for_this_channel', 'custom_html' => 'no_renderer_exists_for_this_channel'],
                    $home->json('compatibility.withheld'),
                    $channel,
                );

                continue;
            }

            // The customer app draws the spacer; the custom_html section is kept for the seller app, so visibility leaves it out before capability is asked.
            $this->assertSame(['spacer'], array_column($home->json('sections'), 'type'), $channel);
            $this->assertSame([], $home->json('compatibility.withheld'), $channel);
        }
    }
}
