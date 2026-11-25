<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Listings\ListingDraftService;
use App\Services\SalesChannels\Publishing\ChannelMarketMatch;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSalesChannelSchema;

/**
 * A product of one market is never linked, drafted or priced on another market's channel account (H2-16).
 *
 * The stock half of this was already built: a pool share is refused by `StockListingGate`, which asks `ChannelMarketMatch`
 * (`ChannelPoolMarketTest`). Three ways in were not asked at all, and each of them ends with our figures on the wrong country's
 * marketplace:
 *
 *  - a product id TYPED into the link form. The picker labels each product's country (#76), and a label is a help, not a
 *    refusal;
 *  - a listing DRAFT, which is what creates a listing at the channel in the first place;
 *  - a PRICE push, which needs no location and so never went past the stock gate.
 *
 * Each case has its unrestricted twin -- the same request for a product of the account's OWN market, which must still work --
 * because a refusal on its own cannot tell a guard from a world where nothing would have happened anyway. And each has its
 * dormant twin: with the storefronts switched off, every one of the three does what it does on the platform today.
 *
 * The link is driven through the real admin route, so the validation rule, the error bag and the listing's row are what a
 * person would get.
 */
class ChannelMarketPushGuardTest extends AdminSalesChannelsTestCase
{
    use BuildsSalesChannelSchema;

    private const SAUDI_PRODUCT = 'Saudi product';

    private const KUWAITI_PRODUCT = 'Kuwaiti product';

    /** The page a form posts from, as the listing screens' own tests do, so a redirect back is a real address. */

    private int $saudiProduct;

    private int $kuwaitiProduct;

    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        app(StorefrontMarketRows::class)->forget();
        Http::preventStrayRequests();

        $this->saudiProduct = $this->productOfTheMarket(self::SAUDI_PRODUCT, 'SA');
        $this->kuwaitiProduct = $this->productOfTheMarket(self::KUWAITI_PRODUCT, 'KW');
        config(['gcc.storefronts.enabled' => true]);
    }

    // ---- the link form -----------------------------------------------------------------------------------------------

    public function test_a_product_of_another_market_is_not_a_product_this_listing_may_point_at(): void
    {
        $listing = $this->listing($this->trendyolAccount());

        $this->backTo()->post(route('admin.sales-channels.products.link', $listing), ['product_id' => $this->kuwaitiProduct])
            ->assertSessionHasErrorsIn('listing_' . $listing->id, 'product_id');

        $this->assertSame(SalesChannelProductMapping::UNMAPPED, $listing->fresh()->status, 'nothing was linked');
        $this->assertNull($listing->fresh()->product_id);
    }

    public function test_a_product_of_the_accounts_own_market_is_linked_exactly_as_before(): void
    {
        $listing = $this->listing($this->trendyolAccount());

        $this->backTo()->post(route('admin.sales-channels.products.link', $listing), ['product_id' => $this->saudiProduct])
            ->assertRedirect(route('admin.sales-channels.products.index'));

        $this->assertSame(
            [SalesChannelProductMapping::MAPPED, $this->saudiProduct],
            [$listing->fresh()->status, $listing->fresh()->product_id],
            'the unrestricted twin: the account\'s own market still links',
        );
    }

    public function test_with_the_storefronts_off_any_of_our_products_may_be_linked(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $listing = $this->listing($this->trendyolAccount());

        $this->backTo()->post(route('admin.sales-channels.products.link', $listing), ['product_id' => $this->kuwaitiProduct]);

        $this->assertSame($this->kuwaitiProduct, $listing->fresh()->product_id, 'dormant: the platform as it is today');
    }

    public function test_a_suggestion_of_another_market_cannot_be_accepted(): void
    {
        $listing = $this->listing($this->trendyolAccount(), [
            'status' => SalesChannelProductMapping::SUGGESTED,
            'suggested_product_id' => $this->kuwaitiProduct,
            'match_method' => 'barcode',
        ]);

        $this->backTo()->post(route('admin.sales-channels.products.accept-suggestion', $listing));

        $this->assertSame(SalesChannelProductMapping::SUGGESTED, $listing->fresh()->status, 'the suggestion is still only a suggestion');
        $this->assertNull($listing->fresh()->product_id);
    }

    public function test_a_suggestion_of_the_accounts_own_market_is_still_accepted(): void
    {
        $listing = $this->listing($this->trendyolAccount(), [
            'status' => SalesChannelProductMapping::SUGGESTED,
            'suggested_product_id' => $this->saudiProduct,
            'match_method' => 'barcode',
        ]);

        $this->backTo()->post(route('admin.sales-channels.products.accept-suggestion', $listing));

        $this->assertSame(
            [SalesChannelProductMapping::MAPPED, $this->saudiProduct],
            [$listing->fresh()->status, $listing->fresh()->product_id],
            'the unrestricted twin',
        );
    }

    // ---- the price push ----------------------------------------------------------------------------------------------

    public function test_the_price_of_a_product_of_another_market_is_never_published(): void
    {
        $account = $this->trendyolAccount();
        $this->priceable($account, $this->kuwaitiProduct);
        Http::fake();

        $result = app(StockPricePublisher::class)->publishPrices($account->fresh());

        $this->assertSame([ChannelMarketMatch::PRODUCT_OF_ANOTHER_MARKET => 1], $result['stats']['skipped']);
        Http::assertNothingSent();
    }

    public function test_the_price_of_a_product_of_the_accounts_own_market_is_published(): void
    {
        $account = $this->trendyolAccount();
        $this->priceable($account, $this->saudiProduct);
        Http::fake([self::TY . '/*' => Http::response(['batchRequestId' => 'batch-1'])]);

        $result = app(StockPricePublisher::class)->publishPrices($account->fresh());

        $this->assertSame(1, $result['stats']['accepted'] ?? null, 'the unrestricted twin: ' . json_encode($result));
    }

    public function test_with_the_storefronts_off_the_price_goes_out_as_it_does_today(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $account = $this->trendyolAccount();
        $this->priceable($account, $this->kuwaitiProduct);
        Http::fake([self::TY . '/*' => Http::response(['batchRequestId' => 'batch-1'])]);

        $result = app(StockPricePublisher::class)->publishPrices($account->fresh());

        $this->assertSame(1, $result['stats']['accepted'] ?? null, 'dormant: ' . json_encode($result));
    }

    // ---- the listing draft -------------------------------------------------------------------------------------------

    public function test_a_draft_of_a_product_of_another_market_is_refused_before_anything_a_person_could_fix(): void
    {
        $account = $this->trendyolAccount();
        $draft = app(ListingDraftService::class)->draftFor($account, $this->kuwaitiProduct, productType: '', payload: []);

        $outcome = app(ListingDraftService::class)->validate($draft->fresh());

        $this->assertSame(ChannelMarketMatch::PRODUCT_OF_ANOTHER_MARKET, $draft->fresh()->refusal_code);
        $this->assertSame(
            ChannelMarketMatch::PRODUCT_OF_ANOTHER_MARKET,
            $outcome->reason,
            'and not the missing product type, which is the next thing wrong and the thing a person would have gone to fix',
        );
    }

    public function test_a_draft_of_the_accounts_own_market_is_judged_on_its_own_fields(): void
    {
        $account = $this->trendyolAccount();
        $draft = app(ListingDraftService::class)->draftFor($account, $this->saudiProduct, productType: '', payload: []);

        app(ListingDraftService::class)->validate($draft->fresh());

        $this->assertSame(
            'listing_product_type_missing',
            $draft->fresh()->refusal_code,
            'the unrestricted twin: its own market\'s draft reaches the checks about the draft itself',
        );
    }

    private function backTo(): static
    {
        return $this->from(route('admin.sales-channels.products.index'));
    }

    private function productOfTheMarket(string $name, string $market): int
    {
        $id = $this->product($name, 'CODE-' . $market);
        DB::table('products')->where('id', $id)->update(['storefront_country' => $market]);

        return $id;
    }

    /** A listing of this product with a price a person set, opted in for publishing, on an account whose writes are on: what the price push looks at. */
    private function priceable(SalesChannelAccount $account, int $productId): SalesChannelProductMapping
    {
        $this->liveWritesOn();

        return $this->listing($account, [
            'product_id' => $productId,
            'status' => SalesChannelProductMapping::MAPPED,
            'stock_location_id' => app(InventoryLocationService::class)->ensureDefaults()[2]->id,
            'price_source' => 'manual',
            'price_publish' => true,
            'inventory_publish' => false,
            'snapshot' => ['manual_price' => '49.95'],
        ]);
    }
}
