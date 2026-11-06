{{-- The switches that govern every call to a channel, read-only: they are set on the server, not here.
     Expects $globalEnabled and $externalWritesEnabled; optional $managedDispatchEnabled. --}}
<x-k.card :title="translate('server_switches')" class="mb-3">
    <p class="fs-12 text-muted">{{ translate('server_switches_help') }}</p>
    <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
        <li class="d-flex flex-wrap gap-2 align-items-start">
            <x-k.badge :tone="$globalEnabled ? 'success' : 'danger'">{{ $globalEnabled ? translate('switched_on') : translate('switched_off') }}</x-k.badge>
            <span class="fs-12"><strong>{{ translate('channel_integrations') }}</strong> <code>SALES_CHANNELS_ENABLED</code>
                <span class="d-block text-muted">{{ $globalEnabled ? translate('channel_integrations_on_help') : translate('channel_integrations_off_help') }}</span></span>
        </li>
        <li class="d-flex flex-wrap gap-2 align-items-start">
            <x-k.badge :tone="$externalWritesEnabled ? 'warning' : 'success'">{{ $externalWritesEnabled ? translate('switched_on') : translate('switched_off') }}</x-k.badge>
            <span class="fs-12"><strong>{{ translate('external_writes') }}</strong> <code>SALES_CHANNELS_EXTERNAL_WRITES_ENABLED</code>
                <span class="d-block text-muted">{{ $externalWritesEnabled ? translate('external_writes_on_help') : translate('external_writes_off_help') }}</span></span>
        </li>
        @isset($managedDispatchEnabled)
            <li class="d-flex flex-wrap gap-2 align-items-start">
                <x-k.badge :tone="$managedDispatchEnabled ? 'warning' : 'neutral'">{{ $managedDispatchEnabled ? translate('switched_on') : translate('not_available') }}</x-k.badge>
                <span class="fs-12"><strong>{{ translate('create_provider_shipments_for_channel_orders') }}</strong> <code>sales_channels.managed_dispatch_enabled</code>
                    <span class="d-block text-muted">{{ translate('managed_dispatch_not_available_help') }}</span></span>
            </li>
        @endisset
    </ul>
</x-k.card>
