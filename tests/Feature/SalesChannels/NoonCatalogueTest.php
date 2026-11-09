<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\ListingSubmission;
use App\Services\SalesChannels\Noon\NoonConnector;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Noon\NoonParentSku;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Putting a product INTO noon's catalogue, and finding out afterwards what noon made of it.
 *
 * Three documented calls, and one thing noon does not publish at all:
 *
 *  - `POST /content/v1/product/upsert` creates AND amends, noon deciding which by our own `partner_sku`. Every field its schema lists is required, and its 200 carries both
 *    a `status` object (so a processed request can still be a refusal) and `sku_parent`.
 *  - `POST /content/v1/product/content/get` says what noon holds — but it takes `sku_parent` ONLY. Our `partner_sku` is not a key it accepts.
 *  - `POST /catplat/v1/barcode/map` ties a `partner_sku` to a barcode and creates the barcode if noon lacks it.
 *
 * **noon publishes no way to look a `sku_parent` up from a `partner_sku`.** So the pair is remembered from the upsert that answered it, and a product we hold no `sku_parent`
 * for is UNKNOWN — never ABSENT. That single distinction is what stops a second catalogue item being created for a product noon already lists, which is the failure this
 * whole file exists to prevent.
 *
 * Every response is a fixture shaped after noon's own published schemas. No test reaches noon, and nothing here proves a real seller account works.
 */
class NoonCatalogueTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const PRODUCTION = 'https://noon-api-gateway.noon.partners';
    private const LOGIN = self::PRODUCTION . '/identity/public/v1/api/login';
    private const UPSERT = self::PRODUCTION . '/content/v1/product/upsert';
    private const CONTENT_GET = self::PRODUCTION . '/content/v1/product/content/get';
    private const BARCODE_MAP = self::PRODUCTION . '/catplat/v1/barcode/map';
    private const CATEGORY = 'health-vitamins_and_supplements-vitamin_c';
    private const PRODUCT = 4242;

    private SalesChannelAccount $account;

    private int $drafts = 0;

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
        $this->account = $this->noonAccount();
    }

    // ---- the upsert body is noon's own schema --------------------------------------------------------------------

    public function test_a_create_sends_every_field_noons_schema_requires(): void
    {
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1', 'variants' => [['sku' => 'N1', 'partner_sku' => 'SKU-1', 'psku_code' => 'P1']], 'status' => ['status_code' => 'OK', 'status_id' => 0]])]);

        $submission = app(NoonConnector::class)->createListing($this->account, $this->draft(), $this->attributes());

        $this->assertTrue($submission->wasTaken());
        $body = $this->bodyOf(self::UPSERT);
        $this->assertSame([['partner_sku' => 'SKU-1', 'size' => '500mg']], $body['skus']);
        $this->assertSame('Acme Pharma', $body['brand']);
        $this->assertSame(self::CATEGORY, $body['category']);
        $this->assertSame([['url' => 'https://cdn.example.test/a.jpg', 'sort' => 1], ['url' => 'https://cdn.example.test/b.jpg', 'sort' => 2]], $body['images']);
        $this->assertSame($this->attributes(), $body['attributes']);
    }

    public function test_the_sku_parent_noon_answers_is_what_the_submission_carries(): void
    {
        // It is the ONLY moment noon ever tells us its own code for our product, and the only key `content/get` accepts.
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-99', 'status' => ['status_code' => 'OK', 'status_id' => 0]])]);

        $submission = app(NoonConnector::class)->createListing($this->account, $this->draft(), $this->attributes());

        $this->assertSame('NP-99', $submission->id);
    }

    public function test_a_blank_size_is_left_out_rather_than_sent_empty(): void
    {
        // noon's schema calls `size` nullable, and no size is not the same thing as a size of "".
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1', 'status' => ['status_code' => 'OK', 'status_id' => 0]])]);

        app(NoonConnector::class)->createListing($this->account, $this->draft(['size' => '']), $this->attributes());

        $this->assertSame([['partner_sku' => 'SKU-1']], $this->bodyOf(self::UPSERT)['skus']);
    }

    public function test_a_draft_missing_a_field_noon_requires_is_refused_before_anything_is_sent(): void
    {
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1'])]);

        foreach ([['brand' => ''], ['images' => []]] as $hole) {
            $submission = app(NoonConnector::class)->createListing($this->account, $this->draft($hole), $this->attributes());

            $this->assertTrue($submission->isRejected(), 'noon requires every one of these, so our own message says what to fill in');
            $this->assertStringContainsString('noon requires', $submission->problems[0]);
        }
        $this->assertSame([], $this->sentTo(self::UPSERT));
    }

    public function test_a_draft_with_no_category_is_refused_because_noon_takes_the_category_on_this_call(): void
    {
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1'])]);
        $draft = $this->draft();
        $draft->forceFill(['product_type' => ''])->save();

        $submission = app(NoonConnector::class)->createListing($this->account, $draft, $this->attributes());

        $this->assertTrue($submission->isRejected());
        $this->assertSame([], $this->sentTo(self::UPSERT));
    }

    // ---- a 200 is not a success, at the product level either ----------------------------------------------------

    public function test_a_two_hundred_whose_own_status_refused_the_product_is_a_refusal(): void
    {
        // The 200's `status` is the same object as every per-item status here: a request noon processed can still have refused the product.
        $this->fake([self::UPSERT => Http::response([
            'sku_parent' => 'NP-1',
            'status' => ['status_code' => 'INVALID_CATEGORY', 'status_id' => 3, 'message' => 'category is not in the list'],
        ])]);

        $submission = app(NoonConnector::class)->createListing($this->account, $this->draft(), $this->attributes());

        $this->assertTrue($submission->isRejected());
        $this->assertStringContainsString('INVALID_CATEGORY', $submission->problems[0]);
        $this->assertStringContainsString('not in the list', $submission->problems[0]);
    }

    public function test_an_ok_status_without_a_usable_id_does_not_confirm_the_product_either(): void
    {
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1', 'status' => ['status_code' => 'OK']])]);

        $submission = app(NoonConnector::class)->createListing($this->account, $this->draft(), $this->attributes());

        $this->assertTrue($submission->isRejected());
        $this->assertStringContainsString('ok_without_a_usable_status_id', $submission->problems[0]);
    }

    public function test_a_two_hundred_naming_no_sku_parent_is_refused_because_nothing_could_follow_it_up(): void
    {
        // Taken, perhaps. But `sku_parent` is the only key `content/get` accepts and noon publishes no way to find one later, so calling this accepted would leave a
        // product nobody can ever ask about again.
        $this->fake([self::UPSERT => Http::response(['status' => ['status_code' => 'OK', 'status_id' => 0]])]);

        $submission = app(NoonConnector::class)->createListing($this->account, $this->draft(), $this->attributes());

        $this->assertTrue($submission->isRejected());
        $this->assertStringContainsString('named no sku_parent', $submission->problems[0]);
    }

    public function test_a_response_with_no_status_object_at_all_is_still_read_for_its_sku_parent(): void
    {
        // noon's schema marks `status` required, but an answer that omits it has not refused anything: the absence of a refusal is what there is to go on.
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-7'])]);

        $submission = app(NoonConnector::class)->createListing($this->account, $this->draft(), $this->attributes());

        $this->assertTrue($submission->wasTaken());
        $this->assertSame('NP-7', $submission->id);
    }

    public function test_a_refused_request_is_a_refusal_and_not_a_submission(): void
    {
        $this->fake([self::UPSERT => Http::response(['message' => 'bad request'], 400)]);

        $submission = app(NoonConnector::class)->createListing($this->account, $this->draft(), $this->attributes());

        $this->assertTrue($submission->isRejected());
    }

    // ---- an amend sends the whole product, because noon never said it merges -------------------------------------

    public function test_an_amend_sends_the_whole_attribute_set_and_not_only_what_changed(): void
    {
        // noon's page says only "Creates or updates a product in the catalog" and never says what becomes of an attribute an amend does not mention. Against a REPLACE,
        // sending only the changed field would empty everything nobody touched -- so the whole set goes, which is safe either way.
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1', 'status' => ['status_code' => 'OK', 'status_id' => 0]])]);

        app(NoonConnector::class)->updateListing($this->account, $this->draft(), ['item_name' => 'Vitamin C 500mg, 60 tablets']);

        $this->assertSame($this->attributes(), $this->bodyOf(self::UPSERT)['attributes'], 'every attribute the draft holds, not the one that changed');
    }

    public function test_an_amend_with_nothing_changed_sends_nothing(): void
    {
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1'])]);

        $submission = app(NoonConnector::class)->updateListing($this->account, $this->draft(), []);

        $this->assertSame('nothing_changed', $submission->channelStatus);
        $this->assertSame([], $this->sentTo(self::UPSERT));
    }

    public function test_an_offer_attribute_never_reaches_the_content_call_even_on_an_amend(): void
    {
        // The publisher strips offer attributes from what it passes in; an amend builds its own payload from the draft, so it has to strip them itself or a price would
        // reach noon through the one call no price switch guards.
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1', 'status' => ['status_code' => 'OK', 'status_id' => 0]])]);
        $draft = $this->draft();
        $draft->forceFill(['payload' => ($draft->payload ?? []) + ['attributes' => $this->attributes() + ['price' => '49.00', 'qty' => 10]]])->save();

        app(NoonConnector::class)->updateListing($this->account, $draft->fresh(), ['item_name' => 'changed']);

        $sent = $this->bodyOf(self::UPSERT)['attributes'];
        $this->assertArrayNotHasKey('price', $sent);
        $this->assertArrayNotHasKey('qty', $sent);
        $this->assertArrayHasKey('item_name', $sent);
    }

    // ---- what noon holds: sku_parent only -----------------------------------------------------------------------

    public function test_a_product_whose_sku_parent_we_never_learned_is_unknown_and_never_absent(): void
    {
        // THE central rule. noon has no partner_sku -> sku_parent lookup, so there is nothing to ask; reading that silence as "noon holds nothing" is what would create a
        // second catalogue item for a product noon already lists.
        $this->fake([self::CONTENT_GET => Http::response(['sku_parent' => 'NP-1'])]);

        $submission = app(NoonConnector::class)->listingFor($this->account, 'SKU-UNSEEN');

        $this->assertTrue($submission->isUnknown());
        $this->assertFalse($submission->isAbsent(), 'an answer we cannot ask for is not an absence');
        $this->assertSame('no_sku_parent_known_for_this_partner_sku', $submission->channelStatus);
        $this->assertSame([], $this->sentTo(self::CONTENT_GET), 'and nothing is sent on a key we do not have');
    }

    public function test_the_sku_parent_remembered_from_an_upsert_is_what_is_asked_about(): void
    {
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response($this->contentAnswer('NP-55'))]);

        app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertSame(['sku_parent' => 'NP-55'], $this->bodyOf(self::CONTENT_GET));
    }

    public function test_a_confirmed_listing_key_is_preferred_over_a_drafts_unconfirmed_submission_id(): void
    {
        $draft = $this->rememberParent('SKU-1', 'NP-SUBMITTED');
        $listing = SalesChannelProductMapping::create([
            'account_id' => $this->account->id, 'product_id' => self::PRODUCT, 'variant_key' => '',
            'listing_key' => 'NP-CONFIRMED', 'external_sku' => 'NP-CONFIRMED',
            'status' => SalesChannelProductMapping::MAPPED, 'inventory_publish' => false, 'price_publish' => false,
        ]);
        $draft->forceFill(['product_mapping_id' => $listing->id])->save();
        $this->fake([self::CONTENT_GET => Http::response($this->contentAnswer('NP-CONFIRMED'))]);

        app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertSame(['sku_parent' => 'NP-CONFIRMED'], $this->bodyOf(self::CONTENT_GET), 'the key noon has confirmed beats the one an upsert merely answered');
    }

    public function test_a_parent_sku_is_never_shared_between_accounts(): void
    {
        $this->rememberParent('SKU-1', 'NP-55');
        $other = $this->noonAccount('partner-2');

        $this->assertNull(app(NoonParentSku::class)->forPartnerSku($other, 'SKU-1'));
        $this->assertSame('NP-55', app(NoonParentSku::class)->forPartnerSku($this->account, 'SKU-1'));
    }

    public function test_a_rehearsing_account_can_still_read_what_noon_holds(): void
    {
        // A rehearsal withholds WRITES. Asking noon what it holds changes nothing there, and it is the one question worth answering before any write is switched on --
        // not least because the answer is what decides whether a create would duplicate an existing product.
        $this->account->forceFill(['dry_run' => true])->save();
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response($this->contentAnswer('NP-55'))]);

        $submission = app(NoonConnector::class)->listingFor($this->account->fresh(), 'SKU-1');

        $this->assertTrue($submission->isLive());
        $this->assertCount(1, $this->sentTo(self::CONTENT_GET));
    }

    public function test_a_failed_read_is_unknown_rather_than_an_empty_catalogue(): void
    {
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response([], 503)]);

        $submission = app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertTrue($submission->isUnknown());
        $this->assertFalse($submission->isAbsent());
    }

    // ---- noon's three judgements, read in the order that matters ------------------------------------------------

    public function test_an_approved_and_active_product_in_every_language_is_live(): void
    {
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response($this->contentAnswer('NP-55'))]);

        $submission = app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertTrue($submission->isLive());
        $this->assertSame('NP-55', $submission->listingKey);
        $this->assertContains('EN_qc_approved', $submission->statuses);
        $this->assertContains('AR_qc_approved', $submission->statuses);
    }

    public function test_a_product_rejected_in_one_language_is_rejected_however_good_the_other_is(): void
    {
        // A product is only as live as its worst language. An English listing whose Arabic content noon refused is not one this store may leave alone.
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response($this->contentAnswer('NP-55', arabic: [
            'language' => 'LANGUAGE_AR',
            'content' => ['completeness' => '100', 'missing_attributes' => [], 'invalid_attributes' => []],
            'qc' => ['status' => 'QC_STATUS_REJECTED', 'rejection_reasons' => ['the Arabic title is machine translated'], 'comment' => 'please rewrite it'],
            'overall_status' => 'OVERALL_STATUS_INACTIVE',
            'errors' => [],
        ]))]);

        $submission = app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertTrue($submission->isRejected());
        $this->assertContains('AR: the Arabic title is machine translated', $submission->problems);
        $this->assertContains('AR: please rewrite it', $submission->problems);
    }

    public function test_a_product_error_is_a_refusal_with_noons_own_code_and_words(): void
    {
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response($this->contentAnswer('NP-55', arabic: [
            'language' => 'LANGUAGE_AR',
            'content' => ['completeness' => '100', 'missing_attributes' => [], 'invalid_attributes' => []],
            'qc' => ['status' => 'QC_STATUS_APPROVED', 'rejection_reasons' => [], 'comment' => ''],
            'overall_status' => 'OVERALL_STATUS_ACTIVE',
            'errors' => [['code' => 'IMAGE_TOO_SMALL', 'message' => 'the main image is under 1000px']],
        ]))]);

        $submission = app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertTrue($submission->isRejected());
        $this->assertContains('AR: IMAGE_TOO_SMALL: the main image is under 1000px', $submission->problems);
    }

    public function test_an_incomplete_product_is_working_and_names_what_it_is_missing(): void
    {
        // noon's guide says an item cannot enter quality review below 100% completeness. So a missing attribute is not noon being slow: it is us not having sent something,
        // and the attribute names are the whole point of reporting it.
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response($this->contentAnswer('NP-55', english: [
            'language' => 'LANGUAGE_EN',
            'content' => ['completeness' => '80', 'missing_attributes' => ['dosage_form'], 'invalid_attributes' => ['net_weight']],
            'qc' => ['status' => 'QC_STATUS_PENDING', 'rejection_reasons' => [], 'comment' => ''],
            'overall_status' => 'OVERALL_STATUS_INACTIVE',
            'errors' => [],
        ]))]);

        $submission = app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertSame(ListingSubmission::WORKING, $submission->status);
        $this->assertFalse($submission->isLive());
        $this->assertContains('EN: missing dosage_form', $submission->problems);
        $this->assertContains('EN: invalid net_weight', $submission->problems);
    }

    public function test_a_product_noon_holds_but_says_nothing_about_is_working_not_absent(): void
    {
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response(['sku_parent' => 'NP-55', 'attributes' => [], 'images' => [], 'statuses' => []])]);

        $submission = app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertSame(ListingSubmission::WORKING, $submission->status);
        $this->assertFalse($submission->isAbsent(), 'noon holds the sku_parent, so this is the opposite of an absence');
        $this->assertSame('no_statuses_from_noon', $submission->channelStatus);
    }

    public function test_a_pending_review_is_working_even_with_nothing_missing(): void
    {
        $this->rememberParent('SKU-1', 'NP-55');
        $this->fake([self::CONTENT_GET => Http::response($this->contentAnswer('NP-55', english: [
            'language' => 'LANGUAGE_EN',
            'content' => ['completeness' => '100', 'missing_attributes' => [], 'invalid_attributes' => []],
            'qc' => ['status' => 'QC_STATUS_PENDING', 'rejection_reasons' => [], 'comment' => ''],
            'overall_status' => 'OVERALL_STATUS_INACTIVE',
            'errors' => [],
        ]))]);

        $submission = app(NoonConnector::class)->listingFor($this->account, 'SKU-1');

        $this->assertSame(ListingSubmission::WORKING, $submission->status);
    }

    // ---- barcodes ------------------------------------------------------------------------------------------------

    public function test_a_barcode_is_tied_to_our_partner_sku_in_noons_own_body(): void
    {
        $this->fake([self::BARCODE_MAP => Http::response(['items' => [['barcode' => '6281000000013', 'partner_sku' => 'SKU-1', 'status' => ['status_code' => 'OK', 'status_id' => 0]]]])]);

        $result = app(NoonConnector::class)->mapBarcodes($this->account, ['SKU-1' => '6281000000013']);

        $this->assertSame(['SKU-1'], $result['mapped']);
        $this->assertSame([], $result['failed']);
        $this->assertSame([['barcode' => '6281000000013', 'partner_sku' => 'SKU-1']], $this->bodyOf(self::BARCODE_MAP)['items']);
    }

    public function test_force_map_is_never_sent_because_its_meaning_is_not_documented(): void
    {
        // Its own page documents the field and says nothing about what it does. The identically named field on the Batch SKU map page is a different endpoint, and
        // borrowing its meaning would be a guess with a seller's barcodes at stake.
        $this->fake([self::BARCODE_MAP => Http::response(['items' => [['barcode' => '6281000000013', 'partner_sku' => 'SKU-1', 'status' => ['status_code' => 'OK', 'status_id' => 0]]]])]);

        app(NoonConnector::class)->mapBarcodes($this->account, ['SKU-1' => '6281000000013']);

        $this->assertArrayNotHasKey('force_map', $this->bodyOf(self::BARCODE_MAP)['items'][0]);
    }

    public function test_a_barcode_noon_refused_per_item_keeps_its_own_reason(): void
    {
        $this->fake([self::BARCODE_MAP => Http::response(['items' => [
            ['barcode' => '6281000000013', 'partner_sku' => 'SKU-1', 'status' => ['status_code' => 'OK', 'status_id' => 0]],
            ['barcode' => '6281000000020', 'partner_sku' => 'SKU-2', 'status' => ['status_code' => 'SKU_NOT_FOUND', 'status_id' => 4, 'message' => 'noon holds no such partner_sku']],
        ]])]);

        $result = app(NoonConnector::class)->mapBarcodes($this->account, ['SKU-1' => '6281000000013', 'SKU-2' => '6281000000020']);

        $this->assertSame(['SKU-1'], $result['mapped']);
        $this->assertStringContainsString('no such partner_sku', $result['failed']['SKU-2']);
    }

    public function test_a_barcode_noon_never_answered_about_is_not_treated_as_mapped(): void
    {
        $this->fake([self::BARCODE_MAP => Http::response(['items' => [['barcode' => '6281000000013', 'partner_sku' => 'SKU-1', 'status' => ['status_code' => 'OK', 'status_id' => 0]]]])]);

        $result = app(NoonConnector::class)->mapBarcodes($this->account, ['SKU-1' => '6281000000013', 'SKU-2' => '6281000000020']);

        $this->assertSame(['SKU-1'], $result['mapped']);
        $this->assertStringContainsString('did not answer', $result['failed']['SKU-2']);
    }

    public function test_a_product_with_no_barcode_is_nothing_to_map_rather_than_an_invented_one(): void
    {
        $this->fake([self::BARCODE_MAP => Http::response(['items' => []])]);

        $result = app(NoonConnector::class)->mapBarcodes($this->account, ['SKU-1' => '']);

        $this->assertSame([], $result['mapped']);
        $this->assertFalse($result['sent'], 'there was nothing to send');
        $this->assertStringContainsString('carries no barcode', $result['failed']['SKU-1']);
        $this->assertSame([], $this->sentTo(self::BARCODE_MAP));
    }

    public function test_a_refused_barcode_request_mapped_nothing_at_all(): void
    {
        $this->fake([self::BARCODE_MAP => Http::response([], 500)]);

        $result = app(NoonConnector::class)->mapBarcodes($this->account, ['SKU-1' => '6281000000013']);

        $this->assertSame([], $result['mapped']);
        $this->assertStringContainsString('no barcode was mapped', $result['failed']['SKU-1']);
    }

    // ---- the switches hold here too -----------------------------------------------------------------------------

    public function test_without_the_product_sync_capability_no_catalogue_write_leaves(): void
    {
        $this->account->forceFill(['capabilities' => ['connection_test']])->save();
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1'])]);

        // The contract is to THROW before anything is sent, which the publisher turns into a refusal on the draft. The assertion that matters is the second one.
        try {
            app(NoonConnector::class)->createListing($this->account->fresh(), $this->draft(), $this->attributes());
            $this->fail('a capability that is off must stop the write');
        } catch (ChannelUnavailableException $exception) {
            $this->assertStringContainsString('product_sync', $exception->getMessage());
        }
        $this->assertSame([], $this->sentTo(self::UPSERT));
    }

    public function test_without_the_product_writes_approval_no_catalogue_write_leaves(): void
    {
        // The capability being on is not enough: putting a product into a channel's catalogue needs somebody to have approved that kind of write for this account.
        $this->account->forceFill(['capability_approvals' => ['product_sync' => ['by' => 1]]])->save();
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1'])]);

        try {
            app(NoonConnector::class)->createListing($this->account->fresh(), $this->draft(), $this->attributes());
            $this->fail('an unapproved write must be stopped');
        } catch (ChannelUnavailableException $exception) {
            $this->assertStringContainsString('product_writes', $exception->getMessage());
        }
        $this->assertSame([], $this->sentTo(self::UPSERT));
    }

    public function test_with_the_host_switch_off_no_catalogue_write_leaves(): void
    {
        config(['sales_channels.external_writes_enabled' => false]);
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1'])]);

        try {
            app(NoonConnector::class)->createListing($this->account, $this->draft(), $this->attributes());
            $this->fail('the host switch must stop the write');
        } catch (ChannelUnavailableException $exception) {
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
        $this->assertSame([], $this->sentTo(self::UPSERT));
    }

    public function test_without_the_product_writes_approval_no_barcode_leaves_either(): void
    {
        $this->account->forceFill(['capability_approvals' => ['product_sync' => ['by' => 1]]])->save();
        $this->fake([self::BARCODE_MAP => Http::response(['items' => []])]);

        try {
            app(NoonConnector::class)->mapBarcodes($this->account->fresh(), ['SKU-1' => '6281000000013']);
            $this->fail('an unapproved write must be stopped');
        } catch (ChannelUnavailableException $exception) {
            $this->assertStringContainsString('product_writes', $exception->getMessage());
        }
        $this->assertSame([], $this->sentTo(self::BARCODE_MAP));
    }

    public function test_a_rehearsing_account_sends_no_catalogue_write_and_claims_nothing(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        $this->fake([self::UPSERT => Http::response(['sku_parent' => 'NP-1'])]);

        $submission = app(NoonConnector::class)->createListing($this->account->fresh(), $this->draft(), $this->attributes());

        $this->assertTrue($submission->isUnknown());
        $this->assertSame('dry_run', $submission->channelStatus);
        $this->assertSame([], $this->sentTo(self::UPSERT));
    }

    public function test_the_catalogue_calls_carry_the_service_segment_noon_documents(): void
    {
        foreach ([
            'noon.product_upsert' => '/content/v1/product/upsert',
            'noon.product_content_get' => '/content/v1/product/content/get',
            'noon.barcode_map' => '/catplat/v1/barcode/map',
        ] as $operation => $expected) {
            $this->assertSame($expected, \App\Services\SalesChannels\Noon\NoonOperations::paths()[$operation]);
        }
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function noonAccount(string $partner = 'partner-1'): SalesChannelAccount
    {
        $account = app(ChannelAccountService::class)->create(
            ['channel' => 'noon', 'name' => 'noon KSA ' . $partner, 'external_account_id' => $partner, 'currency' => 'SAR', 'settings' => ['warehouse_code' => 'WH-RUH-01', 'country_code' => 'sa']],
            ['key_id' => 'key-abc', 'private_key' => $this->aRealPrivateKey(), 'project_code' => 'proj_x', 'channel_identifier' => 'noon'],
            adminId: 1,
        );
        $account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'product_sync'],
            'capability_approvals' => ['product_sync' => ['by' => 1], ChannelOperations::SCOPE_PRODUCT_WRITES => ['by' => 1]],
        ])->save();

        return $account->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function draft(array $overrides = []): ChannelListingDraft
    {
        $payload = array_merge([
            'sku' => 'SKU-1',
            'size' => '500mg',
            'brand' => 'Acme Pharma',
            'images' => ['https://cdn.example.test/a.jpg', 'https://cdn.example.test/b.jpg'],
            'attributes' => $this->attributes(),
        ], $overrides);

        return ChannelListingDraft::create([
            'account_id' => $this->account->id,
            'sales_channel' => 'noon',
            // A draft is unique per account, product and variant, so each one made here gets its own product.
            'product_id' => self::PRODUCT + $this->drafts++,
            'variant_key' => '',
            'state' => ChannelListingDraft::VALIDATED,
            'product_type' => self::CATEGORY,
            'payload' => $payload,
        ]);
    }

    /** The `sku_parent` an upsert answered, remembered the way the publisher records it. */
    private function rememberParent(string $partnerSku, string $parent): ChannelListingDraft
    {
        $draft = $this->draft(['sku' => $partnerSku]);
        $draft->forceFill(['state' => ChannelListingDraft::SUBMITTED, 'submission_id' => $parent, 'submitted_at' => now()])->save();

        return $draft->fresh();
    }

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return ['item_name' => 'Vitamin C 500mg', 'dosage_form' => 'tablet', 'pack_size' => 60];
    }

    /**
     * noon's content answer: one `statuses[]` entry per language, as its schema prints it.
     *
     * @param array<string, mixed>|null $english
     * @param array<string, mixed>|null $arabic
     * @return array<string, mixed>
     */
    private function contentAnswer(string $parent, ?array $english = null, ?array $arabic = null): array
    {
        $approved = static fn (string $language): array => [
            'language' => 'LANGUAGE_' . $language,
            'content' => ['completeness' => '100', 'missing_attributes' => [], 'invalid_attributes' => []],
            'qc' => ['status' => 'QC_STATUS_APPROVED', 'rejection_reasons' => [], 'comment' => ''],
            'overall_status' => 'OVERALL_STATUS_ACTIVE',
            'errors' => [],
        ];

        return [
            'sku_parent' => $parent,
            'attributes' => $this->attributes(),
            'images' => [['url' => 'https://cdn.example.test/a.jpg', 'sort' => 1, 'visibility' => 'VISIBILITY_STATUS_VISIBLE', 'review_status' => 'REVIEW_STATUS_VALID', 'issues' => []]],
            'statuses' => [$english ?? $approved('EN'), $arabic ?? $approved('AR')],
        ];
    }

    private function aRealPrivateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    /** @param array<string, mixed> $responses */
    private function fake(array $responses): void
    {
        Http::fake([self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc123; Path=/'])] + $responses);
    }

    /** @return array<string, mixed> */
    private function bodyOf(string $url): array
    {
        return (array) $this->sentTo($url)[0]->data();
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
