<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\DispatchState;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Dispatch\DispatchOutcome;
use App\Services\SalesChannels\Routing\DynamicFulfillmentRoutingEngine;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A channel order is made into one internal order so the platform's fulfilment can ship it, and a channel sold its lines together without
 * any cart of ours keeping two countries apart. So the materializer asks the same rule as the checkout and the tills (D1-02) of the very
 * rows it is about to write the lines from, before the order row exists.
 *
 * It answers in the dispatch's own shape: the attempt is given back with a reason on the dispatch row and is not retried, because a channel
 * order that mixes two selling companies will never stop mixing them. A legitimate single-country channel order is untouched, down to the
 * number of queries it makes.
 */
class ChannelOrderSingleCompanyTest extends DispatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->theStoreKeepsAProductOfEachCountry();
    }

    /** The country column as its migration adds it, product 10 Saudi and a second product, 11, of the UAE, both sold on the channel. */
    private function theStoreKeepsAProductOfEachCountry(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->char(StorefrontCatalog::COLUMN, 2)->default('SA'));
        StorefrontCatalog::flush();

        $this->makeProduct(11);
        $this->approveAtTorod(11);
        SalesChannelProductMapping::create([
            'account_id' => $this->account->id, 'product_id' => 11, 'listing_key' => 'SKU-2', 'external_sku' => 'SKU-2',
            'external_barcode' => 'SKU-2', 'status' => 'mapped', 'stock_location_id' => $this->torodLocation->id,
        ]);
        DB::table('products')->where('id', 11)->update([StorefrontCatalog::COLUMN => 'AE']);
    }

    /** One channel order of two lines: 100 of the Saudi product and 250 of the Emirati one. */
    private function placedWithTwoCountries(): ExternalOrderMapping
    {
        $order = $this->remoteOrder('10234567890', '3012345678', ChannelOrderStatus::New, [
            $this->remoteLine('L1', 'SKU-1', 'SKU-1', 1, '100'),
            $this->remoteLine('L2', 'SKU-2', 'SKU-2', 1, '250'),
        ], overrides: ['fulfillmentChannelRaw' => 'seller_pays', 'fulfillmentMode' => FulfillmentMode::MerchantManaged]);
        $this->placedOrders['3012345678'] = '10234567890';
        $mapping = app(ChannelOrderIngestor::class)->ingest($this->account, $order)->mapping;
        app(DynamicFulfillmentRoutingEngine::class)->route($mapping->refresh());

        return $mapping->refresh();
    }

    private function queriesOf(callable $dispatch): int
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        $dispatch();

        return $queries;
    }

    public function test_a_channel_order_of_two_countries_is_refused_with_a_reason_and_nothing_is_written(): void
    {
        $mapping = $this->placedWithTwoCountries();

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame(DispatchOutcome::BLOCKED, $outcome->state);
        $this->assertSame(['order_mixes_selling_companies'], $outcome->blockers);
        $this->assertSame([0, 0], [DB::table('orders')->count(), DB::table('order_details')->count()], 'no order and no line was written');
        $this->assertNull($mapping->refresh()->order_id, 'and the channel order still has no internal order');
    }

    public function test_the_refused_dispatch_is_not_tried_again_on_its_own(): void
    {
        $this->dispatcher()->dispatch($this->placedWithTwoCountries());

        $row = ChannelOrderDispatch::firstOrFail();
        $this->assertSame([DispatchState::Blocked->value, 'order_mixes_selling_companies'], [$row->state, $row->reason_code]);
        $this->assertNull($row->next_attempt_at, 'an order that mixes two selling companies will never stop mixing them');
        $this->assertNull($row->claim_token);
    }

    public function test_a_second_attempt_at_the_same_mixed_order_still_writes_nothing(): void
    {
        $mapping = $this->placedWithTwoCountries();

        $this->dispatcher()->dispatch($mapping, trigger: 'manual');
        $outcome = $this->dispatcher()->dispatch($mapping->refresh(), trigger: 'manual');

        $this->assertSame(['order_mixes_selling_companies'], $outcome->blockers);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_two_saudi_lines_of_two_selling_companies_are_refused_once_products_carry_one(): void
    {
        DB::table('products')->where('id', 11)->update([StorefrontCatalog::COLUMN => 'SA']);
        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger(StorefrontCatalog::ENTITY_COLUMN)->nullable());
        StorefrontCatalog::flush();
        DB::table('products')->where('id', 10)->update([StorefrontCatalog::ENTITY_COLUMN => 1]);
        DB::table('products')->where('id', 11)->update([StorefrontCatalog::ENTITY_COLUMN => 2]);

        $outcome = $this->dispatcher()->dispatch($this->placedWithTwoCountries());

        $this->assertSame(['order_mixes_selling_companies'], $outcome->blockers);
        $this->assertSame(0, DB::table('orders')->count());
    }

    /**
     * The state the company backfill is in from its first row to its last: one product carrying a company, the next not yet reached, both in
     * one country. The two halves of the selling scope are compared apart, so this is one selling company's order and it is materialized. It
     * matters most here: a blocked dispatch is never retried, so an over-refusal would leave the channel order waiting for a person.
     */
    public function test_a_half_backfilled_company_column_still_materializes_a_one_country_channel_order(): void
    {
        DB::table('products')->where('id', 11)->update([StorefrontCatalog::COLUMN => 'SA']);
        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger(StorefrontCatalog::ENTITY_COLUMN)->nullable());
        StorefrontCatalog::flush();
        DB::table('products')->where('id', 10)->update([StorefrontCatalog::ENTITY_COLUMN => 1]);

        $outcome = $this->dispatcher()->dispatch($this->placedWithTwoCountries());

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state);
        $this->assertSame([10, 11], DB::table('order_details')->orderBy('id')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNull(ChannelOrderDispatch::firstOrFail()->reason_code, 'nothing is left waiting for a person');
    }

    // ---- what a legitimate channel order still does ------------------------------------------------------

    public function test_a_channel_order_of_one_country_is_still_made_into_one_order_with_its_lines(): void
    {
        $outcome = $this->dispatcher()->dispatch($this->placed());

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state);
        $order = DB::table('orders')->sole();
        $this->assertSame([100001, '200.000000'], [(int) $order->id, number_format((float) $order->order_amount, 6, '.', '')]);
        $line = DB::table('order_details')->sole();
        $this->assertSame([10, 2], [(int) $line->product_id, (int) $line->qty]);
        $this->assertSame('Product 10', json_decode((string) $line->product_details, true)['name'], 'the line still carries the product as the table holds it');
        $this->assertSame('Channel order 10234567890', $order->order_note, 'and the channel\'s own order number, as it did before the guard');
    }

    public function test_two_lines_of_one_country_are_still_both_written(): void
    {
        DB::table('products')->where('id', 11)->update([StorefrontCatalog::COLUMN => 'SA']);

        $outcome = $this->dispatcher()->dispatch($this->placedWithTwoCountries());

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state);
        $this->assertSame([10, 11], DB::table('order_details')->orderBy('id')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_before_the_country_migration_has_run_a_mixed_channel_order_is_not_judged(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(StorefrontCatalog::COLUMN));
        StorefrontCatalog::flush();

        $outcome = $this->dispatcher()->dispatch($this->placedWithTwoCountries());

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state, 'the database the platform has today is not judged');
        $this->assertSame(2, DB::table('order_details')->count());
    }

    /**
     * What one Saudi channel order costs, measured on both engines before and after the guard and unchanged by it: the products the lines
     * are written from are read once, where each line used to read its own. The two figures differ because a real server also hands out the
     * order number under a named lock, which SQLite has no need of.
     */
    public function test_one_saudi_channel_order_costs_the_queries_it_cost_before(): void
    {
        $mapping = $this->placed();

        $queries = $this->queriesOf(fn () => $this->dispatcher()->dispatch($mapping));

        $this->assertSame(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 88 : 106, $queries);
        $this->assertSame(1, DB::table('orders')->count());
    }
}
