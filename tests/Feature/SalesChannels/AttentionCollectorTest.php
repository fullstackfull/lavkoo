<?php

namespace Tests\Feature\SalesChannels;

use App\Models\AmazonReportRun;
use App\Models\ChannelPackageSplit;
use App\Models\ChannelReturnDecision;
use App\Models\ChannelStockEvent;
use App\Models\OdooPosting;
use App\Services\SalesChannels\Attention\AttentionActions;
use App\Services\SalesChannels\Attention\AttentionCollector;
use App\Services\SalesChannels\Attention\AttentionRegistry;
use App\Services\SalesChannels\Attention\AttentionSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Everything waiting for a person, in one list — and the actions it refuses.
 *
 * ## What this is for
 *
 * Nine tables across five integrations can end in a state that means "no machine will touch this again". Each was built that
 * way on purpose, and each one was invisible: a pharmacist had to know to go looking. A return a customer is owed, a package
 * that may have been cut twice, a line that may be in a real ledger, a payment taken for the wrong amount — all of them sat
 * in a table with nothing pointing at them.
 *
 * ## And the rule it exists to enforce
 *
 * **Nothing on this screen sends anything.** Eight of the nine sources are not retryable and the tests below are mostly about
 * that: the only sanctioned actions are to look and to record what was found at the channel. The ninth, the stock outbox, may
 * be put back because its publisher recomputes a quantity and compares it with what it last wrote — idempotent by
 * construction, which is a property that can be stated, not a hope.
 */
class AttentionCollectorTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildOdooSchema();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00 UTC'));
    }

    // ---- the list ------------------------------------------------------------------------------------------------

    public function test_it_gathers_what_waits_for_a_person_from_every_corner(): void
    {
        $this->aFencedReturn();
        $this->aSplitNobodyCouldProve();
        $this->aPostingThatMayBeInTheLedger();
        $this->aReportWhoseReplyWasLost();
        $this->aDeadStockEvent();

        $items = app(AttentionCollector::class)->items();

        $this->assertSame(
            ['return_decisions', 'package_splits', 'odoo_postings', 'amazon_reports', 'stock_events'],
            array_values(array_unique(array_map(static fn ($item): string => $item->source, $items))),
            'five sources, and oldest first',
        );
        $this->assertSame(['returns', 'shipments', 'accounting', 'finance', 'stock'], array_values(array_unique(array_map(static fn ($item): string => $item->domain, $items))));
    }

    public function test_a_settled_row_is_not_waiting_for_anybody(): void
    {
        $this->aFencedReturn();
        ChannelReturnDecision::query()->update(['state' => ChannelReturnDecision::DONE]);

        $this->assertSame([], app(AttentionCollector::class)->items());
        $this->assertSame(0, app(AttentionCollector::class)->summary()['total']);
    }

    public function test_the_summary_counts_by_source_and_by_domain(): void
    {
        $this->aFencedReturn();
        $this->aSplitNobodyCouldProve();
        $this->aDeadStockEvent();

        $summary = app(AttentionCollector::class)->summary();

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['by_source']['return_decisions']);
        $this->assertSame(1, $summary['by_domain']['shipments']);
        $this->assertSame(1, $summary['by_domain']['stock']);
    }

    public function test_a_row_nobody_looked_at_for_a_day_is_late_and_a_fresh_one_is_not(): void
    {
        config(['sales_channels.attention.late_after_hours' => 24]);
        $this->aFencedReturn(sentAt: now()->subHours(30));
        $this->aSplitNobodyCouldProve(sentAt: now()->subHour());

        $items = app(AttentionCollector::class)->items();

        $this->assertTrue($items[0]->isLate(), 'thirty hours is late');
        $this->assertFalse($items[1]->isLate(), 'one hour is not');
        $this->assertSame(1, app(AttentionCollector::class)->summary()['late']);
    }

    public function test_a_source_whose_migration_has_not_run_is_skipped_and_not_an_error(): void
    {
        // These tables ship dormant. A host part-way through its migrations must still see the rest of the list.
        $this->aFencedReturn();
        Schema::drop('channel_package_splits');

        $keys = array_map(static fn ($source): string => $source->key, app(AttentionRegistry::class)->all());

        $this->assertNotContains('package_splits', $keys);
        $this->assertContains('return_decisions', $keys);
        $this->assertSame(1, app(AttentionCollector::class)->summary()['total']);
    }

    public function test_the_list_carries_the_reference_the_reason_and_no_buyer(): void
    {
        $this->aFencedReturn();

        $item = app(AttentionCollector::class)->items()[0];

        $this->assertSame('CLAIM-77', $item->reference);
        $this->assertSame('outcome_unknown_and_trendyol_offers_no_way_to_ask', $item->reason);
        $this->assertSame('ORDER-77', $item->orderReference);
        // Nothing personal travels to this screen: it says where to go and look, and the record's own page holds the detail
        // behind whatever permission that page already demands.
        $written = (string) json_encode($item);
        foreach (['phone', 'email', 'address', 'customer_name'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $written);
        }
    }

    // ---- what it refuses -----------------------------------------------------------------------------------------

    public function test_not_one_money_or_shipment_source_may_be_sent_again(): void
    {
        // **THE GUARD THIS WHOLE SCREEN TURNS ON.** A retry button on any of these is a double refund, a second parcel, a
        // duplicated ledger line or a second charge, one click away. The list is asserted whole so that a source added later
        // with the wrong flag fails here rather than in production.
        foreach (app(AttentionRegistry::class)->declared() as $source) {
            if ($source->key === 'stock_events') {
                continue;
            }
            $this->assertFalse($source->retryable, "'{$source->key}' is marked retryable, and it moves money or parcels.");
            $this->assertNotContains(AttentionSource::PUT_BACK, $source->actions, "'{$source->key}' offers to send again.");
            $this->assertNotNull($source->whyNotRetryable(), "'{$source->key}' must be able to tell a person WHY there is no retry.");
        }
    }

    public function test_putting_back_is_refused_for_a_source_that_may_already_have_sent(): void
    {
        $this->aFencedReturn();

        $result = app(AttentionActions::class)->putBack('return_decisions', (int) ChannelReturnDecision::firstOrFail()->id, adminId: 1, note: 'try again');

        $this->assertFalse($result['ok']);
        $this->assertSame('attention_action_not_allowed_for_this_source', $result['refusal']);
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::firstOrFail()->state, 'and the row did not move');
    }

    public function test_the_one_retryable_source_can_be_put_back(): void
    {
        $this->aDeadStockEvent();
        $id = (int) ChannelStockEvent::firstOrFail()->id;

        $result = app(AttentionActions::class)->putBack('stock_events', $id, adminId: 1, note: 'the provider is answering again');

        $this->assertTrue($result['ok'], (string) $result['refusal']);
        $row = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::PENDING, $row->state);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertSame([], app(AttentionCollector::class)->items(), 'and it is no longer waiting for anybody');
    }

    public function test_acknowledging_settles_the_row_as_a_persons_judgement_and_sends_nothing(): void
    {
        $this->aFencedReturn();
        $id = (int) ChannelReturnDecision::firstOrFail()->id;

        $result = app(AttentionActions::class)->acknowledge('return_decisions', $id, adminId: 9, note: 'Checked in the Trendyol panel: the refund went through on the 18th.');

        $this->assertTrue($result['ok'], (string) $result['refusal']);
        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state);
        $this->assertSame([], app(AttentionCollector::class)->items());
    }

    public function test_an_acknowledgement_with_no_words_is_refused(): void
    {
        // The note IS the record. A state change nobody can account for is worse than a row still waiting.
        $this->aFencedReturn();

        $result = app(AttentionActions::class)->acknowledge('return_decisions', (int) ChannelReturnDecision::firstOrFail()->id, adminId: 9, note: '   ');

        $this->assertFalse($result['ok']);
        $this->assertSame('attention_acknowledgement_needs_words', $result['refusal']);
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::firstOrFail()->state);
    }

    public function test_a_row_somebody_else_settled_first_is_not_overwritten(): void
    {
        $this->aFencedReturn();
        $id = (int) ChannelReturnDecision::firstOrFail()->id;
        ChannelReturnDecision::whereKey($id)->update(['state' => ChannelReturnDecision::DONE]);

        $result = app(AttentionActions::class)->acknowledge('return_decisions', $id, adminId: 9, note: 'I looked too');

        // Two pharmacists with the screen open is the ordinary case, and the second must not write over the first's judgement.
        $this->assertFalse($result['ok']);
        $this->assertSame('attention_row_is_no_longer_waiting', $result['refusal']);
    }

    public function test_an_unknown_source_is_refused_rather_than_guessed_at(): void
    {
        foreach ([
            fn (): array => app(AttentionActions::class)->acknowledge('not_a_source', 1, 1, 'x'),
            fn (): array => app(AttentionActions::class)->putBack('not_a_source', 1, 1, 'x'),
        ] as $call) {
            $this->assertSame('attention_action_not_allowed_for_this_source', $call()['refusal']);
        }
        $this->assertNull(app(AttentionCollector::class)->item('not_a_source', 1));
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function aFencedReturn(?\Carbon\CarbonInterface $sentAt = null): void
    {
        ChannelReturnDecision::create([
            'account_id' => 1, 'channel' => 'trendyol', 'claim_id' => 'CLAIM-77', 'claim_item_id' => 'ITEM-A',
            'external_order_id' => 'ORDER-77', 'decision' => ChannelReturnDecision::APPROVE,
            'state' => ChannelReturnDecision::MAY_HAVE_SENT, 'decided_by' => 1,
            'failure_code' => 'outcome_unknown_and_trendyol_offers_no_way_to_ask',
            'sent_at' => $sentAt ?? now()->subHours(5),
        ]);
    }

    private function aSplitNobodyCouldProve(?\Carbon\CarbonInterface $sentAt = null): void
    {
        ChannelPackageSplit::create([
            'account_id' => 1, 'channel' => 'trendyol', 'external_order_id' => 'ORDER-88', 'package_id' => 'PKG-88',
            'kind' => 'lines', 'plan' => ['orderLineIds' => [11]], 'state' => ChannelPackageSplit::NEEDS_REVIEW,
            'failure_code' => 'package_still_reads_as_it_did_after_8_checks', 'sent_at' => $sentAt ?? now()->subHours(4),
        ]);
    }

    private function aPostingThatMayBeInTheLedger(): void
    {
        OdooPosting::create([
            'seller_id' => 0, 'source_type' => 'operational_cost_entry', 'source_id' => 55, 'source_version' => 1,
            'odoo_ref' => 'PKSA-COST-55-ab12', 'state' => OdooPosting::MAY_HAVE_POSTED,
            'failure_code' => 'odoo_answer_could_not_be_read', 'needs_review_since' => now()->subHours(3),
        ]);
    }

    private function aReportWhoseReplyWasLost(): void
    {
        AmazonReportRun::create([
            'seller_id' => 0, 'account_id' => 1, 'report_type' => 'GET_MERCHANT_LISTINGS_ALL_DATA',
            'request_key' => 'rk-1', 'state' => AmazonReportRun::UNKNOWN,
            'failure_code' => 'amazon_report_reply_was_lost', 'requested_at' => now()->subHours(2),
        ]);
    }

    private function aDeadStockEvent(): void
    {
        ChannelStockEvent::create([
            'location_id' => 1, 'product_id' => 1, 'variant_key' => '', 'reason' => ChannelStockEvent::ORDER_IMPORTED,
            'state' => ChannelStockEvent::DEAD, 'attempts' => 5, 'failure_code' => 'the_provider_refused_five_times',
            'updated_at' => now()->subHour(),
        ]);
    }
}
