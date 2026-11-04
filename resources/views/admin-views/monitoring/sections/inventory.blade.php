{{--
    Inventory integrity: stock that disagrees with itself.

    Two things decide whether this page is read correctly, and both are drawn rather than implied.

    The first is WHAT EACH COUNT IS OVER. Two of the figures are exact — negative and zero stock are
    counted from an index that answers them without reading a product — and every other count is
    over the rows this page examined. "No product is oversold" and "none of the three hundred
    products I read is oversold" are different claims, and the population is printed beside each one.

    The second is THAT AN EMPTY LEDGER IS NOT A CLEAN LEDGER. Four checks read the movement history,
    and where nothing was ever recorded they say no_data rather than showing a green tick. Three
    stock paths in this build change a product without writing a movement at all, which is stated on
    the two reconciliation cards and again at the foot: a drift between live stock and the ledger is
    a missing writer at least as often as it is missing stock.

    The range control does not narrow most of this section, because stock is a condition now rather
    than an event inside a window. The banner says so before any number is shown.
--}}

@php
    $scope = $panel['scope'];
    $shop = $panel['shop'];
    $ledger = $panel['ledger'];
    $catalogue = $panel['catalogue'];
    $summary = $panel['summary'];
    $findings = $panel['findings'];
    $gaps = $panel['gaps'];
    $window = $panel['window'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // Signed on purpose: "-7" and "7" are opposite facts about a shelf, and a stock column that
    // drops the sign turns an oversold product into a stocked one.
    $stock = static fn ($value) => $value === null ? null : number_format((float) $value);

    $severityPill = static fn (string $severity) => match ($severity) {
        'critical' => 'mon-pill--critical',
        'major' => 'mon-pill--warning',
        'minor' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    // A stored value is handed to translate() only when the panel confirmed it is one this build
    // writes. translate() persists any key it has not seen into new-messages.php, so a value that
    // came out of a column would mint a language key per distinct value.
    $vocabulary = static fn (string $value, bool $known) => $known ? translate($value) : $value;

    // What one row of a check's population is, so the provenance line reads as a sentence. The
    // scope is one of the panel's own four words, never a value out of a column.
    $populationNoun = static fn (string $scope) => match ($scope) {
        'ledger' => translate('movements'),
        'standing' => translate('orders'),
        default => translate('rows'),
    };

    $sortUrl = static fn (string $sort) => route('admin.monitoring.section', [
        'section' => 'inventory',
        'range' => $range,
        'sort' => $sort,
    ]);
@endphp

{{-- Before any count: whether the shop's own database could be read, whether anything has ever
     recorded a stock movement, and what population these checks actually covered. --}}
<div class="mon-attention">
    @if ($shop['state'] !== 'ok')
        <div class="mon-attention__item mon-attention__item--critical">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_shops_own_database_could_not_be_read') }}</strong>
                <small>{{ $shop['note'] ?? $stateTitle($shop['state']) }}</small>
                <small>{{ translate('every_check_on_this_page_reads_the_stock_tables_directly_so_none_of_them_ran') }}</small>
                @if (!empty($shop['remedy']))
                    <code>{{ $shop['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    @if ($ledger['state'] !== 'ok')
        {{-- Said once, above, because four checks are blank for this one reason. Repeating it under
             each of them would turn one gap into four. --}}
        <div class="mon-attention__item {{ $ledger['state'] === 'failed' ? 'mon-attention__item--critical' : 'mon-attention__item--warning' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('nothing_can_be_reconciled_against_the_movement_ledger') }}</strong>
                <small>{{ $ledger['note'] ?? $stateTitle($ledger['state']) }}</small>
                <small>{{ translate('the_four_checks_that_read_movements_report_no_data_rather_than_a_clean_result_because_nothing_was_recorded_to_compare_against') }}</small>
                @if (!empty($ledger['remedy']))
                    <code>{{ $ledger['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="info" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('what_these_checks_read') }}</strong>
            <small>{{ $scope['note'] }}</small>
            <small>
                {{ translate('catalogue_checks_examine_at_most') }} {{ $count($scope['catalogue_sample_limit']) }}
                {{ translate('products_and_the_stock_outliers_at_most') }} {{ $count($scope['stock_sample_limit']) }};
                {{ translate('ledger_checks_examine_the_most_recent') }} {{ $count($scope['movement_sample_limit']) }}
                {{ translate('movements') }}.
                @if ($ledger['state'] === 'ok' && $ledger['oldest_at'])
                    {{ translate('those_movements_cover') }} {{ $ledger['oldest_at'] }} → {{ $ledger['newest_at'] }}
                    ({{ $scope['timezone'] }}).
                @endif
            </small>
            <small>
                {{ translate('orders_are_read_over_the_last') }} {{ $scope['standing_days'] }} {{ translate('days') }},
                {{ translate('from') }} {{ $scope['standing_since'] }} ({{ $scope['timezone'] }}).
            </small>
        </span>
    </div>
</div>

{{-- The counts, each rendering its own state so a figure that could not be read is never a zero. --}}
<x-k.card :title="translate('inventory_integrity_at_a_glance')">
    <div class="mon-grid">
        @foreach ($panel['headline'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>

    <p class="mon-note">
        {{ $summary['note'] }}
        @if (!$summary['products_implicated_exact'])
            {{ translate('at_least_one_check_stopped_at_its_limit_so_the_figures_above_are_a_floor_rather_than_a_total') }}.
        @endif
    </p>
</x-k.card>

{{-- Ranked by the stock behind each finding, because that is what an operator triages by: one
     product deducted twice matters more than forty listed at zero. --}}
<x-k.card :padded="false">
    <form method="get" class="k-view__toolbar">
        <input type="hidden" name="range" value="{{ $range }}">

        <div class="k-view__toolbar-grow">
            <select name="sort" class="k-select" aria-label="{{ translate('sort_by') }}">
                <option value="units" @selected($panel['sort'] === 'units')>{{ translate('units_implicated') }}</option>
                <option value="count" @selected($panel['sort'] === 'count')>{{ translate('products_implicated') }}</option>
            </select>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$sortUrl('units')" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>
    </form>

    <div class="k-card__body">
        <h3 class="mon-heading">{{ translate('what_contradicts_itself') }}</h3>

        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('check') }}</th>
                    <th>{{ translate('severity') }}</th>
                    <th class="k-table__num">{{ translate('found') }}</th>
                    <th class="k-table__num">{{ translate('units_implicated') }}</th>
                    <th>{{ translate('what_it_read') }}</th>
                    <th>{{ translate('state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($findings as $finding)
                    <tr class="{{ $finding['state'] === 'ok' && ($finding['count'] ?? 0) > 0 ? '' : 'mon-row--muted' }}">
                        <td>{{ translate($finding['key']) }}</td>
                        <td><span class="mon-pill {{ $severityPill($finding['severity']) }}">{{ translate($finding['severity']) }}</span></td>
                        <td class="k-table__num k-num">
                            @if ($finding['count'] === null)
                                {{-- Not a zero. The check did not look, which is a different fact
                                     from looking and finding none. --}}
                                <span class="mon-metric__state">{{ $stateTitle($finding['state']) }}</span>
                            @else
                                {{ $finding['count_exact'] ? '' : '≥' }}{{ $count($finding['count']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($finding['units_known'])
                                {{ $count($finding['units']) }}
                            @elseif (($finding['count'] ?? 0) > 0)
                                <span class="mon-metric__state">{{ translate('not_knowable') }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @switch ($finding['scope'])
                                @case ('ledger')
                                    {{ translate('the_movements_examined') }}
                                    @break
                                @case ('standing')
                                    {{ translate('last') }} {{ $scope['standing_days'] }} {{ translate('days_of_orders') }}
                                    @break
                                @case ('unsupported')
                                    {{ translate('nothing_this_build_records') }}
                                    @break
                                @default
                                    {{ translate('the_catalogue') }}
                            @endswitch
                        </td>
                        <td>
                            @if ($finding['state'] === 'ok')
                                <span class="mon-pill {{ ($finding['count'] ?? 0) > 0 ? $severityPill($finding['severity']) : 'mon-pill--healthy' }}">
                                    {{ ($finding['count'] ?? 0) > 0 ? translate('found') : translate('clean') }}
                                </span>
                            @else
                                <span class="mon-pill mon-pill--unknown">{{ $stateTitle($finding['state']) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ $count($summary['checks_ran']) }}/{{ $count($summary['checks_total']) }} {{ translate('checks_ran') }};
            @if ($summary['checks_blocked'] > 0)
                {{ $count($summary['checks_blocked']) }} {{ translate('could_not_run_and_say_why_below') }}.
            @else
                {{ translate('none_was_blocked') }}.
            @endif
            @if ($summary['checks_unsupported'] > 0)
                {{ $count($summary['checks_unsupported']) }}
                {{ translate('asks_a_question_this_build_has_no_data_for_at_all_and_says_what_would_be_needed') }}.
            @endif
            {{ translate('a_row_marked_with_a_greater_or_equal_sign_stopped_at_its_limit_and_is_a_floor_rather_than_a_total') }}.
        </p>
    </div>
</x-k.card>

{{-- One card per check: what it found, what it means, and what to do about it. The prose is written
     in the panel and echoed as-is — it is composed at runtime from the numbers in each row, and
     putting it through translate() would mint a language key per value. --}}
@foreach ($findings as $finding)
    <x-k.card :title="translate($finding['key'])">
        <x-slot:actions>
            <span class="mon-pill {{ $severityPill($finding['severity']) }}">{{ translate($finding['severity']) }}</span>
        </x-slot:actions>

        <p class="mon-note {{ $finding['state'] === 'ok' && ($finding['count'] ?? 0) > 0 ? 'mon-note--critical' : '' }}">
            {{ $finding['meaning'] }}
        </p>

        @if ($finding['state'] === 'ok' && !empty($finding['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('product') }}</th>
                        <th class="k-table__num">{{ translate('stock_on_the_product') }}</th>
                        <th class="k-table__num">{{ translate('the_figure_that_disagrees') }}</th>
                        <th class="k-table__num">{{ translate('units') }}</th>
                        <th>{{ translate('what_is_wrong') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($finding['rows'] as $row)
                        <tr>
                            <td>
                                @if ($row['product_id'] === null)
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @else
                                    <code>{{ $row['product_id'] }}</code>
                                    @if ($row['product'])
                                        <small class="mon-metric__note" style="display:block">{{ $row['product'] }}</small>
                                    @endif
                                @endif
                            </td>
                            <td class="k-table__num k-num">
                                @if ($row['stock'] === null)
                                    {{-- Blank, never zero: this check did not read the product's own
                                         stock, and a zero here would be a figure nobody measured. --}}
                                    <span class="mon-metric__state">{{ translate('not_read') }}</span>
                                @else
                                    {{ $stock($row['stock']) }}
                                @endif
                            </td>
                            <td class="k-table__num k-num">
                                @if ($row['counted'] === null)
                                    —
                                @else
                                    {{ $stock($row['counted']) }}
                                @endif
                            </td>
                            <td class="k-table__num k-num">
                                @if ($row['units'] !== null)
                                    {{ $count($row['units']) }}
                                @elseif ($finding['units'] === null)
                                    {{-- This check counts no units at all — a product listed at zero
                                         stock is not short a number of units — so the column is
                                         empty rather than claiming the figure was unreadable. --}}
                                    —
                                @else
                                    {{-- The column is measured for the other rows of this check and
                                         not for this one, which is a gap rather than a nil. --}}
                                    <span class="mon-metric__state">{{ translate('not_knowable') }}</span>
                                @endif
                            </td>
                            <td>
                                <small class="mon-metric__note">{{ $row['detail'] }}</small>
                                @if ($row['at'])
                                    <small class="mon-metric__note" style="display:block">
                                        {{ translate('last_touched') }}: {{ $row['at'] }} ({{ $window['timezone'] }})
                                    </small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mon-note">
                <strong>{{ translate('what_to_do') }}:</strong> {{ $finding['action'] }}
            </p>

            @if ($finding['truncated'])
                <p class="mon-note">
                    {{ translate('more_rows_match_this_check_than_are_listed') }}:
                    {{ $count($finding['limit']) }} {{ translate('shown') }}.
                </p>
            @endif
        @elseif ($finding['state'] === 'ok')
            <x-k.empty icon="check"
                       :title="translate('nothing_found_by_this_check')"
                       :text="$finding['note'] ?? ''" />
        @else
            <x-k.empty icon="settings"
                       :title="$stateTitle($finding['state'])"
                       :text="$finding['note'] ?? ''" />
            @if ($finding['blocked_by_connection'])
                {{-- The reason is stated once at the top of the page. Repeating it under every
                     check would turn one fault into twelve. --}}
                <p class="mon-note">{{ translate('the_reason_is_at_the_top_of_this_page') }}</p>
            @endif
            @if (!empty($finding['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $finding['remedy'] }}</code>
                </details>
            @endif
            <p class="mon-note">
                <strong>{{ translate('what_it_would_show') }}:</strong> {{ $finding['action'] }}
            </p>
        @endif

        @if (!empty($finding['caveat']))
            <p class="mon-note">{{ $finding['caveat'] }}</p>
        @endif

        <p class="mon-note">
            {{ translate('read_from') }} <code>{{ $finding['source'] }}</code>
            @if (!empty($finding['index']))
                {{ translate('on') }} <code>{{ $finding['index'] }}</code>
            @endif
            @if ($finding['examined'] !== null)
                — {{ translate('checked_over') }} {{ $count($finding['examined']) }} {{ $populationNoun($finding['scope']) }}@if ($finding['population_truncated']), {{ translate('which_is_fewer_than_there_are') }}@endif.
            @endif
        </p>
    </x-k.card>
@endforeach

{{-- The denominator. One oversold product out of eight and one out of eighty thousand are the same
     row and completely different news, so the size of the catalogue is drawn beside them. --}}
<x-k.card :title="translate('what_the_catalogue_holds')">
    @if ($catalogue['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('measurement') }}</th>
                    <th class="k-table__num">{{ translate('products') }}</th>
                    <th>{{ translate('what_it_counts') }}</th>
                </tr>
                </thead>
                <tbody>
                <tr class="{{ $catalogue['negative'] > 0 ? '' : 'mon-row--muted' }}">
                    <td>{{ translate('products_with_negative_stock') }}</td>
                    <td class="k-table__num k-num">{{ $count($catalogue['negative']) }}</td>
                    <td><small class="mon-metric__note">{{ translate('counted_exactly_from_the_stock_index_across_the_whole_catalogue') }}</small></td>
                </tr>
                <tr class="{{ $catalogue['zero'] > 0 ? '' : 'mon-row--muted' }}">
                    <td>{{ translate('products_at_zero_stock') }}</td>
                    <td class="k-table__num k-num">{{ $count($catalogue['zero']) }}</td>
                    <td><small class="mon-metric__note">{{ translate('products_whose_stock_column_is_null_are_not_in_this_range') }}. {{ translate('a_missing_figure_is_not_a_figure_of_zero') }}.</small></td>
                </tr>
                <tr>
                    <td>{{ translate('physical_products_offered_for_sale') }}</td>
                    <td class="k-table__num k-num">{{ $count($catalogue['sellable']) }}</td>
                    <td><small class="mon-metric__note">{{ $catalogue['sellable_definition'] }}</small></td>
                </tr>
                <tr class="mon-row--muted">
                    <td>{{ translate('products_in_the_catalogue') }}</td>
                    <td class="k-table__num k-num"><span class="mon-metric__state">{{ translate('not_counted') }}</span></td>
                    <td><small class="mon-metric__note">{{ $catalogue['total_note'] }}</small></td>
                </tr>
                </tbody>
            </table>
        </div>
    @else
        <x-k.empty icon="catalog" :title="$stateTitle($catalogue['state'])" :text="$catalogue['note'] ?? ''" />
        @if (!empty($catalogue['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $catalogue['remedy'] }}</code>
            </details>
        @endif
    @endif

    <p class="mon-note">
        {{ translate('counted_from') }} <code>{{ $catalogue['source'] }}</code>
        {{ translate('on') }} <code>{{ $catalogue['index'] }}</code>.
    </p>
</x-k.card>

{{-- The ledger itself, before anything derived from it: how much of it this page read, what period
     that turned out to be, and which movement types are in it. --}}
<x-k.card :title="translate('the_movement_ledger')">
    @if ($ledger['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('movement_type') }}</th>
                    <th class="k-table__num">{{ translate('movements') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($ledger['by_type'] as $type)
                    <tr class="{{ $type['known'] ? '' : 'mon-row--muted' }}">
                        <td>
                            {{ $vocabulary($type['type'], $type['known']) }}
                            @unless ($type['known'])
                                {{-- Echoed, not translated, and named as unrecognised: the column is
                                     a free varchar and a value from it must never become a language
                                     key. A type outside the five StockMovement defines is a finding
                                     of its own. --}}
                                <small class="mon-metric__note" style="display:block">{{ translate('not_a_movement_type_this_build_writes') }}</small>
                            @endunless
                        </td>
                        <td class="k-table__num k-num">{{ $count($type['movements']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ $count($ledger['examined']) }} {{ translate('movements_examined') }}@if ($ledger['truncated']) — {{ translate('the_most_recent_the_ledger_holds_more') }}@endif.
            @if ($ledger['oldest_at'])
                {{ translate('they_cover') }} {{ $ledger['oldest_at'] }} → {{ $ledger['newest_at'] }} ({{ $window['timezone'] }}).
            @endif
        </p>
        <p class="mon-note">{{ $ledger['writers'] }}</p>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($ledger['state'])" :text="$ledger['note'] ?? ''" />
        @if (!empty($ledger['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $ledger['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">{{ $ledger['writers'] }}</p>
    @endif

    <p class="mon-note">
        {{ translate('read_from') }} <code>{{ $ledger['source'] }}</code>
        {{ translate('on') }} <code>{{ $ledger['index'] }}</code>.
        {{ translate('no_index_on_this_table_leads_with_created_at') }},
        {{ translate('so_it_is_read_newest_first_off_its_primary_key_rather_than_by_time') }}.
    </p>
</x-k.card>

{{-- Not caveats this page chose to add — facts about the schema and the code that decide how far
     the findings above can be trusted. Drawn as readings with the exact change that removes each. --}}
<x-k.card :title="translate('what_this_build_does_not_record_about_stock')">
    <div class="mon-grid">
        @foreach ($gaps['fields'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>
    <p class="mon-note">{{ $gaps['note'] }}</p>
</x-k.card>

<p class="mon-note">
    {{ translate('every_figure_on_this_page_is_read_live_from_the_shops_own_tables') }}:
    <code>products</code>, <code>stock_movements</code>, <code>order_details</code>,
    <code>warehouse_stock</code>, <code>product_batches</code>
    ({{ translate('connection') }}: <code>{{ $shop['connection'] ?? '—' }}</code>).
    {{ translate('nothing_here_is_stored_or_measured_by_a_collector_so_there_is_no_history_behind_it_and_no_alert_rule_can_fire_on_it') }}.
    {{ translate('stock_timestamps_are_written_by_the_shop_in_its_own_timezone_and_are_shown_here_in') }}
    {{ $window['timezone'] }}.
</p>
