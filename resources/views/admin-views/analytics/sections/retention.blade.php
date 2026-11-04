{{-- Cohorts: of the visitors first seen in a week, how many came back. The only question on the
     whole area that is about people rather than about traffic, and the one a merchant should
     watch if they can only watch one. --}}
<x-k.card :title="translate('weekly_retention')">
    @if (($data['cohorts']['state'] ?? '') !== 'ok')
        @include('admin-views.analytics.sections._empty', ['state' => $data['cohorts']['state'] ?? 'no_traffic'])
    @else
        <p class="ana-note">{{ translate('each_row_is_the_visitors_who_arrived_for_the_first_time_that_week_the_columns_are_how_many_of_them_came_back_in_the_weeks_after') }}</p>
        <table class="ana-table ana-cohorts">
            <thead>
            <tr>
                <th>{{ translate('first_seen') }}</th>
                <th class="ana-num">{{ translate('visitors') }}</th>
                @for ($week = 0; $week < 8; $week++)
                    <th class="ana-num">{{ translate('week') }} {{ $week }}</th>
                @endfor
            </tr>
            </thead>
            <tbody>
            @foreach ($data['cohorts']['cohorts'] as $cohort)
                <tr>
                    <td>{{ $cohort['cohort'] }}</td>
                    <td class="ana-num">{{ number_format($cohort['size']) }}</td>
                    @for ($week = 0; $week < 8; $week++)
                        @php($cell = $cohort['retention'][$week] ?? null)
                        <td class="ana-num ana-cohorts__cell" @if ($cell) style="--pct: {{ $cell['pct'] }}" @endif>
                            {{ $cell ? $cell['pct'] . '%' : '' }}
                        </td>
                    @endfor
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-k.card>
