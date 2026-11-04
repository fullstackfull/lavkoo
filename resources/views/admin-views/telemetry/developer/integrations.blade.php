{{--
    Integrations: what POSTs into this shop, and what this shop calls out to.

    The inbound list is the reason this page is not decoration. Twelve payment callbacks and a
    courier status webhook sit outside the `api/` prefix, so the explorer, the OpenAPI export and
    the coverage score all skip them — and they are exactly the routes most likely to be pointed at
    the wrong host during a migration.
--}}

<x-k.card :title="translate('endpoints_other_systems_post_into')">
    @if (empty($data['inbound']))
        <x-k.empty icon="plug" :title="translate('nothing_posts_into_this_shop')" :text="''" />
    @else
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead><tr>
                    <th>{{ translate('endpoint') }}</th><th>{{ translate('method') }}</th>
                    <th>{{ translate('authentication') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($data['inbound'] as $endpoint)
                    <tr>
                        <td><code>/{{ $endpoint['uri'] }}</code>
                            @if ($endpoint['name'])<small class="mon-metric__source" style="display:block">{{ $endpoint['name'] }}</small>@endif
                        </td>
                        <td>@foreach ($endpoint['methods'] as $method)<code>{{ $method }}</code>@endforeach</td>
                        <td>
                            {{-- A callback with no guard is a URL anybody can POST an order state
                                 into, which is worth naming rather than leaving to be discovered. --}}
                            <span class="mon-pill mon-pill--{{ $endpoint['guarded'] ? 'ok' : 'missed' }}">
                                {{ $endpoint['guarded'] ? translate('guarded') : translate('unauthenticated') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-k.card>

<x-k.card :title="translate('payment_gateways_this_shop_calls_out_to')">
    @php($gateways = $data['outbound']['payment_gateways'] ?? [])
    @if ($gateways === [])
        <x-k.empty icon="wallet" :title="translate('no_payment_gateway_is_configured')" :text="''" />
    @else
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead><tr>
                    <th>{{ translate('gateway') }}</th><th>{{ translate('switched') }}</th>
                    <th>{{ translate('mode') }}</th><th>{{ translate('verdict') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($gateways as $gateway)
                    <tr class="{{ $gateway['active'] ? '' : 'mon-row--muted' }}">
                        <td><code>{{ $gateway['gateway'] }}</code></td>
                        <td>{{ $gateway['active'] ? translate('on') : translate('off') }}</td>
                        <td>{{ $gateway['mode'] ?: '—' }}</td>
                        <td>
                            @if ($gateway['ready'])
                                <span class="mon-pill mon-pill--ok">{{ translate('ready') }}</span>
                                @if ($gateway['rehearsing'])
                                    <small class="mon-metric__note" style="display:block">{{ translate('test_mode_no_money_moves') }}</small>
                                @endif
                            @else
                                <span class="mon-pill mon-pill--critical">{{ $gateway['verdict'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-k.card>
