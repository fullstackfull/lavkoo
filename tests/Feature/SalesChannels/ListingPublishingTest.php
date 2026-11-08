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
use App\Services\SalesChannels\Listings\RegulatedProductGate;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\ThrowingListingConnector;
use Tests\TestCase;

/**
 * Putting a product of the store ON a channel: the draft, what the channel says it requires, the gates, the one call that creates the listing, and what is made of the answer.
 *
 * Every Amazon response here is a fixture shaped after the published models and no test can reach Amazon (stray requests are refused). They prove the pipeline against those
 * shapes; they do not prove a live seller account works — that needs real credentials.
 */
class ListingPublishingTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const LWA = 'https://api.amazon.com/auth/o2/token';
    private const SP = 'https://sellingpartnerapi-eu.amazon.com';
    private const PRODUCT = 70;
    private const TYPE = 'HEALTH_PERSONAL_CARE';

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
    }

    private function approveProductWrites(): void
    {
        $this->account->forceFill(['capability_approvals' => ((array) $this->account->capability_approvals) + [ChannelOperations::SCOPE_PRODUCT_WRITES => ['by' => 1]]])->save();
        $this->account = $this->account->fresh();
    }

    /**
     * The answers for the next calls, and ONLY those: Laravel keeps every earlier fake and the first match wins, so the list is started again each time.
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

    private function putAnswers(array $body, int $status = 200): array
    {
        return [self::SP . '/listings/2021-08-01/items/*' => Http::response($body, $status)];
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

    private function callsTo(string $needle): int
    {
        return count(Http::recorded(fn ($request): bool => str_contains($request->url(), $needle)));
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
        $draft = $this->draft();

        $this->assertSame('SKU' . self::PRODUCT, $draft->payload['sku']);

        $again = $this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['sku' => 'SOMETHING-ELSE']);

        $this->assertSame('SKU' . self::PRODUCT, $again->payload['sku'], 'the identity of the listing is decided once');
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

        $this->assertSame([ListingOutcome::REFUSED, 'listing_fields_missing'], [$outcome->state, $outcome->reason]);
        $this->assertSame(['brand'], $outcome->problems);
        $draft = ChannelListingDraft::firstOrFail();
        $this->assertSame([ChannelListingDraft::DRAFT, ['brand']], [$draft->state, $draft->problems]);
    }

    public function test_every_field_the_channel_insists_on_makes_it_ready_to_send(): void
    {
        $this->fake($this->definitionSays(['item_name']));

        $outcome = $this->drafts()->validate($this->draft());

        $this->assertSame(ListingOutcome::VALIDATED, $outcome->state);
        $this->assertSame(ChannelListingDraft::VALIDATED, ChannelListingDraft::firstOrFail()->state);
        $this->assertSame(1, $this->callsTo('/definitions/'));
    }

    public function test_the_channels_answer_is_not_asked_for_again_while_it_is_fresh(): void
    {
        $this->fake($this->definitionSays(['item_name']));
        $this->drafts()->validate($this->draft());

        $this->drafts()->validate($this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['item_name' => 'Vitamin C 500mg (new box)']));

        $this->assertSame(1, $this->callsTo('/definitions/'));
    }

    public function test_a_channel_that_will_not_say_what_it_requires_is_not_read_as_requiring_nothing(): void
    {
        // The real definition keeps its rules in a schema behind a presigned link this version does not fetch: the answer carries no `required` list.
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

        $this->assertSame(['brand'], $outcome->problems, 'the owner\'s list decides what is missing');
        $this->assertSame(ListingRequirements::DECLARED_BY_THE_OWNER, ChannelListingDraft::firstOrFail()->requirements['source']);
    }

    public function test_a_draft_without_the_channels_product_type_is_refused_before_anything_is_asked(): void
    {
        $outcome = $this->drafts()->validate($this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['item_name' => 'X']));

        $this->assertSame('listing_product_type_missing', $outcome->reason);
        $this->assertSame(0, $this->callsTo('amazon.com'));
    }

    // ---- the gates -----------------------------------------------------------------------------------------

    public function test_with_the_host_switch_off_nothing_leaves_the_host(): void
    {
        $this->fake($this->definitionSays(['item_name']));
        $this->drafts()->validate($this->draft());
        Http::fake([self::LWA => Http::response(['access_token' => 'Atza|token', 'expires_in' => 3600])]);

        $outcome = $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1);

        $this->assertSame([ListingOutcome::REFUSED, 'listing_publishing_off'], [$outcome->state, $outcome->reason]);
        $this->assertSame(0, $this->callsTo('/listings/'));
    }

    public function test_without_the_recorded_product_writes_approval_the_listing_is_not_created(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->fake($this->definitionSays(['item_name']));
        $this->drafts()->validate($this->draft());

        $outcome = $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1);

        $this->assertSame('product_writes_approval_missing', $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/'));
    }

    public function test_an_account_without_the_product_sync_capability_is_refused(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->approveProductWrites();
        $this->account->forceFill(['capabilities' => ['connection_test', 'read_only_sync']])->save();
        $draft = $this->draft();
        $draft->forceFill(['state' => ChannelListingDraft::VALIDATED])->save();

        $outcome = $this->publisher()->publish($draft->fresh(), adminId: 1);

        $this->assertSame('product_sync_capability_off', $outcome->reason);
    }

    public function test_a_channel_whose_connector_cannot_create_a_listing_says_so_instead_of_pretending(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $trendyol = $this->trendyolAccount();
        $trendyol->forceFill(['capability_approvals' => ((array) $trendyol->capability_approvals) + [ChannelOperations::SCOPE_PRODUCT_WRITES => ['by' => 1]]])->save();
        $draft = $this->drafts()->draftFor($trendyol->fresh(), self::PRODUCT, productType: '1234', payload: ['item_name' => 'X']);

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame('channel_cannot_create_listings', $outcome->reason);
    }

    public function test_a_channel_with_no_connector_at_all_is_refused_not_crashed_on(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->approveProductWrites();
        $draft = $this->draft();
        $draft->forceFill(['state' => ChannelListingDraft::VALIDATED])->save();
        config(['sales_channels.connectors.amazon' => null]);

        $outcome = $this->publisher()->publish($draft->fresh(), adminId: 1);

        $this->assertSame('channel_cannot_create_listings', $outcome->reason);
    }

    // ---- no second listing ---------------------------------------------------------------------------------

    public function test_a_product_that_already_has_a_listing_on_this_account_never_gets_a_second_one(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->approveProductWrites();
        $this->fake($this->definitionSays(['item_name']));
        $draft = $this->draft();
        $this->drafts()->validate($draft);
        // A listing read from the channel after the draft was made, for the same product: it is the same thing, so nothing is created.
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'ANOTHER-KEY');

        $outcome = $this->publisher()->publish($draft->fresh(), adminId: 1);

        $this->assertSame('listing_already_exists', $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/2021-08-01'));
    }

    // ---- the one call that creates it ----------------------------------------------------------------------

    private function readyToSend(): ChannelListingDraft
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $this->approveProductWrites();
        $this->fake($this->definitionSays(['item_name']));
        $draft = $this->draft();
        $this->drafts()->validate($draft);

        return $draft->fresh();
    }

    public function test_an_accepted_listing_is_created_here_with_stock_and_price_publishing_off(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['sku' => 'SKU70', 'status' => 'ACCEPTED', 'submissionId' => 'sub-1']));

        $outcome = $this->publisher()->publish($draft, adminId: 7);

        $this->assertSame(ListingOutcome::ACCEPTED, $outcome->state);
        $listing = SalesChannelProductMapping::findOrFail($outcome->listingId);
        $this->assertSame([self::PRODUCT, 'SKU70', SalesChannelProductMapping::MAPPED], [(int) $listing->product_id, $listing->listing_key, $listing->status]);
        $this->assertFalse((bool) $listing->inventory_publish, 'a new listing sells nothing until somebody decides where its stock comes from');
        $this->assertFalse((bool) $listing->price_publish);
        $draft = ChannelListingDraft::firstOrFail();
        $this->assertSame([ChannelListingDraft::ACCEPTED, 'sub-1', $listing->id, 7], [$draft->state, $draft->submission_id, $draft->product_mapping_id, $draft->requested_by]);
        $this->assertSame(1, $this->callsTo('/listings/2021-08-01'));
    }

    public function test_what_is_sent_is_the_channels_own_fields_and_our_sku_is_the_identity(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'ACCEPTED']));

        $this->publisher()->publish($draft, adminId: 1);

        $put = collect(Http::recorded(fn ($request): bool => $request->method() === 'PUT'))->first();
        $this->assertNotNull($put);
        $this->assertStringContainsString('/listings/2021-08-01/items/A1SELLER/SKU70', $put[0]->url());
        $this->assertSame(['productType' => self::TYPE, 'requirements' => 'LISTING', 'attributes' => ['item_name' => 'Vitamin C 500mg']], $put[0]->data());
    }

    public function test_publishing_an_accepted_draft_again_sends_nothing(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'ACCEPTED']));
        $this->publisher()->publish($draft, adminId: 1);
        $calls = $this->callsTo('/listings/2021-08-01');

        $outcome = $this->publisher()->publish(ChannelListingDraft::firstOrFail(), adminId: 1);

        $this->assertSame('listing_already_accepted', $outcome->reason);
        $this->assertSame($calls, $this->callsTo('/listings/2021-08-01'));
        $this->assertSame(1, SalesChannelProductMapping::count(), 'and no second listing');
    }

    public function test_a_refusal_from_the_channel_is_kept_with_its_reasons_and_creates_no_listing(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers([
            'status' => 'INVALID', 'submissionId' => 'sub-2',
            'issues' => [['code' => '4000001', 'message' => 'brand is required', 'severity' => 'ERROR'], ['code' => '8000', 'message' => 'a hint', 'severity' => 'WARNING']],
        ]));

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame([ListingOutcome::REJECTED, ['4000001: brand is required']], [$outcome->state, $outcome->problems]);
        $draft = ChannelListingDraft::firstOrFail();
        $this->assertSame([ChannelListingDraft::REJECTED, ['4000001: brand is required']], [$draft->state, $draft->channel_problems]);
        $this->assertSame(0, SalesChannelProductMapping::count());
    }

    public function test_a_corrected_draft_is_sent_again_as_the_same_listing_not_as_a_new_product(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'INVALID', 'issues' => [['code' => '1', 'message' => 'brand is required', 'severity' => 'ERROR']]]));
        $this->publisher()->publish($draft, adminId: 1);

        $corrected = $this->drafts()->draftFor($this->account, self::PRODUCT, payload: ['brand' => 'PharmaCo']);
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'ACCEPTED']));
        $outcome = $this->publisher()->publish($corrected, adminId: 1);

        $this->assertSame(ListingOutcome::ACCEPTED, $outcome->state);
        $this->assertSame(1, ChannelListingDraft::count());
        $this->assertSame(1, SalesChannelProductMapping::count());
        $this->assertSame('SKU70', SalesChannelProductMapping::firstOrFail()->listing_key, 'the same SKU: the same listing');
    }

    public function test_a_rate_limit_is_not_a_refusal_of_the_listing_and_leaves_the_draft_ready_to_send(): void
    {
        $draft = $this->readyToSend();
        ThrowingListingConnector::throwing(new ChannelRateLimitedException(90));
        config(['sales_channels.connectors.amazon' => ThrowingListingConnector::class]);

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame([ListingOutcome::REFUSED, 'channel_rate_limited'], [$outcome->state, $outcome->reason]);
        $this->assertSame(ChannelListingDraft::VALIDATED, ChannelListingDraft::firstOrFail()->state, 'nothing about the draft changed: the next run sends it');
        $this->assertSame(0, SalesChannelProductMapping::count());
    }

    public function test_a_failure_nobody_named_refuses_the_attempt_without_repeating_what_the_channel_said(): void
    {
        $draft = $this->readyToSend();
        ThrowingListingConnector::throwing(new RuntimeException('Vitamin C 500mg: the whole payload came back'));
        config(['sales_channels.connectors.amazon' => ThrowingListingConnector::class]);

        $outcome = $this->publisher()->publish($draft, adminId: 1);

        $this->assertSame('listing_write_failed', $outcome->reason);
        $this->assertSame('listing_write_failed', ChannelListingDraft::firstOrFail()->refusal_code);
        $this->assertNull(ChannelListingDraft::firstOrFail()->channel_problems, 'what it said is not stored');
    }

    // ---- following a submission the channel has not finished -----------------------------------------------

    public function test_a_submission_the_channel_is_still_working_on_is_followed_not_sent_again(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'OTHER', 'submissionId' => 'sub-3']));
        $this->assertSame(ListingOutcome::SUBMITTED, $this->publisher()->publish($draft, adminId: 1)->state);
        $this->assertSame(ChannelListingDraft::SUBMITTED, ChannelListingDraft::firstOrFail()->state);

        $this->fake($this->definitionSays(['item_name']) + [self::SP . '/listings/2021-08-01/items/*' => Http::response(['sku' => 'SKU70', 'issues' => []])]);
        $outcome = $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        $this->assertSame(ListingOutcome::ACCEPTED, $outcome->state);
        $this->assertSame(ChannelListingDraft::ACCEPTED, ChannelListingDraft::firstOrFail()->state);
        $this->assertSame(1, SalesChannelProductMapping::count());
    }

    public function test_following_a_submission_that_still_has_errors_keeps_it_refused(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'OTHER', 'submissionId' => 'sub-4']));
        $this->publisher()->publish($draft, adminId: 1);

        $this->fake([self::SP . '/listings/2021-08-01/items/*' => Http::response(['issues' => [['code' => '99', 'message' => 'still wrong', 'severity' => 'ERROR']]])]);
        $outcome = $this->publisher()->follow(ChannelListingDraft::firstOrFail());

        $this->assertSame([ListingOutcome::REJECTED, ['99: still wrong']], [$outcome->state, $outcome->problems]);
        $this->assertSame(0, SalesChannelProductMapping::count());
    }

    // ---- the command a person uses -------------------------------------------------------------------------

    public function test_the_command_lists_the_drafts_and_says_what_each_channel_is_missing(): void
    {
        $this->fake($this->definitionSays(['item_name', 'brand']));
        $this->draft();

        $this->artisan('channels:listings --check')
            ->expectsOutputToContain('refused (listing_fields_missing) — brand')
            ->assertSuccessful();
    }

    public function test_the_command_refuses_to_send_without_the_person_asking(): void
    {
        $draft = $this->readyToSend();

        $this->artisan('channels:listings --publish=' . $draft->id)->assertFailed();

        $this->assertSame(0, $this->callsTo('/listings/2021-08-01'));
    }

    public function test_the_command_sends_the_draft_a_person_names(): void
    {
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'ACCEPTED', 'submissionId' => 'sub-9']));

        $this->artisan('channels:listings --publish=' . $draft->id . ' --admin=4')->expectsOutputToContain('accepted')->assertSuccessful();

        $this->assertSame([ChannelListingDraft::ACCEPTED, 4], [ChannelListingDraft::firstOrFail()->state, ChannelListingDraft::firstOrFail()->requested_by]);
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
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'ACCEPTED']));
        $this->publisher()->publish($draft, adminId: 1);

        $rows = app(ProductChannelPanel::class)->for(self::PRODUCT);

        $this->assertSame('listed', $rows[0]['state']);
        $this->assertFalse((bool) $rows[0]['listing']->inventory_publish, 'listed and selling nothing yet');
    }

    public function test_a_channel_that_cannot_create_listings_is_said_so_on_the_form(): void
    {
        $trendyol = $this->trendyolAccount();

        $rows = collect(app(ProductChannelPanel::class)->for(self::PRODUCT))->keyBy(static fn (array $row): int => (int) $row['account']->id);

        $this->assertFalse($rows[$trendyol->id]['canCreate']);
        $this->assertSame('not_offered', $rows[$trendyol->id]['state']);
        $this->assertTrue($rows[$this->account->id]['canCreate']);
    }

    public function test_the_panel_answers_with_nothing_before_the_modules_migrations_have_run(): void
    {
        $this->assertSame([], app(ProductChannelPanel::class)->for(null));
    }

    // ---- a pharmacy's own catalogue ------------------------------------------------------------------------

    public function test_a_regulated_product_is_never_published_by_anything_automatic(): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update(['name' => 'Vitamin C 500mg']);
        config(['sales_channels.listing.regulated_name_patterns' => ['Vitamin']]);
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'ACCEPTED']));

        $outcome = $this->publisher()->publish($draft, adminId: null);

        $this->assertSame(RegulatedProductGate::NEEDS_A_PERSON, $outcome->reason);
        $this->assertSame(0, $this->callsTo('/listings/2021-08-01'));
    }

    public function test_a_person_may_publish_a_regulated_product_and_it_is_recorded(): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update(['name' => 'Vitamin C 500mg']);
        config(['sales_channels.listing.regulated_name_patterns' => ['Vitamin']]);
        $draft = $this->readyToSend();
        $this->fake($this->definitionSays(['item_name']) + $this->putAnswers(['status' => 'ACCEPTED']));

        $outcome = $this->publisher()->publish($draft, adminId: 9);

        $this->assertSame(ListingOutcome::ACCEPTED, $outcome->state);
        $this->assertSame(9, ChannelListingDraft::firstOrFail()->requested_by);
    }

    public function test_a_product_in_a_regulated_category_is_held_back_too(): void
    {
        DB::table('products')->where('id', self::PRODUCT)->update(['category_ids' => json_encode([['id' => '12', 'position' => 1]])]);
        config(['sales_channels.listing.regulated_category_ids' => ['12']]);

        $this->assertTrue(app(RegulatedProductGate::class)->isRegulated(self::PRODUCT));
        $this->assertNull(app(RegulatedProductGate::class)->refusalFor(self::PRODUCT, true));
    }

    public function test_with_the_owners_lists_empty_this_gate_stops_nothing(): void
    {
        $this->assertFalse(app(RegulatedProductGate::class)->isRegulated(self::PRODUCT));
    }
}
