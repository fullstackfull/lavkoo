@extends('layouts.admin.app')

@section('title', translate('Stock_by_Location'))

@section('content')
    @php
        $qualityTone = static fn (string $quality): string => match ($quality) {
            'confirmed' => 'success',
            'manual' => 'info',
            'stale' => 'warning',
            default => 'neutral',
        };
        $figure = static fn (?int $value): string => $value === null ? '—' : number_format($value);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Stock_by_Location')" :subtitle="translate('stock_by_location_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">{{ translate('stock_by_location_note') }}</div>
        <div class="fs-12 text-muted mb-3">{{ translate('stock_net_note') }}</div>

        @if ($selected === null)
            <x-k.card>
                <x-k.empty icon="store" :title="translate('no_stock_location_yet')" :text="translate('no_stock_location_yet_help')" />
            </x-k.card>
        @else
            <x-k.tabs class="mb-3" :items="$locations->map(fn ($location) => [
                'label' => $location->name,
                'href' => route('admin.sales-channels.inventory.index', ['location' => $location->id]),
                'active' => $location->id === $selected->id,
            ])->all()" />

            <x-k.card class="mb-3">
                <dl class="row mb-0 fs-12">
                    <dt class="col-sm-3">{{ translate('type') }}</dt>
                    <dd class="col-sm-9">{{ $labels->locationType($selected->type) }}</dd>
                    <dt class="col-sm-3">{{ translate('source_of_the_figures') }}</dt>
                    <dd class="col-sm-9">{{ $labels->locationSource($selected->source_of_truth) }}</dd>
                    @if ($selected->sales_channel_account_id)
                        <dt class="col-sm-3">{{ translate('account') }}</dt>
                        <dd class="col-sm-9">{{ $accounts->get($selected->sales_channel_account_id) ?? '#' . $selected->sales_channel_account_id }}</dd>
                    @endif
                    <dt class="col-sm-3">{{ translate('sold_from_the_store') }}</dt>
                    <dd class="col-sm-9">{{ $selected->storefront_sellable ? translate('yes') : translate('no') }}</dd>
                    <dt class="col-sm-3">{{ translate('offered_to_channels') }}</dt>
                    <dd class="col-sm-9">{{ $selected->publishable_channels ? implode(', ', $selected->publishable_channels) : translate('none_of_them') }}</dd>
                    <dt class="col-sm-3">{{ translate('skus') }}</dt>
                    <dd class="col-sm-9">{{ number_format($skus) }}</dd>
                </dl>
            </x-k.card>

            <x-k.card class="mb-3">
                <form method="GET" action="{{ route('admin.sales-channels.inventory.index') }}" class="row g-2 align-items-end">
                    <input type="hidden" name="location" value="{{ $selected->id }}">
                    <div class="col-md-4">
                        <label class="form-label fs-12" for="stock-sku">{{ translate('sku') }}</label>
                        <input class="form-control form-control-sm" id="stock-sku" name="sku" value="{{ $sku }}">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.inventory.index', ['location' => $selected->id]) }}">{{ translate('reset') }}</a>
                    </div>
                </form>
            </x-k.card>

            <x-k.card :padded="false">
                <div class="k-table-wrap table-responsive">
                    <table class="k-table">
                        <thead><tr>
                            <th>{{ translate('sku') }}</th><th>{{ translate('product') }}</th>
                            <th class="k-table__num">{{ translate('available') }}</th><th class="k-table__num">{{ translate('held_by_open_orders') }}</th>
                            <th class="k-table__num">{{ translate('held_by_store_orders') }}</th><th class="k-table__num">{{ translate('net_to_offer') }}</th>
                            @if ($selected->provider === null)
                                <th class="k-table__num">{{ translate('reserved') }}</th><th class="k-table__num">{{ translate('unfulfillable') }}</th>
                                <th class="k-table__num">{{ translate('inbound') }}</th><th class="k-table__num">{{ translate('total') }}</th>
                            @endif
                            <th>{{ translate('quality') }}</th><th>{{ translate('last_synced') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($rows as $row)
                            @php($product = $row['product_id'] ? $products->get($row['product_id']) : null)
                            <tr>
                                <td class="fs-12 text-break">{{ $row['sku'] ?? '—' }}@if ($row['variant_key'] !== '')<div class="fs-10 text-muted text-break">{{ $row['variant_key'] }}</div>@endif</td>
                                <td class="fs-12">{{ $product?->name ?? ($row['product_id'] ? '#' . $row['product_id'] : translate('not_linked_to_a_product')) }}
                                    @if ($row['members'] !== [])
                                        <details class="mt-1">
                                            <summary>{{ translate('listings_sharing_this_stock') }} ({{ count($row['members']) }})</summary>
                                            @foreach ($row['members'] as $member)
                                                @php($share = (array) $member->allocation)
                                                @php($publishState = $member->snapshot['publish']['stock']['state'] ?? null)
                                                <div class="text-break mt-1">
                                                    {{ $member->account?->name }} · <code>{{ $member->listing_key }}</code>
                                                    · {{ ($share['rule'] ?? 'equal') === 'equal' ? translate('stock_share_equal') : $labels->stockShareMode($share['rule']) }}
                                                    · {{ translate('stock_entitled') }}: {{ $share['entitlement'] ?? '—' }}
                                                    @if (($share['lag'] ?? 0) > 0) · {{ translate('stock_expected_sales_margin') }}: {{ $share['lag'] }}@endif
                                                    · {{ translate('stock_may_be_shown') }}: {{ $share['publish'] ?? '—' }}
                                                    · {{ translate('we_last_sent') }}: {{ $member->last_published_quantity ?? '—' }}
                                                    · {{ translate('the_channel_shows') }}: {{ $member->remote_quantity ?? '—' }}
                                                    @if ($publishState) · <x-k.badge tone="neutral" :dot="false">{{ $labels->publishState($publishState) }}</x-k.badge>@endif
                                                    @if (!empty($share['reason'])) · {{ $labels->publishSkip($share['reason']) }}@elseif (!empty($share['raising_blocked_by'])) · {{ $labels->publishSkip($share['raising_blocked_by']) }}@endif
                                                </div>
                                            @endforeach
                                        </details>
                                    @endif
                                </td>
                                <td class="fs-12 k-table__num">{{ $figure($row['availability']->available) }}</td>
                                <td class="fs-12 k-table__num">{{ $row['availability']->available === null ? '—' : number_format($row['availability']->inFlight) }}@foreach ($row['holds'] as $channel => $units)<div class="fs-10 text-muted">{{ $channel === '' ? translate('channel_not_recorded') : $channel }}: {{ number_format($units) }}</div>@endforeach</td>
                                <td class="fs-12 k-table__num">{{ $row['availability']->available === null ? '—' : number_format($row['pool']->storeHolds) }}</td>
                                <td class="fs-12 k-table__num">{{ $figure($row['pool']->net()) }}@if ($row['pool']->buffer > 0)<div class="fs-10 text-muted">{{ translate('pool_buffer') }}: {{ number_format($row['pool']->buffer) }}</div>@endif @if ($row['pool']->deficit() > 0)<div><x-k.badge tone="danger" :dot="false">{{ translate('promised_beyond_the_figure') }}: {{ number_format($row['pool']->deficit()) }}</x-k.badge></div>@endif</td>
                                @if ($selected->provider === null)
                                    <td class="fs-12 k-table__num">{{ $figure($row['extras']['reserved'] ?? null) }}</td>
                                    <td class="fs-12 k-table__num">{{ $figure($row['extras']['unfulfillable'] ?? null) }}</td>
                                    <td class="fs-12 k-table__num">{{ $figure($row['extras']['inbound'] ?? null) }}</td>
                                    <td class="fs-12 k-table__num">{{ $figure($row['extras']['total'] ?? null) }}</td>
                                @endif
                                <td><x-k.badge :tone="$qualityTone($row['availability']->quality)">{{ $labels->stockQuality($row['availability']->quality) }}</x-k.badge>@if ($row['pool']->refusal)<div class="fs-10 text-warning">{{ $labels->publishSkip($row['pool']->refusal) }}</div>@endif</td>
                                <td class="fs-12">{{ $row['availability']->syncedAt?->format('Y-m-d H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $selected->provider === null ? 12 : 8 }}">
                                @if ($sku)
                                    <x-k.empty icon="search" :title="translate('no_stock_figure_matches')" :text="translate('no_stock_figure_matches_help')">
                                        <x-slot:action><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.inventory.index', ['location' => $selected->id]) }}">{{ translate('reset') }}</a></x-slot:action>
                                    </x-k.empty>
                                @else
                                    <x-k.empty icon="clock" :title="translate('no_stock_figures_yet')" :text="translate('no_stock_figures_yet_help')" />
                                @endif
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($rows->hasPages())
                    <x-slot:footer>{{ $rows->links() }}</x-slot:footer>
                @endif
            </x-k.card>
        @endif
    </div>
@endsection
