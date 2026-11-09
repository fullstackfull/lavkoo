<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\Admin;
use App\Models\ChannelReturnDecision;
use App\Models\ChannelStockEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * The screen that shows what waits for a person — its permission, its words, and the actions it will not take.
 *
 * The page itself is the point of the whole collector: nine tables could already end in a state meaning "no machine will
 * touch this again", and nothing pointed at them. What the tests below guard is that the page says so plainly and that
 * neither of its two buttons sends anything to anybody.
 */
class AdminAttentionScreenTest extends AdminSalesChannelsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00 UTC'));
    }

    /**
     * The module permission stays ON for this file, because one of its tests is about exactly that: a page listing refunds a
     * customer is owed, payments taken for the wrong amount and lines that may be in a real ledger has to be behind the
     * module, and a test that disabled the middleware would prove nothing about it.
     *
     * @return list<class-string>
     */
    protected function middlewareToDisable(): array
    {
        return array_values(array_filter(
            parent::middlewareToDisable(),
            static fn (string $class): bool => $class !== ModulePermissionMiddleware::class,
        ));
    }

    public function test_the_page_lists_what_waits_and_says_that_nothing_is_sent_again(): void
    {
        $this->aFencedReturn();

        $response = $this->get(route('admin.sales-channels.attention'));

        $response->assertOk();
        $response->assertSee(translate('attention_nothing_here_is_sent_again_note'), false);
        $response->assertSee('CLAIM-77');
        $response->assertSee(translate('attention_source_return_decisions'), false);
        // The reason is a code this version knows, shown as words; an unknown one is shown as it came.
        $response->assertSee(translate('attention_why_return_decisions'), false);
    }

    public function test_an_admin_without_the_module_cannot_see_it(): void
    {
        $this->aFencedReturn();
        // Admin 5 holds `operational_costs` only, which the schema builder sets up for exactly this.
        $this->actingAs(Admin::findOrFail(5), 'admin');

        $this->get(route('admin.sales-channels.attention'))->assertRedirect();
        $this->post(route('admin.sales-channels.attention.act', ['source' => 'return_decisions', 'id' => 1, 'action' => 'acknowledge']), ['note' => 'x'])
            ->assertRedirect();
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::firstOrFail()->state);
    }

    public function test_a_fenced_row_offers_no_way_to_send_it_again(): void
    {
        $this->aFencedReturn();

        $response = $this->get(route('admin.sales-channels.attention'));

        // The put-back form exists for one source in the whole system, and this is not it.
        $response->assertDontSee(route('admin.sales-channels.attention.act', ['source' => 'return_decisions', 'id' => ChannelReturnDecision::firstOrFail()->id, 'action' => 'put_back']), false);
        $response->assertSee(translate('i_checked'), false);
    }

    public function test_recording_what_a_person_found_settles_the_row_and_calls_nothing(): void
    {
        $this->aFencedReturn();
        $id = (int) ChannelReturnDecision::firstOrFail()->id;

        $response = $this->post(route('admin.sales-channels.attention.act', ['source' => 'return_decisions', 'id' => $id, 'action' => 'acknowledge']), [
            'note' => 'Checked in the Trendyol panel: the refund was made on the 18th.',
        ]);

        $response->assertRedirect();
        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state);
        // `preventStrayRequests` would have raised on any call; this says it in the test's own words as well.
        $this->assertSame([], Http::recorded()->all(), 'the screen called a channel');
    }

    public function test_the_one_idempotent_source_can_be_put_back_from_the_screen(): void
    {
        ChannelStockEvent::create([
            'location_id' => 1, 'product_id' => 1, 'variant_key' => '', 'reason' => ChannelStockEvent::ORDER_IMPORTED,
            'state' => ChannelStockEvent::DEAD, 'attempts' => 5, 'failure_code' => 'the_provider_refused_five_times',
        ]);
        $id = (int) ChannelStockEvent::firstOrFail()->id;

        $this->post(route('admin.sales-channels.attention.act', ['source' => 'stock_events', 'id' => $id, 'action' => 'put_back']), [
            'note' => 'the provider is answering again',
        ])->assertRedirect();

        $this->assertSame(ChannelStockEvent::PENDING, ChannelStockEvent::firstOrFail()->state);
        $this->assertSame([], Http::recorded()->all(), 'putting it back queues work; it does not send it');
    }

    public function test_an_action_the_route_does_not_know_is_not_reachable_at_all(): void
    {
        $this->aFencedReturn();

        // The route constrains the action to the two that exist, so an invented one never reaches the controller.
        $this->post('/admin/sales-channels/attention/return_decisions/1/send_again', ['note' => 'go on'])->assertNotFound();
    }

    public function test_an_acknowledgement_with_no_words_is_refused_with_a_toast(): void
    {
        $this->aFencedReturn();
        $id = (int) ChannelReturnDecision::firstOrFail()->id;

        $this->post(route('admin.sales-channels.attention.act', ['source' => 'return_decisions', 'id' => $id, 'action' => 'acknowledge']), ['note' => '  ']);

        $this->assertToastContains(translate('attention_acknowledgement_needs_words'));
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::firstOrFail()->state);
    }

    public function test_the_page_shows_no_secret_and_nothing_personal(): void
    {
        $this->aFencedReturn();

        $body = $this->get(route('admin.sales-channels.attention'))->getContent();

        $this->assertNoSecretIn($body, 'the attention page');
        foreach (['@', 'Riyadh', '+9665'] as $personal) {
            $this->assertStringNotContainsString($personal . 'customer', $body);
        }
    }

    private function aFencedReturn(): void
    {
        ChannelReturnDecision::create([
            'account_id' => 1, 'channel' => 'trendyol', 'claim_id' => 'CLAIM-77', 'claim_item_id' => 'ITEM-A',
            'external_order_id' => 'ORDER-77', 'decision' => ChannelReturnDecision::APPROVE,
            'state' => ChannelReturnDecision::MAY_HAVE_SENT, 'decided_by' => 1,
            'failure_code' => 'outcome_unknown_and_trendyol_offers_no_way_to_ask', 'sent_at' => now()->subHours(5),
        ]);
    }
}
