@extends('layouts.seller.app')

@section('title', translate('nav_movements'))

@php
    $columns = [
        ['key' => 'time', 'label' => translate('timestamp'), 'width' => 150],
        ['key' => 'sku', 'label' => translate('sku'), 'width' => 130],
        ['key' => 'product', 'label' => translate('product')],
        ['key' => 'type', 'label' => translate('type'), 'width' => 110],
        ['key' => 'change', 'label' => translate('change'), 'width' => 80, 'num' => true],
        ['key' => 'before', 'label' => translate('before'), 'width' => 80, 'num' => true, 'priority' => 'md'],
        ['key' => 'after', 'label' => translate('after'), 'width' => 80, 'num' => true],
        ['key' => 'reference', 'label' => translate('reference'), 'width' => 160, 'priority' => 'lg'],
        ['key' => 'by', 'label' => translate('by'), 'width' => 140, 'priority' => 'md'],
    ];

    $typeUrl = fn ($type) => request()->fullUrlWithQuery(['type' => $type, 'page' => null]);
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_inventory')" :title="translate('nav_movements')"
                      :sub="translate('every_change_carries_the_balance_it_left_behind_the_log_reads_without_replaying_it')"
                      :back="route('seller.inventory.index')"
                      :crumbs="[
                          ['label' => translate('nav_inventory'), 'href' => route('seller.inventory.index')],
                          ['label' => translate('nav_movements')],
                      ]">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="download-simple">{{ translate('export') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-toolbar">
            {{-- Movement types come from the server; the client never carries its own list. --}}
            <a class="sc-btn sc-btn--sm {{ request('type') ? 'sc-btn--ghost' : 'sc-btn--secondary' }}" href="{{ $typeUrl(null) }}">
                {{ translate('all') }}
            </a>
            @foreach ($types as $type)
                <a class="sc-btn sc-btn--sm {{ request('type') === $type ? 'sc-btn--secondary' : 'sc-btn--ghost' }}"
                   href="{{ $typeUrl($type) }}">{{ translate($type) }}</a>
            @endforeach
            <div class="sc-spacer"></div>
            <span class="sc-toolbar__count">{{ \App\Services\SellerCenter\Copy::line('n_movements', ['count' => $movements->total()]) }}</span>
        </div>

        <x-sc.table :columns="$columns" :state="$state" :cards="false">
            <x-slot:empty>
                <x-sc.empty glyph="list" :title="translate('no_movements_recorded_in_this_period')"
                            :text="translate('stock_changes_appear_here_with_the_balance_they_left_behind')" />
            </x-slot:empty>
            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_movements_match_these_filters')"
                            :text="translate('adjust_or_clear_the_filters_to_see_more')" />
            </x-slot:noResults>

            @foreach ($movements as $movement)
                @php($isAdjustment = $movement->type === \App\Models\StockMovement::TYPE_ADJUSTMENT)
                <x-sc.tr :id="$movement->id">
                    <x-sc.td class="sc-muted sc-ts">{{ \App\Services\SellerCenter\Moment::stamp($movement->created_at, withYear: true) }}</x-sc.td>
                    <x-sc.td class="sc-code">{{ $movement->product?->code ?: '—' }}</x-sc.td>
                    {{-- A movement outlives the product it describes: the log is the record of what
                         happened, so a deleted product leaves its id rather than an empty row. --}}
                    <x-sc.td>{{ $movement->product?->getRawOriginal('name') ?: ('#' . $movement->product_id) }}</x-sc.td>
                    <x-sc.td>
                        @if ($isAdjustment)
                            <x-sc.badge tone="high" glyph="sliders-horizontal" :label="translate($movement->type)" />
                        @else
                            <span class="sc-muted">{{ translate($movement->type) }}</span>
                        @endif
                    </x-sc.td>
                    <x-sc.td num :tone="(int) $movement->qty_change > 0 ? 'good' : null">
                        {{ (int) $movement->qty_change > 0 ? '+' : '' }}{{ (int) $movement->qty_change }}
                    </x-sc.td>
                    <x-sc.td num drop="md" class="sc-muted">
                        {{ $movement->balance_after === null ? '—' : (int) $movement->balance_after - (int) $movement->qty_change }}
                    </x-sc.td>
                    {{-- Rendered, never recomputed: the ledger reads without replaying it. --}}
                    <x-sc.td num>{{ $movement->balance_after === null ? '—' : (int) $movement->balance_after }}</x-sc.td>
                    <x-sc.td drop="lg" class="sc-muted" :sub="$movement->note">
                        {{ $movement->reason ? translate($movement->reason) : ($movement->reference_type ? translate($movement->reference_type) . ' ' . $movement->reference_id : '—') }}
                    </x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">
                        {{ $movement->created_by_type ? translate($movement->created_by_type) : translate('system') }}
                    </x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:footer><x-sc.pager :paginator="$movements" /></x-slot:footer>
        </x-sc.table>
    </div>
@endsection
