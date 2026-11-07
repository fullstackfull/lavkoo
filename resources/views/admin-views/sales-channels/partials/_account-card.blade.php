{{-- One account on the hub. Expects $card (HubSummary::cards()) and $labels. --}}
@php
    $account = $card['account'];
    $writeState = $card['writeState'];
    $states = $card['states'];
    $modes = $card['modes'];
    $mark = static fn (array $query): string => route('admin.sales-channels.orders.index', ['account' => $account->id] + $query);
@endphp
<x-k.card :title="$account->name" class="h-100">
    <x-slot:actions>
        <x-k.badge :tone="$account->enabled ? 'success' : 'neutral'">{{ $account->enabled ? translate('enabled') : translate('disabled') }}</x-k.badge>
        <x-k.badge :tone="$writeState->tone()">{{ translate($writeState->labelKey()) }}</x-k.badge>
    </x-slot:actions>

    <div class="fs-12 text-muted mb-2">
        {{ translate($account->channelCode()?->labelKey() ?? 'channel') }} · {{ $labels->environment($account->environment) }}
        · {{ translate('seller') }}: {{ $account->seller_id === 0 ? translate('platform') : '#' . $account->seller_id }}
    </div>

    <div class="mb-3">
        <div class="fs-12 text-muted mb-1">{{ translate('switched_on_modes') }}</div>
        @forelse ($card['capabilities'] as $capability)
            <x-k.badge tone="info" :dot="false">{{ translate($capability->labelKey()) }}</x-k.badge>
        @empty
            <x-k.badge :dot="false">{{ translate('disabled_no_mode') }}</x-k.badge>
        @endforelse
    </div>

    <div class="mb-3 fs-12">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <strong>{{ translate('connection') }}</strong>
            <x-k.badge :tone="$account->connectionStatus()->isUsable() ? 'success' : ($account->connection_status === 'untested' ? 'neutral' : 'danger')">{{ translate($account->connectionStatus()->labelKey()) }}</x-k.badge>
            <span class="text-muted">{{ translate('last_checked') }}: {{ $account->connection_checked_at?->diffForHumans() ?? '—' }}</span>
        </div>
        @if ($account->missing_permissions)
            <div class="text-warning mt-1">{{ translate('missing_permissions') }}: <span class="text-break">{{ implode(', ', $account->missing_permissions) }}</span></div>
        @endif
        <div class="text-muted mt-1">{{ translate($writeState->helpKey()) }}</div>
    </div>

    <div class="mb-3 fs-12">
        <div class="fw-semibold mb-1">{{ translate('last_sync') }}</div>
        @foreach ($card['resources'] as $resource)
            @php($state = $states->get($resource))
            <div class="d-flex justify-content-between gap-2 border-bottom py-1">
                <span>{{ $labels->syncResource($resource) }}</span>
                <span class="text-muted">
                    @if ($state?->backoff_until?->isFuture())
                        <x-k.badge tone="warning" :dot="false">{{ translate('backing_off_until') }} {{ $state->backoff_until->format('H:i') }}</x-k.badge>
                    @endif
                    {{ $state?->last_success_at?->diffForHumans() ?? translate('never_run') }}
                </span>
            </div>
        @endforeach
    </div>

    <div class="k-stats mb-3">
        <x-k.stat :label="translate('unlinked_listings')" :value="$card['unlinkedListings']" :href="route('admin.sales-channels.products.index', ['account' => $account->id, 'unlinked' => 1])" />
        <x-k.stat :label="translate('orders_needing_review')" :value="$card['ordersNeedingReview']" :href="$mark(['review' => 1])" />
        <x-k.stat :label="translate('orders_held_for_a_person')" :value="$card['ordersHeld']" :href="$mark(['policy' => 'manual_review'])" />
        <x-k.stat :label="translate('orders_fulfilled_by_the_channel')" :value="$modes['channel']" :href="$mark(['mode' => 'fba'])" />
        <x-k.stat :label="translate('orders_fulfilled_by_the_merchant')" :value="$modes['merchant']" :href="$mark([])" />
        <x-k.stat :label="translate('orders_with_unknown_fulfilment')" :value="$modes['unknown']" :href="$mark(['mode' => 'unknown'])" />
    </div>

    <a class="btn btn-primary btn-sm" href="{{ route('admin.sales-channels.accounts.show', $account) }}">{{ translate('open_account') }}</a>
</x-k.card>
