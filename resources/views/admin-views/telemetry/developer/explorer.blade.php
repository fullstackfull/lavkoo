@include('admin-views.telemetry.developer._filters', ['data' => $data])

<x-k.card>
    <div class="dev-listhead">
        <span>{{ number_format($data['total']) }} {{ translate('endpoints') }}</span>
        @if ($data['pages'] > 1)
            <span class="dev-muted">{{ translate('page') }} {{ $data['page'] }} / {{ $data['pages'] }}</span>
        @endif
    </div>

    @forelse ($data['endpoints'] as $endpoint)
        @include('admin-views.telemetry.developer._endpoint-row', ['endpoint' => $endpoint])
    @empty
        <x-k.empty
            :title="translate('nothing_matches_those_filters')"
            :text="translate('every_endpoint_here_is_read_from_the_live_route_table_so_an_empty_result_means_no_route_matches_not_that_documentation_is_missing')" />
    @endforelse

    @if ($data['pages'] > 1)
        <nav class="dev-pager">
            @if ($data['page'] > 1)
                <a class="k-btn k-btn--ghost k-btn--sm" href="{{ request()->fullUrlWithQuery(['page' => $data['page'] - 1]) }}">{{ translate('previous') }}</a>
            @endif
            @if ($data['page'] < $data['pages'])
                <a class="k-btn k-btn--ghost k-btn--sm" href="{{ request()->fullUrlWithQuery(['page' => $data['page'] + 1]) }}">{{ translate('next') }}</a>
            @endif
        </nav>
    @endif
</x-k.card>
