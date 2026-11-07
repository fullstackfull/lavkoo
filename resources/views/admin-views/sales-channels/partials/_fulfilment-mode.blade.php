{{-- Who fulfils an order. A mode the channel did not state is shown as unknown and never as merchant-fulfilled. Expects $mode (FulfillmentMode). --}}
<x-k.badge :tone="$mode->isFulfilledByChannel() ? 'info' : ($mode->value === 'unknown' ? 'warning' : 'neutral')" :dot="false">{{ translate($mode->labelKey()) }}</x-k.badge>
