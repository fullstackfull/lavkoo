<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Publishing\StockListingGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * noon joins the shared pool only where a location has been asked to admit it.
 *
 * The owner's decision one, approved 2026-11-10 (docs/sales-channels/decisions.md §8): noon shares the pool with the
 * storefront, Amazon FBM and Trendyol, but **only for what we fulfil ourselves**, the admission is **per inventory location**
 * and is **never granted automatically** to a location that exists or to one made later, and `noon` is **not added to any
 * existing location**. Allocation, reservation and the bar on overselling hold across all four.
 *
 * Nothing here needed a new column: `inventory_locations.publishable_channels` already IS the per-location admission, and no
 * location lists `noon`. So the default is that noon takes no stock, which is the policy the owner asked for while the
 * question was open, and admitting one location is a deliberate act a person performs.
 */
class NoonPoolMembershipTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const PRODUCT = 4242;

    private SalesChannelAccount $noon;

    private InventoryLocation $own;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
        $this->own = app(InventoryLocationService::class)->ensureDefaults()[2];
        $this->noon = $this->noonAccount();
    }

    private function listing(?SalesChannelAccount $account = null, array $overrides = []): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::create($overrides + [
            'account_id' => ($account ?? $this->noon)->id, 'product_id' => self::PRODUCT, 'listing_key' => 'NOON-1', 'external_sku' => 'SKU-1',
            'external_barcode' => 'BC-1', 'status' => 'mapped', 'stock_location_id' => $this->own->id, 'fulfillment_mode' => 'fbpi',
            'inventory_publish' => true, 'price_publish' => true, 'price_source' => 'manual', 'snapshot' => ['manual_price' => '49.95'],
        ]);
    }

    private function admit(InventoryLocation $location, string ...$channels): void
    {
        $location->forceFill(['publishable_channels' => array_values(array_unique([...($location->publishable_channels ?? []), ...$channels]))])->save();
    }

    private function refusal(SalesChannelProductMapping $listing, ?SalesChannelAccount $account = null, ?InventoryLocation $location = null): ?string
    {
        return app(StockListingGate::class)->refusal($account ?? $this->noon, $listing, $location ?? $this->own);
    }

    private function stock(int $available): void
    {
        InventoryLocationBalance::updateOrCreate(
            ['location_id' => $this->own->id, 'sku' => 'SKU-1', 'variant_key' => ''],
            ['product_id' => self::PRODUCT, 'available' => $available, 'quality' => 'manual', 'source_synced_at' => now()],
        );
    }

    // ---- the default, which is the policy while the question was open ------------------------------------------------

    public function test_the_three_default_locations_do_not_admit_noon(): void
    {
        foreach (app(InventoryLocationService::class)->ensureDefaults() as $location) {
            $this->assertNotContains('noon', (array) $location->publishable_channels, "{$location->code} must not admit noon by being created");
        }
    }

    public function test_a_noon_listing_takes_no_stock_while_no_location_admits_noon(): void
    {
        $this->stock(10);

        $this->assertSame('location_not_for_this_channel', $this->refusal($this->listing()));
    }

    public function test_running_the_default_setup_again_does_not_admit_noon_anywhere(): void
    {
        $this->admit($this->own, 'noon');
        $codes = array_map(static fn (InventoryLocation $l): string => (string) $l->code, app(InventoryLocationService::class)->ensureDefaults());

        $torod = InventoryLocation::where('code', InventoryLocationService::TOROD_CODE)->firstOrFail();

        $this->assertNotContains('noon', (array) $torod->publishable_channels, 'admitting one location must not admit the others');
        $this->assertContains(InventoryLocationService::OWN_CODE, $codes);
        $this->assertContains('noon', (array) $this->own->fresh()->publishable_channels, 'and the one that was admitted stays admitted');
    }

    public function test_a_location_made_later_does_not_admit_noon_either(): void
    {
        $fresh = InventoryLocation::create([
            'code' => 'second-warehouse', 'name' => 'Second warehouse', 'seller_id' => 0, 'type' => InventoryLocation::TYPE_OWN, 'provider' => null,
            'source_of_truth' => InventoryLocation::TRUTH_MANUAL, 'storefront_sellable' => true, 'publishable_channels' => ['storefront'], 'is_active' => true,
        ]);

        $this->assertSame('location_not_for_this_channel', $this->refusal($this->listing(), location: $fresh));
    }

    // ---- and the admission itself ------------------------------------------------------------------------------------

    public function test_a_location_asked_to_admit_noon_lets_its_listings_take_stock(): void
    {
        $this->stock(10);
        $this->admit($this->own, 'noon');

        $this->assertNull($this->refusal($this->listing(), location: $this->own->fresh()));
    }

    public function test_admitting_noon_changes_nothing_for_the_other_channels(): void
    {
        $this->stock(10);
        $this->admit($this->own, 'noon');
        $location = $this->own->fresh();
        $trendyol = $this->trendyolAccount();

        $this->assertNull($this->refusal($this->listing($trendyol, ['listing_key' => 'T-1']), $trendyol, $location), 'trendyol was admitted before and still is');
        $this->assertNull($this->refusal($this->listing(), location: $location), 'and noon is admitted now');
    }

    // ---- condition one: only what we fulfil ourselves ---------------------------------------------------------------

    public function test_stock_the_channel_itself_holds_never_joins_the_pool_whichever_channel_holds_it(): void
    {
        $this->stock(10);
        $this->admit($this->own, 'noon');
        $location = $this->own->fresh();

        $fbn = $this->listing(overrides: ['listing_key' => 'NOON-FBN', 'fulfillment_mode' => 'fbn']);

        $this->assertSame('fbn_listing', $this->refusal($fbn, location: $location), 'FBN is noon\'s own stock, as FBA is Amazon\'s');
    }

    public function test_a_noon_listing_whose_fulfilment_nobody_stated_takes_no_stock(): void
    {
        $this->stock(10);
        $this->admit($this->own, 'noon');
        $location = $this->own->fresh();

        $unknown = $this->listing(overrides: ['listing_key' => 'NOON-?', 'fulfillment_mode' => null]);

        $this->assertSame(
            'fulfillment_mode_unknown',
            $this->refusal($unknown, location: $location),
            'a channel that can hold stock itself must say who fulfils before it is given any of ours',
        );
    }

    // ---- and the location still has to be a stock source at all -----------------------------------------------------

    public function test_an_inactive_admitted_location_is_still_no_stock_source(): void
    {
        $this->stock(10);
        $this->admit($this->own, 'noon');
        $this->own->forceFill(['is_active' => false])->save();

        $this->assertSame('no_stock_source', $this->refusal($this->listing(), location: $this->own->fresh()));
    }
}
