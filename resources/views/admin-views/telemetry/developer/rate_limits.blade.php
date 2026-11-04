{{-- The limits configured on the routes, tightest first. Only real ones: an endpoint with no
     throttle middleware is not listed as "unlimited", because the group limit still applies and
     claiming otherwise would invite a client to hammer it. --}}
<x-k.card :title="translate('rate_limits')">
    @forelse ($data['limits'] as $limit)
        <div class="dev-limit">
            <div class="dev-limit__figure">
                <span class="k-num">{{ $limit['requests'] }}</span>
                <small>{{ translate('per') }} {{ $limit['minutes'] }} {{ translate('minute_s') }}</small>
            </div>
            <div class="dev-limit__endpoints">
                <span class="dev-muted">{{ count($limit['endpoints']) }} {{ translate('endpoints') }}</span>
                <ul>
                    @foreach (array_slice($limit['endpoints'], 0, 12) as $endpoint)
                        <li><code>{{ $endpoint }}</code></li>
                    @endforeach
                    @if (count($limit['endpoints']) > 12)
                        <li class="dev-muted">{{ translate('and') }} {{ count($limit['endpoints']) - 12 }} {{ translate('more') }}</li>
                    @endif
                </ul>
            </div>
        </div>
    @empty
        <x-k.empty
            :title="translate('no_endpoint_carries_its_own_rate_limit')"
            :text="translate('only_the_api_groups_own_throttle_applies_which_is_high_enough_to_be_no_real_protection_add_a_throttle_middleware_to_the_credential_endpoints_first')" />
    @endforelse
</x-k.card>
