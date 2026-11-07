{{-- One channel listing. Expects $listing, $products (id => Product), $locations (collection of id/name), $priceSources, $shareModes, $labels, $errors. --}}
@php
    $account = $listing->account;
    $bag = 'listing_' . $listing->id;
    $linked = $listing->product_id === null ? null : $products->get($listing->product_id);
    $suggested = $listing->suggested_product_id === null ? null : $products->get($listing->suggested_product_id);
    $publish = (array) ($listing->snapshot['publish'] ?? []);
    $currency = $listing->remote_currency ?? $account?->currency;
    $statusTone = match ($listing->status) {
        'mapped' => 'success',
        'suggested' => 'info',
        'conflict' => 'danger',
        'unmapped' => 'warning',
        default => 'neutral',
    };
@endphp
<tr id="listing-{{ $listing->id }}">
    <td>
        <div class="fw-semibold text-break">{{ $listing->external_title ?: $listing->listing_key }}</div>
        <div class="fs-12 text-muted text-break"><code>{{ $listing->listing_key }}</code></div>
        <div class="fs-12 text-muted">{{ translate('sku') }}: {{ $listing->external_sku ?? '—' }} · {{ translate('barcode') }}: {{ $listing->external_barcode ?? '—' }} · {{ translate('channel_id') }}: {{ $listing->external_id ?? '—' }}</div>
        <div class="fs-12">
            {{ $account?->name }} <span class="text-muted">· {{ translate('seller') }}: {{ ($account?->seller_id ?? 0) === 0 ? translate('platform') : '#' . $account->seller_id }}</span>
            <x-k.badge :tone="$statusTone">{{ $labels->listingStatus($listing->status) }}</x-k.badge>
            @if ($listing->fulfillment_mode)<x-k.badge :tone="$listing->fulfillment_mode === 'fba' ? 'info' : 'neutral'" :dot="false">{{ strtoupper($listing->fulfillment_mode) }}</x-k.badge>@endif
        </div>
    </td>
    <td class="fs-12">
        <div>{{ translate('price') }}: @include('admin-views.sales-channels.partials._money', ['amount' => $listing->remote_price, 'currency' => $currency])</div>
        <div>{{ translate('quantity') }}:
            @if ($listing->remote_quantity === null)@include('admin-views.sales-channels.partials._missing', ['label' => translate('unknown_value')])@else<span class="k-num">{{ $listing->remote_quantity }}</span>@endif</div>
        <div>{{ translate('channel_status') }}: {{ $listing->remote_status ?? '—' }}@if ($listing->remote_status === 'not_seen') <x-k.badge tone="warning" :dot="false">{{ translate('not_seen_in_the_last_full_read') }}</x-k.badge>@endif</div>
        <div class="text-muted">{{ translate('last_seen') }}: {{ $listing->last_seen_at?->diffForHumans() ?? '—' }}</div>
    </td>
    <td class="fs-12">
        @if ($linked)
            <div class="fw-semibold">{{ $linked->name }}</div>
            <div class="text-muted">#{{ $linked->id }} · {{ $linked->code ?? '—' }}@if ($listing->variant_key !== '') · {{ $listing->variant_key }}@endif</div>
            <div class="text-muted">{{ $labels->matchMethod($listing->match_method) }}</div>
        @elseif ($listing->product_id !== null)
            <div>#{{ $listing->product_id }}</div>
        @else
            @include('admin-views.sales-channels.partials._missing', ['label' => translate('not_linked')])
        @endif
        @if ($listing->status === 'suggested' && $listing->suggested_product_id)
            <div class="mt-1">
                <x-k.badge tone="info" :dot="false">{{ translate('suggestion') }}</x-k.badge>
                {{ $suggested?->name ?? '#' . $listing->suggested_product_id }} <span class="text-muted">({{ $labels->matchMethod($listing->match_method) }})</span>
            </div>
        @elseif ($listing->status === 'conflict')
            <div class="mt-1 text-danger">{{ translate('more_than_one_product_fits_or_it_is_linked_elsewhere') }} ({{ $labels->matchMethod($listing->match_method) }})</div>
        @endif
    </td>
    <td class="fs-12">
        @foreach (['stock', 'price'] as $field)
            @php
                $state = $publish[$field] ?? null;
                $optedIn = (bool) ($field === 'stock' ? $listing->inventory_publish : $listing->price_publish);
                $sent = $field === 'stock' ? $listing->last_published_quantity : $listing->last_published_price;
                $shown = $field === 'stock' ? $listing->remote_quantity : $listing->remote_price;
                $stateTone = match ($state['state'] ?? null) {
                    'applied' => 'success',
                    'queued' => 'info',
                    'failed' => 'danger',
                    'unconfirmed' => 'warning',
                    default => 'neutral',
                };
            @endphp
            <div class="mb-2">
                <div class="fw-semibold">{{ translate($field === 'stock' ? 'stock' : 'price') }}
                    <x-k.badge :tone="$optedIn ? 'warning' : 'neutral'" :dot="false">{{ $optedIn ? translate('opted_in_to_publishing') : translate('not_published') }}</x-k.badge></div>
                <div class="text-muted">{{ translate('we_last_sent') }}: {{ $sent === null ? '—' : ($field === 'stock' ? $sent : number_format((float) $sent, 2)) }}
                    @if ($listing->last_published_at && $sent !== null) ({{ $listing->last_published_at->diffForHumans() }})@endif</div>
                <div class="text-muted">{{ translate('the_channel_shows') }}: {{ $shown === null ? translate('unknown_value') : ($field === 'stock' ? $shown : number_format((float) $shown, 2)) }}</div>
                @if ($state)
                    <div><x-k.badge :tone="$stateTone">{{ $labels->publishState($state['state'] ?? null) }}</x-k.badge>
                        @if (isset($state['attempted']))<span class="text-muted">{{ translate('attempted') }}: {{ $state['attempted'] }}</span>@endif</div>
                    @if (!empty($state['error']))<div class="text-danger text-break">{{ $state['error'] }}</div>@endif
                @endif
            </div>
        @endforeach
    </td>
    <td style="min-inline-size: 260px">
        @foreach ($errors->getBag($bag)->all() as $message)<div class="text-danger fs-12" role="alert">{{ $message }}</div>@endforeach
        @include('admin-views.sales-channels.partials._listing-actions')
    </td>
</tr>
