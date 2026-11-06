@extends('layouts.admin.app')

@section('title', translate('m5azn_products'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('m5azn_products')" :subtitle="translate('m5azn_products_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.show', 'm5azn') }}">{{ translate('settings') }}</a>
                <form method="POST" action="{{ route('admin.fulfillment-providers.sync', ['m5azn', 'products']) }}" class="d-inline">@csrf
                    <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('sync_products_now') }}</button></form>
                <form method="POST" action="{{ route('admin.fulfillment-providers.m5azn.import-all') }}" class="d-inline">@csrf
                    <button class="btn btn-primary btn-sm" type="submit" @disabled(!$catalogMapVerified || !$config->bool('import_enabled'))>{{ translate('import_all_pending') }}</button></form>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12">
            <strong>{{ translate('profit_margin') }}:</strong> {{ translate('m5azn_margin_limitation') }}
        </div>
        @unless ($catalogMapVerified)
            <div class="alert alert-warning fs-12">{{ translate('m5azn_catalog_map_unverified') }}
                <a href="{{ route('admin.fulfillment-providers.show', 'm5azn') }}#field-maps">{{ translate('verify_field_map') }}</a></div>
        @endunless

        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach (['' => 'all', 'new' => 'new', 'imported' => 'imported', 'needs_review' => 'needs_review', 'error' => 'error'] as $value => $label)
                <a class="btn btn-sm {{ $status === (string) $value ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('admin.fulfillment-providers.m5azn.products', array_filter(['status' => $value])) }}">
                    {{ translate('catalog_status_' . $label) }} @if ($value !== '')<span class="badge bg-light text-dark">{{ $counts[$value] ?? 0 }}</span>@endif</a>
            @endforeach
        </div>

        <x-k.card :padded="false" class="mb-3">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('product') }}</th><th>{{ translate('m5azn_id') }}</th><th>{{ translate('stock') }}</th>
                        <th>{{ translate('supplier_cost') }} ({{ $currency }})</th><th>{{ translate('selling_price') }} ({{ $currency }})</th><th>{{ translate('margin') }}</th>
                        <th>{{ translate('status') }}</th><th>{{ translate('action') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($rows as $row)
                        @php($item = $row['item'])
                        <tr>
                            <td>{{ $row['product']->name ?? $item->name }}
                                <div class="fs-10 text-muted">{{ $item->sku }} @if ($item->barcode) · {{ $item->barcode }}@endif</div>
                                @if ($row['product'])<a class="fs-10" href="{{ route('admin.products.view', ['addedBy' => 'in-house', 'id' => $row['product']->id]) }}">{{ translate('open_store_product') }} #{{ $row['product']->id }}</a>@endif</td>
                            <td>{{ $item->external_id }}</td>
                            <td>@if ($row['mapping']){{ $row['mapping']->provider_stock ?? '—' }}<div class="fs-10 text-muted">{{ translate('stock_source_' . $row['mapping']->stock_source->value) }}</div>@else{{ $item->stock ?? '—' }}@endif</td>
                            <td>{{ $row['cost'] !== null ? number_format($row['cost'], 2) : '—' }}</td>
                            <td>{{ $row['sellingPrice'] !== null ? number_format($row['sellingPrice'], 2) : '—' }}</td>
                            <td>@if ($row['marginPercent'] !== null)
                                    <x-k.badge :tone="$row['margin'] <= 0 || ($config->get('min_margin_percent') && $row['marginPercent'] < (float) $config->get('min_margin_percent')) ? 'danger' : 'success'">{{ number_format($row['margin'], 2) }} ({{ $row['marginPercent'] }}%)</x-k.badge>
                                @else — @endif</td>
                            <td><x-k.badge :tone="match ($item->status) { 'imported' => 'success', 'needs_review', 'error' => 'warning', default => 'neutral' }">{{ translate('catalog_status_' . $item->status) }}</x-k.badge>
                                @if ($item->note)<div class="fs-10 text-muted">{{ $item->note }}</div>@endif</td>
                            <td>
                                <form method="POST" action="{{ route('admin.fulfillment-providers.m5azn.import', $item->id) }}" class="d-flex gap-1 align-items-center">@csrf
                                    @if ($item->status === 'needs_review')
                                        <label class="fs-10 mb-0"><input type="checkbox" name="force" value="1"> {{ translate('import_as_new_anyway') }}</label>
                                    @endif
                                    <button class="btn btn-outline-primary btn-sm" type="submit" @disabled(!$catalogMapVerified)>{{ translate($item->status === 'imported' ? 'refresh' : 'import') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-k.empty icon="box" :title="translate('no_m5azn_products_yet')" :text="translate('no_m5azn_products_yet_help')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($paginator->hasPages())
                <x-slot:footer>{{ $paginator->links() }}</x-slot:footer>
            @endif
        </x-k.card>

        <x-k.card :title="translate('price_synchronization_history')" :padded="false">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead><tr><th>{{ translate('time') }}</th><th>{{ translate('product') }}</th><th>{{ translate('field') }}</th><th>{{ translate('from') }}</th><th>{{ translate('to') }}</th><th>{{ translate('source') }}</th></tr></thead>
                    <tbody>
                    @forelse ($priceChanges as $change)
                        <tr><td class="fs-12">{{ $change->created_at?->format('Y-m-d H:i') }}</td><td>#{{ $change->product_id }}</td>
                            <td>{{ translate('price_field_' . $change->field) }}</td>
                            <td>{{ $change->old_value !== null ? rtrim(rtrim(number_format((float) $change->old_value, 4, '.', ''), '0'), '.') : '—' }}</td>
                            <td>{{ rtrim(rtrim(number_format((float) $change->new_value, 4, '.', ''), '0'), '.') }}</td><td>{{ $change->source }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">{{ translate('no_price_changes_yet') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-k.card>
    </div>
@endsection
