@extends('layouts.admin.app')

@section('title', translate('Products_&_Channels'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Products_&_Channels')" :subtitle="translate('products_and_channels_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">{{ translate('listing_linking_rules') }}</div>

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.sales-channels.products.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-q">{{ translate('search') }}</label>
                    <input class="form-control form-control-sm" id="filter-q" name="q" value="{{ $filters['q'] }}" placeholder="{{ translate('sku_barcode_or_title') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-account">{{ translate('account') }}</label>
                    <select class="form-select form-select-sm" id="filter-account" name="account">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected($filters['account'] === $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-status">{{ translate('status') }}</label>
                    <select class="form-select form-select-sm" id="filter-status" name="status">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $labels->listingStatus($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-unlinked" name="unlinked" value="1" @checked($filters['unlinked'])>
                        <label class="form-check-label fs-12" for="filter-unlinked">{{ translate('only_unlinked') }}</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.products.index') }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        <x-k.card :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('listing') }}</th><th>{{ translate('on_the_channel') }}</th><th>{{ translate('our_product') }}</th><th>{{ translate('publishing') }}</th><th>{{ translate('actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($listings as $listing)
                        @include('admin-views.sales-channels.partials._listing-row')
                    @empty
                        <tr><td colspan="5">
                            @if (array_filter($filters))
                                <x-k.empty icon="search" :title="translate('no_listing_matches_the_filters')" :text="translate('no_listing_matches_the_filters_help')">
                                    <x-slot:action><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.products.index') }}">{{ translate('reset') }}</a></x-slot:action>
                                </x-k.empty>
                            @else
                                <x-k.empty icon="catalog" :title="translate('no_listings_read_yet')" :text="translate('no_listings_read_yet_help')">
                                    <x-slot:action><a class="btn btn-primary btn-sm" href="{{ route('admin.sales-channels.index') }}">{{ translate('Channels_Hub') }}</a></x-slot:action>
                                </x-k.empty>
                            @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($listings->hasPages())
                <x-slot:footer>{{ $listings->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection

@push('script')
    @include('admin-views.sales-channels.partials._product-picker-script')
@endpush
