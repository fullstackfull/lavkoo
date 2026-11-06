<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Services\OperationalCosts\Profit\ProfitRow;
use App\Services\OperationalCosts\Reports\ProfitLine;
use App\Services\OperationalCosts\Support\Money;

/** The words of the cost screens that come from a stored value: every value has them, and a value nobody wrote words for is shown as it is. */
class AdminOperationalCostsLabelsTest extends AdminPagesTestCase
{
    public function test_every_key_is_lower_case_snake_case_and_listed_once(): void
    {
        $keys = CostLabels::TRANSLATION_KEYS;

        $this->assertSame(array_values(array_unique($keys)), $keys, 'A key is listed once.');
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/', $key);
        }
    }

    public function test_a_known_value_is_translated_and_an_unknown_one_is_shown_as_it_is_without_becoming_a_key(): void
    {
        $this->assertSame(translate('missing_reason_no_contract'), CostLabels::of('missing_reason', 'no_contract'));
        $this->assertSame('a_reason_a_newer_version_writes', CostLabels::of('missing_reason', 'a_reason_a_newer_version_writes'));
        $this->assertSame('—', CostLabels::of('missing_reason', null));
        $this->assertSame('—', CostLabels::of('missing_reason', ''));
        $this->assertSame('party_type_made_up', CostLabels::of('party_type', 'party_type_made_up'), 'Nothing outside the list is ever looked up in the language files.');
    }

    public function test_the_values_of_a_family_are_the_ones_it_lists_in_order(): void
    {
        $this->assertSame(['all', 'shipment', 'storage', 'marketplace', 'payment', 'missing'], CostLabels::values('entries_view'));
        $this->assertSame(['accept', 'dispute', 'write_off'], CostLabels::values('reconciliation_resolution'));
        $this->assertSame(['none', 'units', 'volume_m3'], CostLabels::values('multiply_by'));
        $this->assertSame(['flat', 'per_unit'], CostLabels::values('tier_mode'));
        $this->assertSame([], CostLabels::values('no_such_family'));
    }

    public function test_every_stored_state_the_services_write_has_words(): void
    {
        foreach ([
            'entry_source' => ['rule', 'invoice', 'layer', 'provider_field', 'manual', 'channel_api'],
            'allocation_status' => ['not_applicable', 'pending', 'allocated', 'unallocated'],
            'invoice_status' => ['draft', 'under_review', 'approved', 'disputed', 'void'],
            'invoice_kind' => ['service', 'purchase', 'marketplace_statement'],
            'invoice_item_match' => ['unmatched', 'matched', 'partial', 'disputed', 'ignored'],
            'reconciliation_status' => ['matched', 'variance', 'unmatched_invoice', 'unmatched_estimate', 'disputed', 'resolved'],
            'party_type' => ['fulfillment_provider', 'carrier', 'marketplace', 'payment_gateway', 'supplier', 'other'],
            'layer_status' => ['open', 'depleted', 'void'],
            'sale_state' => ['open', 'shipped', 'delivered', 'cancelled', 'returned'],
        ] as $family => $values) {
            $this->assertSame($values, array_values(array_intersect($values, CostLabels::values($family))), $family);
        }
    }

    public function test_a_channel_is_named_by_its_label_and_a_channel_this_version_does_not_know_by_itself(): void
    {
        $this->assertSame(translate('sales_channel_trendyol'), CostLabels::channel('trendyol'));
        $this->assertSame('myspace', CostLabels::channel('myspace'));
        $this->assertSame('—', CostLabels::channel(null));
    }

    public function test_a_provider_is_named_by_the_fulfillment_settings_and_none_has_its_own_word(): void
    {
        $this->assertSame('Torod Point', CostLabels::provider('torod_point'));
        $this->assertSame('a-new-courier', CostLabels::provider('a-new-courier'));
        $this->assertSame(translate('no_provider'), CostLabels::provider('none'));
        $this->assertSame('—', CostLabels::provider(null));
    }

    public function test_completeness_names_the_gap_and_never_calls_a_figure_with_a_gap_complete(): void
    {
        $this->assertSame(translate('complete'), CostLabels::completeness(0, 0));
        $this->assertSame(translate('incomplete') . ': 3 ' . translate('sales'), CostLabels::completeness(3, 0));
        $this->assertSame(translate('incomplete') . ': 3 ' . translate('sales') . ' · 5 ' . translate('missing'), CostLabels::completeness(3, 5));
        $this->assertSame(translate('incomplete') . ': 2 ' . translate('missing') . ', 1 ' . translate('pending'), CostLabels::completeness(1, 2, 1), 'For one sale the costs that are the gap are named.');
        $this->assertSame(translate('incomplete') . ': 1 ' . translate('pending'), CostLabels::completeness(1, 0, 1));
        $this->assertSame(translate('incomplete'), CostLabels::completeness(1, 0, 0), 'Incomplete for another reason (its revenue is not certain).');
    }

    public function test_a_line_is_judged_by_the_figure_it_is_asked_about(): void
    {
        $sale = new ProfitLine(source: $this->row(), key: 'order:1', label: 'ORD-1', orders: 1, units: null, missing: 0, pending: 2, incompleteEstimated: 0, incompleteActual: 1);

        $this->assertSame(translate('complete'), CostLabels::lineCompleteness($sale, settled: false), 'Waiting for the real charge does not make an estimate incomplete.');
        $this->assertSame(translate('incomplete') . ': 2 ' . translate('pending'), CostLabels::lineCompleteness($sale, settled: true));
    }

    private function row(): ProfitRow
    {
        $row = new ProfitRow('x', 'x');
        $row->revenue = Money::of('1');

        return $row;
    }
}
