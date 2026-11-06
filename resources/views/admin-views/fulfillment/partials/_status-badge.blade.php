{{-- A provider fulfillment's status as a badge. Expects $status (App\Enums\Fulfillment\FulfillmentStatus). --}}
@php
    $statusTone = match ($status) {
        \App\Enums\Fulfillment\FulfillmentStatus::Delivered => 'success',
        \App\Enums\Fulfillment\FulfillmentStatus::Failed, \App\Enums\Fulfillment\FulfillmentStatus::DeliveryFailed, \App\Enums\Fulfillment\FulfillmentStatus::NeedsAttention => 'danger',
        \App\Enums\Fulfillment\FulfillmentStatus::Held, \App\Enums\Fulfillment\FulfillmentStatus::ReconcilePending, \App\Enums\Fulfillment\FulfillmentStatus::RetryWait,
        \App\Enums\Fulfillment\FulfillmentStatus::DeliveryPostponed, \App\Enums\Fulfillment\FulfillmentStatus::DryRun => 'warning',
        \App\Enums\Fulfillment\FulfillmentStatus::Submitted, \App\Enums\Fulfillment\FulfillmentStatus::Processing,
        \App\Enums\Fulfillment\FulfillmentStatus::InTransit, \App\Enums\Fulfillment\FulfillmentStatus::OutForDelivery, \App\Enums\Fulfillment\FulfillmentStatus::Dispatching => 'info',
        \App\Enums\Fulfillment\FulfillmentStatus::PartiallyDelivered => 'accent',
        default => 'neutral',
    };
@endphp
<x-k.badge :tone="$statusTone">{{ translate($status->labelKey()) }}</x-k.badge>
