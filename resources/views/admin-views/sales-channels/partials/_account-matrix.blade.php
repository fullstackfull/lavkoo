{{-- What the channel documents and what is built, so that nobody is told something works that does not. The notes are the connector's own words.
     Expects $matrix (ChannelConnector::capabilityMatrix()), $labels. --}}
<x-k.card :title="translate('what_is_built_and_what_is_not')" :padded="false" class="mb-3">
    <p class="fs-12 text-muted p-3 pb-0 mb-0">{{ translate('what_is_built_and_what_is_not_help') }}</p>
    <div class="k-table-wrap table-responsive">
        <table class="k-table k-table--compact">
            <thead><tr><th>{{ translate('feature') }}</th><th>{{ translate('documented_by_the_channel') }}</th><th>{{ translate('built_here') }}</th></tr></thead>
            <tbody>
            @foreach ($matrix as $feature => $entry)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $labels->connectorFeature($feature) }}</div>
                        <div class="fs-12 text-muted text-break">{{ $entry['note'] }}</div>
                    </td>
                    <td><x-k.badge :tone="$entry['documented'] ? 'success' : 'neutral'">{{ $entry['documented'] ? translate('yes') : translate('no_not_documented') }}</x-k.badge></td>
                    <td><x-k.badge :tone="$entry['built'] ? 'success' : 'warning'">{{ $entry['built'] ? translate('yes') : translate('not_built') }}</x-k.badge></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-k.card>
