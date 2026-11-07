<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\Support\HubSummary;
use App\Http\Controllers\Admin\SalesChannels\Support\WriteState;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Models\SalesChannelSyncState;

/** The hub: what governs every channel call, one card per account, and the things that need a person. */
class AdminSalesChannelsHubTest extends AdminSalesChannelsTestCase
{
    public function test_with_no_account_the_hub_offers_to_connect_the_first_channel(): void
    {
        $html = $this->get(route('admin.sales-channels.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('no_channel_connected_yet')), $html);
        $this->assertStringContainsString(e(translate('connect_your_first_channel')), $html);
        $this->assertStringContainsString(route('admin.sales-channels.accounts.create'), $html);
    }

    public function test_it_states_the_switches_of_this_server_and_that_provider_shipments_are_not_available(): void
    {
        config(['sales_channels.enabled' => false, 'sales_channels.external_writes_enabled' => false]);
        $html = $this->get(route('admin.sales-channels.index'))->assertOk()->getContent();

        $this->assertStringContainsString('SALES_CHANNELS_ENABLED', $html);
        $this->assertStringContainsString('SALES_CHANNELS_EXTERNAL_WRITES_ENABLED', $html);
        $this->assertStringContainsString(e(translate('channel_integrations_off_help')), $html);
        $this->assertStringContainsString(e(translate('external_writes_off_help')), $html);
        $this->assertStringContainsString(e(translate('managed_dispatch_not_available_help')), $html, 'the module does not create provider shipments for channel orders, and says so');

        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $html = $this->get(route('admin.sales-channels.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('channel_integrations_on_help')), $html);
        $this->assertStringContainsString(e(translate('external_writes_on_help')), $html);
    }

    public function test_each_account_card_states_what_the_account_is_and_what_it_may_do(): void
    {
        $account = $this->trendyolAccount(['connection_test', 'read_only_sync'], writes: false);
        $account->forceFill(['seller_id' => 7, 'connection_status' => 'ok', 'connection_checked_at' => now()->subHour(), 'missing_permissions' => ['finance:read']])->save();

        $html = $this->get(route('admin.sales-channels.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Trendyol KSA', $html);
        $this->assertStringContainsString(e(translate('environment_production')), $html);
        $this->assertStringContainsString('#7', $html, 'the seller id of the account is shown');
        $this->assertStringContainsString(e(translate('enabled')), $html);
        $this->assertStringContainsString(e(translate('integration_capability_connection_test')), $html);
        $this->assertStringContainsString(e(translate('integration_capability_read_only_sync')), $html);
        $this->assertStringNotContainsString(e(translate('integration_capability_fulfillment_dispatch')), $html, 'only the modes the account holds are listed');
        $this->assertStringContainsString(e(translate('write_state_rehearsal')), $html, 'dry run is on, so a write is only rehearsed');
        $this->assertStringContainsString(e(translate('connection_status_ok')), $html);
        $this->assertStringContainsString('finance:read', $html, 'the permissions the connection test found missing');
        $this->assertStringContainsString(e(translate('sync_resource_orders')), $html);
        $this->assertStringContainsString(e(translate('never_run')), $html);
        $this->assertStringContainsString(route('admin.sales-channels.accounts.show', $account), $html);
        $this->assertNoSecretIn($html, 'the hub');
    }

    public function test_a_new_account_shows_no_mode_and_is_disabled(): void
    {
        $this->post(route('admin.sales-channels.accounts.store'), $this->trendyolForm())->assertRedirect();

        $html = $this->get(route('admin.sales-channels.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('disabled')), $html);
        $this->assertStringContainsString(e(translate('disabled_no_mode')), $html);
        $this->assertStringContainsString(e(translate('write_state_account_disabled')), $html);
    }

    public function test_the_numbers_on_the_cards_are_counted_per_account(): void
    {
        $trendyol = $this->trendyolAccount();
        $amazon = $this->amazonAccount();
        foreach (['unmapped', 'unmapped', 'suggested', 'conflict', 'mapped', 'ignored'] as $status) {
            $this->listing($trendyol, ['status' => $status]);
        }
        $this->listing($amazon, ['status' => 'unmapped']);
        $this->channelOrder($trendyol, ['review_reason' => 'unmapped_lines', 'execution_policy' => 'manual_review']);
        $this->channelOrder($trendyol, ['execution_policy' => 'manual_review', 'status' => 'processing']);
        $this->channelOrder($trendyol, ['execution_policy' => 'manual_review', 'status' => 'cancelled'], []);
        $this->channelOrder($trendyol, ['fulfillment_mode' => 'fba']);
        $this->channelOrder($trendyol, ['fulfillment_mode' => null]);
        $this->channelOrder($amazon, ['fulfillment_mode' => 'fba', 'review_reason' => 'split_replaced']);

        $cards = collect(app(HubSummary::class)->cards(SalesChannelAccount::orderBy('id')->get()))->keyBy(fn (array $card): int => $card['account']->id);

        $this->assertSame(4, $cards[$trendyol->id]['unlinkedListings'], 'unmapped, suggested and conflict listings wait for a person; a mapped or ignored one does not');
        $this->assertSame(1, $cards[$amazon->id]['unlinkedListings']);
        $this->assertSame(1, $cards[$trendyol->id]['ordersNeedingReview']);
        $this->assertSame(1, $cards[$amazon->id]['ordersNeedingReview']);
        $this->assertSame(2, $cards[$trendyol->id]['ordersHeld'], 'a closed order that was held is not waiting for anyone');
        $this->assertSame(['channel' => 1, 'merchant' => 3, 'unknown' => 1], $cards[$trendyol->id]['modes'], 'a mode the channel did not state is unknown, never merchant-fulfilled');
        $this->assertSame(['channel' => 1, 'merchant' => 0, 'unknown' => 0], $cards[$amazon->id]['modes']);
    }

    public function test_what_needs_a_person_is_listed_with_the_link_that_resolves_it(): void
    {
        $healthy = $this->trendyolAccount();
        $lost = $this->amazonAccount();
        $lost->forceFill(['connection_status' => 'auth_expired', 'connection_message' => 'The refresh token was refused.'])->save();
        SalesChannelSyncState::create(['account_id' => $healthy->id, 'resource' => 'orders', 'backoff_until' => now()->addMinutes(20), 'consecutive_failures' => 3, 'last_error' => 'HTTP 500 <b>boom</b>']);
        $order = $this->channelOrder($healthy, ['review_reason' => 'managed_dispatch_not_available', 'execution_policy' => 'manual_review']);
        $this->listing($healthy, ['status' => 'mapped', 'product_id' => 1, 'snapshot' => ['publish' => ['stock' => ['state' => 'failed', 'error' => 'Barcode is not found']]]]);
        SalesChannelEvent::create(['account_id' => $healthy->id, 'channel' => 'trendyol', 'source' => 'poll', 'event_type' => 'finance.unclassified', 'dedupe_key' => 'd1', 'external_reference' => 'ORD-9|', 'outcome' => 'needs_review']);

        $html = $this->get(route('admin.sales-channels.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('attention_authorisation_lost')), $html);
        $this->assertStringContainsString(route('admin.sales-channels.accounts.show', $lost), $html);
        $this->assertStringContainsString('The refresh token was refused.', $html);
        $this->assertStringContainsString(e(translate('attention_sync_backing_off')), $html);
        $this->assertStringContainsString('HTTP 500 &lt;b&gt;boom&lt;/b&gt;', $html, 'what a failure said is printed as text');
        $this->assertStringNotContainsString('<b>boom</b>', $html);
        $this->assertStringContainsString(e(translate('attention_orders_need_review')), $html);
        $this->assertStringContainsString(route('admin.sales-channels.orders.show', $order), $html);
        $this->assertStringContainsString(e(translate('order_reason_managed_dispatch_not_available')), $html);
        $this->assertStringContainsString(e(translate('attention_publishes_failed')), $html);
        $this->assertStringContainsString('Barcode is not found', $html);
        $this->assertStringContainsString(e(translate('attention_finance_needs_review')), $html);
        $this->assertStringContainsString('outcome=needs_review', $html);
    }

    public function test_nothing_needs_attention_is_said_only_when_it_is_true(): void
    {
        $this->trendyolAccount();

        $this->get(route('admin.sales-channels.index'))->assertOk()->assertSee(e(translate('nothing_needs_attention')), false);

        $this->channelOrder(SalesChannelAccount::firstOrFail(), ['review_reason' => 'split_replaced']);

        $this->get(route('admin.sales-channels.index'))->assertOk()->assertDontSee(e(translate('nothing_needs_attention')), false);
    }

    public function test_the_write_state_follows_the_order_in_which_the_transport_checks_its_switches(): void
    {
        $account = $this->trendyolAccount(writes: true);

        config(['sales_channels.enabled' => false]);
        $this->assertSame(WriteState::GlobalOff, WriteState::of($account));

        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => false]);
        $this->assertSame(WriteState::EnvironmentReadOnly, WriteState::of($account));

        $this->liveWritesOn();
        $this->assertSame(WriteState::Live, WriteState::of($account));
        $this->assertTrue(WriteState::of($account)->sendsForReal());

        $this->assertSame(WriteState::WritesNotAllowed, WriteState::of($account->forceFill(['write_enabled' => false])));
        $this->assertSame(WriteState::Rehearsal, WriteState::of($account->forceFill(['dry_run' => true])), 'a rehearsal needs neither the environment switch nor the account switch');
        $this->assertSame(WriteState::AccountDisabled, WriteState::of($account->forceFill(['enabled' => false])));
    }

    /** @return array<string, mixed> */
    private function trendyolForm(): array
    {
        return [
            'channel' => 'trendyol', 'name' => 'New Trendyol', 'environment' => 'production', 'external_account_id' => '5550001', 'currency' => 'SAR',
            'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub', 'accept_language' => 'ar', 'torod_reference_field' => 'order_number', 'carrier_codes' => ''],
            'secrets' => ['api_key' => 'k', 'api_secret' => 's'],
        ];
    }
}
