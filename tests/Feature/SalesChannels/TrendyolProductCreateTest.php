<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\ListingSubmission;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Trendyol\TrendyolConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/**
 * Putting one of OUR products on Trendyol, and the three things that must never be confused.
 *
 * Trendyol's product calls are BATCHES. `POST v2/products` answers a `batchRequestId` and nothing else: HTTP 200 means the batch was taken, not that a product exists, and
 * the outcome per item is a later question. Reporting a success here is how a product nobody created is marked as listed.
 *
 * And a barcode can be in one of three states at Trendyol, not two: approved, awaiting review, or genuinely unknown. The middle one is the trap — a product waiting for
 * Trendyol's review EXISTS, and creating it again because it is "not approved yet" leaves the seller with two of the same product and a locked barcode.
 *
 * Product **V2** paths throughout; V1 is retired and used nowhere. Every response is shaped after the published models and no test can reach Trendyol.
 */
class TrendyolProductCreateTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const API = 'https://apigw.trendyol.com/integration';
    private const SELLER = '/product/sellers/' . Trendyol::SELLER;
    private const CREATE = self::API . self::SELLER . '/v2/products';
    private const UPDATE = self::API . self::SELLER . '/products/content-bulk-update';
    private const APPROVED = self::API . self::SELLER . '/products/approved*';
    private const UNAPPROVED = self::API . self::SELLER . '/products/unapproved*';
    private const BARCODE = '6281000000017';
    private const BRANDS = self::API . '/product/brands/by-name*';

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
            'capabilities' => ['read_only_sync', 'product_sync'],
            'capability_approvals' => ['product_writes' => ['by' => 1]],
        ])->save();
    }

    // ---- a batch taken is not a product listed -------------------------------------------------------------------

    public function test_a_create_comes_back_as_taken_for_processing_and_never_as_a_listing(): void
    {
        Http::fake([self::CREATE => Http::response(['batchRequestId' => 'batch-1'])]);

        $submission = $this->trendyol->createListing($this->account, $this->draft(), ['title' => 'Vitamin C 500mg', 'categoryId' => 1234]);

        $this->assertTrue($submission->wasTaken());
        $this->assertFalse($submission->isLive(), 'HTTP 200 is a batch taken, not a product that exists');
        $this->assertSame('batch-1', $submission->id);
        $this->assertSame('batch_taken', $submission->channelStatus);
    }

    public function test_a_two_hundred_with_no_batch_id_is_not_claimed_as_taken(): void
    {
        Http::fake([self::CREATE => Http::response(['warnings' => []])]);

        $submission = $this->trendyol->createListing($this->account, $this->draft(), ['title' => 'Vitamin C']);

        $this->assertSame(ListingSubmission::WORKING, $submission->status, 'without a batch id there is nothing to follow up, so nothing may be claimed');
        $this->assertSame('no_batch_id', $submission->channelStatus);
    }

    public function test_the_create_sends_the_barcode_as_the_identity_and_uses_the_v2_path(): void
    {
        Http::fake([self::CREATE => Http::response(['batchRequestId' => 'batch-1'])]);

        $this->trendyol->createListing($this->account, $this->draft(), ['title' => 'Vitamin C 500mg']);

        $sent = $this->sent();
        $this->assertStringContainsString('/v2/products', $sent[0]->url());
        $this->assertSame(self::BARCODE, $sent[0]->data()['items'][0]['barcode']);
    }

    public function test_a_product_with_no_barcode_is_refused_before_anything_is_sent(): void
    {
        Http::fake([self::CREATE => Http::response(['batchRequestId' => 'batch-1'])]);
        $draft = $this->draft(sku: '');

        $submission = $this->trendyol->createListing($this->account, $draft, ['title' => 'Vitamin C']);

        $this->assertTrue($submission->isRejected());
        $this->assertSame(['barcode_is_required_by_trendyol'], $submission->problems);
        Http::assertNothingSent();
    }

    public function test_a_rehearsing_account_sends_nothing_and_claims_nothing(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        Http::fake([self::CREATE => Http::response(['batchRequestId' => 'batch-1'])]);

        $submission = $this->trendyol->createListing($this->account->fresh(), $this->draft(), ['title' => 'Vitamin C']);

        $this->assertTrue($submission->isUnknown());
        $this->assertSame('dry_run', $submission->channelStatus);
    }

    public function test_without_the_approval_the_create_is_stopped_before_the_call(): void
    {
        $this->account->forceFill(['capability_approvals' => []])->save();
        Http::fake([self::CREATE => Http::response(['batchRequestId' => 'batch-1'])]);

        $this->expectExceptionMessageMatches('/approv/i');
        $this->trendyol->createListing($this->account->fresh(), $this->draft(), ['title' => 'Vitamin C']);
    }

    // ---- what Trendyol will not let an approved product change ---------------------------------------------------

    public function test_a_change_trendyol_refuses_for_an_approved_product_is_refused_here_by_name(): void
    {
        Http::fake([self::UPDATE => Http::response(['batchRequestId' => 'batch-2'])]);

        foreach (['barcode', 'productMainId', 'brandId', 'categoryId'] as $field) {
            $submission = $this->trendyol->updateListing($this->account, $this->draft(), [$field => 'something-else']);

            $this->assertTrue($submission->isRejected(), "{$field} may not be changed once Trendyol approved the product");
            $this->assertSame(['trendyol_will_not_change_' . $field], $submission->problems);
        }

        Http::assertNothingSent();
    }

    public function test_an_ordinary_content_change_goes_as_a_batch_to_the_bulk_update_path(): void
    {
        Http::fake([self::UPDATE => Http::response(['batchRequestId' => 'batch-2'])]);

        $submission = $this->trendyol->updateListing($this->account, $this->draft(), ['title' => 'Vitamin C 1000mg']);

        $this->assertTrue($submission->wasTaken());
        $this->assertSame('batch-2', $submission->id);
        $sent = $this->sent();
        // V2 has no PUT v2/products: the PUT was V1, which is retired.
        $this->assertSame('POST', $sent[0]->method());
        $this->assertStringContainsString('/products/content-bulk-update', $sent[0]->url());
        $this->assertSame(self::BARCODE, $sent[0]->data()['items'][0]['barcode']);
    }

    public function test_a_change_of_nothing_is_not_sent(): void
    {
        Http::fake([self::UPDATE => Http::response(['batchRequestId' => 'batch-2'])]);

        $submission = $this->trendyol->updateListing($this->account, $this->draft(), []);

        $this->assertSame('nothing_changed', $submission->channelStatus);
        Http::assertNothingSent();
    }

    // ---- the three states of a barcode, and the trap in the middle -----------------------------------------------

    public function test_a_barcode_in_the_approved_list_is_live(): void
    {
        Http::fake([self::APPROVED => Http::response($this->page([['barcode' => self::BARCODE]]))]);

        $submission = $this->trendyol->listingFor($this->account, self::BARCODE);

        $this->assertTrue($submission->isLive());
        $this->assertSame(self::BARCODE, $submission->listingKey);
    }

    /** The trap: a product awaiting review exists, and a create would duplicate it. */
    public function test_a_barcode_awaiting_trendyols_review_is_working_and_never_absent(): void
    {
        Http::fake([
            self::APPROVED => Http::response($this->page([])),
            self::UNAPPROVED => Http::response($this->page([['barcode' => self::BARCODE]])),
        ]);

        $submission = $this->trendyol->listingFor($this->account, self::BARCODE);

        $this->assertSame(ListingSubmission::WORKING, $submission->status);
        $this->assertFalse($submission->isAbsent(), 'a create on this would leave two of the same product and a locked barcode');
        $this->assertSame('awaiting_trendyol_approval', $submission->channelStatus);
    }

    public function test_a_barcode_in_neither_list_is_absent_which_is_the_only_answer_a_create_may_be_made_on(): void
    {
        Http::fake([
            self::APPROVED => Http::response($this->page([])),
            self::UNAPPROVED => Http::response($this->page([])),
        ]);

        $submission = $this->trendyol->listingFor($this->account, self::BARCODE);

        $this->assertTrue($submission->isAbsent());
    }

    public function test_a_list_that_could_not_be_read_is_unknown_and_never_absent(): void
    {
        Http::fake([self::APPROVED => Http::response('', 503)]);

        $submission = $this->trendyol->listingFor($this->account, self::BARCODE);

        $this->assertTrue($submission->isUnknown());
        $this->assertFalse($submission->isAbsent(), 'a failed call is not an empty catalogue');
    }

    public function test_an_unapproved_list_that_could_not_be_read_is_unknown_not_absent(): void
    {
        Http::fake([
            self::APPROVED => Http::response($this->page([])),
            self::UNAPPROVED => Http::response('', 500),
        ]);

        $submission = $this->trendyol->listingFor($this->account, self::BARCODE);

        $this->assertTrue($submission->isUnknown(), 'the approved list answered but the other did not, so the answer is not known');
    }

    public function test_a_page_that_answers_with_another_barcode_is_not_read_as_holding_ours(): void
    {
        Http::fake([
            self::APPROVED => Http::response($this->page([['barcode' => '9999999999999']])),
            self::UNAPPROVED => Http::response($this->page([])),
        ]);

        $submission = $this->trendyol->listingFor($this->account, self::BARCODE);

        $this->assertTrue($submission->isAbsent(), 'the filter is Trendyol\'s, but the barcode is checked here rather than trusting a non-empty page');
    }

    public function test_a_barcode_on_a_variant_of_an_approved_product_counts(): void
    {
        Http::fake([self::APPROVED => Http::response($this->page([['productMainId' => 'VITC', 'variants' => [['barcode' => self::BARCODE]]]]))]);

        $this->assertTrue($this->trendyol->listingFor($this->account, self::BARCODE)->isLive());
    }

    public function test_no_barcode_at_all_is_unknown_and_asks_nothing(): void
    {
        Http::fake([self::APPROVED => Http::response($this->page([]))]);

        $this->assertTrue($this->trendyol->listingFor($this->account, '  ')->isUnknown());
        Http::assertNothingSent();
    }

    // ---- the offer never rides along ------------------------------------------------------------------------------

    public function test_every_field_that_would_price_or_stock_a_listing_is_named_as_an_offer_attribute(): void
    {
        $offer = $this->trendyol->offerAttributes();

        foreach (['listPrice', 'salePrice', 'quantity'] as $field) {
            $this->assertContains($field, $offer, "{$field} must be withheld from a create: a price and a quantity leave only through the publishing path");
        }
    }

    // ---- the channel's own brand list ----------------------------------------------------------------------------

    public function test_a_brand_name_is_resolved_to_trendyols_own_id(): void
    {
        Http::fake([self::BRANDS => Http::response([['id' => 1584892, 'name' => 'Acme', 'luxe' => false]])]);

        $brands = $this->trendyol->brandsNamed($this->account, 'Acme');

        $this->assertSame([['id' => 1584892, 'name' => 'Acme']], $brands);
    }

    public function test_an_exact_match_leads_but_every_candidate_comes_back(): void
    {
        Http::fake([self::BRANDS => Http::response([
            ['id' => 2, 'name' => 'Acme Health', 'luxe' => false],
            ['id' => 1, 'name' => 'Acme', 'luxe' => false],
            ['id' => 3, 'name' => 'Acme Pharma', 'luxe' => false],
        ])]);

        $brands = $this->trendyol->brandsNamed($this->account, 'Acme');

        $this->assertSame('Acme', $brands[0]['name'], 'the exact match leads');
        $this->assertCount(3, $brands, 'and none of the others is thrown away: a person may want one of them');
    }

    public function test_a_channel_with_no_such_brand_answers_an_empty_list_which_is_not_a_failure(): void
    {
        Http::fake([self::BRANDS => Http::response([])]);

        $this->assertSame([], $this->trendyol->brandsNamed($this->account, 'Nobody'));
    }

    public function test_a_brand_lookup_that_could_not_be_asked_throws_rather_than_answering_nothing(): void
    {
        Http::fake([self::BRANDS => Http::response('', 503)]);

        // "We do not know" must never arrive as "there is no such brand": the one would have a person register a brand that already exists.
        $this->expectException(RuntimeException::class);
        $this->trendyol->brandsNamed($this->account, 'Acme');
    }

    public function test_rows_without_an_id_or_a_name_are_not_offered_as_brands(): void
    {
        Http::fake([self::BRANDS => Http::response([['name' => 'No id'], ['id' => 7], ['id' => 8, 'name' => '  '], ['id' => 9, 'name' => 'Real']])]);

        $this->assertSame([['id' => 9, 'name' => 'Real']], $this->trendyol->brandsNamed($this->account, 'Real'));
    }

    public function test_an_empty_name_asks_nothing(): void
    {
        Http::fake([self::BRANDS => Http::response([['id' => 1, 'name' => 'Acme']])]);

        $this->assertSame([], $this->trendyol->brandsNamed($this->account, '   '));
        Http::assertNothingSent();
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function draft(string $sku = self::BARCODE): ChannelListingDraft
    {
        return new ChannelListingDraft([
            'account_id' => $this->account->id,
            'sales_channel' => 'trendyol',
            'product_id' => 70,
            'variant_key' => '',
            'product_type' => '1234',
            'payload' => ['sku' => $sku, 'title' => 'Vitamin C 500mg'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private function page(array $records): array
    {
        return ['content' => $records, 'totalElements' => count($records), 'page' => 0, 'size' => 1];
    }

    /** @return list<Request> */
    private function sent(): array
    {
        return Http::recorded(static fn (Request $request): bool => $request->method() === 'POST')->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
