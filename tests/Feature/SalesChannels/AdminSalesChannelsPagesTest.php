<?php

namespace Tests\Feature\SalesChannels;

use Tests\Support\CreatesChannelRecords;

class AdminSalesChannelsPagesTest extends AdminPagesTestCase
{
    use CreatesChannelRecords;

    public function test_every_page_renders_with_content(): void
    {
        $trendyol = $this->trendyolAccount();
        $amazon = $this->amazonAccount();
        $listing = $this->listing($trendyol);
        $order = $this->channelOrder($trendyol);
        $this->routingRule();
        $this->routingDecision($order);

        $pages = [
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

        foreach ($pages as $url) {
            $response = $this->get($url);
            $response->assertOk();
            $this->assertNotSame('', trim(strip_tags($response->getContent())), $url);
        }
    }
}
