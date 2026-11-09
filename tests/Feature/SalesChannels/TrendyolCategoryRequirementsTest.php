<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Listings\ListingDraftService;
use App\Services\SalesChannels\Listings\ListingOutcome;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Trendyol\TrendyolConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/**
 * What TRENDYOL requires of a product in one of its categories, read from Trendyol itself.
 *
 * Until now Trendyol answered nothing about its requirements, so a draft for it could never leave DRAFT and the channel refused every attempt with
 * `channel_cannot_create_listings`. Its categories are where the requirements live: each names its attributes, which are required or not, which values it accepts, and
 * whether a value of our own is allowed.
 *
 * Product **V2** paths are used; V1 is retired and unused in this module. Every response here is shaped after the published models and no test can reach Trendyol.
 */
class TrendyolCategoryRequirementsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;

    private const API = 'https://apigw.trendyol.com/integration';
    private const ATTRIBUTES = self::API . '/product/categories/*/attributes';
    private const CATEGORY = '1234';
    private const BRANDS = self::API . '/product/brands/by-name*';
    private const PRODUCT = 71;

    private TrendyolConnector $trendyol;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->trendyol = app(TrendyolConnector::class);
        $this->account = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => Trendyol::SELLER, 'currency' => 'SAR', 'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub']],
            ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789'],
            adminId: 1,
        );
        $this->account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'product_sync', 'order_import'],
            'capability_approvals' => ['product_writes' => ['by' => 1]],
        ])->save();
    }

    public function test_a_required_attribute_is_required_and_an_optional_one_is_optional(): void
    {
        $this->fake($this->attributes([
            $this->attribute(338, 'Renk', required: true, values: ['Kırmızı', 'Mavi']),
            $this->attribute(47, 'Menşei', required: false, values: ['Türkiye']),
        ]));

        $requirements = $this->trendyol->listingRequirements($this->account, self::CATEGORY);

        $this->assertFalse($requirements->unknown);
        $this->assertSame(self::CATEGORY, $requirements->productType);
        $this->assertSame(['Renk'], $requirements->required);
        $this->assertSame(['Menşei'], $requirements->optional);
        $this->assertSame(['Kırmızı', 'Mavi'], $requirements->allowedValues['Renk']);
    }

    public function test_the_attribute_and_value_ids_are_kept_because_a_write_sends_ids_not_names(): void
    {
        $this->fake($this->attributes([$this->attribute(338, 'Renk', required: true, values: ['Kırmızı', 'Mavi'])]));

        $requirements = $this->trendyol->listingRequirements($this->account, self::CATEGORY);

        $this->assertSame(338, $requirements->channelFields['Renk']['attribute_id']);
        $this->assertSame(['Kırmızı' => 1, 'Mavi' => 2], $requirements->channelFields['Renk']['values']);
        $this->assertFalse($requirements->channelFields['Renk']['allow_custom']);
    }

    public function test_an_attribute_that_takes_a_value_of_our_own_is_not_limited_to_a_list(): void
    {
        $this->fake($this->attributes([$this->attribute(12, 'Hacim', required: true, values: ['50 ml'], allowCustom: true)]));

        $requirements = $this->trendyol->listingRequirements($this->account, self::CATEGORY);

        $this->assertSame(['Hacim'], $requirements->required);
        $this->assertArrayNotHasKey('Hacim', $requirements->allowedValues, 'a check against the list would refuse a value Trendyol would have taken');
        $this->assertTrue($requirements->channelFields['Hacim']['allow_custom']);
    }

    public function test_an_attribute_whose_values_trendyol_did_not_embed_gets_no_value_list_rather_than_an_empty_one(): void
    {
        // Trendyol embeds a short list; a very long one lives behind its own endpoint this version does not page through.
        $this->fake($this->attributes([$this->attribute(99, 'Beden', required: true, values: [])]));

        $requirements = $this->trendyol->listingRequirements($this->account, self::CATEGORY);

        $this->assertSame(['Beden'], $requirements->required);
        $this->assertArrayNotHasKey('Beden', $requirements->allowedValues, 'an empty list would read as "no value is acceptable" and refuse every product');
    }

    public function test_the_v2_path_is_the_one_called(): void
    {
        $this->fake($this->attributes([$this->attribute(338, 'Renk', required: true, values: ['Kırmızı'])]));

        $this->trendyol->listingRequirements($this->account, self::CATEGORY);

        $called = $this->sentTo('/product/categories/');
        $this->assertCount(1, $called);
        $this->assertStringContainsString('/product/categories/1234/attributes', $called[0]->url());
        // V1's path, which is retired and must not be used anywhere in this module.
        $this->assertStringNotContainsString('/product/product-categories/', $called[0]->url());
    }

    // ---- what must never be read as "nothing is required" --------------------------------------------------------

    public function test_a_category_that_cannot_be_read_is_unknown_and_not_an_empty_requirement_list(): void
    {
        $this->fake(null, status: 503);

        $requirements = $this->trendyol->listingRequirements($this->account, self::CATEGORY);

        $this->assertTrue($requirements->unknown, 'a draft must stay a draft when the channel could not be asked');
        $this->assertSame([], $requirements->required);
    }

    public function test_a_category_that_names_no_attribute_at_all_is_unknown(): void
    {
        $this->fake($this->attributes([]));

        $requirements = $this->trendyol->listingRequirements($this->account, self::CATEGORY);

        $this->assertTrue($requirements->unknown, 'silence is not permission');
    }

    public function test_a_rehearsing_account_still_learns_the_requirements_because_reading_them_is_not_a_write(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        $this->fake($this->attributes([$this->attribute(338, 'Renk', required: true, values: ['Kırmızı'])]));

        $requirements = $this->trendyol->listingRequirements($this->account->fresh(), self::CATEGORY);

        // A read is not a write, so Trendyol IS asked; what matters is that a dry-run answer is never taken as the requirements.
        $this->assertFalse($requirements->unknown);
    }

    public function test_something_that_is_not_a_category_id_is_never_asked_about(): void
    {
        $this->fake(null);

        foreach (['', 'HEALTH_PERSONAL_CARE', 'abc'] as $notACategory) {
            $this->assertTrue($this->trendyol->listingRequirements($this->account, $notACategory)->unknown);
        }

        Http::assertNothingSent();
    }

    public function test_the_capability_is_needed_and_without_it_nothing_is_claimed(): void
    {
        $this->account->forceFill(['capabilities' => ['read_only_sync']])->save();
        $this->fake($this->attributes([$this->attribute(338, 'Renk', required: true, values: ['Kırmızı'])]));

        $this->expectExceptionMessageMatches('/product_sync|capabilit/i');
        $this->trendyol->listingRequirements($this->account->fresh(), self::CATEGORY);
    }

    // ---- the brand id the check fills in -------------------------------------------------------------------------

    public function test_the_check_turns_the_brand_name_into_trendyols_own_id(): void
    {
        $this->storeProduct(self::PRODUCT);
        $this->makeTheProductFitToList(self::PRODUCT);
        Http::fake([
            self::ATTRIBUTES => Http::response($this->attributes([$this->attribute(1, 'brandId', required: true, values: [])])),
            self::BRANDS => Http::response([['id' => 1584892, 'name' => 'Acme', 'luxe' => false]]),
        ]);
        $draft = app(ListingDraftService::class)->draftFor($this->account, self::PRODUCT, productType: self::CATEGORY, payload: ['brand' => 'Acme', 'sku' => '6281000000017']);

        $outcome = app(ListingDraftService::class)->validate($draft);

        $this->assertSame(ListingOutcome::VALIDATED, $outcome->state, 'the id was missing only because nobody could know it');
        $this->assertSame('1584892', (string) ($draft->refresh()->payload['brandId'] ?? ''));
    }

    public function test_several_brands_of_one_name_are_left_for_a_person(): void
    {
        $this->storeProduct(self::PRODUCT);
        $this->makeTheProductFitToList(self::PRODUCT);
        Http::fake([
            self::ATTRIBUTES => Http::response($this->attributes([$this->attribute(1, 'brandId', required: true, values: [])])),
            self::BRANDS => Http::response([['id' => 1, 'name' => 'Acme'], ['id' => 2, 'name' => 'Acme Pharma']]),
        ]);
        $draft = app(ListingDraftService::class)->draftFor($this->account, self::PRODUCT, productType: self::CATEGORY, payload: ['brand' => 'Acme', 'sku' => '6281000000017']);

        $outcome = app(ListingDraftService::class)->validate($draft);

        $this->assertNotSame(ListingOutcome::VALIDATED, $outcome->state, 'putting our product under another company\'s brand is not a thing to guess at');
        $this->assertSame('', (string) ($draft->refresh()->payload['brandId'] ?? ''));
    }

    public function test_a_brand_lookup_that_fails_leaves_the_id_missing_rather_than_passing_the_draft(): void
    {
        $this->storeProduct(self::PRODUCT);
        $this->makeTheProductFitToList(self::PRODUCT);
        Http::fake([
            self::ATTRIBUTES => Http::response($this->attributes([$this->attribute(1, 'brandId', required: true, values: [])])),
            self::BRANDS => Http::response('', 503),
        ]);
        $draft = app(ListingDraftService::class)->draftFor($this->account, self::PRODUCT, productType: self::CATEGORY, payload: ['brand' => 'Acme', 'sku' => '6281000000017']);

        $outcome = app(ListingDraftService::class)->validate($draft);

        $this->assertNotSame(ListingOutcome::VALIDATED, $outcome->state);
        $this->assertContains('brandId', $draft->refresh()->problems ?? [], 'the id is reported missing, which is true');
    }

    public function test_a_brand_id_somebody_already_typed_is_not_looked_up(): void
    {
        $this->storeProduct(self::PRODUCT);
        $this->makeTheProductFitToList(self::PRODUCT);
        Http::fake([self::ATTRIBUTES => Http::response($this->attributes([$this->attribute(1, 'brandId', required: true, values: [])]))]);
        $draft = app(ListingDraftService::class)->draftFor($this->account, self::PRODUCT, productType: self::CATEGORY, payload: ['brandId' => '999', 'brand' => 'Acme', 'sku' => '6281000000017']);

        app(ListingDraftService::class)->validate($draft);

        $this->assertSame('999', (string) $draft->refresh()->payload['brandId']);
        $this->assertCount(0, Http::recorded(static fn ($request): bool => str_contains($request->url(), '/brands')));
    }

    /** The store's own data a product needs before any channel may be asked (ProductListingReadiness). */
    private function makeTheProductFitToList(int $productId): void
    {
        DB::table('products')->where('id', $productId)->update([
            'details' => '<p>Vitamin C, 500mg, 60 tablets.</p>',
            'category_ids' => json_encode([['id' => '7', 'position' => 1]]),
            // Absolute https URLs, which is what a shop of any size really has: its pictures sit on a CDN. A channel is given
            // the URL and fetches it, so this is also the only shape that can be checked without writing files to a disk.
            'thumbnail' => 'https://cdn.pharmacyksa.sa/thumb-' . $productId . '.png',
            'images' => json_encode([['image_name' => 'https://cdn.pharmacyksa.sa/front-' . $productId . '.png', 'storage' => 'public']]),
            'brand_id' => 3,
            'barcode' => '6281000000017',
        ]);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /** @param array<string, mixed>|null $body */
    private function fake(?array $body, int $status = 200): void
    {
        Http::fake([self::ATTRIBUTES => $body === null ? Http::response('', $status) : Http::response($body, $status)]);
    }

    /**
     * @param list<array<string, mixed>> $attributes
     * @return array<string, mixed>
     */
    private function attributes(array $attributes): array
    {
        return ['id' => (int) self::CATEGORY, 'name' => 'Vitaminler', 'displayName' => 'Vitaminler', 'categoryAttributes' => $attributes];
    }

    /**
     * @param list<string> $values
     * @return array<string, mixed>
     */
    private function attribute(int $id, string $name, bool $required, array $values, bool $allowCustom = false): array
    {
        $rows = [];
        foreach ($values as $index => $value) {
            $rows[] = ['id' => $index + 1, 'name' => $value];
        }

        return [
            'attribute' => ['id' => $id, 'name' => $name],
            'categoryAttributeValues' => $rows,
            'required' => $required,
            'allowCustom' => $allowCustom,
            'varianter' => false,
            'slicer' => false,
            'allowMultipleAttributeValues' => false,
        ];
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(fn (array $pair): Request => $pair[0])->all();
    }
}
