<?php

namespace Tests\Feature\Catalog;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Listings\ListingDraftService;
use App\Services\SalesChannels\Listings\ListingPublisher;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\Storefront\StorefrontCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * D3: the default outbound listing key of a product is its code. Two countries may now hold products of one code, and one channel account may
 * not list both under it: a key a listing of this account already holds for another product refuses the second attempt, whatever the countries
 * of the two products are and whether the country-scoped codes are on. Different accounts are different keys.
 */
class ListingKeyCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsSalesChannelSchema, BuildsStockPools, CreatesChannelAccounts;

    private const LWA = 'https://api.amazon.com/auth/o2/token';
    private const SP = 'https://sellingpartnerapi-eu.amazon.com';
    private const SHARED_CODE = 'SHARED100';
    private const SAUDI = 70;
    private const KUWAITI = 71;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
        $this->addStorefrontCountryColumn();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true, 'sales_channels.listing.publish_enabled' => true]);
        $this->account = $this->amazonAccount();
        $this->account->forceFill(['capability_approvals' => ((array) $this->account->capability_approvals) + [ChannelOperations::SCOPE_PRODUCT_WRITES => ['by' => 1]]])->save();
        $this->account = $this->account->fresh();
        $this->productOf(self::SAUDI, 'SA');
        $this->productOf(self::KUWAITI, 'KW');
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function productOf(int $id, string $country): void
    {
        $this->storeProduct($id, provider: null);
        DB::table('products')->where('id', $id)->update([
            'code' => self::SHARED_CODE, 'storefront_country' => $country, 'details' => '<p>Vitamin C, 500mg, 60 tablets.</p>',
            'category_ids' => json_encode([['id' => '7', 'position' => 1]]), 'thumbnail' => 'https://cdn.example.test/thumb-' . $id . '.png',
            'images' => json_encode([['image_name' => 'https://cdn.example.test/front-' . $id . '.png', 'storage' => 'public']]), 'brand_id' => 3,
        ]);
    }

    private function fakeAmazon(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'token_type' => 'bearer', 'expires_in' => 3600]),
            self::SP . '/definitions/2020-09-01/productTypes/*' => Http::response(['productType' => 'HEALTH_PERSONAL_CARE', 'required' => ['item_name'], 'optionalAttributes' => ['colour']]),
            self::SP . '/listings/2021-08-01/items/*' => Http::response(['errors' => [['code' => 'NOT_FOUND']]], 404),
        ]);
    }

    private function readyToSend(int $productId, SalesChannelAccount $account): \App\Models\ChannelListingDraft
    {
        $this->fakeAmazon();
        $drafts = app(ListingDraftService::class);
        $draft = $drafts->draftFor($account, $productId, productType: 'HEALTH_PERSONAL_CARE', payload: ['item_name' => 'Vitamin C 500mg']);
        $drafts->validate($draft);

        return $draft->fresh();
    }

    private function holdTheKeyFor(int $productId, SalesChannelAccount $account): void
    {
        SalesChannelProductMapping::create([
            'account_id' => $account->id, 'product_id' => $productId, 'variant_key' => '', 'listing_key' => self::SHARED_CODE,
            'external_sku' => self::SHARED_CODE, 'status' => SalesChannelProductMapping::MAPPED,
        ]);
    }

    private function callsToListings(): int
    {
        return count(Http::recorded(fn ($request): bool => str_contains($request->url(), '/listings/')));
    }

    /** @return array<string, array{0: bool}> */
    public static function switches(): array
    {
        return ['the switch off' => [false], 'the switch on' => [true]];
    }

    #[DataProvider('switches')]
    public function test_the_default_key_of_both_products_is_their_one_shared_code(bool $scoped): void
    {
        $scoped && $this->scopeCodesByCountry();

        $this->assertSame(self::SHARED_CODE, $this->readyToSend(self::SAUDI, $this->account)->payload['sku']);
        $this->assertSame(self::SHARED_CODE, $this->readyToSend(self::KUWAITI, $this->account)->payload['sku']);
    }

    #[DataProvider('switches')]
    public function test_the_second_country_cannot_publish_the_code_the_first_one_holds_in_the_same_account(bool $scoped): void
    {
        $scoped && $this->scopeCodesByCountry();
        $this->holdTheKeyFor(self::SAUDI, $this->account);
        $draft = $this->readyToSend(self::KUWAITI, $this->account);
        $before = $this->callsToListings();

        $outcome = app(ListingPublisher::class)->publish($draft, adminId: 1);

        $this->assertSame('listing_key_belongs_to_another_product', $outcome->reason);
        $this->assertSame($before, $this->callsToListings(), 'our own records settled it: the channel was not asked');
        $this->assertSame(self::SAUDI, (int) SalesChannelProductMapping::where('listing_key', self::SHARED_CODE)->value('product_id'), 'and the first country keeps its listing');
    }

    #[DataProvider('switches')]
    public function test_the_same_holds_the_other_way_round(bool $scoped): void
    {
        $scoped && $this->scopeCodesByCountry();
        $this->holdTheKeyFor(self::KUWAITI, $this->account);
        $draft = $this->readyToSend(self::SAUDI, $this->account);

        $this->assertSame('listing_key_belongs_to_another_product', app(ListingPublisher::class)->publish($draft, adminId: 1)->reason);
    }

    public function test_another_account_does_not_hold_the_key(): void
    {
        $this->scopeCodesByCountry();
        $this->holdTheKeyFor(self::SAUDI, $this->account);
        $other = $this->account->replicate();
        $other->forceFill(['name' => 'Amazon KW', 'external_account_id' => 'A2SELLER'])->save();

        $this->assertNotSame('listing_key_belongs_to_another_product', app(ListingPublisher::class)->publish($this->readyToSend(self::KUWAITI, $other), adminId: 1)->reason);
    }
}
