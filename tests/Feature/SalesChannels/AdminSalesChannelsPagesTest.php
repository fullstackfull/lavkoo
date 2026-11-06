<?php

namespace Tests\Feature\SalesChannels;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Every screen of the module opens, with something on it, and the ones the sidebar links to are there under the names it uses. */
class AdminSalesChannelsPagesTest extends AdminSalesChannelsTestCase
{
    /** @return list<string> the address of every page, with one of everything to look at */
    private function pages(): array
    {
        $trendyol = $this->trendyolAccount();
        $amazon = $this->amazonAccount();
        $this->listing($trendyol);
        $order = $this->channelOrder($trendyol);
        $this->routingRule();
        $this->routingDecision($order);

        return [
            route('admin.sales-channels.index'),
            route('admin.sales-channels.accounts.create'),
            route('admin.sales-channels.accounts.create', ['channel' => 'amazon']),
            route('admin.sales-channels.accounts.create', ['channel' => 'trendyol']),
            route('admin.sales-channels.accounts.show', $trendyol),
            route('admin.sales-channels.accounts.show', $amazon),
            route('admin.sales-channels.products.index'),
            route('admin.sales-channels.orders.index'),
            route('admin.sales-channels.orders.show', $order),
            route('admin.sales-channels.routing.index'),
            route('admin.sales-channels.logs.index'),
            route('admin.sales-channels.logs.index', ['tab' => 'events']),
        ];
    }

    public function test_every_page_opens_with_a_title_and_content(): void
    {
        foreach ($this->pages() as $url) {
            $response = $this->get($url)->assertOk();

            $this->assertMatchesRegularExpression('/<title>\s*\S/', $response->getContent(), $url);
            $this->assertStringContainsString('class="k-page-head__title"', $response->getContent(), $url);
            $this->assertNotSame('', trim(strip_tags($response->getContent())), $url);
        }
    }

    public function test_the_pages_the_sidebar_links_to_exist_under_the_names_it_uses(): void
    {
        foreach (['index', 'products.index', 'orders.index', 'routing.index', 'logs.index'] as $name) {
            $this->assertTrue(Route::has('admin.sales-channels.' . $name), $name);
        }
        $this->assertSame('/admin/sales-channels', parse_url(route('admin.sales-channels.index'), PHP_URL_PATH));
        $this->assertSame('/admin/sales-channels/products', parse_url(route('admin.sales-channels.products.index'), PHP_URL_PATH));
        $this->assertSame('/admin/sales-channels/orders', parse_url(route('admin.sales-channels.orders.index'), PHP_URL_PATH));
        $this->assertSame('/admin/sales-channels/routing', parse_url(route('admin.sales-channels.routing.index'), PHP_URL_PATH));
        $this->assertSame('/admin/sales-channels/logs', parse_url(route('admin.sales-channels.logs.index'), PHP_URL_PATH));
    }

    public function test_every_page_links_to_the_others_so_that_nothing_is_a_dead_end(): void
    {
        $hub = $this->get(route('admin.sales-channels.index'))->getContent();

        foreach (['accounts.create', 'products.index', 'orders.index', 'routing.index', 'logs.index'] as $name) {
            $this->assertStringContainsString(route('admin.sales-channels.' . $name), $hub, $name);
        }
        foreach (['products.index', 'orders.index', 'routing.index', 'logs.index'] as $name) {
            $this->assertStringContainsString(route('admin.sales-channels.index'), $this->get(route('admin.sales-channels.' . $name))->getContent(), $name . ' leads back to the hub');
        }
    }

    public function test_a_record_that_does_not_exist_is_not_found(): void
    {
        $this->get(route('admin.sales-channels.accounts.show', 12345))->assertNotFound();
        $this->get(route('admin.sales-channels.orders.show', 12345))->assertNotFound();
        $this->get('/admin/sales-channels/accounts/not-a-number')->assertNotFound();
        $this->get('/admin/sales-channels/orders/not-a-number')->assertNotFound();
    }

    public function test_until_the_migrations_have_run_every_page_says_so_instead_of_failing(): void
    {
        Schema::drop('inventory_locations');

        foreach ([route('admin.sales-channels.index'), route('admin.sales-channels.products.index'), route('admin.sales-channels.orders.index'), route('admin.sales-channels.routing.index'), route('admin.sales-channels.logs.index')] as $url) {
            $response = $this->get($url)->assertStatus(503);
            $this->assertStringContainsString(e(translate('sales_channels_not_migrated')), $response->getContent(), $url);
        }
    }
}
