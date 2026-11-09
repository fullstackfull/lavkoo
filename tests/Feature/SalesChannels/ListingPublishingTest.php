<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Data\ListingRequirements;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use App\Services\SalesChannels\Listings\ListingDraftService;
use App\Services\SalesChannels\Listings\ListingOutcome;
use App\Services\SalesChannels\Listings\ListingPublisher;
use App\Services\SalesChannels\Listings\ProductChannelPanel;
use App\Services\SalesChannels\Listings\ProductListingReadiness;
use App\Services\SalesChannels\Listings\RegulatedProductGate;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\ThrowingListingConnector;
use Tests\Support\UnreachableChannelConnector;
use Tests\TestCase;

/**
 * Putting a product of the store ON a channel: the draft, what the channel says it requires, the gates, whose SKU it is, the one call that creates the listing or the patch that
 * changes it, and the difference between a submission the channel TOOK and a listing the channel HOLDS.
 *
 * Every Amazon response here is a fixture shaped after the published models and no test can reach Amazon (stray requests are refused). They prove the pipeline against those
 * shapes; they do not prove a live seller account works.
 */
class ListingPublishingTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const LWA = 'https://api.amazon.com/auth/o2/token';
    private const SP = 'https://sellingpartnerapi-eu.amazon.com';
    private const LISTINGS = self::SP . '/listings/2021-08-01/items/*';
    private const PRODUCT = 70;
    private const TYPE = 'HEALTH_PERSONAL_CARE';

    /** An admin whose role carries the `sales_channels` module (built by the test schema), and one whose role does not. */
    private const CHANNELS_ADMIN = 4;
    private const CATALOG_ADMIN = 2;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = $this->amazonAccount();
        $this->storeProduct(self::PRODUCT, provider: null);
        $this->makeTheProductFitToList(self::PRODUCT);
    }

    /** The store's own data a product needs before any channel may be asked (ProductListingReadiness): a description, a category, a thumbnail and an image. */
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
        ]);
    }

    private function approveProductWrites(): void
    {
        $this->account->forceFill(['capability_approvals' => ((array) $this->account->capability_approvals) + [ChannelOperations::SCOPE_PRODUCT_WRITES => ['by' => 1]]])->save();
        $this->account = $this->account->fresh();
    }

    /**
     * The answers for the next calls, and only those: Laravel keeps every earlier fake and the first match wins, so the list is started again each time.
     *
     * @param array<string, mixed> $responses
     */
    private function fake(array $responses): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([self::LWA => Http::response(['access_token' => 'Atza|token', 'token_type' => 'bearer', 'expires_in' => 3600])] + $responses);
    }

    /** Amazon's product-type definition, in the one shape this version reads: a `required` list the response itself carries. */
    private function definitionSays(array $required): array
    {
        return [self::SP . '/definitions/2020-09-01/productTypes/*' => Http::response(['productType' => self::TYPE, 'required' => $required, 'optionalAttributes' => ['colour']])];
    }

    /**
     * The listings endpoint answers by method: what Amazon HOLDS for the SKU (GET) and what it makes of a write (PUT/PATCH).
     *
     * @param array<string, mixed>|null $holds null answers 404: Amazon holds nothing for the SKU
     * @param array<string, mixed> $write
     */
    private function listings(?array $holds, array $write = ['status' => 'ACCEPTED', 'submissionId' => 'sub-1'], int $writeStatus = 200): array
    {
        return [self::LISTINGS => function ($request) use ($holds, $write, $writeStatus) {
            if ($request->method() === 'GET') {
                return $holds === null ? Http::response(['errors' => [['code' => 'NOT_FOUND']]], 404) : Http::response($holds);
            }

            return Http::response($write, $writeStatus);
        }];
    }

    /** What Amazon answers for a listing it holds: summaries with its own statuses, and no error issues. */
    private function holdsAListing(array $statuses = ['DISCOVERABLE'], array $issues = []): array
    {
        return ['sku' => 'SKU70', 'summaries' => [['marketplaceId' => 'A17E79C6D8DWNP', 'status' => $statuses]], 'issues' => $issues];
    }

    private function drafts(): ListingDraftService
    {
        return app(ListingDraftService::class);
    }

    private function publisher(): ListingPublisher
    {
        return app(ListingPublisher::class);
    }

    private function draft(array $payload = ['item_name' => 'Vitamin C 500mg']): ChannelListingDraft
    {
        return $this->drafts()->draftFor($this->account, self::PRODUCT, productType: self::TYPE, payload: $payload);
    }

    private function callsTo(string $needle, ?string $method = null): int
    {
        return count(Http::recorded(fn ($request): bool => str_contains($request->url(), $needle) && ($method === null || $request->method() === $method)));
    }

    /** A draft that has passed validation, with the host switch on and the write approved. */
    private function readyToSend(): ChannelListingDraft
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->approveProductWrites();
        $this->fake($this->definitionSays(['item_name']));
        $draft = $this->draft();
        $this->drafts()->validate($draft);

        return $draft->fresh();
    }

    // ---- the draft -----------------------------------------------------------------------------------------

    public function test_one_draft_per_product_and_variant_however_often_it_is_asked_for(): void
    {
        $first = $this->draft();
        $second = $this->drafts()->draftFor($this->account, self::PRODUCT, productType: self::TYPE, payload: ['item_name' => 'Vitamin C 1000mg']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ChannelListingDraft::count());
        $this->assertSame('Vitamin C 1000mg', $second->payload['item_name'], 'asking again edits the draft');
    }

    public function test_the_sellers_own_sku_comes_from_the_product_and_never_changes_afterwards(): void
    {
        $this->assertSame('SKU' . self::PRODUCT, $this->draft()->payload['sku']);
        $this->assertSame('SKU' . self::PRODUCT, $this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['sku' => 'SOMETHING-ELSE'])->payload['sku']);
    }

    public function test_a_draft_for_a_product_that_is_already_listed_here_is_an_update_from_the_start(): void
    {
        $listing = $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'OLD-KEY');

        $draft = $this->draft();

        $this->assertSame($listing->id, $draft->product_mapping_id);
        $this->assertTrue($draft->updatesAnExistingListing());
    }

    public function test_the_product_itself_is_never_written(): void
    {
        $before = (array) DB::table('products')->where('id', self::PRODUCT)->first();

        $this->draft();

        $this->assertSame($before, (array) DB::table('products')->where('id', self::PRODUCT)->first());
    }

    // ---- what the channel requires -------------------------------------------------------------------------

    public function test_a_missing_field_the_channel_insists_on_keeps_the_draft_a_draft(): void
    {
        $this->fake($this->definitionSays(['item_name', 'brand']));

        $outcome = $this->drafts()->validate($this->draft());

        $this->assertSame([ListingOutcome::REFUSED, 'listing_fields_missing', ['brand']], [$outcome->state, $outcome->reason, $outcome->problems]);
        $this->assertSame([ChannelListingDraft::DRAFT, ['brand']], [ChannelListingDraft::firstOrFail()->state, ChannelListingDraft::firstOrFail()->problems]);
    }

    public function test_every_field_the_channel_insists_on_makes_it_ready_to_send(): void
    {
        $this->fake($this->definitionSays(['item_name']));

        $this->assertSame(ListingOutcome::VALIDATED, $this->drafts()->validate($this->draft())->state);
        $this->assertSame(ChannelListingDraft::VALIDATED, ChannelListingDraft::firstOrFail()->state);
    }

    public function test_a_channel_that_will_not_say_what_it_requires_is_not_read_as_requiring_nothing(): void
    {
        $this->fake([self::SP . '/definitions/2020-09-01/productTypes/*' => Http::response(['productType' => self::TYPE, 'schema' => ['link' => ['resource' => 'https://example.test/schema.json']]])]);

        $outcome = $this->drafts()->validate($this->draft());

        $this->assertSame([ListingOutcome::REFUSED, 'listing_requirements_unknown'], [$outcome->state, $outcome->reason]);
        $this->assertSame(ChannelListingDraft::DRAFT, ChannelListingDraft::firstOrFail()->state);
    }

    public function test_the_owners_own_list_is_used_when_the_channel_will_not_say_and_is_recorded_as_the_owners(): void
    {
        $this->account->forceFill(['settings' => ['listing_requirements' => [self::TYPE => ['required' => ['item_name', 'brand']]]] + (array) $this->account->settings])->save();
        $this->account = $this->account->fresh();
        $this->fake([self::SP . '/definitions/2020-09-01/productTypes/*' => Http::response(['productType' => self::TYPE])]);

        $outcome = $this->drafts()->validate($this->draft());

        $this->assertSame(['brand'], $outcome->problems);
        $this->assertSame(ListingRequirements::DECLARED_BY_THE_OWNER, ChannelListingDraft::firstOrFail()->requirements['source']);
    }

    // ---- the store's own product data ----------------------------------------------------------------------

    public function test_a_product_the_store_has_not_finished_is_not_offered_to_any_channel(): void
    {
        $this->fake($this->definitionSays(['item_name']));
        DB::table('products')->where('id', self::PRODUCT)->update(['details' => null, 'thumbnail' => null, 'images' => null, 'barcode' => null, 'code' => null, 'category_ids' => null]);

        $outcome = $this->drafts()->validate($this->draft());

        $this->assertSame([ListingOutcome::REFUSED, 'product_data_not_ready'], [$outcome->state, $outcome->reason]);
        $this->assertSame([
            ProductListingReadiness::NO_DESCRIPTION,
            ProductListingReadiness::NO_IDENTIFIER,
            ProductListingReadiness::NO_CATEGORY,
            ProductListingReadiness::NO_THUMBNAIL,
            ProductListingReadiness::NOT_ENOUGH_IMAGES,
        ], $outcome->problems);
        $this->assertSame(0, $this->callsTo('/definitions/'), 'the channel is not asked about a product that is not ready');
    }

    public function test_the_number_of_images_and_whether_a_brand_is_needed_are_the_owners_to_set(): void
    {
        config(['sales_channels.listing.product_data' => ['images_at_least' => 3, 'brand_required' => true]]);
        DB::table('products')->where('id', self::PRODUCT)->update(['brand_id' => null]);

        $problems = app(ProductListingReadiness::class)->problemsFor(self::PRODUCT);

        $this->assertSame([ProductListingReadiness::NO_BRAND, ProductListingReadiness::NOT_ENOUGH_IMAGES], $problems);

        config(['sales_channels.listing.product_data' => ['images_at_least' => 0, 'brand_required' => false]]);
        $this->assertSame([], app(ProductListingReadiness::class)->problemsFor(self::PRODUCT));
    }

    public function test_a_product_sold_per_variant_is_listed_per_variant_and_the_variant_must_exist_and_have_a_price(): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update(['variation' => json_encode([
            ['type' => 'Red', 'price' => 39, 'qty' => 5],
            ['type' => 'Blue', 'price' => 0, 'qty' => 5],
        ])]);
        $readiness = app(ProductListingReadiness::class);

        $this->assertSame([ProductListingReadiness::VARIANT_KEY_REQUIRED], $readiness->problemsFor(self::PRODUCT));
        $this->assertSame([], $readiness->problemsFor(self::PRODUCT, 'Red'));
        $this->assertSame([ProductListingReadiness::VARIANT_WITHOUT_A_PRICE], $readiness->problemsFor(self::PRODUCT, 'Blue'));
        $this->assertSame([ProductListingReadiness::VARIANT_NOT_FOUND], $readiness->problemsFor(self::PRODUCT, 'Green'));
    }

    public function test_a_vendors_product_and_a_digital_one_are_not_ours_to_list(): void
    {
        $this->storeProduct(81, provider: null);
        DB::table('products')->where('id', 81)->update(['added_by' => 'seller']);
        $this->storeProduct(82, provider: null);
        DB::table('products')->where('id', 82)->update(['product_type' => 'digital']);
        $readiness = app(ProductListingReadiness::class);

        $this->assertSame([ProductListingReadiness::NOT_A_PHYSICAL_PRODUCT], $readiness->problemsFor(81));
        $this->assertSame([ProductListingReadiness::NOT_A_PHYSICAL_PRODUCT], $readiness->problemsFor(82));
        $this->assertSame([ProductListingReadiness::NOT_FOUND], $readiness->problemsFor(999));
    }

    // ---- the gates -----------------------------------------------------------------------------------------

    public function test_with_the_host_switch_off_nothing_leaves_the_host(): void
    {
        $draft = $this->readyToSend();
        config(['sales_channels.listing.publish_enabled' => false]);
        $this->fake($this->listings(null));

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame([ListingOutcome::REFUSED, 'listing_publishing_off'], [$outcome->state, $outcome->reason]);
        $this->assertSame(0, $this->callsTo('/listings/'));
    }

    public function test_without_the_recorded_product_writes_approval_the_listing_is_not_created(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->fake($this->definitionSays(['item_name']));
        $this->drafts()->validate($this->draft());
        $this->fake($this->listings(null));

        $outcome = $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1);

        $this->assertSame('product_writes_approval_missing', $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/'));
    }

    public function test_an_account_without_the_product_sync_capability_is_refused(): void
    {
        $draft = $this->readyToSend();
        $this->account->forceFill(['capabilities' => ['connection_test', 'read_only_sync']])->save();

        $this->assertSame('product_sync_capability_off', $this->publisher()->publish($draft, adminId: 1)->reason);
    }

    public function test_a_channel_whose_connector_cannot_create_a_listing_says_so_instead_of_pretending(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        // A connector that implements ChannelConnector and nothing about listings. Trendyol used to stand in for this and no longer can: it creates listings since PR-D, so
        // the case needs a connector that really cannot, or it would be testing nothing.
        config(['sales_channels.connectors.amazon' => UnreachableChannelConnector::class]);
        $draft = $this->readyToSend();

        $this->assertSame('channel_cannot_create_listings', $this->publisher()->publish($draft, adminId: 1)->reason);
    }

    public function test_a_channel_with_no_connector_at_all_is_refused_not_crashed_on(): void
    {
        $draft = $this->readyToSend();
        config(['sales_channels.connectors.amazon' => null]);

        $this->assertSame('channel_cannot_create_listings', $this->publisher()->publish($draft, adminId: 1)->reason);
    }

    // ---- whose SKU is it -----------------------------------------------------------------------------------

    public function test_a_listing_key_that_belongs_to_another_product_refuses_the_attempt(): void
    {
        $draft = $this->readyToSend();
        $this->storeProduct(71, provider: null);
        SalesChannelProductMapping::create([
            'account_id' => $this->account->id, 'product_id' => 71, 'variant_key' => '', 'listing_key' => 'SKU70',
            'external_sku' => 'SKU70', 'status' => SalesChannelProductMapping::MAPPED,
        ]);
        $this->fake($this->listings(null));

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame('listing_key_belongs_to_another_product', $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/'), 'our own records settled it: the channel was not even asked');
    }

    public function test_a_sku_the_channel_already_lists_and_we_have_no_record_of_is_never_replaced(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings($this->holdsAListing()));

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame('sku_already_listed_at_the_channel_and_not_linked_here', $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/', 'PUT'), 'nothing was written over it');
        $this->assertSame(0, SalesChannelProductMapping::count(), 'and it was not adopted either');
    }

    public function test_a_listing_we_cannot_read_is_not_written_to_blindly(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + [self::LISTINGS => function ($request) {
            return $request->method() === 'GET' ? Http::response([], 500) : Http::response(['status' => 'ACCEPTED']);
        }]);

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame('could_not_read_what_the_channel_holds', $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/', 'PUT'));
    }

    public function test_an_answer_that_does_not_say_whether_a_listing_exists_blocks_the_write(): void
    {
        // Amazon answers 200 for the SKU with no summary for the marketplace: the item IS in the seller's catalogue, so a PUT would set it.
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(['sku' => 'SKU70', 'summaries' => [], 'issues' => []]));

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame([ListingOutcome::REFUSED, 'could_not_confirm_that_the_channel_holds_no_listing'], [$outcome->state, $outcome->reason]);
        $this->assertSame(0, $this->callsTo('/listings/', 'PUT'));
        $this->assertSame(0, $this->callsTo('/listings/', 'PATCH'));
        $this->assertSame(0, SalesChannelProductMapping::count());
    }

    public function test_only_a_plain_absence_lets_a_listing_be_created(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $this->assertSame(ListingOutcome::SUBMITTED, $this->publisher()->publish($draft, adminId: 1)->state);
        $this->assertSame(1, $this->callsTo('/listings/', 'PUT'));
    }

    // ---- create with PUT, change with PATCH ----------------------------------------------------------------

    public function test_a_listing_the_channel_does_not_hold_is_created_with_a_put_of_the_product_only(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $outcome = $this->publisher()->publish($draft, adminId: 7);

        $this->assertSame(ListingOutcome::SUBMITTED, $outcome->state);
        $put = collect(Http::recorded(fn ($request): bool => $request->method() === 'PUT'))->first();
        $this->assertNotNull($put);
        $this->assertStringContainsString('/listings/2021-08-01/items/A1SELLER/SKU70', $put[0]->url());
        $this->assertSame([
            'productType' => self::TYPE,
            'requirements' => 'LISTING_PRODUCT_ONLY',
            'attributes' => ['item_name' => 'Vitamin C 500mg'],
        ], $put[0]->data());
    }

    public function test_a_change_to_a_listing_the_channel_holds_is_a_patch_of_only_what_differs(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 1);
        // The channel now holds it, and our own record links it to this product.
        $this->fake($this->definitionSays(['item_name']) + $this->listings($this->holdsAListing()));
        $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        $this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['brand' => 'PharmaCo']);
        $this->fake($this->definitionSays(['item_name']) + $this->listings($this->holdsAListing(), ['status' => 'ACCEPTED', 'submissionId' => 'sub-2']));
        $outcome = $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1);

        $this->assertSame(ListingOutcome::SUBMITTED, $outcome->state);
        $this->assertSame(0, $this->callsTo('/listings/', 'PUT'), 'an existing listing is never replaced by a PUT');
        $patch = collect(Http::recorded(fn ($request): bool => $request->method() === 'PATCH'))->first();
        $this->assertNotNull($patch);
        $this->assertSame([
            'productType' => self::TYPE,
            'patches' => [['op' => 'replace', 'path' => '/attributes/brand', 'value' => 'PharmaCo']],
        ], $patch[0]->data(), 'only the field that changed, and nothing removed');
    }

    public function test_what_was_sent_is_not_what_the_channel_has_confirmed(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 1);

        $draft = ChannelListingDraft::firstOrFail();
        $this->assertSame(['item_name' => 'Vitamin C 500mg'], $draft->submitted_payload, 'it is waiting for the channel');
        $this->assertNull($draft->published_payload, 'and nothing is published because a submission was taken');
        $this->assertSame(['item_name' => 'Vitamin C 500mg'], $draft->changedAttributes(['item_name' => 'Vitamin C 500mg']), 'so the field would still be sent');

        $this->fake($this->listings($this->holdsAListing()));
        $this->publisher()->follow($draft);

        $draft = ChannelListingDraft::firstOrFail();
        $this->assertSame(['item_name' => 'Vitamin C 500mg'], $draft->published_payload, 'now the channel holds it');
        $this->assertNull($draft->submitted_payload, 'and nothing is waiting');
        $this->assertSame([], $draft->changedAttributes(['item_name' => 'Vitamin C 500mg']));
        $this->assertSame(['brand' => 'PharmaCo'], $draft->changedAttributes(['item_name' => 'Vitamin C 500mg', 'brand' => 'PharmaCo']));

        // A second field, so that the merge is read back from the database with more than one key (MySQL stores a JSON object in its own key order, hence the canonical compare).
        $this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['brand' => 'PharmaCo']);
        $this->fake($this->definitionSays(['item_name']) + $this->listings($this->holdsAListing()));
        $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1);
        $this->fake($this->listings($this->holdsAListing()));
        $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        $confirmed = ChannelListingDraft::firstOrFail()->published_payload;
        $this->assertEqualsCanonicalizing(['item_name' => 'Vitamin C 500mg', 'brand' => 'PharmaCo'], $confirmed);
        $this->assertSame('Vitamin C 500mg', $confirmed['item_name'], 'the first field was not lost by the merge');
        $this->assertSame([], ChannelListingDraft::firstOrFail()->changedAttributes(['item_name' => 'Vitamin C 500mg', 'brand' => 'PharmaCo']));
    }

    public function test_the_same_field_can_be_changed_again_and_again_and_the_last_value_is_the_one_remembered(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 1);
        $this->fake($this->listings($this->holdsAListing()));
        $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        foreach (['Vitamin C 1000mg', 'Vitamin C 1000mg (60 tablets)'] as $name) {
            $this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['item_name' => $name]);
            $this->fake($this->definitionSays(['item_name']) + $this->listings($this->holdsAListing()));
            $this->assertSame(ListingOutcome::SUBMITTED, $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1)->state, "sending {$name}");
            $patch = collect(Http::recorded(fn ($request): bool => $request->method() === 'PATCH'))->last();
            $this->assertSame([['op' => 'replace', 'path' => '/attributes/item_name', 'value' => $name]], $patch[0]->data()['patches'], "the patch carries {$name}");

            $this->fake($this->listings($this->holdsAListing()));
            $this->publisher()->follow(ChannelListingDraft::firstOrFail());
            $this->assertSame(['item_name' => $name], ChannelListingDraft::firstOrFail()->published_payload, "the channel now holds {$name}");
        }

        // And the last value is not sent a third time.
        $this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['item_name' => 'Vitamin C 1000mg (60 tablets)']);
        $this->fake($this->definitionSays(['item_name']) + $this->listings($this->holdsAListing()));
        $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1);

        $this->assertSame(0, $this->callsTo('/listings/', 'PATCH'), 'nothing differs from what the channel holds');
    }

    public function test_a_change_the_channel_refused_is_not_remembered_as_published_and_goes_out_again(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 1);
        $this->fake($this->listings($this->holdsAListing()));
        $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        // A change the channel refuses outright.
        $this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['item_name' => 'Vitamin C 1000mg']);
        $this->fake($this->definitionSays(['item_name']) + $this->listings($this->holdsAListing(), ['status' => 'INVALID', 'issues' => [['code' => '1', 'message' => 'too long', 'severity' => 'ERROR']]]));
        $this->assertSame(ListingOutcome::REJECTED, $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1)->state);

        $draft = ChannelListingDraft::firstOrFail();
        $this->assertNull($draft->submitted_payload, 'the refusal applied nothing');
        $this->assertSame(['item_name' => 'Vitamin C 500mg'], $draft->published_payload, 'what the channel holds is unchanged');
        $this->assertSame(['item_name' => 'Vitamin C 1000mg'], $draft->changedAttributes(['item_name' => 'Vitamin C 1000mg']), 'so the new value goes out again');
    }

    // ---- no offer ever leaves here -------------------------------------------------------------------------

    public function test_a_price_or_a_quantity_in_the_draft_is_withheld_and_recorded(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->approveProductWrites();
        $this->fake($this->definitionSays(['item_name']));
        $draft = $this->draft([
            'item_name' => 'Vitamin C 500mg',
            'purchasable_offer' => [['currency' => 'SAR', 'our_price' => [['schedule' => [['value_with_tax' => 39]]]]]],
            'fulfillment_availability' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => 99]],
        ]);
        $this->drafts()->validate($draft);
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $this->publisher()->publish($draft->fresh(), adminId: 1);

        $put = collect(Http::recorded(fn ($request): bool => $request->method() === 'PUT'))->first();
        $this->assertSame(['item_name' => 'Vitamin C 500mg'], $put[0]->data()['attributes'], 'no price and no quantity left the host');
        $this->assertSame(['purchasable_offer', 'fulfillment_availability'], ChannelListingDraft::firstOrFail()->withheld_attributes);
    }

    public function test_a_draft_that_is_nothing_but_an_offer_sends_nothing_at_all(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->approveProductWrites();
        $this->account->forceFill(['settings' => ['listing_requirements' => [self::TYPE => ['required' => ['price']]]] + (array) $this->account->settings])->save();
        $this->account = $this->account->fresh();
        $this->fake([self::SP . '/definitions/2020-09-01/productTypes/*' => Http::response(['productType' => self::TYPE])]);
        $draft = $this->draft(['price' => 39]);
        $this->drafts()->validate($draft);

        $outcome = $this->publisher()->publish($draft->fresh(), adminId: 1);

        $this->assertSame('listing_has_nothing_to_send_that_is_not_an_offer', $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/'));
    }

    // ---- a submission is not a listing ---------------------------------------------------------------------

    public function test_an_accepted_submission_is_not_a_listing_and_creates_nothing_here(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $outcome = $this->publisher()->publish($draft, adminId: 7);

        $this->assertSame(ListingOutcome::SUBMITTED, $outcome->state, 'Amazon took the submission; it did not say a listing exists');
        $draft = ChannelListingDraft::firstOrFail();
        $this->assertSame([ChannelListingDraft::SUBMITTED, 'sub-1', null, 7], [$draft->state, $draft->submission_id, $draft->product_mapping_id, $draft->requested_by]);
        $this->assertSame(0, SalesChannelProductMapping::count(), 'no listing row until the channel says it holds one');
    }

    public function test_the_listing_is_recorded_only_when_the_channel_says_it_holds_one(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 7);

        // Still nothing there: Amazon is working on it.
        $this->fake($this->listings(null));
        $this->assertSame(ListingOutcome::WORKING, $this->publisher()->follow(ChannelListingDraft::firstOrFail())->state);
        $this->assertSame(0, SalesChannelProductMapping::count());

        $this->fake($this->listings($this->holdsAListing(['DISCOVERABLE'])));
        $outcome = $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        $this->assertSame(ListingOutcome::ACCEPTED, $outcome->state);
        $listing = SalesChannelProductMapping::findOrFail($outcome->listingId);
        $this->assertSame([self::PRODUCT, 'SKU70', SalesChannelProductMapping::MAPPED], [(int) $listing->product_id, $listing->listing_key, $listing->status]);
        $this->assertFalse((bool) $listing->inventory_publish, 'a new listing sells nothing until somebody decides where its stock comes from');
        $this->assertFalse((bool) $listing->price_publish);
        $draft = ChannelListingDraft::firstOrFail();
        $this->assertSame([ChannelListingDraft::ACCEPTED, ['DISCOVERABLE']], [$draft->state, $draft->channel_listing_statuses]);
        $this->assertNull($draft->channel_problems);
    }

    public function test_a_listing_the_channel_calls_buyable_although_we_published_no_offer_is_reported_not_celebrated(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 1);

        $this->fake($this->listings($this->holdsAListing(['BUYABLE', 'DISCOVERABLE'])));
        $outcome = $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        $this->assertSame(ListingOutcome::ACCEPTED, $outcome->state);
        $this->assertSame([ListingPublisher::BUYABLE_WITHOUT_OUR_OFFER], $outcome->problems);
        $this->assertSame([ListingPublisher::BUYABLE_WITHOUT_OUR_OFFER], ChannelListingDraft::firstOrFail()->channel_problems);
        $this->assertSame(['BUYABLE', 'DISCOVERABLE'], ChannelListingDraft::firstOrFail()->channel_listing_statuses);
    }

    public function test_a_listing_with_an_error_at_the_channel_is_refused_with_its_reasons(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 1);

        $this->fake($this->listings($this->holdsAListing(['DISCOVERABLE'], [['code' => '99', 'message' => 'still wrong', 'severity' => 'ERROR']])));
        $outcome = $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        $this->assertSame([ListingOutcome::REJECTED, ['99: still wrong']], [$outcome->state, $outcome->problems]);
        $this->assertSame(0, SalesChannelProductMapping::count());
    }

    public function test_a_refusal_of_the_write_itself_is_kept_with_its_reasons_and_creates_no_listing(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null, [
            'status' => 'INVALID', 'submissionId' => 'sub-2',
            'issues' => [['code' => '4000001', 'message' => 'brand is required', 'severity' => 'ERROR'], ['code' => '8000', 'message' => 'a hint', 'severity' => 'WARNING']],
        ]));

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame([ListingOutcome::REJECTED, ['4000001: brand is required']], [$outcome->state, $outcome->problems]);
        $this->assertSame([ChannelListingDraft::REJECTED, ['4000001: brand is required']], [ChannelListingDraft::firstOrFail()->state, ChannelListingDraft::firstOrFail()->channel_problems]);
        $this->assertSame(0, SalesChannelProductMapping::count());
    }

    public function test_a_draft_already_with_the_channel_is_not_sent_again(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 1);
        $writes = $this->callsTo('/listings/', 'PUT');

        $outcome = $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1);

        $this->assertSame('listing_already_submitted', $outcome->reason);
        $this->assertSame($writes, $this->callsTo('/listings/', 'PUT'));
    }

    public function test_a_rate_limit_is_not_a_refusal_of_the_listing_and_leaves_the_draft_ready_to_send(): void
    {
        $draft = $this->readyToSend();
        ThrowingListingConnector::throwing(new ChannelRateLimitedException(90), onTheWriteOnly: true);
        config(['sales_channels.connectors.amazon' => ThrowingListingConnector::class]);

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame([ListingOutcome::REFUSED, 'channel_rate_limited'], [$outcome->state, $outcome->reason]);
        $this->assertSame(ChannelListingDraft::VALIDATED, ChannelListingDraft::firstOrFail()->state, 'nothing about the draft changed: the next run sends it');
        $this->assertSame(0, SalesChannelProductMapping::count());
    }

    public function test_a_failure_nobody_named_refuses_the_attempt_without_repeating_what_the_channel_said(): void
    {
        $draft = $this->readyToSend();
        ThrowingListingConnector::throwing(new RuntimeException('Vitamin C 500mg: the whole payload came back'), onTheWriteOnly: true);
        config(['sales_channels.connectors.amazon' => ThrowingListingConnector::class]);

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame('listing_write_failed', $outcome->reason);
        $this->assertSame('listing_write_failed', ChannelListingDraft::firstOrFail()->refusal_code);
        $this->assertNull(ChannelListingDraft::firstOrFail()->channel_problems, 'what it said is not stored');
    }

    // ---- a pharmacy's own catalogue ------------------------------------------------------------------------

    private function regulate(array $rules = ['name_patterns' => ['Vitamin']]): void
    {
        config(['sales_channels.listing.regulated' => ['mode' => 'strict', 'category_ids' => [], 'name_patterns' => [], 'brand_ids' => [], 'channels' => []] + []]);
        foreach ($rules as $key => $value) {
            config(['sales_channels.listing.regulated.' . $key => $value]);
        }
        DB::table('products')->where('id', self::PRODUCT)->update(['name' => 'Vitamin C 500mg']);
    }

    public function test_with_the_gate_off_a_product_the_rules_would_name_goes_through(): void
    {
        $draft = $this->readyToSend();
        DB::table('products')->where('id', self::PRODUCT)->update(['name' => 'Vitamin C 500mg']);
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $this->assertSame(ListingOutcome::SUBMITTED, $this->publisher()->publish($draft, adminId: 1)->state);
        $this->assertSame(RegulatedProductGate::MODE_OFF, app(RegulatedProductGate::class)->modeFor('amazon'));
        $this->assertFalse(app(RegulatedProductGate::class)->needsClearance(self::PRODUCT, 'amazon'));
    }

    public function test_a_product_whose_classification_is_taken_away_after_the_check_is_refused_not_waved_through(): void
    {
        // The draft passed its checks with a category; somebody removed it before the draft was sent. The gate is the last word, and it fails closed.
        $draft = $this->readyToSend();
        $this->regulate();
        DB::table('products')->where('id', self::PRODUCT)->update(['category_ids' => null]);
        $draft->forceFill(['state' => ChannelListingDraft::VALIDATED, 'problems' => null])->save();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $outcome = $this->publisher()->publish($draft->fresh(), adminId: 1);

        $this->assertContains($outcome->reason, [RegulatedProductGate::CLASSIFICATION_MISSING, 'product_data_not_ready'], 'either lock catches it; neither lets it through');
        $this->assertSame(0, $this->callsTo('/listings/'));
    }

    public function test_the_gate_itself_refuses_an_unclassified_product_whatever_the_rest_says(): void
    {
        $draft = $this->readyToSend();
        $this->regulate();
        DB::table('products')->where('id', self::PRODUCT)->update(['category_ids' => null]);

        $this->assertSame(RegulatedProductGate::CLASSIFICATION_MISSING, app(RegulatedProductGate::class)->refusalFor($draft->fresh()));
        $this->assertTrue(app(RegulatedProductGate::class)->needsClearance(self::PRODUCT, 'amazon'));
    }

    public function test_a_regulated_product_needs_a_recorded_clearance_and_an_admin_id_is_not_one(): void
    {
        $draft = $this->readyToSend();
        $this->regulate();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $outcome = $this->publisher()->publish($draft, adminId: self::CHANNELS_ADMIN);

        $this->assertSame(RegulatedProductGate::NEEDS_CLEARANCE, $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/'), 'passing an admin id is not a clearance');
    }

    public function test_a_recorded_clearance_lets_the_regulated_product_through(): void
    {
        $draft = $this->readyToSend();
        $this->regulate();
        $this->assertTrue($this->drafts()->recordClearance($draft, 'SFDA-12345', self::CHANNELS_ADMIN)->ok());
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $outcome = $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: self::CHANNELS_ADMIN);

        $this->assertSame(ListingOutcome::SUBMITTED, $outcome->state);
        $clearance = ChannelListingDraft::firstOrFail()->regulatory_clearance;
        $this->assertSame(['SFDA-12345', self::CHANNELS_ADMIN], [$clearance['reference'], $clearance['by']]);
    }

    public function test_only_an_admin_whose_role_carries_the_module_may_record_a_clearance(): void
    {
        $draft = $this->readyToSend();
        $this->regulate();

        $this->assertSame(RegulatedProductGate::CLEARANCE_NOT_PERMITTED, $this->drafts()->recordClearance($draft, 'SFDA-1', self::CATALOG_ADMIN)->reason);
        $this->assertSame(RegulatedProductGate::CLEARANCE_NOT_PERMITTED, $this->drafts()->recordClearance($draft, 'SFDA-1', 999)->reason);
        $this->assertSame('regulatory_clearance_needs_a_reference', $this->drafts()->recordClearance($draft, '   ', self::CHANNELS_ADMIN)->reason);
        $this->assertNull(ChannelListingDraft::firstOrFail()->regulatory_clearance);
        $this->assertTrue(app(RegulatedProductGate::class)->mayRecordClearance(1), 'the master admin may');
    }

    public function test_a_category_and_a_brand_rule_can_be_set_for_one_channel_only(): void
    {
        $this->regulate(['category_ids' => [], 'channels' => ['amazon' => ['category_ids' => ['7']]]]);
        DB::table('products')->where('id', self::PRODUCT)->update(['name' => 'Plain soap']);
        $gate = app(RegulatedProductGate::class);

        $this->assertTrue($gate->needsClearance(self::PRODUCT, 'amazon'), 'category 7 is closed on Amazon');
        $this->assertFalse($gate->needsClearance(self::PRODUCT, 'trendyol'), 'and nowhere else');
    }

    // ---- the command a person uses -------------------------------------------------------------------------

    public function test_the_command_lists_the_drafts_and_says_what_each_channel_is_missing(): void
    {
        $this->fake($this->definitionSays(['item_name', 'brand']));
        $this->draft();

        $this->artisan('channels:listings --check')->expectsOutputToContain('refused (listing_fields_missing) — brand')->assertSuccessful();
    }

    public function test_the_command_refuses_to_send_without_the_person_asking(): void
    {
        $draft = $this->readyToSend();

        $this->artisan('channels:listings --publish=' . $draft->id)->assertFailed();

        $this->assertSame(0, $this->callsTo('/listings/'));
    }

    public function test_the_command_sends_the_draft_a_person_names(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));

        $this->artisan('channels:listings --publish=' . $draft->id . ' --admin=4')->expectsOutputToContain('submitted')->assertSuccessful();

        $this->assertSame([ChannelListingDraft::SUBMITTED, 4], [ChannelListingDraft::firstOrFail()->state, ChannelListingDraft::firstOrFail()->requested_by]);
    }

    public function test_the_command_records_a_clearance_and_sends_nothing(): void
    {
        $draft = $this->readyToSend();
        $this->regulate();

        $this->artisan('channels:listings --clear-regulated=' . $draft->id . ' --admin=4 --reference=SFDA-7')->expectsOutputToContain('clearance recorded')->assertSuccessful();

        $this->assertSame('SFDA-7', ChannelListingDraft::firstOrFail()->regulatory_clearance['reference']);
        $this->assertSame(0, $this->callsTo('/listings/'));
    }

    public function test_the_command_refuses_a_clearance_without_a_reference_or_an_admin(): void
    {
        $draft = $this->readyToSend();

        $this->artisan('channels:listings --clear-regulated=' . $draft->id . ' --admin=4')->assertFailed();
        $this->artisan('channels:listings --clear-regulated=' . $draft->id . ' --reference=X')->assertFailed();

        $this->assertNull(ChannelListingDraft::firstOrFail()->regulatory_clearance);
    }

    // ---- what the product form shows -----------------------------------------------------------------------

    public function test_the_product_form_shows_where_the_product_stands_on_every_channel_without_calling_any(): void
    {
        $this->fake($this->definitionSays(['item_name', 'brand']));
        $this->draft();
        $this->drafts()->validate(ChannelListingDraft::firstOrFail());
        $calls = count(Http::recorded(fn ($request): bool => true));

        $rows = app(ProductChannelPanel::class)->for(self::PRODUCT);

        $this->assertCount(1, $rows);
        $this->assertSame([$this->account->id, ChannelListingDraft::DRAFT, ['brand'], true], [
            $rows[0]['account']->id, $rows[0]['state'], $rows[0]['problems'], $rows[0]['canCreate'],
        ]);
        $this->assertNull($rows[0]['listing']);
        $this->assertSame($calls, count(Http::recorded(fn ($request): bool => true)), 'the panel calls no channel');
    }

    public function test_a_listed_product_reads_as_listed_with_what_is_being_published(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->listings(null));
        $this->publisher()->publish($draft, adminId: 1);
        $this->fake($this->listings($this->holdsAListing()));
        $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        $rows = app(ProductChannelPanel::class)->for(self::PRODUCT);

        $this->assertSame('listed', $rows[0]['state']);
        $this->assertFalse((bool) $rows[0]['listing']->inventory_publish, 'listed and selling nothing yet');
    }

    public function test_the_form_says_which_channels_can_be_sent_a_product(): void
    {
        $trendyol = $this->trendyolAccount();

        $rows = collect(app(ProductChannelPanel::class)->for(self::PRODUCT))->keyBy(static fn (array $row): int => (int) $row['account']->id);

        // Trendyol creates listings since PR-D, so the form offers it. What the product is not yet ON is a separate fact from what it CAN be sent to.
        $this->assertTrue($rows[$trendyol->id]['canCreate']);
        $this->assertSame('not_offered', $rows[$trendyol->id]['state']);
        $this->assertTrue($rows[$this->account->id]['canCreate']);
    }

    public function test_a_channel_whose_connector_knows_nothing_of_listings_is_not_offered_on_the_form(): void
    {
        config(['sales_channels.connectors.amazon' => UnreachableChannelConnector::class]);

        $rows = collect(app(ProductChannelPanel::class)->for(self::PRODUCT))->keyBy(static fn (array $row): int => (int) $row['account']->id);

        $this->assertFalse($rows[$this->account->id]['canCreate']);
    }

    public function test_the_form_says_what_the_store_still_has_to_finish(): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update(['thumbnail' => null, 'details' => null]);

        $problems = app(ProductChannelPanel::class)->productProblems(self::PRODUCT);

        $this->assertSame([ProductListingReadiness::NO_DESCRIPTION, ProductListingReadiness::NO_THUMBNAIL], $problems);
        $this->assertSame([], app(ProductChannelPanel::class)->productProblems(null));
    }

    public function test_the_panel_answers_with_nothing_before_the_modules_migrations_have_run(): void
    {
        $this->assertSame([], app(ProductChannelPanel::class)->for(null));
    }
}
