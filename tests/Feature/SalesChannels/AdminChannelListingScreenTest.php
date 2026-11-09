<?php

namespace Tests\Feature\SalesChannels;

use App\Models\Admin;
use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Listings\RegulatedProductGate;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The screen a person puts a product on a channel from: the draft, the check, the clearance, the send and the follow-up.
 *
 * What these tests are really about is that the screen claims nothing the pipeline did not do. Stray requests are refused throughout, so a test that passes while a channel
 * was called cannot exist: opening the screen must call nobody, saving a draft must send nothing, and a send that the host's switch refuses must say so rather than report
 * success. The pipeline itself is proved in ListingPublishingTest; this is the admin's side of it.
 */
class AdminChannelListingScreenTest extends AdminSalesChannelsTestCase
{
    /** An admin whose role carries the `sales_channels` module (built by the test schema), and one whose role does not. */
    private const CHANNELS_ADMIN = 4;
    private const CATALOG_ADMIN = 2;

    private const TYPE = 'HEALTH_PERSONAL_CARE';

    private SalesChannelAccount $account;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = $this->amazonAccount();
        $this->productId = $this->product(name: 'Vitamin C 500mg', code: 'VITC-500', barcode: '6281000000017');
        $this->makeTheProductFitToList($this->productId);
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
        ]);
    }

    private function screen(): string
    {
        return route('admin.sales-channels.listings.show', $this->productId);
    }

    /** @param array<string, string> $fields */
    private function saveTheDraft(array $fields, string $productType = self::TYPE): ChannelListingDraft
    {
        $rows = [];
        foreach (array_values($fields) as $index => $value) {
            $rows[$index] = ['name' => array_keys($fields)[$index], 'value' => $value];
        }
        $this->post(route('admin.sales-channels.listings.draft', ['product' => $this->productId, 'account' => $this->account->id]), [
            'product_type' => $productType,
            'variant_key' => '',
            'fields' => $rows,
        ])->assertRedirect($this->screen());

        return ChannelListingDraft::where('product_id', $this->productId)->firstOrFail();
    }

    private function approveProductWrites(): void
    {
        $this->account->forceFill(['capability_approvals' => ((array) $this->account->capability_approvals) + [ChannelOperations::SCOPE_PRODUCT_WRITES => ['by' => 1]]])->save();
        $this->account = $this->account->fresh();
    }

    // ---- opening it ---------------------------------------------------------------------------------------

    public function test_the_screen_shows_the_account_and_calls_no_channel(): void
    {
        $response = $this->get($this->screen());

        $response->assertOk();
        $response->assertSee($this->account->name);
        $response->assertSee(translate('put_this_product_on_a_channel'));
        $response->assertSee(translate('product_channel_state_not_offered'));
        $this->assertNoSecretIn($response->getContent(), 'the listing screen');
        Http::assertNothingSent();
    }

    public function test_the_screen_says_the_hosts_listing_switch_is_off_rather_than_offering_to_send(): void
    {
        config(['sales_channels.listing.publish_enabled' => false]);

        $this->get($this->screen())->assertOk()->assertSee(translate('listing_publishing_is_off_on_this_host'));
    }

    public function test_the_screen_lists_what_the_store_has_not_finished_about_the_product(): void
    {
        DB::table('products')->where('id', $this->productId)->update(['details' => '', 'thumbnail' => null]);

        $response = $this->get($this->screen());

        $response->assertOk();
        $response->assertSee(translate('product_has_no_description'));
        $response->assertSee(translate('product_has_no_thumbnail'));
        Http::assertNothingSent();
    }

    /**
     * The module's readiness check does not require this table (the product card degrades instead of 503ing the catalogue), so the screen has to answer for itself when the
     * one migration it needs has not run — and name it, rather than showing the administrator a missing-table error.
     */
    public function test_without_its_own_migration_the_screen_names_the_migration_instead_of_failing(): void
    {
        Schema::drop('channel_listing_drafts');

        $this->get($this->screen())->assertOk()->assertSee('2026_11_12_100000_create_channel_listing_drafts_table');
        $this->post(route('admin.sales-channels.listings.draft', ['product' => $this->productId, 'account' => $this->account->id]), ['product_type' => self::TYPE])
            ->assertOk()
            ->assertSee('2026_11_12_100000_create_channel_listing_drafts_table');
    }

    public function test_the_product_form_card_links_to_the_screen(): void
    {
        $rendered = view('admin-views.partials._sales-channels-product', ['product' => DB::table('products')->where('id', $this->productId)->first()])->render();

        $this->assertStringContainsString($this->screen(), $rendered);
        $this->assertStringContainsString(translate('put_this_product_on_a_channel'), $rendered);
    }

    // ---- the draft ----------------------------------------------------------------------------------------

    public function test_saving_the_draft_stores_the_channels_own_fields_and_sends_nothing(): void
    {
        $draft = $this->saveTheDraft(['brand' => 'Acme', 'item_name' => 'Vitamin C 500mg']);

        $this->assertSame(self::TYPE, $draft->product_type);
        $this->assertSame('Acme', $draft->payload['brand']);
        $this->assertSame('Vitamin C 500mg', $draft->payload['item_name']);
        // The SKU is the draft service's decision, taken once from the product's own code, and the form never offers it.
        $this->assertSame('VITC-500', $draft->payload['sku']);
        $this->assertSame(ChannelListingDraft::DRAFT, $draft->state);
        $this->assertNull($draft->submitted_payload);
        $this->assertNull($draft->published_payload);
        $this->assertToastContains(translate('listing_draft_saved_nothing_sent'));
        Http::assertNothingSent();
    }

    public function test_saving_the_same_field_twice_edits_the_one_draft_and_keeps_the_newer_value(): void
    {
        $first = $this->saveTheDraft(['brand' => 'Acme']);
        $second = $this->saveTheDraft(['brand' => 'Beta Pharma']);

        $this->assertSame($first->id, $second->id, 'a second save made a second draft');
        $this->assertSame('Beta Pharma', $second->payload['brand']);
        $this->assertSame(1, ChannelListingDraft::where('product_id', $this->productId)->count());
    }

    public function test_a_blank_row_is_not_a_field_and_something_that_is_not_a_field_name_is_refused(): void
    {
        $this->post(route('admin.sales-channels.listings.draft', ['product' => $this->productId, 'account' => $this->account->id]), [
            'product_type' => self::TYPE,
            'fields' => [['name' => 'brand', 'value' => 'Acme'], ['name' => '', 'value' => 'nothing']],
        ])->assertRedirect($this->screen());

        $draft = ChannelListingDraft::where('product_id', $this->productId)->firstOrFail();
        // Read back by NAME: MySQL stores a JSON object in its own key order, and assertSame on arrays compares order too.
        $this->assertEqualsCanonicalizing(['brand' => 'Acme', 'sku' => 'VITC-500'], $draft->payload);

        $this->post(route('admin.sales-channels.listings.draft', ['product' => $this->productId, 'account' => $this->account->id]), [
            'product_type' => self::TYPE,
            'fields' => [['name' => 'brand"><script>', 'value' => 'Acme']],
        ])->assertSessionHasErrors(['fields.0.name'], null, 'draft_' . $this->account->id);

        $this->assertEqualsCanonicalizing(['brand' => 'Acme', 'sku' => 'VITC-500'], $draft->fresh()->payload, 'a refused request changed the draft');
    }

    // ---- checking it --------------------------------------------------------------------------------------

    public function test_checking_it_reports_the_stores_own_product_first_and_asks_no_channel(): void
    {
        $draft = $this->saveTheDraft(['brand' => 'Acme']);
        DB::table('products')->where('id', $this->productId)->update(['details' => '']);
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.check', $draft))->assertRedirect($this->screen());

        $this->assertSame(ChannelListingDraft::DRAFT, $draft->fresh()->state);
        $this->assertSame('product_data_not_ready', $draft->fresh()->refusal_code);
        $this->assertToastContains(translate('product_data_not_ready'));
        Http::assertNothingSent();
    }

    public function test_checking_it_says_what_the_channel_requires_is_unknown_rather_than_calling_it_ready(): void
    {
        // The account's read switches are on but nothing answers: a requirement that could not be read is never treated as "nothing is required".
        $draft = $this->saveTheDraft(['brand' => 'Acme']);
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.check', $draft))->assertRedirect($this->screen());

        $draft = $draft->fresh();
        $this->assertSame(ChannelListingDraft::DRAFT, $draft->state);
        $this->assertSame('listing_requirements_unknown', $draft->refusal_code);
        $this->assertToastContains(translate('listing_requirements_unknown'));
    }

    public function test_the_owners_own_declared_requirements_make_a_complete_draft_ready_to_send(): void
    {
        $this->account->forceFill(['settings' => ((array) $this->account->settings) + [
            'listing_requirements' => [self::TYPE => ['required' => ['brand', 'item_name']]],
        ]])->save();
        $draft = $this->saveTheDraft(['brand' => 'Acme', 'item_name' => 'Vitamin C 500mg']);
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.check', $draft))->assertRedirect($this->screen());

        $this->assertSame(ChannelListingDraft::VALIDATED, $draft->fresh()->state);
        $this->assertToastContains(translate('listing_is_ready_to_send'));
        $this->get($this->screen())->assertOk()->assertSee(translate('requirements_declared_by_the_owner'));
    }

    // ---- sending it ---------------------------------------------------------------------------------------

    public function test_the_hosts_switch_being_off_refuses_the_send_and_nothing_leaves_this_host(): void
    {
        config(['sales_channels.listing.publish_enabled' => false]);
        $draft = $this->readyToSend();
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.publish', $draft))->assertRedirect($this->screen());

        $draft = $draft->fresh();
        $this->assertSame(ChannelListingDraft::VALIDATED, $draft->state, 'the draft was moved on although nothing was sent');
        $this->assertSame('listing_publishing_off', $draft->refusal_code);
        $this->assertNull($draft->submitted_at);
        $this->assertToastContains(translate('nothing_was_sent_and_here_is_why'));
        $this->assertToastContains(translate('listing_publishing_off'));
        Http::assertNothingSent();
    }

    public function test_without_the_recorded_approval_of_product_writes_nothing_is_sent(): void
    {
        config(['sales_channels.listing.publish_enabled' => true]);
        $draft = $this->readyToSend();
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.publish', $draft))->assertRedirect($this->screen());

        $this->assertSame('product_writes_approval_missing', $draft->fresh()->refusal_code);
        $this->assertToastContains(translate('product_writes_approval_missing'));
        Http::assertNothingSent();
    }

    public function test_a_live_account_needs_the_confirmation_box_ticked_before_anything_is_sent(): void
    {
        config(['sales_channels.listing.publish_enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->approveProductWrites();
        $draft = $this->readyToSend();

        $this->post(route('admin.sales-channels.listings.publish', $draft))->assertSessionHasErrors(['confirm_live'], null, 'publish_' . $draft->id);

        $this->assertNull($draft->fresh()->submitted_at);
        Http::assertNothingSent();
    }

    public function test_following_up_a_draft_that_is_not_with_the_channel_says_so_and_calls_nobody(): void
    {
        $draft = $this->readyToSend();
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.follow', $draft))->assertRedirect($this->screen());

        $this->assertToastContains(translate('listing_not_waiting_for_the_channel'));
        Http::assertNothingSent();
    }

    // ---- the pharmacy gate --------------------------------------------------------------------------------

    public function test_an_admin_who_does_not_hold_the_module_cannot_record_a_clearance(): void
    {
        config(['sales_channels.listing.regulated.mode' => RegulatedProductGate::MODE_STRICT, 'sales_channels.listing.regulated.name_patterns' => ['vitamin']]);
        $draft = $this->saveTheDraft(['brand' => 'Acme']);
        $this->actingAs(Admin::findOrFail(self::CATALOG_ADMIN), 'admin');
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.clearance', $draft), ['reference' => 'SFDA-2026-11'])->assertRedirect($this->screen());

        $this->assertNull($draft->fresh()->regulatory_clearance);
        $this->assertToastContains(translate(RegulatedProductGate::CLEARANCE_NOT_PERMITTED));
    }

    public function test_an_admin_who_holds_the_module_records_the_clearance_with_their_own_id(): void
    {
        config(['sales_channels.listing.regulated.mode' => RegulatedProductGate::MODE_STRICT, 'sales_channels.listing.regulated.name_patterns' => ['vitamin']]);
        $draft = $this->saveTheDraft(['brand' => 'Acme']);
        $this->actingAs(Admin::findOrFail(self::CHANNELS_ADMIN), 'admin');
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.clearance', $draft), ['reference' => 'SFDA-2026-11'])->assertRedirect($this->screen());

        $clearance = (array) $draft->fresh()->regulatory_clearance;
        $this->assertSame('SFDA-2026-11', $clearance['reference']);
        $this->assertSame(self::CHANNELS_ADMIN, (int) $clearance['by'], 'the clearance was recorded against somebody else');
        $this->assertToastContains(translate('regulatory_clearance_recorded'));
    }

    public function test_a_clearance_with_no_reference_is_refused_by_the_form(): void
    {
        $draft = $this->saveTheDraft(['brand' => 'Acme']);

        $this->post(route('admin.sales-channels.listings.clearance', $draft), ['reference' => ' '])
            ->assertSessionHasErrors(['reference'], null, 'clearance_' . $draft->id);

        $this->assertNull($draft->fresh()->regulatory_clearance);
    }

    // ---- withdrawing it -----------------------------------------------------------------------------------

    public function test_a_draft_can_be_withdrawn_and_the_channel_is_told_nothing(): void
    {
        $draft = $this->readyToSend();
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.withdraw', $draft))->assertRedirect($this->screen());

        $this->assertSame(ChannelListingDraft::WITHDRAWN, $draft->fresh()->state);
        $this->assertToastContains(translate('listing_draft_withdrawn'));
        Http::assertNothingSent();
    }

    public function test_a_draft_that_is_with_the_channel_is_not_withdrawn_behind_its_back(): void
    {
        $draft = $this->readyToSend();
        $draft->forceFill(['state' => ChannelListingDraft::SUBMITTED, 'submission_id' => 'sub-1', 'submitted_at' => now()])->save();
        $this->forgetToasts();

        $this->post(route('admin.sales-channels.listings.withdraw', $draft))->assertRedirect($this->screen());

        $this->assertSame(ChannelListingDraft::SUBMITTED, $draft->fresh()->state);
        $this->assertToastContains(translate('listing_is_with_the_channel_follow_it_up_first'));
    }

    /** A draft the channel's own requirements accept, so that what is being tested is the gate and not the fields. */
    private function readyToSend(): ChannelListingDraft
    {
        $this->account->forceFill(['settings' => ((array) $this->account->settings) + [
            'listing_requirements' => [self::TYPE => ['required' => ['brand']]],
        ]])->save();
        $this->account = $this->account->fresh();
        $draft = $this->saveTheDraft(['brand' => 'Acme']);
        $this->post(route('admin.sales-channels.listings.check', $draft));

        return $draft->fresh();
    }
}
