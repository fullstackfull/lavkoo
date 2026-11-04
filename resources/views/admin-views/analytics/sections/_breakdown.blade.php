{{-- One ranked table, shared by every screen that ranks something. Written once so twelve screens
     cannot drift into showing different columns for the same shape of data. --}}
@php($rows = $breakdown['rows'] ?? [])
<x-k.card :title="$title">
    @if ($rows === [])
        @include('admin-views.analytics.sections._empty', ['state' => $breakdown['state'] ?? 'no_traffic'])
    @else
        <table class="ana-table">
            <thead>
            <tr>
                {{-- The rollup fills a different subset per dimension: search terms and event names
                     carry events, not sessions, and printing an event count under a "sessions"
                     heading labels the number wrongly on three screens. --}}
                @php($countsEvents = collect($rows)->every(fn ($row) => (int) $row['sessions'] === 0))
                <th>{{ $label ?? translate('key') }}</th>
                <th class="ana-num">{{ $countsEvents ? translate('events') : translate('sessions') }}</th>
                @if ($showEngagement ?? true)
                    <th class="ana-num">{{ translate('bounce') }}</th>
                @endif
                <th class="ana-num">{{ translate('orders') }}</th>
                @if ($showRevenue ?? true)
                    <th class="ana-num">{{ translate('revenue') }}</th>
                @endif
                <th class="ana-num">{{ translate('share') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>
                        @if (($row['deleted'] ?? false))
                            <span class="ana-muted" title="{{ translate('this_record_has_been_deleted_since') }}">#{{ $row['key'] }} — {{ translate('deleted') }}</span>
                        @else
                            {{ $row['name'] ?? $row['key'] }}
                        @endif
                    </td>
                    <td class="ana-num">{{ number_format($row['sessions'] ?: $row['events']) }}</td>
                    @if ($showEngagement ?? true)
                        <td class="ana-num">{{ $row['bounce_rate'] !== null ? $row['bounce_rate'] . '%' : '—' }}</td>
                    @endif
                    <td class="ana-num">{{ number_format($row['orders']) }}</td>
                    @if ($showRevenue ?? true)
                        <td class="ana-num">{{ $row['revenue'] > 0 ? number_format($row['revenue'], 2) : '—' }}</td>
                    @endif
                    <td class="ana-num">
                        <span class="ana-bar"><i style="width: {{ min(100, (float) ($row['share'] ?? 0)) }}%"></i></span>
                        {{ $row['share'] !== null ? $row['share'] . '%' : '—' }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if ($breakdown['truncated'] ?? false)
            {{-- Shares are computed against the whole dimension, so a top-N table does not add up
                 to 100% — and a table that does not add up reads as broken unless it says why. --}}
            <p class="ana-note">
                {{ translate('the_rest_of_this_dimension') }}:
                <strong>{{ number_format($breakdown['other']) }}</strong>
                {{ $countsEvents ? translate('events') : translate('sessions') }}
                {{ translate('across_the_keys_not_shown') }}.
            </p>
        @endif

        @isset($dimension)
            <a class="k-btn k-btn--ghost k-btn--sm ana-export"
               href="{{ route('admin.analytics.export', ['dimension' => $dimension, 'range' => $window->key]) }}">
                <i class="tio-download"></i> {{ translate('export_csv') }}
            </a>
        @endisset
    @endif
</x-k.card>
