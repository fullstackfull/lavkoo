{{-- A deprecation is only real once somebody can see it, and only safe once the traffic is gone.
     Both facts are on every row. --}}
<x-k.card :title="translate('deprecated_endpoints')">
    @forelse ($data['endpoints'] as $endpoint)
        <div class="dev-deprecation">
            @include('admin-views.telemetry.developer._endpoint-row', ['endpoint' => $endpoint + ['health' => ['measured' => false]]])
            <div class="dev-deprecation__detail">
                @if ($endpoint['replaced_by'])
                    <p>{{ translate('use') }} <code>{{ $endpoint['replaced_by'] }}</code> {{ translate('instead') }}.</p>
                @else
                    <p class="dev-warn">{{ translate('no_replacement_is_named_a_deprecation_without_one_tells_a_developer_to_stop_but_not_what_to_do') }}</p>
                @endif
                @if ($endpoint['sunset_at'])
                    <p>{{ translate('scheduled_for_removal_on') }} <strong>{{ $endpoint['sunset_at'] }}</strong>.</p>
                @endif
                <p class="{{ ($endpoint['removal']['safe'] ?? null) === false ? 'dev-warn' : 'dev-muted' }}">
                    {{ $endpoint['removal']['message'] }}
                </p>
            </div>
        </div>
    @empty
        <x-k.empty
            :title="translate('nothing_is_marked_deprecated')"
            :text="translate('mark_an_endpoint_with_an_apidoc_attribute_naming_its_replacement_and_a_sunset_date_and_it_will_appear_here_with_its_remaining_traffic')" />
    @endforelse
</x-k.card>
