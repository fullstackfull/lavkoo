<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Models\FulfillmentRoutingDecision;
use App\Models\InventoryLocation;
use App\Models\SalesChannelApiLog;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/** The listings read from the channels, and the decisions about them. Linking changes no product; saving a setting publishes nothing. */
class AdminSalesChannelsProductsTest extends AdminSalesChannelsTestCase
{
    private function index(array $query = []): string
    {
        return $this->get(route('admin.sales-channels.products.index', $query))->assertOk()->getContent();
    }

    private function backTo(): static
    {
        return $this->from(route('admin.sales-channels.products.index'));
    }

    public function test_the_table_shows_what_is_on_the_channel_what_is_linked_and_what_was_published(): void
    {
        $account = $this->trendyolAccount();
        $product = $this->product('Paracetamol 500mg', 'PARA-500');
        $this->listing($account, [
            'status' => 'mapped', 'product_id' => $product, 'match_method' => 'manual', 'external_title' => 'Paracetamol 500mg x20', 'listing_key' => 'BC-1', 'external_sku' => 'SKU-1',
            'external_barcode' => 'BC-1', 'external_id' => 'EXT-9', 'remote_price' => '49.500000', 'remote_quantity' => 12, 'remote_status' => 'ACTIVE', 'fulfillment_mode' => 'fbm',
            'inventory_publish' => true, 'last_published_quantity' => 15, 'last_published_at' => now()->subHour(),
            'snapshot' => ['publish' => ['stock' => ['state' => 'failed', 'error' => 'Barcode is not found', 'attempted' => '15', 'failures' => 1]]],
        ]);

        $html = $this->index();

        foreach (['Paracetamol 500mg x20', 'BC-1', 'SKU-1', 'EXT-9', '49.50', 'SAR', 'ACTIVE', 'Paracetamol 500mg', 'PARA-500', 'Barcode is not found', 'Trendyol KSA'] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        $this->assertStringContainsString(e($this->labels()->listingStatus('mapped')), $html);
        $this->assertStringContainsString(e($this->labels()->publishState('failed')), $html);
        $this->assertStringContainsString(e($this->labels()->matchMethod('manual')), $html);
        $this->assertStringContainsString(e(translate('we_last_sent')) . ': 15', $html, 'what was last sent is shown next to what the channel shows');
        $this->assertStringContainsString(e(translate('the_channel_shows')) . ': 12', $html);
    }

    public function test_what_is_not_known_is_an_em_dash_and_a_badge_never_a_zero(): void
    {
        $account = $this->trendyolAccount();
        $this->listing($account, ['remote_price' => null, 'remote_quantity' => null, 'remote_currency' => null]);

        $html = $this->index();

        $this->assertStringContainsString('<span class="text-muted">—</span>', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, e(translate('unknown_value'))), 'the price and the quantity are both unknown');
        $this->assertStringContainsString(e(translate('not_linked')), $html);
        $this->assertStringNotContainsString('0.00', $html);
        $this->assertStringNotContainsString(e(translate('the_channel_shows')) . ': 0', $html);
    }

    public function test_a_listing_the_last_full_read_did_not_see_is_marked_as_such(): void
    {
        $account = $this->trendyolAccount();
        $this->listing($account, ['listing_key' => 'BC-SEEN', 'remote_status' => 'ACTIVE']);
        $this->listing($account, ['listing_key' => 'BC-GONE', 'remote_status' => 'not_seen']);

        $html = $this->index();

        $this->assertSame(1, substr_count($html, e(translate('not_seen_in_the_last_full_read'))), 'only the listing that disappeared from the channel carries the mark');
    }

    public function test_a_suggestion_and_a_conflict_are_told_apart_from_a_link(): void
    {
        $account = $this->trendyolAccount();
        $suggested = $this->product('Ibuprofen 400mg', 'IBU-400');
        $this->listing($account, ['status' => 'suggested', 'suggested_product_id' => $suggested, 'match_method' => 'product_code']);
        $this->listing($account, ['status' => 'conflict', 'match_method' => 'ambiguous']);

        $html = $this->index();

        $this->assertStringContainsString(e(translate('suggestion')), $html);
        $this->assertStringContainsString('Ibuprofen 400mg', $html);
        $this->assertStringContainsString(e(translate('accept_suggestion')), $html);
        $this->assertStringContainsString(e(translate('more_than_one_product_fits_or_it_is_linked_elsewhere')), $html);
        $this->assertSame(1, substr_count($html, e(translate('accept_suggestion'))), 'only the suggestion can be accepted');
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $trendyol = $this->trendyolAccount();
        $amazon = $this->amazonAccount();
        $this->listing($trendyol, ['external_title' => 'Alpha cream', 'status' => 'unmapped', 'external_sku' => 'AL-1', 'external_barcode' => '1111', 'listing_key' => '1111']);
        $this->listing($trendyol, ['external_title' => 'Beta syrup', 'status' => 'mapped', 'product_id' => 1, 'external_sku' => 'BE-1', 'external_barcode' => '2222', 'listing_key' => '2222']);
        $this->listing($amazon, ['external_title' => 'Gamma tablets', 'status' => 'ignored', 'external_sku' => 'GA-1', 'external_barcode' => '3333', 'listing_key' => 'GA-1']);

        $this->assertListed(['Alpha cream', 'Beta syrup', 'Gamma tablets'], $this->index());
        $this->assertListed(['Gamma tablets'], $this->index(['account' => $amazon->id]));
        $this->assertListed(['Beta syrup'], $this->index(['status' => 'mapped']));
        $this->assertListed(['Alpha cream'], $this->index(['unlinked' => 1]), 'only the listings that wait for a link');
        $this->assertListed(['Alpha cream'], $this->index(['q' => 'AL-1']));
        $this->assertListed(['Beta syrup'], $this->index(['q' => '2222']));
        $this->assertListed(['Gamma tablets'], $this->index(['q' => 'gamma']));
        $this->assertListed([], $this->index(['status' => 'conflict']));
        $this->assertStringContainsString(e(translate('no_listing_matches_the_filters')), $this->index(['status' => 'conflict']));
    }

    /** @param list<string> $titles */
    private function assertListed(array $titles, string $html): void
    {
        foreach (['Alpha cream', 'Beta syrup', 'Gamma tablets'] as $title) {
            in_array($title, $titles, true) ? $this->assertStringContainsString($title, $html) : $this->assertStringNotContainsString($title, $html);
        }
    }

    public function test_a_list_longer_than_a_page_is_paged_and_keeps_its_filters(): void
    {
        $account = $this->trendyolAccount();
        for ($index = 0; $index < 30; $index++) {
            $this->listing($account, ['external_title' => sprintf('Listing number %02d', $index)]);
        }

        $first = $this->index(['status' => 'unmapped']);
        $this->assertSame(25, preg_match_all('/Listing number \d\d/', $first));
        $this->assertStringContainsString('status=unmapped&amp;page=2', $first);

        $second = $this->index(['status' => 'unmapped', 'page' => 2]);
        $this->assertSame(5, preg_match_all('/Listing number \d\d/', $second));
    }

    public function test_a_query_that_is_not_what_the_form_sends_is_no_filter_and_no_error(): void
    {
        $this->listing($this->trendyolAccount(), ['external_title' => 'Still listed']);

        $html = $this->get(route('admin.sales-channels.products.index', ['q' => ['x'], 'status' => ['mapped'], 'account' => ['1'], 'unlinked' => ['yes'], 'page' => 'abc']))->assertOk()->getContent();

        $this->assertStringContainsString('Still listed', $html);
    }

    // ---- linking ------------------------------------------------------------------------------------------

    public function test_a_person_links_a_listing_to_one_of_our_products(): void
    {
        $account = $this->trendyolAccount();
        $listing = $this->listing($account);
        $product = $this->product();

        $this->backTo()->post(route('admin.sales-channels.products.link', $listing), ['product_id' => $product, 'variant_key' => ''])->assertRedirect(route('admin.sales-channels.products.index'));

        $listing->refresh();
        $this->assertSame(['mapped', $product, 'manual', 1], [$listing->status, $listing->product_id, $listing->match_method, $listing->mapped_by]);
        $this->assertNotNull($listing->mapped_at);
        $this->assertNull($listing->suggested_product_id);
        $this->assertToastContains(translate('listing_linked'));
        $this->assertSame(1, DB::table('products')->count(), 'linking creates and changes no product');
    }

    public function test_only_one_of_our_own_physical_products_can_be_chosen(): void
    {
        $listing = $this->listing($this->trendyolAccount());
        $url = route('admin.sales-channels.products.link', $listing);

        foreach ([$this->product('Seller product', 'S-1', null, 'seller'), $this->product('Digital product', 'D-1', null, 'admin', 'digital'), 99999] as $notChoosable) {
            $this->backTo()->post($url, ['product_id' => $notChoosable])->assertSessionHasErrorsIn('listing_' . $listing->id, 'product_id');
        }
        $this->backTo()->post($url, [])->assertSessionHasErrorsIn('listing_' . $listing->id, 'product_id');
        $this->assertSame('unmapped', $listing->fresh()->status);
    }

    public function test_an_ignored_listing_must_be_restored_before_it_is_linked(): void
    {
        $listing = $this->listing($this->trendyolAccount(), ['status' => 'ignored']);

        $this->backTo()->post(route('admin.sales-channels.products.link', $listing), ['product_id' => $this->product()])->assertRedirect();

        $this->assertToastContains(translate('listing_is_ignored_restore_it_first'));
        $this->assertSame('ignored', $listing->fresh()->status);
    }

    public function test_linking_a_listing_links_the_order_lines_that_waited_for_it_and_decides_those_orders_again(): void
    {
        $account = $this->trendyolAccount();
        $order = $this->channelOrder($account, ['review_reason' => 'unmapped_lines', 'execution_policy' => 'manual_review'], [['sku' => 'SKU-X', 'barcode' => 'BC-X']]);
        $listing = $this->listing($account, ['listing_key' => 'BC-X', 'external_sku' => 'SKU-X', 'external_barcode' => 'BC-X']);
        $product = $this->product();

        $this->backTo()->post(route('admin.sales-channels.products.link', $listing), ['product_id' => $product]);

        $this->assertSame($product, $order->lines()->firstOrFail()->product_id);
        $decision = FulfillmentRoutingDecision::where('external_order_mapping_id', $order->id)->firstOrFail();
        $this->assertSame('no_rule_matched', $decision->reason_code, 'with its line linked the order is decided on its facts, and no rule matches');
        $this->assertToastContains(translate('waiting_order_lines_linked'));
    }

    public function test_a_product_that_is_already_linked_elsewhere_is_linked_again_with_a_warning(): void
    {
        $account = $this->trendyolAccount();
        $product = $this->product();
        $this->listing($account, ['status' => 'mapped', 'product_id' => $product, 'listing_key' => 'FIRST-KEY']);
        $second = $this->listing($account);

        $this->backTo()->post(route('admin.sales-channels.products.link', $second), ['product_id' => $product]);

        $this->assertSame('mapped', $second->fresh()->status, 'a person may decide that two listings are one product');
        $this->assertToastContains(translate('product_already_linked_to_other_listings'));
        $this->assertToastContains('FIRST-KEY');
    }

    public function test_a_suggestion_becomes_a_link_only_when_a_person_accepts_it(): void
    {
        $account = $this->trendyolAccount();
        $product = $this->product();
        $suggested = $this->listing($account, ['status' => 'suggested', 'suggested_product_id' => $product, 'match_method' => 'product_code']);
        $plain = $this->listing($account);

        $this->backTo()->post(route('admin.sales-channels.products.accept-suggestion', $plain))->assertRedirect();
        $this->assertToastContains(translate('no_suggestion_to_accept'));
        $this->assertSame('unmapped', $plain->fresh()->status);

        $this->backTo()->post(route('admin.sales-channels.products.accept-suggestion', $suggested))->assertRedirect();
        $suggested->refresh();
        $this->assertSame(['mapped', $product, 'product_code', 1], [$suggested->status, $suggested->product_id, $suggested->match_method, $suggested->mapped_by], 'it keeps how it was matched and records who accepted it');
    }

    public function test_ignoring_a_listing_drops_its_link_and_both_publish_switches_and_it_can_be_restored(): void
    {
        $listing = $this->listing($this->trendyolAccount(), ['status' => 'mapped', 'product_id' => $this->product(), 'inventory_publish' => true, 'price_publish' => true]);

        $this->backTo()->post(route('admin.sales-channels.products.ignore', $listing))->assertRedirect();

        $listing->refresh();
        $this->assertSame(['ignored', null, false, false], [$listing->status, $listing->product_id, $listing->inventory_publish, $listing->price_publish]);
        $this->assertStringContainsString(e(translate('restore_listing')), $this->index());

        $this->backTo()->post(route('admin.sales-channels.products.unignore', $listing))->assertRedirect();
        $this->assertSame('unmapped', $listing->fresh()->status);

        $this->forgetToasts();
        $this->backTo()->post(route('admin.sales-channels.products.unignore', $listing));
        $this->assertToastContains(translate('listing_is_not_ignored'));
    }

    // ---- settings -----------------------------------------------------------------------------------------

    public function test_the_settings_are_saved_and_nothing_is_published(): void
    {
        $account = $this->trendyolAccount();
        $listing = $this->listing($account, ['status' => 'mapped', 'product_id' => $this->product()]);
        $own = app(InventoryLocationService::class)->ensureDefaults()[2];
        Http::fake();

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), [
            'stock_location_id' => $own->id, 'price_source' => 'manual', 'manual_price' => '49.9', 'inventory_publish' => 1, 'price_publish' => 1, 'safety_buffer' => 2, 'max_publish_quantity' => 50,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $listing->refresh();
        $this->assertSame([$own->id, 'manual', true, true, 2, 50, '49.900000'], [
            $listing->stock_location_id, $listing->price_source, $listing->inventory_publish, $listing->price_publish, $listing->safety_buffer, $listing->max_publish_quantity, $listing->snapshot['manual_price'],
        ]);
        Http::assertNothingSent();
        $this->assertSame(0, SalesChannelApiLog::count(), 'not even a rehearsal is logged');
        $this->assertNull($listing->last_published_at);
        $this->assertToastContains(translate('listing_settings_saved_nothing_published'));
        $this->assertStringContainsString('value="49.90"', $this->index());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badSettings(): array
    {
        return [
            'a manual source with no price' => [['price_source' => 'manual'], 'manual_price'],
            'a price of zero' => [['price_source' => 'manual', 'manual_price' => '0'], 'manual_price'],
            'a price that is not a number' => [['price_source' => 'manual', 'manual_price' => 'cheap'], 'manual_price'],
            'a price source that does not exist' => [['price_source' => 'storefront'], 'price_source'],
            'a price switch on a source that is not a price a person set' => [['price_source' => 'channel', 'price_publish' => 1], 'price_publish'],
            'a location that does not exist' => [['price_source' => 'internal', 'stock_location_id' => 99999], 'stock_location_id'],
            'a negative safety buffer' => [['price_source' => 'internal', 'safety_buffer' => -1], 'safety_buffer'],
            'a maximum that is not a whole number' => [['price_source' => 'internal', 'max_publish_quantity' => 1.5], 'max_publish_quantity'],
        ];
    }

    #[DataProvider('badSettings')]
    public function test_bad_settings_are_refused_on_the_listing_they_were_typed_for(array $input, string $field): void
    {
        $listing = $this->listing($this->trendyolAccount(), ['status' => 'mapped', 'product_id' => $this->product()]);

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), $input)->assertSessionHasErrorsIn('listing_' . $listing->id, $field);

        $this->assertSame('internal', $listing->fresh()->price_source);
        $this->assertFalse($listing->fresh()->price_publish);
    }

    public function test_a_place_that_is_switched_off_cannot_be_the_stock_source(): void
    {
        $listing = $this->listing($this->trendyolAccount(), ['status' => 'mapped', 'product_id' => $this->product()]);
        $own = app(InventoryLocationService::class)->ensureDefaults()[2];
        $own->forceFill(['is_active' => false])->save();

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), ['price_source' => 'internal', 'stock_location_id' => $own->id])->assertSessionHasErrorsIn('listing_' . $listing->id, 'stock_location_id');
    }

    public function test_amazon_held_stock_is_never_pushed_so_the_switch_is_refused(): void
    {
        $listing = $this->listing($this->amazonAccount(), ['status' => 'mapped', 'product_id' => $this->product(), 'fulfillment_mode' => 'fba']);

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), ['price_source' => 'internal', 'inventory_publish' => 1])->assertSessionHasErrorsIn('listing_' . $listing->id, 'inventory_publish');

        $this->assertFalse($listing->fresh()->inventory_publish);
    }

    // ---- searching our products ---------------------------------------------------------------------------

    public function test_the_product_search_offers_only_our_own_physical_products(): void
    {
        $this->product('Paracetamol 500mg', 'PARA-500', '6280000000011');
        $this->product('Paracetamol e-book', 'PARA-BOOK', null, 'admin', 'digital');
        $this->product('Paracetamol from a seller', 'PARA-SELL', null, 'seller');
        $this->product('Vitamin C', 'VIT-C', '6280000000028');

        $byName = $this->getJson(route('admin.sales-channels.products.product-search', ['q' => 'Parace']))->assertOk()->json();
        $this->assertSame(['Paracetamol 500mg'], array_column($byName, 'name'));
        $this->assertSame(['id', 'name', 'code', 'barcode'], array_keys($byName[0]));

        $this->assertSame(['Vitamin C'], array_column($this->getJson(route('admin.sales-channels.products.product-search', ['q' => 'VIT-C']))->json(), 'name'), 'by code');
        $this->assertSame(['Vitamin C'], array_column($this->getJson(route('admin.sales-channels.products.product-search', ['q' => '6280000000028']))->json(), 'name'), 'by barcode');
        $this->assertSame([], $this->getJson(route('admin.sales-channels.products.product-search', ['q' => 'P']))->json(), 'one letter is not a search');
        $this->assertSame([], $this->getJson(route('admin.sales-channels.products.product-search', ['q' => ['x']]))->json());
    }

    public function test_the_product_search_never_returns_more_than_twenty(): void
    {
        for ($index = 0; $index < 25; $index++) {
            $this->product('Many ' . $index, 'M-' . $index);
        }

        $this->assertCount(20, $this->getJson(route('admin.sales-channels.products.product-search', ['q' => 'Many']))->json());
    }

    private function labels(): ChannelLabels
    {
        return app(ChannelLabels::class);
    }
}
