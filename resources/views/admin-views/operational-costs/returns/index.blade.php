@extends('layouts.admin.app')

@section('title', translate('returns_awaiting_a_decision'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('returns_awaiting_a_decision')" :subtitle="translate('returns_awaiting_a_decision_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.index') }}">{{ translate('operational_costs_overview') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.operational-costs.partials._module-state')

        <div class="alert alert-info fs-12" role="note">{{ translate('return_decisions_help') }}</div>

        @forelse ($lines as $line)
            <x-k.card class="mb-3">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div class="fs-12">
                        @if ($line->order)
                            <a href="{{ route('admin.sales-channels.orders.show', $line->order) }}">{{ $line->order->external_order_id }}</a>
                            <span class="text-muted">· {{ $line->order->account?->name }} · {{ $line->order->purchased_at?->format('Y-m-d') }}</span>
                        @endif
                        @if ($line->product_id)<span class="text-muted">· {{ $products[$line->product_id] ?? '#' . $line->product_id }}</span>@endif
                    </div>
                    @if ($line->order)
                        <a class="fs-12" href="{{ route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $line->order->id]) }}">{{ translate('profit_of_a_sale') }}</a>
                    @endif
                </div>
                @include('admin-views.operational-costs.partials._return-decision', ['line' => $line, 'standing' => $standing[$line->id], 'locations' => $locations, 'canDecide' => true])
            </x-k.card>
        @empty
            <x-k.card>
                <x-k.empty icon="check" :title="translate('no_returns_awaiting_a_decision')" :text="translate('no_returns_awaiting_a_decision_help')" />
            </x-k.card>
        @endforelse

        {{ $lines->links() }}
    </div>
@endsection
