@extends('layouts.admin.app')

@section('title', translate('bulk_operations'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);

    $tone = [
        'completed' => 'success', 'partial' => 'warning', 'failed' => 'danger',
        'processing' => 'info', 'queued' => 'neutral',
    ];
@endphp

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('bulk_operations')"
                         :subtitle="translate('price_and_stock_changes_sellers_made_in_bulk_and_how_far_each_got')" />

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        <x-k.card :padded="false" :title="translate('bulk_operations')">
            @if ($jobs === null)
                <x-k.empty icon="info" :title="translate('not_installed')" />
            @elseif ($jobs->isEmpty())
                <x-k.empty icon="orders" :title="translate('no_bulk_operations_yet')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('seller') }}</th>
                                <th>{{ translate('type') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th class="k-table__num">{{ translate('rows') }}</th>
                                <th>{{ translate('date') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($jobs as $job)
                            <tr>
                                <td>{{ $shopName($job->seller_id) }}</td>
                                <td class="k-text-muted">{{ $job->type }}</td>
                                <td>
                                    <x-k.badge :tone="$tone[$job->status] ?? 'neutral'">
                                        {{ translate($job->status) }}
                                    </x-k.badge>
                                    @if ($job->error)
                                        <div class="k-text-subtle" style="font-size:var(--k-text-sm)">
                                            {{ translate($job->error) }}
                                        </div>
                                    @endif
                                </td>
                                {{-- One column, read as a sentence: three numeric columns make a
                                     reader do the arithmetic to find out whether it went well. --}}
                                <td class="k-table__num k-num">
                                    {{ $job->succeeded }} / {{ $job->total }}
                                    @if ($job->failed > 0)
                                        <x-k.badge tone="danger">
                                            {{ $job->failed }} {{ translate('failed') }}
                                        </x-k.badge>
                                    @endif
                                </td>
                                <td class="k-text-muted">{{ $job->created_at }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="k-pager">{{ $jobs->links() }}</div>
            @endif
        </x-k.card>
    </div>
@endsection
