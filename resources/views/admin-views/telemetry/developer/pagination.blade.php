<x-k.card :title="translate('pagination')">
    <p class="dev-note">{{ $data['note'] }}</p>
    <pre class="dev-code"><code>{{ json_encode($data['envelope'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
</x-k.card>

<x-k.card :title="translate('endpoints_that_page')">
    @forelse ($data['endpoints'] as $endpoint)
        @include('admin-views.telemetry.developer._endpoint-row', ['endpoint' => $endpoint])
    @empty
        <x-k.empty :title="translate('none_found')"
                   :text="translate('these_are_detected_by_looking_for_limit_offset_or_page_in_each_endpoints_validation_rules')" />
    @endforelse
</x-k.card>
