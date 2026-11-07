<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteListing;
use App\Services\SalesChannels\Sync\ListingSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

class ListingSyncServiceTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private ListingSyncService $listings;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->listings = app(ListingSyncService::class);
        $this->account = SalesChannelAccount::create(['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'enabled' => true, 'settings' => ['auto_link_exact_barcode' => false]]);
    }

    private function product(int $id, ?string $code, ?string $barcode, array $variation = [], string $addedBy = 'admin'): void
    {
        DB::table('products')->insert(['id' => $id, 'name' => 'Product ' . $id, 'code' => $code, 'barcode' => $barcode, 'added_by' => $addedBy, 'product_type' => 'physical', 'variation' => json_encode($variation)]);
    }

    private function listing(string $key, ?string $sku = null, ?string $barcode = null, string $title = 'A listing', ?int $quantity = 5, string $price = '99'): RemoteListing
    {
        return new RemoteListing($key, $sku, 'content-1', $barcode ?? $key, null, $title, null, 'onSale', Money::of($price), 'SAR', $quantity, CarbonImmutable::parse('2026-11-10'), ['title' => $title]);
    }

    public function test_a_listing_that_changes_at_the_channel_keeps_the_price_a_person_set_and_the_state_of_its_publishes(): void
    {
        $this->listings->sync($this->account, [$this->listingSaying(['brand_code' => 'B-1'], quantity: 5)]);
        $row = SalesChannelProductMapping::firstOrFail();
        $row->forceFill(['snapshot' => $row->snapshot + [
            'manual_price' => '120.000000',
            'publish' => ['stock' => ['state' => 'failed', 'attempted' => '3', 'failures' => 2]],
        ]])->save();

        // The channel now shows another quantity (for example because our own publish just changed it).
        $this->listings->sync($this->account, [$this->listingSaying(['brand_code' => 'B-2'], quantity: 3)]);

        $after = $row->fresh();
        $this->assertSame(3, $after->remote_quantity);
        $this->assertSame('120.000000', $after->snapshot['manual_price']);
        $this->assertSame(['state' => 'failed', 'attempted' => '3', 'failures' => 2], $after->snapshot['publish']['stock']);
        $this->assertSame('B-2', $after->snapshot['brand_code'], 'What the channel says is still refreshed.');
    }

    /** @param array<string, mixed> $snapshot */
    private function listingSaying(array $snapshot, int $quantity): RemoteListing
    {
        return new RemoteListing('999', null, 'content-1', '999', null, 'A listing', null, 'onSale', Money::of('99'), 'SAR', $quantity, CarbonImmutable::parse('2026-11-10'), $snapshot);
    }

    public function test_reading_listings_creates_unlinked_rows_and_touches_no_product(): void
    {
        $this->product(1, 'P-1', '111');

        $stats = $this->listings->sync($this->account, [$this->listing('999', title: 'Product 1')]);

        $this->assertSame(1, $stats['created']);
        $row = SalesChannelProductMapping::first();
        $this->assertNull($row->product_id);
        $this->assertSame('unmapped', $row->status);
        $this->assertSame(1, DB::table('products')->count());
    }

    public function test_a_name_is_never_a_match(): void
    {
        $this->product(1, 'P-1', '111');

        $this->listings->sync($this->account, [$this->listing('999', sku: 'ZZZ', title: 'Product 1')]);

        $this->assertSame('unmapped', SalesChannelProductMapping::first()->status);
        $this->assertNull(SalesChannelProductMapping::first()->suggested_product_id);
    }

    public function test_an_exact_identifier_is_suggested_not_linked(): void
    {
        $this->product(1, 'P-1', '111');

        $stats = $this->listings->sync($this->account, [$this->listing('111', sku: 'P-1')]);

        $row = SalesChannelProductMapping::first();
        $this->assertSame(1, $stats['suggested']);
        $this->assertSame('suggested', $row->status);
        $this->assertSame(1, $row->suggested_product_id);
        $this->assertNull($row->product_id);
    }

    public function test_a_variant_sku_is_found_and_the_variant_key_comes_with_it(): void
    {
        $this->product(2, 'P-2', null, [['type' => 'Red-XL', 'sku' => 'P-2-RXL', 'qty' => 3]]);

        $this->listings->sync($this->account, [$this->listing('222', sku: 'P-2-RXL')]);

        $row = SalesChannelProductMapping::first();
        $this->assertSame(2, $row->suggested_product_id);
        $this->assertSame('Red-XL', $row->variant_key);
    }

    public function test_an_identifier_that_fits_two_products_is_a_conflict_for_a_person(): void
    {
        $this->product(1, 'P-1', '111');
        $this->product(2, 'P-1', '222');

        $stats = $this->listings->sync($this->account, [$this->listing('999', sku: 'P-1')]);

        $this->assertSame(1, $stats['conflicts']);
        $this->assertSame('conflict', SalesChannelProductMapping::first()->status);
        $this->assertNull(SalesChannelProductMapping::first()->suggested_product_id);
    }

    public function test_a_seller_owned_product_is_never_proposed(): void
    {
        $this->product(1, 'P-1', '111', addedBy: 'seller');

        $this->listings->sync($this->account, [$this->listing('111', sku: 'P-1')]);

        $this->assertSame('unmapped', SalesChannelProductMapping::first()->status);
    }

    public function test_with_the_accounts_explicit_setting_one_unambiguous_exact_match_is_linked(): void
    {
        $this->account->forceFill(['settings' => ['auto_link_exact_barcode' => true]])->save();
        $this->product(1, 'P-1', '111');

        $stats = $this->listings->sync($this->account->fresh(), [$this->listing('111')]);

        $row = SalesChannelProductMapping::first();
        $this->assertSame(1, $stats['linked']);
        $this->assertSame('mapped', $row->status);
        $this->assertSame(1, $row->product_id);
        $this->assertSame('exact_barcode_auto', $row->match_method);
    }

    public function test_a_second_listing_cannot_take_a_product_already_linked_in_the_account(): void
    {
        $this->account->forceFill(['settings' => ['auto_link_exact_barcode' => true]])->save();
        $this->product(1, 'P-1', '111');
        $this->listings->sync($this->account->fresh(), [$this->listing('111')]);

        $this->listings->sync($this->account->fresh(), [$this->listing('111-copy', sku: 'P-1')]);

        $second = SalesChannelProductMapping::where('listing_key', '111-copy')->first();
        $this->assertSame('conflict', $second->status);
        $this->assertNull($second->product_id);
    }

    public function test_a_sync_never_replaces_an_existing_link_and_an_unchanged_listing_is_left_alone(): void
    {
        $this->product(1, 'P-1', '111');
        $this->product(2, 'P-2', '222');
        $this->listings->sync($this->account, [$this->listing('111', title: 'Old title')]);
        $row = SalesChannelProductMapping::first();
        $row->forceFill(['product_id' => 2, 'status' => 'mapped', 'match_method' => 'manual'])->save();

        $changed = $this->listings->sync($this->account, [$this->listing('111', title: 'New title', price: '120')]);
        $unchanged = $this->listings->sync($this->account, [$this->listing('111', title: 'New title', price: '120')]);

        $row = $row->fresh();
        $this->assertSame(1, $changed['updated']);
        $this->assertSame(1, $unchanged['unchanged']);
        $this->assertSame(2, $row->product_id);
        $this->assertSame('mapped', $row->status);
        $this->assertSame('120.000000', $row->remote_price);
        $this->assertSame('New title', $row->external_title);
        $this->assertSame(1, SalesChannelProductMapping::count());
    }

    public function test_listings_missing_from_a_complete_run_are_flagged_not_deleted(): void
    {
        $this->listings->sync($this->account, [$this->listing('111'), $this->listing('222')]);
        $start = now()->addSecond();
        $this->travel(2)->seconds();
        $this->listings->sync($this->account, [$this->listing('111')]);

        $flagged = $this->listings->markUnseen($this->account, $start);

        $this->assertSame(1, $flagged);
        $this->assertSame('not_seen', SalesChannelProductMapping::where('listing_key', '222')->first()->remote_status);
        $this->assertSame(2, SalesChannelProductMapping::count());
    }

    public function test_a_listing_that_comes_back_unchanged_loses_the_not_seen_flag_and_gets_the_channels_status_back(): void
    {
        $this->listings->sync($this->account, [$this->listing('111')]);
        $start = now()->addSecond();
        $this->travel(2)->seconds();
        $this->listings->markUnseen($this->account, $start);
        $this->assertSame('not_seen', SalesChannelProductMapping::firstOrFail()->remote_status);

        $this->travel(5)->minutes();
        $this->listings->sync($this->account, [$this->listing('111')]);

        $row = SalesChannelProductMapping::firstOrFail();
        $this->assertNotSame('not_seen', $row->remote_status, 'It was seen again, unchanged: the badge must not stay.');
        $this->assertSame($this->listing('111')->status, $row->remote_status);
    }
}
