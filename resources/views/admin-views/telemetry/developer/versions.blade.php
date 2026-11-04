{{-- Traffic, not intention, is what decides whether a version can be retired. --}}
<x-k.card :title="translate('api_versions')">
    <table class="dev-table">
        <thead><tr>
            <th>{{ translate('version') }}</th><th>{{ translate('endpoints') }}</th>
            <th>{{ translate('deprecated') }}</th><th>{{ translate('clients') }}</th>
            <th>{{ translate('traffic_30d') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($data['versions'] as $version => $info)
            <tr>
                <td><code>{{ $version }}</code></td>
                <td>{{ $info['endpoints'] }}</td>
                <td>{{ $info['deprecated'] ?: '—' }}</td>
                <td class="dev-muted">
                    @foreach ($info['audiences'] as $audience => $count)
                        {{ translate($audience) }} {{ $count }}@if (!$loop->last) · @endif
                    @endforeach
                </td>
                <td>
                    @if ($info['traffic']['measured'] ?? false)
                        <strong>{{ number_format($info['traffic']['hits_30d']) }}</strong>
                        @if ($info['traffic']['hits_30d'] === 0)
                            <x-k.badge tone="success">{{ translate('retirable') }}</x-k.badge>
                        @endif
                    @else
                        <span class="dev-muted">{{ translate('not_measured') }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-k.card>
