{{-- One row in any endpoint list. Shared by the explorer and every per-audience section so the
     four screens cannot drift into showing different things about the same endpoint. --}}
@php($method = $endpoint['methods'][0] ?? 'GET')
<a class="dev-row" href="{{ route('admin.developer.endpoint', ['id' => $endpoint['id']]) }}">
    <span class="dev-method dev-method--{{ strtolower($method) }}">{{ $method }}</span>

    <span class="dev-row__path">
        <code>{{ $endpoint['path'] }}</code>
        <small>{{ $endpoint['summary'] }}</small>
    </span>

    <span class="dev-row__tags">
        @if ($endpoint['auth']['required'])
            <x-k.badge tone="info">{{ translate($endpoint['auth']['mechanism']) }}</x-k.badge>
        @elseif ($endpoint['auth']['optional_auth'] ?? false)
            <x-k.badge tone="neutral">{{ translate('guest_or_customer') }}</x-k.badge>
        @else
            <x-k.badge tone="neutral">{{ translate('public') }}</x-k.badge>
        @endif

        @if ($endpoint['deprecated'])
            <x-k.badge tone="warning">{{ translate('deprecated') }}</x-k.badge>
        @endif

        @if (($endpoint['rate_limit']['requests'] ?? null) !== null)
            <span class="dev-row__limit" title="{{ translate('rate_limit') }}">
                {{ $endpoint['rate_limit']['requests'] }}/{{ $endpoint['rate_limit']['minutes'] }}m
            </span>
        @endif
    </span>

    {{-- Health, where it has been measured. An endpoint nobody has called shows a dash rather
         than a green tick: no traffic is not the same as no errors. --}}
    <span class="dev-row__health" data-state="{{ $endpoint['health']['status'] ?? 'no_traffic' }}">
        @if ($endpoint['health']['measured'] ?? false)
            <strong>{{ number_format($endpoint['health']['hits']) }}</strong>
            <small>
                {{ $endpoint['health']['error_rate'] }}% · p95 {{ $endpoint['health']['p95'] ?? '—' }}ms
            </small>
        @else
            <strong class="dev-muted">—</strong>
            <small class="dev-muted">{{ translate('no_traffic') }}</small>
        @endif
    </span>
</a>
