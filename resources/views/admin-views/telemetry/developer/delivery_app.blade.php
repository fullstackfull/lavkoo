{{-- One audience, in full. Same rows as the explorer, pre-filtered — the point of a per-audience
     screen is that an app developer never has to work out which of the other three hundred
     endpoints are theirs. --}}
@include('admin-views.telemetry.developer._filters', ['data' => $data])

<x-k.card>
    <div class="dev-listhead">
        <span>{{ number_format($data['total']) }} {{ translate('endpoints') }}</span>
        <span class="dev-muted">
            @foreach ($data['facets']['group'] ?? [] as $group => $count)
                {{ translate($group) }} {{ $count }}@if (!$loop->last) · @endif
            @endforeach
        </span>
    </div>

    @forelse ($data['endpoints'] as $endpoint)
        @include('admin-views.telemetry.developer._endpoint-row', ['endpoint' => $endpoint])
    @empty
        <x-k.empty
            :title="translate('no_endpoint_is_classified_for_this_client')"
            :text="translate('classification_comes_from_the_route_path_the_authentication_it_requires_and_any_apidoc_attribute_on_the_controller')" />
    @endforelse
</x-k.card>
