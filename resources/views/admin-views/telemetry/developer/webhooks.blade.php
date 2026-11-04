{{--
    Outbound webhooks: the contract, and whether this deployment is keeping it.

    This section was declared in the navigation, its capability probe returned true so it rendered
    enabled, and it opened onto an empty card — while a complete signed-delivery system sat behind
    it: six events, an HMAC signature, an SSRF-guarded dialler, a retry ledger and an auto-disable
    rule. Everything an integrator needs was in the code and nowhere they could read it.

    Every number here is read from the running system, so this page cannot describe a delivery
    guarantee the platform does not make.
--}}

@php($contract = $data['contract'] ?? [])
@php($health = $contract['delivery_health'] ?? ['state' => 'unavailable'])

<x-k.card :title="translate('the_events_you_can_subscribe_to')">
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead><tr><th>{{ translate('event') }}</th><th>{{ translate('sent_when') }}</th></tr></thead>
            <tbody>
            @foreach ($contract['events'] ?? [] as $event)
                <tr>
                    <td><code>{{ $event['event'] }}</code></td>
                    <td>{{ $event['meaning'] ? translate($event['meaning']) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-k.card>

<x-k.card :title="translate('verifying_a_delivery')">
    @php($signature = $contract['signature'] ?? [])
    <p class="mon-note" style="margin-block-start:0">
        {{ translate('every_delivery_is_signed_without_a_signature_a_webhook_endpoint_is_a_url_that_does_something_when_anybody_posts_to_it') }}.
    </p>
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <tbody>
                <tr><td>{{ translate('header') }}</td><td><code>{{ $signature['header'] ?? '' }}</code></td></tr>
                <tr><td>{{ translate('algorithm') }}</td><td><code>{{ $signature['algorithm'] ?? '' }}</code></td></tr>
                <tr><td>{{ translate('signed_over') }}</td><td>{{ translate($signature['signed_over'] ?? '') }}</td></tr>
                <tr><td>{{ translate('the_secret') }}</td><td>{{ translate($signature['secret_shown'] ?? '') }}</td></tr>
                <tr><td>{{ translate('other_headers') }}</td>
                    <td>@foreach ($signature['other_headers'] ?? [] as $header)<code>{{ $header }}</code>{{ $loop->last ? '' : ', ' }}@endforeach</td></tr>
            </tbody>
        </table>
    </div>
</x-k.card>

<x-k.card :title="translate('what_happens_when_your_endpoint_is_down')">
    @php($retries = $contract['retries'] ?? [])
    @php($disable = $contract['auto_disable'] ?? [])
    <div class="k-stats">
        <x-k.stat :label="translate('attempts')" :value="$retries['max_attempts'] ?? '—'" icon="reports" />
        <x-k.stat :label="translate('first_retry')" :value="($retries['first_retry_minutes'] ?? '—') . ' ' . translate('minutes')" icon="clock"
                  :caption="translate('doubling_each_attempt')" />
        <x-k.stat :label="translate('total_window')" :value="($retries['total_window_minutes'] ?? '—') . ' ' . translate('minutes')" icon="trend-up"
                  :caption="translate('plan_your_outage_window_against_this')" />
        <x-k.stat :label="translate('timeout')" :value="($retries['timeout_seconds'] ?? '—') . 's'" icon="alert" />
        <x-k.stat :label="translate('switched_off_after')" :value="$disable['after_consecutive_failures'] ?? '—'" icon="settings"
                  :caption="translate('consecutive_failures')" />
    </div>
    <p class="mon-note">
        {{ translate('a_switched_off_endpoint_is_cleared_by') }}: {{ translate($disable['cleared_by'] ?? '') }}.
        {{ translate('the_retry_sweep_is') }} <code>{{ $retries['sweep'] ?? '' }}</code>.
    </p>
</x-k.card>

<x-k.card :title="translate('where_we_will_and_will_not_deliver')">
    @php($rules = $contract['destination_rules'] ?? [])
    <ul class="mon-note" style="margin-block-start:0">
        <li>{{ translate('https_only') }}</li>
        <li>{{ translate('refused') }}: {{ translate($rules['refused'] ?? '') }}</li>
        <li>{{ translate('redirects_are_not_followed') }}</li>
    </ul>
</x-k.card>

@if (($health['state'] ?? '') === 'ok')
    {{-- The same page saying whether the promise above is being kept here, rather than only that it
         was made. --}}
    <x-k.card :title="translate('delivery_on_this_deployment')">
        <div class="k-stats">
            <x-k.stat :label="translate('endpoints')" :value="$health['endpoints']" icon="plug" />
            <x-k.stat :label="translate('active')" :value="$health['active']" icon="check" />
            <x-k.stat :label="translate('switched_off_by_us')" :value="$health['auto_disabled']" icon="alert" />
            <x-k.stat :label="translate('waiting_to_retry')" :value="$health['pending']" icon="clock" />
            <x-k.stat :label="translate('given_up_on')" :value="$health['failed']" icon="warning-octagon" />
            <x-k.stat :label="translate('delivered_today')" :value="$health['delivered_today']" icon="trend-up" />
        </div>
    </x-k.card>
@endif
