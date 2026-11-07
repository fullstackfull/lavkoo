<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Models\Admin;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingDecision;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\DB;

/** The screens around dispatching: what an order shows and offers, who may approve passing buyer details, and the two account settings. */
class AdminSalesChannelsDispatchTest extends AdminSalesChannelsTestCase
{
    private function labels(): ChannelLabels
    {
        return app(ChannelLabels::class);
    }

    private function routedToTorod(ExternalOrderMapping $order): void
    {
        FulfillmentRoutingDecision::create([
            'external_order_mapping_id' => $order->id, 'account_id' => $order->account_id, 'sales_channel' => $order->sales_channel, 'fulfillment_mode' => $order->fulfillment_mode,
            'action' => 'route_to_provider', 'execution_mode' => 'managed', 'provider' => 'torod_point', 'reason_code' => 'rule_route_to_provider', 'reason' => 'rule', 'version' => 1,
            'is_current' => true, 'applied' => true, 'decided_by' => 'engine',
        ]);
    }

    private function page(ExternalOrderMapping $order): string
    {
        return $this->get(route('admin.sales-channels.orders.show', $order))->assertOk()->getContent();
    }

    private function trendyolForm(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Trendyol KSA', 'environment' => 'production', 'seller_id' => 0, 'external_account_id' => '1234567', 'currency' => 'SAR',
            'settings' => [
                'storefront_code' => 'SA', 'integration_name' => 'PharmacyHub', 'accept_language' => 'ar', 'torod_reference_field' => 'order_number',
                'auto_link_exact_barcode' => 0, 'prices_include_tax' => 1, 'route_cod_orders' => 0, 'carrier_codes' => 'smsa=SMSA',
            ],
        ], $overrides);
    }

    private function approvalUrl(SalesChannelAccount $account, string $action = 'approve'): string
    {
        return route('admin.sales-channels.accounts.approval', ['account' => $account, 'scope' => ChannelOperations::SCOPE_BUYER_DATA_TRANSFER, 'action' => $action]);
    }

    public function test_an_order_sent_to_a_provider_shows_what_stands_in_the_way_and_offers_no_button_while_something_does(): void
    {
        $order = $this->channelOrder($this->trendyolAccount());
        $this->routedToTorod($order);

        $html = $this->page($order);

        $this->assertStringContainsString(e(translate('dispatch_card_title')), $html);
        foreach (['managed_dispatch_off', 'buyer_data_transfer_off', 'buyer_data_approval_missing', 'shipment_creation_not_declared'] as $blocker) {
            $this->assertStringContainsString(e($this->labels()->dispatchReason($blocker)), $html, "the page does not say '{$blocker}'");
        }
        $this->assertStringNotContainsString(route('admin.sales-channels.orders.dispatch', $order), $html, 'no button while a switch is off');
    }

    public function test_an_order_nobody_routes_to_a_provider_does_not_show_the_card_at_all(): void
    {
        $order = $this->channelOrder($this->trendyolAccount());

        $this->assertStringNotContainsString(e(translate('dispatch_card_title')), $this->page($order));
    }

    public function test_pressing_dispatch_while_a_switch_is_off_makes_nothing_and_says_why(): void
    {
        $order = $this->channelOrder($this->trendyolAccount());
        $this->routedToTorod($order);

        $this->from(route('admin.sales-channels.orders.show', $order))->post(route('admin.sales-channels.orders.dispatch', $order))->assertRedirect(route('admin.sales-channels.orders.show', $order));

        $this->assertToastContains($this->labels()->dispatchReason('managed_dispatch_off'));
        $this->assertSame([0, 0], [ChannelOrderDispatch::count(), DB::table('sales_channel_api_logs')->count()], 'nothing was claimed and the channel was not asked for the buyer');
    }

    public function test_a_dispatched_order_shows_its_state_and_cannot_have_its_shipment_unlinked_by_hand(): void
    {
        $order = $this->channelOrder($this->trendyolAccount(), ['order_id' => 100001, 'execution_policy' => 'managed', 'shipment_link_state' => 'linked', 'provider' => 'torod_point', 'shipment_reference' => 'O1', 'tracking_number' => 'TRK']);
        ChannelOrderDispatch::create(['mapping_id' => $order->id, 'account_id' => $order->account_id, 'state' => 'accepted', 'provider' => 'torod_point', 'internal_order_id' => 100001, 'attempts' => 1, 'accepted_at' => now(), 'buyer_data_purged_at' => now()]);

        $html = $this->page($order);

        $this->assertStringContainsString(e($this->labels()->dispatchState('accepted')), $html);
        $this->assertStringContainsString(e(translate('dispatch_buyer_data_removed')), $html);
        $this->assertStringNotContainsString(route('admin.sales-channels.orders.unlink-shipment', $order), $html, 'unlinking would hide a shipment that exists at the provider');
    }

    public function test_a_waiting_dispatch_shows_what_the_buyers_details_lack_in_words(): void
    {
        $order = $this->channelOrder($this->trendyolAccount());
        ChannelOrderDispatch::create(['mapping_id' => $order->id, 'account_id' => $order->account_id, 'state' => 'blocked', 'attempts' => 1, 'reason_code' => 'recipient_incomplete:phone_missing', 'next_attempt_at' => now()->addMinutes(5)]);

        $html = $this->page($order);

        $this->assertStringContainsString(e(translate('dispatch_reason_recipient_incomplete')) . ' (' . e(translate('dispatch_problem_phone_missing')) . ')', $html);
        $this->assertStringContainsString(e(translate('dispatch_next_attempt')), $html);
    }

    public function test_only_a_master_administrator_can_approve_passing_buyer_details_and_the_record_says_what_was_agreed(): void
    {
        $account = $this->connected($this->trendyolAccount([]));
        $this->actingAs(Admin::findOrFail(4), 'admin');

        $this->from(route('admin.sales-channels.accounts.show', $account))->post($this->approvalUrl($account), ['approval_reason' => 'Legal basis reviewed with the owner'])
            ->assertSessionHasErrorsIn('approval_' . ChannelOperations::SCOPE_BUYER_DATA_TRANSFER, 'approval_reason');
        $this->assertArrayNotHasKey(ChannelOperations::SCOPE_BUYER_DATA_TRANSFER, (array) $account->fresh()->capability_approvals);

        $this->actingAs(Admin::findOrFail(1), 'admin');
        $this->post($this->approvalUrl($account), ['approval_reason' => 'Legal basis reviewed with the owner'])->assertSessionHasNoErrors();

        $record = $account->fresh()->capability_approvals[ChannelOperations::SCOPE_BUYER_DATA_TRANSFER];
        $this->assertSame([1, 'Legal basis reviewed with the owner', ['name', 'mobile', 'street', 'city']], [$record['by'], $record['reason'], $record['details']['data']]);
        $this->assertSame(['torod_point', 'm5azn'], $record['details']['providers']);

        $this->actingAs(Admin::findOrFail(4), 'admin');
        $this->post($this->approvalUrl($account, 'revoke'))->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey(ChannelOperations::SCOPE_BUYER_DATA_TRANSFER, (array) $account->fresh()->capability_approvals, 'anyone with the module may withdraw it');
    }

    public function test_the_account_page_offers_the_approval_with_its_words_and_saves_the_two_dispatch_settings(): void
    {
        $account = $this->connected($this->trendyolAccount([]));

        $html = $this->get(route('admin.sales-channels.accounts.show', $account))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('approval_scope_buyer_data_transfer')), $html);
        $this->assertStringContainsString(e(translate('shipment_creation_setting')), $html);

        $form = $this->trendyolForm(['settings' => ['shipment_creation' => 'app', 'dispatch_mode' => 'auto']]);
        $this->post(route('admin.sales-channels.accounts.update', $account), $form)->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(['app', 'auto'], [$account->setting('shipment_creation'), $account->setting('dispatch_mode')]);
    }
}
