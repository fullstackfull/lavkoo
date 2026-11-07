{{-- An order's status at the channel, as a badge. Expects $status (ChannelOrderStatus). --}}
@use('App\Enums\SalesChannels\ChannelOrderStatus')
@php
    $statusTone = match ($status) {
        ChannelOrderStatus::Delivered, ChannelOrderStatus::Shipped => 'success',
        ChannelOrderStatus::Cancelled, ChannelOrderStatus::Returned, ChannelOrderStatus::Failed => 'danger',
        ChannelOrderStatus::Pending, ChannelOrderStatus::AwaitingReview => 'warning',
        ChannelOrderStatus::Processing => 'info',
        default => 'neutral',
    };
@endphp
<x-k.badge :tone="$statusTone">{{ translate($status->labelKey()) }}</x-k.badge>
