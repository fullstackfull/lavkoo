{{-- One capability's state. Expects $capability (App\Services\Fulfillment\Data\CapabilityState). --}}
@php
    $capabilityTone = match ($capability->state) {
        \App\Services\Fulfillment\Data\CapabilityState::ENABLED => 'success',
        \App\Services\Fulfillment\Data\CapabilityState::READY_DISABLED => 'neutral',
        \App\Services\Fulfillment\Data\CapabilityState::NEEDS_CONFIGURATION, \App\Services\Fulfillment\Data\CapabilityState::UNVERIFIED => 'warning',
        \App\Services\Fulfillment\Data\CapabilityState::UNDOCUMENTED => 'danger',
        default => 'info',
    };
@endphp
<x-k.badge :tone="$capabilityTone">{{ translate('capability_state_' . $capability->state) }}</x-k.badge>
