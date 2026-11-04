@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['terms'], 'title' => translate('what_customers_searched_for'), 'label' => translate('term'), 'dimension' => 'search_term', 'window' => $window, 'showEngagement' => false])

{{-- The most actionable table in the whole area: a term with real volume and nothing to show for
     it is a stocking decision waiting to be made, and it is invisible to any report that counts
     searches without counting what they found. --}}
<x-k.card :title="translate('searches_that_found_nothing')">
    @if (($data['no_results']['rows'] ?? []) === [])
        {{-- "Every search found something" is a claim about the catalogue. An empty table can also
             mean the tables are absent, the rollup has never run, or nobody searched at all — and
             only one of those is good news. --}}
        @if (($data['no_results']['state'] ?? 'ok') !== 'ok' && ($data['no_results']['state'] ?? '') !== 'no_traffic')
            @include('admin-views.analytics.sections._empty', ['state' => $data['no_results']['state']])
        @else
            <x-k.empty
                :title="translate('every_search_found_something')"
                :text="translate('no_customer_searched_for_a_term_the_catalogue_could_not_answer_in_this_period')" />
        @endif
    @else
        <p class="ana-note">{{ translate('each_of_these_is_a_customer_who_wanted_something_this_shop_does_not_sell_or_does_not_name_the_way_they_do') }}</p>
        <table class="ana-table">
            <thead><tr><th>{{ translate('term') }}</th><th class="ana-num">{{ translate('searches') }}</th><th class="ana-num">{{ translate('visitors') }}</th></tr></thead>
            <tbody>
            @foreach ($data['no_results']['rows'] as $row)
                <tr>
                    <td><strong>{{ $row['key'] }}</strong></td>
                    <td class="ana-num">{{ number_format($row['events']) }}</td>
                    <td class="ana-num">{{ number_format($row['visitors']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <a class="k-btn k-btn--ghost k-btn--sm ana-export" href="{{ route('admin.analytics.export', ['dimension' => 'search_no_results', 'range' => $window->key]) }}">
            <i class="tio-download"></i> {{ translate('export_csv') }}
        </a>
    @endif
</x-k.card>
