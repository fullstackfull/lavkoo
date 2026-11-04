<x-k.card :title="translate('api_health')">
    @if ($data['api']['measured'])
        <div class="dev-metrics">
            <div><span class="k-num">{{ number_format($data['api']['requests']) }}</span><small>{{ translate('requests') }}</small></div>
            <div><span class="k-num">{{ number_format($data['api']['errors']) }}</span><small>{{ translate('errors') }}</small></div>
            <div><span class="k-num">{{ $data['api']['error_rate'] }}%</span><small>{{ translate('error_rate') }}</small></div>
            <div><span class="k-num">{{ $data['api']['p95'] ?? '—' }}<i>ms</i></span><small>p95</small></div>
            <div><span class="k-num">{{ $data['api']['p99'] ?? '—' }}<i>ms</i></span><small>p99</small></div>
            <div><span class="k-num">{{ $data['api']['requests_per_minute'] }}</span><small>{{ translate('per_minute') }}</small></div>
        </div>
        <p class="dev-note">{{ translate('measured_from_the_same_request_buckets_the_monitoring_area_reads_so_the_two_can_never_disagree') }}</p>
    @else
        <x-k.empty :title="translate('not_measured')" :text="$data['api']['reason']" />
    @endif
</x-k.card>
