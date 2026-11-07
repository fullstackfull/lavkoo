<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Http\Controllers\Admin\OperationalCosts\EntryActions;
use App\Models\OperationalCostAllocation;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\CostLedger;
use Illuminate\Support\Arr;
use Tests\Support\CreatesOperationalCostData;

/** The cost ledger on screen: estimated, actual, reconciled, missing and disputed side by side and never blended, and a ledger that is corrected by adding to it. */
class AdminOperationalCostsEntriesTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    private const NOVEMBER = ['from' => '2026-11-01', 'to' => '2026-11-30'];

    private function list(array $query = []): string
    {
        return $this->get(route('admin.operational-costs.entries.index', $query + self::NOVEMBER))->assertOk()->getContent();
    }

    private function entryUrl(OperationalCostEntry $entry): string
    {
        return route('admin.operational-costs.entries.show', $entry->id);
    }

    /** The markup of the table row that mentions a text, so that a figure can be asserted to be in THAT row. */
    private function rowWith(string $html, string $text): string
    {
        preg_match_all('#<tr[^>]*>.*?</tr>#s', $html, $rows);
        foreach ($rows[0] as $row) {
            if (str_contains($row, $text)) {
                return $row;
            }
        }
        $this->fail("No table row mentions {$text}.");
    }

    public function test_the_page_has_a_tab_for_each_kind_of_cost_and_defaults_to_the_current_month(): void
    {
        $this->travelTo('2026-11-15');
        $this->recordCost(CostState::Actual, '5', CostComponent::Shipping, '2026-11-02', ['reference' => 'IN-MONTH']);
        $this->recordCost(CostState::Actual, '6', CostComponent::Shipping, '2026-10-02', ['reference' => 'LAST-MONTH']);

        $html = $this->get(route('admin.operational-costs.entries.index'))->assertOk()->getContent();

        foreach (['all', 'shipment', 'storage', 'marketplace', 'payment', 'missing'] as $view) {
            $this->assertStringContainsString(e(translate('entries_view_' . $view)), $html);
            $this->assertStringContainsString(e(route('admin.operational-costs.entries.index', ['view' => $view, 'from' => '2026-11-01', 'to' => '2026-11-30', 'seller' => '0'])), $html, 'A tab keeps the period it was opened with.');
        }
        $this->assertStringContainsString('IN-MONTH', $html);
        $this->assertStringNotContainsString('LAST-MONTH', $html);
        $this->assertStringContainsString('value="2026-11-01"', $html);
    }

    public function test_emptying_the_dates_lists_every_entry_and_the_missing_tab_lists_every_missing_cost(): void
    {
        $this->travelTo('2026-11-15');
        $this->recordCost(CostState::Actual, '6', CostComponent::Shipping, '2025-03-02', ['reference' => 'OLD-ACTUAL']);
        $this->recordCost(CostState::Missing, null, CostComponent::Shipping, '2025-03-02', ['reference' => 'OLD-MISSING']);

        $this->assertStringNotContainsString('OLD-ACTUAL', $this->get(route('admin.operational-costs.entries.index'))->getContent());
        $this->assertStringContainsString('OLD-ACTUAL', $this->get(route('admin.operational-costs.entries.index', ['from' => '', 'to' => '']))->getContent());
        $missingTab = $this->get(route('admin.operational-costs.entries.index', ['view' => 'missing']))->getContent();
        $this->assertStringContainsString('OLD-MISSING', $missingTab, 'The missing tab is a work list: it does not hide an old gap behind a month.');
        $this->assertStringNotContainsString('OLD-ACTUAL', $missingTab);
    }

    public function test_a_missing_cost_is_shown_as_missing_with_its_reason_and_never_as_zero(): void
    {
        $this->recordCost(CostState::Missing, null, CostComponent::Shipping, '2026-11-05', ['reference' => 'GAP-1', 'reason' => 'weight_unknown']);

        $row = $this->rowWith($this->list(), 'GAP-1');

        $this->assertStringContainsString(e(translate('cost_state_missing')), $row);
        $this->assertStringContainsString(e(translate('missing_reason_weight_unknown')), $row);
        $this->assertStringContainsString('—', $row, 'Where the amount would be, a dash.');
        $this->assertStringNotContainsString('0.00', $row);
        $this->assertStringNotContainsString('>0<', $row);
        $this->assertNull(OperationalCostEntry::firstOrFail()->amount, 'The ledger holds no amount at all for it.');
    }

    public function test_an_estimate_and_the_real_charge_are_told_apart_and_totalled_apart(): void
    {
        $this->recordCost(CostState::Estimated, '12', CostComponent::Shipping, '2026-11-05', ['reference' => 'EST-1']);
        $this->recordCost(CostState::Actual, '30', CostComponent::Picking, '2026-11-05', ['reference' => 'ACT-1']);
        $reconciled = $this->recordCost(CostState::Actual, '8', CostComponent::Packing, '2026-11-05', ['reference' => 'REC-1']);
        app(CostLedger::class)->markReconciled($reconciled, 1);

        $html = $this->list();

        $this->assertStringContainsString(e(translate('cost_state_estimated')), $this->rowWith($html, 'EST-1'));
        $this->assertStringContainsString(e(translate('cost_state_actual')), $this->rowWith($html, 'ACT-1'));
        $this->assertStringContainsString(e(translate('cost_state_reconciled')), $this->rowWith($html, 'REC-1'));
        $this->assertStringContainsString('12.00 SAR', $html);
        $this->assertStringContainsString('30.00 SAR', $html);
        $this->assertStringContainsString('8.00 SAR', $html);
        $this->assertStringNotContainsString('50.00 SAR', $html, 'Estimated, actual and reconciled are never added into one number.');
        $this->assertStringNotContainsString('42.00 SAR', $html);
        $this->assertStringNotContainsString('38.00 SAR', $html);
        $this->assertStringContainsString(e(translate('still_waiting_for_the_real_charge')), $html);
    }

    public function test_the_totals_state_how_many_entries_are_missing_or_disputed_beside_the_sums(): void
    {
        $this->recordCost(CostState::Actual, '30', CostComponent::Shipping, '2026-11-05');
        $this->recordCost(CostState::Actual, '20', CostComponent::Picking, '2026-11-05');
        $this->recordCost(CostState::Missing, null, CostComponent::Packing, '2026-11-05');
        $this->recordCost(CostState::Missing, null, CostComponent::Stickers, '2026-11-05', ['reason' => 'no_contract']);
        $this->recordCost(CostState::Missing, null, CostComponent::Printing, '2026-11-05', ['reason' => 'no_contract']);
        app(CostLedger::class)->dispute($this->recordCost(CostState::Actual, '7', CostComponent::Stickers, '2026-11-06'), 'billed twice');

        $html = $this->list();

        $this->assertMatchesRegularExpression('#2 ' . preg_quote(e(translate('entries')), '#') . '</span>\s*<strong class="k-num">50\.00 SAR#', $html, 'Two actual entries, 50.00 between them.');
        $this->assertMatchesRegularExpression('#3 ' . preg_quote(e(translate('entries')), '#') . '</span>\s*<span class="fs-12 text-danger">' . preg_quote(e(translate('no_amount_a_missing_cost_is_not_a_zero')), '#') . '#', $html, 'Three missing costs, with no amount.');
        $this->assertMatchesRegularExpression('#1 ' . preg_quote(e(translate('entries')), '#') . '</span>\s*<strong class="k-num">7\.00 SAR</strong>#', $html, 'The disputed charge is a line of its own.');
        $this->assertStringContainsString(e(translate('in_no_total_until_resolved')), $html);
    }

    public function test_entries_with_no_amount_in_the_reporting_currency_are_counted_but_left_out_of_the_sum(): void
    {
        $this->recordCost(CostState::Actual, '10', CostComponent::Shipping, '2026-11-05');
        $this->recordCost(CostState::Actual, '99', CostComponent::Picking, '2026-11-05', ['currency' => 'EUR']);

        $html = $this->list();

        $this->assertMatchesRegularExpression('#2 ' . preg_quote(e(translate('entries')), '#') . '</span>\s*<strong class="k-num">10\.00 SAR</strong>#', $html);
        $this->assertStringContainsString(e(CostLabels::filled(translate('entries_left_out_no_exchange_rate'), ['count' => 1])), $html);
    }

    public function test_each_tab_lists_its_own_kind_of_cost(): void
    {
        $this->recordCost(CostState::Actual, '1', CostComponent::Shipping, '2026-11-05', ['reference' => 'SHIPMENT-ROW']);
        $this->recordCost(CostState::Actual, '2', CostComponent::StorageDaily, '2026-11-05', ['reference' => 'STORAGE-ROW']);
        $this->recordCost(CostState::Actual, '3', CostComponent::MarketplaceCommission, '2026-11-05', ['reference' => 'MARKETPLACE-ROW']);
        $this->recordCost(CostState::Actual, '4', CostComponent::PaymentGateway, '2026-11-05', ['reference' => 'PAYMENT-ROW']);
        $this->recordCost(CostState::Missing, null, CostComponent::Cogs, '2026-11-05', ['reference' => 'MISSING-ROW', 'reason' => 'product_cost_unknown']);
        $rows = ['SHIPMENT-ROW', 'STORAGE-ROW', 'MARKETPLACE-ROW', 'PAYMENT-ROW', 'MISSING-ROW'];

        foreach (['all' => $rows, 'shipment' => ['SHIPMENT-ROW'], 'storage' => ['STORAGE-ROW'], 'marketplace' => ['MARKETPLACE-ROW'], 'payment' => ['PAYMENT-ROW'], 'missing' => ['MISSING-ROW']] as $view => $expected) {
            $html = $this->list(['view' => $view]);
            foreach ($rows as $row) {
                in_array($row, $expected, true) ? $this->assertStringContainsString($row, $html, "{$view} lists {$row}") : $this->assertStringNotContainsString($row, $html, "{$view} does not list {$row}");
            }
        }
        $this->assertStringContainsString('SHIPMENT-ROW', $this->list(['view' => 'nonsense']), 'An unknown tab is the first tab.');
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $account = $this->channelSale()->account_id;
        $this->recordCost(CostState::Actual, '1', CostComponent::Shipping, '2026-11-05', ['reference' => 'A-SHIPPING', 'provider' => 'torod_point']);
        $this->recordCost(CostState::Estimated, '2', CostComponent::Picking, '2026-11-12', ['reference' => 'B-PICKING', 'provider' => 'm5azn', 'channel' => 'trendyol', 'accountId' => $account, 'subjectType' => 'channel_order']);
        $this->recordCost(CostState::Actual, '3', CostComponent::MarketplaceCommission, '2026-11-20', ['reference' => 'C-COMMISSION', 'provider' => 'amazon', 'channel' => 'amazon']);
        $others = static fn (array $mentioned): array => array_values(array_diff(['A-SHIPPING', 'B-PICKING', 'C-COMMISSION'], $mentioned));

        foreach ([
            [['component' => 'picking'], ['B-PICKING']],
            [['state' => 'estimated'], ['B-PICKING']],
            [['class' => 'marketplace_fee'], ['C-COMMISSION']],
            [['provider' => 'torod_point'], ['A-SHIPPING']],
            [['channel' => 'amazon'], ['C-COMMISSION']],
            [['account' => $account], ['B-PICKING']],
            [['subject' => 'channel_order'], ['B-PICKING']],
            [['from' => '2026-11-10', 'to' => '2026-11-15'], ['B-PICKING']],
            [['q' => 'COMMISSION'], ['C-COMMISSION']],
        ] as [$query, $expected]) {
            $html = $this->list($query);
            foreach ($expected as $mentioned) {
                $this->assertStringContainsString($mentioned, $html, json_encode($query));
            }
            foreach ($others($expected) as $absent) {
                $this->assertStringNotContainsString($absent, $html, json_encode($query));
            }
        }
        $this->get(route('admin.operational-costs.entries.index', ['component' => ['x'], 'state' => 'nonsense', 'account' => 'abc', 'from' => ['y'], 'q' => ['z'], 'view' => ['v']]))->assertOk();
    }

    public function test_the_books_of_a_seller_are_not_in_the_platforms_list(): void
    {
        $this->recordCost(CostState::Actual, '1', CostComponent::Shipping, '2026-11-05', ['reference' => 'PLATFORM-COST']);
        $this->recordCost(CostState::Actual, '2', CostComponent::Shipping, '2026-11-05', ['reference' => 'SELLER-COST', 'seller' => 9]);

        $platform = $this->list();
        $this->assertStringContainsString('PLATFORM-COST', $platform);
        $this->assertStringNotContainsString('SELLER-COST', $platform);
        $seller = $this->list(['seller' => 9]);
        $this->assertStringContainsString('SELLER-COST', $seller);
        $this->assertStringNotContainsString('PLATFORM-COST', $seller);
        $this->assertStringContainsString(e(translate('seller')) . ' #9', $seller);
        $everyone = $this->list(['seller' => 'all']);
        $this->assertStringContainsString('PLATFORM-COST', $everyone);
        $this->assertStringContainsString('SELLER-COST', $everyone);
    }

    public function test_an_estimate_the_real_charge_replaced_is_listed_only_in_the_history_and_shown_muted(): void
    {
        $estimate = $this->recordCost(CostState::Estimated, '12', CostComponent::Shipping, '2026-11-05', ['subjectId' => 5, 'identity' => 'est', 'reference' => 'SLOT-REF']);
        $actual = $this->recordCost(CostState::Actual, '13', CostComponent::Shipping, '2026-11-05', ['subjectId' => 5, 'identity' => 'inv', 'reference' => 'SLOT-REF']);

        $current = $this->list();
        $this->assertStringContainsString('13.00 SAR', $current);
        $this->assertStringNotContainsString('12.00 SAR', $current, 'The estimate no longer counts: it is not listed.');

        $history = $this->list(['history' => 1]);
        $this->assertStringContainsString('12.00 SAR', $history);
        $this->assertStringContainsString('13.00 SAR', $history);
        $this->assertMatchesRegularExpression('#<tr class="text-muted">(?:(?!</tr>).)*12\.00 SAR#s', $history, 'The replaced estimate is a muted row.');
        $this->assertStringContainsString(e(translate('replaced_by')) . ' <a href="' . $this->entryUrl($actual) . '">#' . $actual->id . '</a>', $history);
        $this->assertDoesNotMatchRegularExpression('#<tr class="text-muted">(?:(?!</tr>).)*13\.00 SAR#s', $history);
        $this->assertSame(1, $estimate->fresh()->superseded_by_id === $actual->id ? 1 : 0);
    }

    public function test_the_page_of_an_entry_shows_how_it_was_calculated_how_it_was_split_and_its_history(): void
    {
        $contract = $this->activeContract();
        $estimate = $this->recordCost(CostState::Estimated, '12', CostComponent::Shipping, '2026-11-05', ['subjectId' => 5, 'identity' => 'est', 'contractId' => $contract->id, 'contractVersion' => 1, 'reference' => 'SLOT-REF']);
        $actual = $this->recordCost(CostState::Actual, '13', CostComponent::Shipping, '2026-11-05', ['subjectId' => 5, 'identity' => 'inv', 'contractId' => $contract->id, 'contractVersion' => 1, 'reference' => 'SLOT-REF']);
        $actual->forceFill(['calc_inputs' => ['basis' => 'per_shipment', 'unit_amount' => '12.000000', 'pieces' => [['layer_id' => 3]]], 'allocation_status' => 'allocated'])->save();
        OperationalCostAllocation::create(['entry_id' => $actual->id, 'target_type' => 'order_line', 'target_id' => 41, 'product_id' => 50, 'variant_key' => '', 'basis' => 'value', 'basis_value' => '60', 'share' => '0.6', 'amount' => '7.8', 'currency' => 'SAR']);
        OperationalCostAllocation::create(['entry_id' => $actual->id, 'target_type' => 'order_line', 'target_id' => 42, 'product_id' => 51, 'variant_key' => '', 'basis' => 'value', 'basis_value' => '40', 'share' => '0.4', 'amount' => '5.2', 'currency' => 'SAR']);

        $html = $this->get($this->entryUrl($actual))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('cost_state_actual')), $html);
        $this->assertStringContainsString('13.00 SAR', $html);
        $this->assertStringContainsString('per_shipment', $html, 'The inputs of the calculation are listed.');
        $this->assertStringContainsString('layer_id', $html);
        $this->assertStringContainsString('7.80 SAR', $html);
        $this->assertStringContainsString('5.20 SAR', $html);
        $this->assertStringContainsString('60.00%', $html);
        $this->assertStringContainsString($this->entryUrl($estimate), $html, 'The estimate it replaced is in the history of this cost.');
        $this->assertStringContainsString(route('admin.operational-costs.contracts.show', $contract->id), $html);
        $this->assertStringContainsString('SLOT-REF', $html);
        $this->assertStringNotContainsString(e(translate('this_entry_was_replaced_by')), $html);

        $replaced = $this->get($this->entryUrl($estimate))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('this_entry_was_replaced_by')), $replaced);
        $this->assertStringNotContainsString(route('admin.operational-costs.entries.approve', $estimate->id), $replaced, 'A replaced entry offers no action at all.');
        $this->get(route('admin.operational-costs.entries.show', 9999))->assertNotFound();
    }

    public function test_the_page_of_a_missing_cost_says_why_it_is_missing_and_shows_no_amount(): void
    {
        $entry = $this->recordCost(CostState::Missing, null, CostComponent::Shipping, '2026-11-05', ['reason' => 'city_unknown']);

        $html = $this->get($this->entryUrl($entry))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('cost_state_missing')), $html);
        $this->assertStringContainsString(e(translate('missing_reason_city_unknown')), $html);
        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('0.00', $html);
        $this->assertSame([], EntryActions::available($entry));
        $this->assertStringContainsString(e(translate('no_action_is_available_for_this_entry')), $html);
    }

    public function test_an_actual_charge_can_be_approved_by_the_admin_who_checked_it(): void
    {
        $entry = $this->recordCost(CostState::Actual, '13', CostComponent::Shipping, '2026-11-05');
        $this->assertStringContainsString(route('admin.operational-costs.entries.approve', $entry->id), $this->get($this->entryUrl($entry))->getContent());

        $this->from($this->entryUrl($entry))->post(route('admin.operational-costs.entries.approve', $entry->id), ['note' => 'checked against the statement'])->assertRedirect($this->entryUrl($entry));

        $entry = $entry->fresh();
        $this->assertNotNull($entry->approved_at);
        $this->assertSame(1, $entry->approved_by, 'The approval records the signed-in admin.');
        $this->assertSame('checked against the statement', $entry->note);
        $this->assertSame(CostState::Actual, $entry->state);
        $this->assertContains(translate('entry_approved'), $this->flashedToasts());
    }

    public function test_an_estimate_a_missing_cost_and_an_approved_entry_offer_nothing_to_approve(): void
    {
        $estimate = $this->recordCost(CostState::Estimated, '12', CostComponent::Shipping);
        $missing = $this->recordCost(CostState::Missing, null, CostComponent::Picking);
        $approved = $this->recordCost(CostState::Actual, '5', CostComponent::Packing);
        app(CostLedger::class)->approve($approved, 1);

        foreach ([$estimate, $missing, $approved] as $entry) {
            $this->assertStringNotContainsString(route('admin.operational-costs.entries.approve', $entry->id), $this->get($this->entryUrl($entry))->getContent());
            $this->post(route('admin.operational-costs.entries.approve', $entry->id));
            $this->assertContains(translate('entry_action_not_available'), $this->flashedToasts());
        }
        $this->assertNull($estimate->fresh()->approved_at);
        $this->assertNull($missing->fresh()->approved_at);
        $this->assertStringNotContainsString(route('admin.operational-costs.entries.approve', $approved->id), $this->list());
    }

    public function test_an_approved_entry_is_never_edited_only_disputed_or_reversed(): void
    {
        $entry = $this->recordCost(CostState::Actual, '5', CostComponent::Packing);
        app(CostLedger::class)->approve($entry, 1);

        $html = $this->get($this->entryUrl($entry))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('an_approved_entry_is_never_edited_note')), $html);
        $this->assertStringContainsString(route('admin.operational-costs.entries.dispute', $entry->id), $html);
        $this->assertStringContainsString(route('admin.operational-costs.entries.reverse', $entry->id), $html);
        $this->assertStringNotContainsString(route('admin.operational-costs.entries.approve', $entry->id), $html);
        $this->assertStringNotContainsString(route('admin.operational-costs.entries.resolve', $entry->id), $html);
        $this->assertSame([EntryActions::DISPUTE, EntryActions::REVERSE], EntryActions::available($entry->fresh()));
        $this->assertDoesNotMatchRegularExpression('#<input[^>]*name="(amount|currency|component)"#', $html);
    }

    public function test_a_charge_is_disputed_with_a_reason_and_leaves_the_state_apart_from_every_total(): void
    {
        $entry = $this->recordCost(CostState::Actual, '5', CostComponent::Packing, '2026-11-05');
        $url = route('admin.operational-costs.entries.dispute', $entry->id);

        $this->from($this->entryUrl($entry))->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->from($this->entryUrl($entry))->post($url, ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason');
        $this->assertSame(CostState::Actual, $entry->fresh()->state);

        $this->from($this->entryUrl($entry))->post($url, ['reason' => 'billed twice'])->assertRedirect($this->entryUrl($entry));

        $entry = $entry->fresh();
        $this->assertSame(CostState::Disputed, $entry->state);
        $this->assertSame('billed twice', $entry->note);
        $this->assertSame('5.000000', $entry->amount, 'The amount is not touched.');
        $this->assertContains(translate('entry_disputed'), $this->flashedToasts());
        $this->assertStringContainsString(route('admin.operational-costs.entries.resolve', $entry->id), $this->get($this->entryUrl($entry))->getContent());
    }

    public function test_a_cost_is_reversed_with_a_reason_by_adding_an_opposite_entry_and_the_original_stays_as_it_was(): void
    {
        $entry = $this->recordCost(CostState::Actual, '5', CostComponent::Packing, '2026-11-05', ['subjectId' => 77, 'reference' => 'REV-REF']);
        app(CostLedger::class)->approve($entry, 1);
        $snapshot = static fn (): array => Arr::only($entry->fresh()->getRawOriginal(), ['amount', 'state', 'approved_at', 'approved_by', 'is_effective', 'dedupe_key']);
        $before = $snapshot();
        $url = route('admin.operational-costs.entries.reverse', $entry->id);

        $this->from($this->entryUrl($entry))->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(1, OperationalCostEntry::count());

        $this->from($this->entryUrl($entry))->post($url, ['reason' => 'charged to the wrong order'])->assertRedirect($this->entryUrl($entry));
        $this->from($this->entryUrl($entry))->post($url, ['reason' => 'charged to the wrong order'])->assertRedirect($this->entryUrl($entry));

        $this->assertSame($before, $snapshot(), 'The approved entry is exactly what it was.');
        $reversals = OperationalCostEntry::where('id', '!=', $entry->id)->get();
        $this->assertCount(1, $reversals, 'Reversing twice leaves one reversal.');
        $this->assertSame('-5.000000', $reversals[0]->amount);
        $this->assertSame(CostState::Actual, $reversals[0]->state);
        $this->assertSame('charged to the wrong order', $reversals[0]->note);
        $this->assertSame(77, $reversals[0]->subject_id);
        $this->assertContains(translate('entry_reversed'), $this->flashedToasts());

        $page = $this->get($this->entryUrl($entry))->getContent();
        $this->assertStringContainsString(route('admin.operational-costs.entries.show', $reversals[0]->id), $page, 'The correction is listed beside the entry it corrects.');
        $this->assertStringNotContainsString(route('admin.operational-costs.entries.reverse', $reversals[0]->id), $this->get($this->entryUrl($reversals[0]))->getContent(), 'A reversal is not reversed again.');
    }

    public function test_a_dispute_the_charge_survives_returns_the_entry_to_actual(): void
    {
        $entry = $this->recordCost(CostState::Actual, '5', CostComponent::Packing, '2026-11-05');
        app(CostLedger::class)->dispute($entry, 'billed twice');
        $url = route('admin.operational-costs.entries.resolve', $entry->id);

        $this->from($this->entryUrl($entry))->post($url, ['outcome' => 'stands', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->from($this->entryUrl($entry))->post($url, ['outcome' => 'maybe', 'reason' => 'x'])->assertSessionHasErrors('outcome');
        $this->from($this->entryUrl($entry))->post($url, ['reason' => 'x'])->assertSessionHasErrors('outcome');
        $this->assertSame(CostState::Disputed, $entry->fresh()->state);

        $this->from($this->entryUrl($entry))->post($url, ['outcome' => 'stands', 'reason' => 'the provider showed the two parcels'])->assertRedirect($this->entryUrl($entry));

        $this->assertSame(CostState::Actual, $entry->fresh()->state);
        $this->assertSame('the provider showed the two parcels', $entry->fresh()->note);
        $this->assertSame(1, OperationalCostEntry::count());
        $this->assertContains(translate('dispute_resolved'), $this->flashedToasts());
    }

    public function test_a_dispute_the_charge_does_not_survive_is_recorded_through_the_ledger_with_a_reversal(): void
    {
        $entry = $this->recordCost(CostState::Actual, '5', CostComponent::Packing, '2026-11-05');
        app(CostLedger::class)->dispute($entry, 'billed twice');

        $this->from($this->entryUrl($entry))->post(route('admin.operational-costs.entries.resolve', $entry->id), ['outcome' => 'does_not_stand', 'reason' => 'refunded by the provider'])->assertRedirect($this->entryUrl($entry));

        $reversal = OperationalCostEntry::where('id', '!=', $entry->id)->firstOrFail();
        $this->assertSame('-5.000000', $reversal->amount);
        $this->assertSame('refunded by the provider', $reversal->note);
        $this->assertSame('5.000000', $entry->fresh()->amount, 'The disputed entry itself is left as it was.');
    }

    public function test_only_a_disputed_entry_can_be_resolved(): void
    {
        $entry = $this->recordCost(CostState::Actual, '5', CostComponent::Packing);

        $this->post(route('admin.operational-costs.entries.resolve', $entry->id), ['outcome' => 'stands', 'reason' => 'x']);

        $this->assertContains(translate('entry_action_not_available'), $this->flashedToasts());
        $this->assertSame(CostState::Actual, $entry->fresh()->state);
    }

    public function test_an_estimate_cannot_be_disputed_or_reversed(): void
    {
        $estimate = $this->recordCost(CostState::Estimated, '12', CostComponent::Shipping);

        $this->post(route('admin.operational-costs.entries.dispute', $estimate->id), ['reason' => 'x']);
        $this->post(route('admin.operational-costs.entries.reverse', $estimate->id), ['reason' => 'x']);

        $this->assertSame(CostState::Estimated, $estimate->fresh()->state);
        $this->assertSame(1, OperationalCostEntry::count());
        $this->assertSame([], EntryActions::available($estimate));
    }

    public function test_a_page_of_a_seller_entry_names_whose_books_it_is_in(): void
    {
        $entry = $this->recordCost(CostState::Actual, '5', CostComponent::Packing, '2026-11-05', ['seller' => 12]);

        $this->assertStringContainsString(e(translate('seller')) . ' #12', $this->get($this->entryUrl($entry))->assertOk()->getContent());
    }
}
