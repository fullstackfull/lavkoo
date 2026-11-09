<?php

namespace Tests\Feature\SalesChannels;

use App\Models\AmazonReportRun;
use App\Models\ChannelAttentionReview;
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
use Tests\Support\BuildsAuditLogTable;
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
    use BuildsAuditLogTable;
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

    public function test_the_late_count_is_the_real_number_and_not_a_page_of_it(): void
    {
        // **THE MONITORING DEFECT.** `late` was computed from `items()`, which is capped -- so past the cap a late refund
        // was invisible, and the one number that says "nobody has looked at this for a day" under-reported exactly when it
        // mattered most. 250 rows, all late, against a default page of 200.
        config(['sales_channels.attention.late_after_hours' => 24]);
        $rows = [];
        for ($n = 0; $n < 250; $n++) {
            $rows[] = [
                'account_id' => 1, 'channel' => 'trendyol', 'claim_id' => 'CLAIM-' . $n, 'claim_item_id' => 'ITEM-' . $n,
                'decision' => ChannelReturnDecision::APPROVE, 'state' => ChannelReturnDecision::MAY_HAVE_SENT,
                'sent_at' => now()->subHours(48), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        ChannelReturnDecision::insert($rows);

        $summary = app(AttentionCollector::class)->summary();

        $this->assertSame(250, $summary['total']);
        $this->assertSame(250, $summary['late'], 'the late count is a page of the list, not the list');
        $this->assertCount(200, app(AttentionCollector::class)->items(), 'and the LIST is still a page, which is right');
    }

    public function test_a_fresh_row_is_not_counted_late_however_many_old_ones_there_are(): void
    {
        config(['sales_channels.attention.late_after_hours' => 24]);
        $this->aFencedReturn(sentAt: now()->subHours(48));
        $this->aSplitNobodyCouldProve(sentAt: now()->subMinutes(5));

        $this->assertSame(1, app(AttentionCollector::class)->summary()['late']);
    }

    public function test_the_registry_declares_exactly_the_number_the_documents_claim(): void
    {
        // I wrote "nine sources" in six places and `declared()` has ten. A count in prose that nobody checks is a count that
        // drifts, so it is asserted here against the registry itself.
        $this->assertCount(10, app(AttentionRegistry::class)->declared());
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

    public function test_a_review_records_what_a_person_saw_and_changes_no_operation(): void
    {
        // **THE DEFECT THIS REPLACES.** `acknowledge()` used to move this row to `done` on the strength of a typed note. A
        // sentence is not proof that a customer was refunded. The review is recorded; the operation stays exactly as true as
        // it was.
        $this->aFencedReturn();
        $id = (int) ChannelReturnDecision::firstOrFail()->id;

        $result = app(AttentionActions::class)->review(
            sourceKey: 'return_decisions',
            id: $id,
            adminId: 9,
            outcome: ChannelAttentionReview::CONFIRMED_AT_CHANNEL,
            note: 'Checked the Trendyol panel: the refund shows as made on the 18th.',
            evidence: 'TY-REFUND-55120',
            closeTheAlert: true,
        );

        $this->assertTrue($result['ok'], (string) $result['refusal']);
        // The operation: UNCHANGED.
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::firstOrFail()->state, 'a note settled an ambiguous refund');
        // The review: kept, in full, where nine of the ten source tables have nowhere to put it.
        $review = ChannelAttentionReview::firstOrFail();
        $this->assertSame(['return_decisions', $id, 9, ChannelAttentionReview::CONFIRMED_AT_CHANNEL, 'TY-REFUND-55120', ChannelAttentionReview::CLOSED], [
            $review->source, (int) $review->source_row_id, (int) $review->reviewed_by, $review->outcome, $review->evidence, $review->alert_state,
        ]);
        $this->assertSame('CLAIM-77', $review->reference, 'copied at review time, because the source row may change or go');
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, $review->observed_state);
        // And the alert is off the screen while the operation is still what it is.
        $this->assertSame([], app(AttentionCollector::class)->items());
    }

    public function test_a_review_that_does_not_close_the_alert_leaves_it_on_the_screen_with_its_reviewer(): void
    {
        // "I looked" and "stop showing me this" are different things, and somebody still working on a refund must be able to
        // say the first without the second -- while the next person sees that it is in hand.
        $this->aFencedReturn();
        $id = (int) ChannelReturnDecision::firstOrFail()->id;

        app(AttentionActions::class)->review('return_decisions', $id, 9, ChannelAttentionReview::STILL_UNKNOWN, 'Asked Trendyol support, waiting.');

        $items = app(AttentionCollector::class)->items();
        $this->assertCount(1, $items);
        $this->assertSame(9, $items[0]->reviewedBy);
        $this->assertSame(ChannelAttentionReview::STILL_UNKNOWN, $items[0]->reviewOutcome);
    }

    public function test_two_people_cannot_record_two_conflicting_outcomes_for_one_row(): void
    {
        // Two pharmacists with the screen open is the ordinary case. The unique key on the review table is what stops one
        // refund carrying both "the channel shows it happened" and "the channel shows it did not".
        $this->aFencedReturn();
        $id = (int) ChannelReturnDecision::firstOrFail()->id;

        $first = app(AttentionActions::class)->review('return_decisions', $id, 9, ChannelAttentionReview::CONFIRMED_AT_CHANNEL, 'It was refunded.');
        $second = app(AttentionActions::class)->review('return_decisions', $id, 11, ChannelAttentionReview::NOT_DONE_AT_CHANNEL, 'It was not refunded.');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('attention_somebody_already_reviewed_this_confirmed_at_channel', $second['refusal']);
        $this->assertSame(1, ChannelAttentionReview::count(), 'one review, not two contradicting each other');
    }

    public function test_an_outcome_the_screen_does_not_know_is_refused(): void
    {
        // The outcome is a fixed vocabulary on purpose: a word nobody can group is not a record, and free text here would
        // make "was it done?" unanswerable across a hundred rows.
        $this->aFencedReturn();

        $result = app(AttentionActions::class)->review('return_decisions', (int) ChannelReturnDecision::firstOrFail()->id, 9, 'sorted_it_out', 'trust me');

        $this->assertSame('attention_outcome_is_not_one_we_recognise', $result['refusal']);
        $this->assertSame(0, ChannelAttentionReview::count());
    }

    public function test_a_review_with_no_words_or_no_person_is_refused(): void
    {
        $this->aFencedReturn();
        $id = (int) ChannelReturnDecision::firstOrFail()->id;

        $this->assertSame('attention_acknowledgement_needs_words', app(AttentionActions::class)->review('return_decisions', $id, 9, ChannelAttentionReview::STILL_UNKNOWN, '   ')['refusal']);
        $this->assertSame('attention_review_needs_a_named_person', app(AttentionActions::class)->review('return_decisions', $id, 0, ChannelAttentionReview::STILL_UNKNOWN, 'looked')['refusal']);
        $this->assertSame(0, ChannelAttentionReview::count());
    }

    public function test_nothing_is_recorded_when_the_review_table_has_not_been_migrated(): void
    {
        // **No durable record, no reported success.** The review row IS the record this feature promises, so a host without
        // it is told rather than shown a green toast over nothing.
        $this->aFencedReturn();
        Schema::drop('channel_attention_reviews');

        $result = app(AttentionActions::class)->review('return_decisions', (int) ChannelReturnDecision::firstOrFail()->id, 9, ChannelAttentionReview::STILL_UNKNOWN, 'looked');

        $this->assertFalse($result['ok']);
        $this->assertSame('attention_reviews_table_not_migrated', $result['refusal']);
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, ChannelReturnDecision::firstOrFail()->state);
    }

    public function test_a_review_whose_audit_line_cannot_be_written_records_nothing_at_all(): void
    {
        // The audit trail exists here, and a line that FAILS takes the review row with it: a trail that silently lost a line
        // is worse than one that never had it, because the first is believed.
        $this->aFencedReturn();
        $this->buildAuditLogTable();
        // A column the writer must use, made impossible: the insert fails inside the transaction.
        Schema::table('audit_logs', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropColumn('action');
        });

        $result = app(AttentionActions::class)->review('return_decisions', (int) ChannelReturnDecision::firstOrFail()->id, 9, ChannelAttentionReview::STILL_UNKNOWN, 'looked');

        $this->assertFalse($result['ok']);
        $this->assertSame('attention_review_could_not_be_recorded', $result['refusal']);
        $this->assertSame(0, ChannelAttentionReview::count(), 'the review row went with the audit line');
    }

    public function test_a_review_keeps_its_audit_line_when_the_trail_exists(): void
    {
        $this->aFencedReturn();
        $this->buildAuditLogTable();

        $result = app(AttentionActions::class)->review('return_decisions', (int) ChannelReturnDecision::firstOrFail()->id, 9, ChannelAttentionReview::CONFIRMED_AT_CHANNEL, 'refunded', 'TY-1');

        $this->assertTrue($result['ok'], (string) $result['refusal']);
        $line = \App\Models\AuditLog::where('action', 'sales_channel.attention_reviewed')->firstOrFail();
        // The trail says out loud that the operation was NOT settled by this.
        $this->assertSame(ChannelReturnDecision::MAY_HAVE_SENT, $line->context['operation_state_unchanged']);
        $this->assertSame('TY-1', $line->context['evidence']);
    }

    public function test_putting_back_is_refused_when_the_row_moved_under_us(): void
    {
        // The read-then-write race: `whereKey` alone would have applied this to whatever state the row had reached by now.
        $this->aDeadStockEvent();
        $id = (int) ChannelStockEvent::firstOrFail()->id;
        $actions = app(AttentionActions::class);

        // Somebody else works it between the read and the write.
        ChannelStockEvent::whereKey($id)->update(['state' => ChannelStockEvent::WORKED]);
        $result = $actions->putBack('stock_events', $id, 1, 'the provider is answering again');

        $this->assertFalse($result['ok']);
        $this->assertSame('attention_row_is_no_longer_waiting', $result['refusal']);
        $this->assertSame(ChannelStockEvent::WORKED, ChannelStockEvent::firstOrFail()->state, 'the other write was overwritten');
    }

    public function test_an_unknown_source_is_refused_rather_than_guessed_at(): void
    {
        foreach ([
            fn (): array => app(AttentionActions::class)->review('not_a_source', 1, 1, ChannelAttentionReview::STILL_UNKNOWN, 'x'),
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
