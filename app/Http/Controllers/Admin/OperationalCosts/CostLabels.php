<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\SalesChannels\SalesChannel;
use App\Services\OperationalCosts\Profit\ConversionGaps;
use App\Services\OperationalCosts\Reports\ProfitFilters;
use App\Services\OperationalCosts\Reports\ProfitLine;

/**
 * The words of the cost screens that come from a stored value (why a cost is missing, what kind of company a contract is with, where an entry came from).
 *
 * Every key such a value can turn into is listed in TRANSLATION_KEYS, so the translations can be written from this list. A value that is not in it
 * (a reason a newer version of the services starts to write) is shown as it is: it never becomes a translation key of its own, so a stored value can not
 * add a key to the language files.
 *
 * Enum labels do not belong here: they come from the enum's own labelKey().
 */
final class CostLabels
{
    public const TRANSLATION_KEYS = [
        // The kind of company a contract or an invoice is with.
        'party_type_fulfillment_provider', 'party_type_carrier', 'party_type_marketplace', 'party_type_payment_gateway', 'party_type_supplier', 'party_type_other',

        // Why a cost is missing.
        'missing_reason_no_contract', 'missing_reason_not_in_contract', 'missing_reason_no_matching_rule', 'missing_reason_outside_price_list', 'missing_reason_unknown_calc_method',
        'missing_reason_city_unknown', 'missing_reason_country_unknown', 'missing_reason_payment_method_unknown', 'missing_reason_sales_channel_unknown',
        'missing_reason_service_level_unknown', 'missing_reason_weight_unknown', 'missing_reason_value_unknown', 'missing_reason_volume_unknown',
        'missing_reason_destination_unknown', 'missing_reason_stock_unknown', 'missing_reason_awaiting_channel_statement', 'missing_reason_product_cost_unknown',
        'missing_reason_inventory_location_unknown', 'missing_reason_cost_layers_short', 'missing_reason_supplier_cost_unknown', 'missing_reason_fx_unavailable',
        'missing_reason_product_not_linked', 'missing_reason_vendor_owned_goods',

        // Where a cost entry came from, what it is about, and whether it has been split.
        'entry_source_rule', 'entry_source_invoice', 'entry_source_layer', 'entry_source_provider_field', 'entry_source_manual', 'entry_source_channel_api',
        'entry_subject_shipment', 'entry_subject_external_shipment', 'entry_subject_channel_order', 'entry_subject_invoice_item', 'entry_subject_order',
        'entry_subject_order_line', 'entry_subject_channel_order_line', 'entry_subject_location_period', 'entry_subject_channel_account',
        'allocation_status_not_applicable', 'allocation_status_pending', 'allocation_status_allocated', 'allocation_status_unallocated',

        // The tabs of the cost entries page.
        'entries_view_all', 'entries_view_shipment', 'entries_view_storage', 'entries_view_marketplace', 'entries_view_payment', 'entries_view_missing',

        // Invoices, their lines and their reconciliation.
        'invoice_kind_service', 'invoice_kind_purchase', 'invoice_kind_marketplace_statement',
        'invoice_status_draft', 'invoice_status_under_review', 'invoice_status_approved', 'invoice_status_disputed', 'invoice_status_void',
        'invoice_item_match_unmatched', 'invoice_item_match_matched', 'invoice_item_match_partial', 'invoice_item_match_disputed', 'invoice_item_match_ignored',
        'invoice_warning_same_invoice_number', 'invoice_warning_same_file', 'invoice_warning_items_do_not_add_up', 'invoice_warning_looks_like_invoice',
        'reconciliation_status_matched', 'reconciliation_status_variance', 'reconciliation_status_unmatched_invoice', 'reconciliation_status_unmatched_estimate',
        'reconciliation_status_disputed', 'reconciliation_status_resolved',
        'reconciliation_resolution_accept', 'reconciliation_resolution_dispute', 'reconciliation_resolution_write_off',
        'line_error_too_few_columns', 'line_error_too_many_columns', 'line_error_invalid_date', 'line_error_unknown_component', 'line_error_invalid_amount',
        'line_error_invalid_currency', 'line_error_invalid_quantity', 'line_error_too_many_lines',

        // Stock cost layers.
        'layer_source_purchase_invoice', 'layer_source_purchase_order', 'layer_source_manual', 'layer_source_opening_balance', 'layer_source_transfer', 'layer_source_transfer_in',
        'layer_source_return', 'layer_source_adjustment',
        'layer_status_open', 'layer_status_depleted', 'layer_status_void',
        'consumer_type_order_line', 'consumer_type_channel_order_line', 'consumer_type_transfer', 'consumer_type_write_off',

        // Contracts: what happened to one, how a rule is calculated, and the figures and conditions it holds.
        'contract_revision_created', 'contract_revision_updated', 'contract_revision_rule_added', 'contract_revision_rule_changed', 'contract_revision_rule_removed',
        'contract_revision_activated', 'contract_revision_superseded', 'contract_revision_archived',
        'calc_method_help_fixed', 'calc_method_help_percent', 'calc_method_help_percent_plus_fixed', 'calc_method_help_per_unit', 'calc_method_help_per_shipment',
        'calc_method_help_per_order', 'calc_method_help_per_day', 'calc_method_help_by_weight', 'calc_method_help_by_volume', 'calc_method_help_by_zone',
        'calc_method_help_tiered_quantity', 'calc_method_help_tiered_value',
        'multiply_by_none', 'multiply_by_units', 'multiply_by_volume_m3',
        'tier_mode_flat', 'tier_mode_per_unit',
        'condition_cities', 'condition_countries', 'condition_payment_methods', 'condition_sales_channels', 'condition_service_levels',
        'condition_min_weight_kg', 'condition_max_weight_kg', 'condition_min_value', 'condition_max_value',

        // Profitability.
        'profit_dimension_product', 'profit_dimension_order', 'profit_dimension_channel', 'profit_dimension_provider',
        'sale_state_open', 'sale_state_shipped', 'sale_state_delivered', 'sale_state_cancelled', 'sale_state_returned',
        'profit_note_revenue_not_convertible', 'profit_note_channel_amounts_missing', 'profit_note_amount_not_convertible', 'profit_note_total_does_not_add_up', 'profit_note_tax_not_separated',
        'profit_note_cost_not_convertible',

        // Why a return decision was refused.
        'return_refused_nothing_to_decide', 'return_refused_unknown_return_event', 'return_refused_claim_units_must_all_be_decided', 'return_refused_use_the_claim',
        'return_refused_stale_form', 'return_refused_more_than_sold', 'return_refused_not_linked', 'return_refused_no_location', 'return_refused_location_unknown', 'return_refused_no_cost_recognised',
        'return_refused_more_than_taken_from_stock',

        // The exports.
        'report_title_entries', 'report_title_missing', 'report_title_profit', 'report_title_invoices',
        'report_help_entries', 'report_help_missing', 'report_help_profit', 'report_help_invoices',
    ];

    /** The text for a stored value, or the value itself when this version has no words for it. A missing value is a dash. */
    public static function of(string $family, ?string $value): string
    {
        if ($value === null || $value === '') {
            return CostFormat::DASH;
        }
        $key = $family . '_' . $value;

        return in_array($key, self::TRANSLATION_KEYS, true) ? (string) translate($key) : $value;
    }

    /** A translated sentence with its :placeholders filled in, so that a translator can set each number where the sentence needs it. */
    public static function filled(?string $sentence, array $values): string
    {
        return strtr((string) $sentence, array_combine(
            array_map(static fn (string $name): string => ':' . $name, array_keys($values)),
            array_map(strval(...), $values),
        ));
    }

    /** A sales channel by name; a channel this version does not know is shown as it is. */
    public static function channel(?string $channel): string
    {
        $case = SalesChannel::tryFrom((string) $channel);
        if ($case !== null) {
            return (string) translate($case->labelKey());
        }

        return $channel === null || $channel === '' ? CostFormat::DASH : $channel;
    }

    /** A company that handles shipments, by the name the fulfillment settings give it. */
    public static function provider(?string $provider): string
    {
        if ($provider === null || $provider === '') {
            return CostFormat::DASH;
        }

        return $provider === ProfitFilters::NO_PROVIDER ? (string) translate('no_provider') : (string) config('fulfillment.providers.' . $provider . '.label', $provider);
    }

    /**
     * How complete a figure is, in words: what was missing in the sales it adds up, never a bare total.
     *
     * For a group of sales it counts the sales with a gap and the costs missing in them; for one sale (`$pendingCosts` given) it names the gap itself: the costs
     * that could not be determined and the ones still waiting for the real charge. A figure with a gap in its amounts (`$hasGaps`: no exchange rate) or a cost nobody
     * determined is never "Complete", even when no sale of it is counted (a product row adds up sale lines, not sales).
     */
    public static function completeness(int $incompleteSales, int $missingCosts, ?int $pendingCosts = null, bool $hasGaps = false): string
    {
        if ($incompleteSales === 0 && $missingCosts === 0 && !$hasGaps) {
            return (string) translate('complete');
        }
        if ($pendingCosts !== null) {
            $gaps = array_filter([
                $missingCosts > 0 ? $missingCosts . ' ' . translate('missing') : null,
                $pendingCosts > 0 ? $pendingCosts . ' ' . translate('pending') : null,
            ]);

            return translate('incomplete') . ($gaps === [] ? '' : ': ' . implode(', ', $gaps));
        }
        if ($incompleteSales === 0) {
            return translate('incomplete') . ($missingCosts > 0 ? ': ' . $missingCosts . ' ' . translate('missing') : '');
        }
        $text = translate('incomplete') . ': ' . $incompleteSales . ' ' . translate('sales');

        return $missingCosts > 0 ? $text . ' · ' . $missingCosts . ' ' . translate('missing') : $text;
    }

    /** What a figure left out for want of an exchange rate, in words: "no exchange rate for EUR, USD". */
    public static function noExchangeRate(ConversionGaps $gaps): string
    {
        return self::filled(translate('no_exchange_rate_for_currencies'), ['currencies' => implode(', ', $gaps->currencies())]);
    }

    /** The completeness of one line of a profit report: of its estimated figures, or (`$settled`) of its actual ones. */
    public static function lineCompleteness(ProfitLine $line, bool $settled): string
    {
        return self::completeness(
            $settled ? $line->incompleteActual : $line->incompleteEstimated,
            $line->missing,
            $line->pending === null ? null : ($settled ? $line->pending : 0),
            hasGaps: !$line->gaps()->isEmpty(),
        );
    }

    /**
     * The values a family has words for, in the order they are listed.
     *
     * @return list<string>
     */
    public static function values(string $family): array
    {
        $prefix = $family . '_';

        return array_values(array_map(
            static fn (string $key): string => substr($key, strlen($prefix)),
            array_filter(self::TRANSLATION_KEYS, static fn (string $key): bool => str_starts_with($key, $prefix)),
        ));
    }
}
