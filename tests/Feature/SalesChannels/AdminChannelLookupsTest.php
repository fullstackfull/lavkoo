<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\ChannelAccountOperationsController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * The reads a person can now ask a channel for, from the button to the transport.
 *
 * Every one of these existed with tests and **no caller**: `TrendyolLookups`, `NoonProductGroups` and `AmazonFinanceGroups`
 * could all answer and nobody could ask. The closure review found the first two and the repaired hygiene guard found the
 * third. These tests go through the route, so they prove the path rather than the class: a class with a passing unit test
 * and no route is exactly what was wrong before.
 *
 * They are reads. The one write offered here only ADDS, and the three that can empty a live noon group have no route at all.
 */
class AdminChannelLookupsTest extends AdminSalesChannelsTestCase
{
    private function lookup(string $kind, int $accountId, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('admin.sales-channels.accounts.lookup', ['account' => $accountId, 'kind' => $kind]), $payload);
    }

    // ---- Trendyol -----------------------------------------------------------------------------------------------------

    public function test_asking_trendyol_for_its_carrier_codes_calls_the_lookup_and_shows_what_came_back(): void
    {
        $account = $this->connected($this->trendyolAccount());
        Http::fake([self::TY . '/product/lookup/cargo-providers' => Http::response([
            ['id' => 10, 'code' => 'ARAS', 'name' => 'Aras Kargo'],
            ['id' => 11, 'code' => 'MNG', 'name' => 'MNG Kargo'],
        ])]);

        $this->lookup('carrier-codes', (int) $account->id)->assertRedirect(route('admin.sales-channels.accounts.show', $account));

        $this->assertCount(1, Http::recorded(fn ($request): bool => str_contains($request->url(), 'cargo-providers')), 'the request leaves only when the button is pressed');
        $lookup = session('lookup');
        $this->assertSame('carrier-codes', $lookup['kind']);
        $this->assertSame(2, $lookup['total']);
    }

    public function test_a_carrier_list_trendyol_does_not_publish_for_this_storefront_is_a_refusal_not_an_empty_list(): void
    {
        $account = $this->connected($this->trendyolAccount());
        $account->forceFill(['settings' => ['storefront_code' => 'TR'] + (array) $account->settings])->save();
        Http::preventStrayRequests();

        $this->lookup('carrier-codes', (int) $account->id)->assertRedirect();

        $this->assertNull(session('lookup'), 'a refused lookup must never leave a list on the page');
    }

    public function test_the_paged_attribute_values_lookup_needs_its_category_and_attribute(): void
    {
        $account = $this->connected($this->trendyolAccount());

        $this->lookup('attribute-values', (int) $account->id, [])->assertSessionHasErrors(['category_id', 'attribute_id']);
    }

    public function test_the_price_and_stock_lookup_reads_back_what_trendyol_holds_for_the_barcodes_given(): void
    {
        $account = $this->connected($this->trendyolAccount());
        Http::fake(['*inventory-and-price*' => Http::response(['content' => [
            ['barcode' => 'BC-1', 'quantity' => 7, 'salePrice' => 49.95, 'listPrice' => 59.95, 'stockCode' => 'SKU-1'],
        ]])]);

        $this->lookup('price-and-stock', (int) $account->id, ['barcodes' => ['BC-1']])->assertRedirect();

        $this->assertSame('approved_only', session('lookup')['note'], 'a barcode missing from the answer may be unapproved, not absent');
    }

    // ---- the channel has to match ------------------------------------------------------------------------------------

    public function test_a_trendyol_lookup_asked_of_an_amazon_account_is_not_found(): void
    {
        $this->lookup('carrier-codes', (int) $this->amazonAccount()->id)->assertNotFound();
    }

    public function test_a_noon_lookup_asked_of_a_trendyol_account_is_not_found(): void
    {
        $this->lookup('product-group', (int) $this->trendyolAccount()->id, ['group_ref' => 'g', 'known_sku_parent' => 's'])->assertNotFound();
    }

    public function test_an_unknown_kind_is_not_a_route_at_all(): void
    {
        $this->lookup('whatever', (int) $this->trendyolAccount()->id)->assertNotFound();
    }

    // ---- and the writes that can empty a group are not reachable ------------------------------------------------------

    public function test_only_the_additive_group_write_has_a_route(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($route): string => (string) $route->getName())->all();

        $this->assertContains('admin.sales-channels.accounts.product-group.members', $names, 'adding members is offered');
        foreach (['replace', 'delete', 'unmap', 'membership'] as $destructive) {
            $this->assertEmpty(
                array_filter($names, static fn (string $name): bool => str_contains($name, 'product-group') && str_contains($name, $destructive)),
                "nothing routes to a {$destructive} of a noon group: the list you send replaces the group",
            );
        }
    }

    public function test_adding_group_members_refuses_an_empty_list_and_a_missing_group(): void
    {
        $account = $this->connected($this->noonAccount());

        $this->post(route('admin.sales-channels.accounts.product-group.members', $account), ['sku_parents' => []])
            ->assertSessionHasErrors(['group_ref', 'known_sku_parent', 'sku_parents']);
    }

    public function test_every_lookup_kind_names_a_channel_that_exists(): void
    {
        foreach (ChannelAccountOperationsController::LOOKUPS as $kind => $channel) {
            $this->assertContains($channel->value, ['trendyol', 'amazon', 'noon'], "{$kind} names no channel we have");
        }
    }
}
