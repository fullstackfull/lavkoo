{{-- Putting one of our products on a channel. Reached from the product form's channels card. Opening it calls no channel: everything here is what we already store. --}}
@extends('layouts.admin.app')

@section('title', translate('put_this_product_on_a_channel'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('put_this_product_on_a_channel')" :subtitle="$product?->name ?: ('#' . $productId)">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.products.update', $productId) }}">{{ translate('back_to_the_product') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.sales-channels.products.index') }}">{{ translate('Products_&_Channels') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">{{ translate('listing_screen_rules') }}</div>

        @unless ($publishingEnabled)
            <div class="alert alert-secondary fs-12" role="note">{{ translate('listing_publishing_is_off_on_this_host') }}</div>
        @endunless

        @if ($productProblems !== [])
            <div class="alert alert-warning fs-12" role="alert">
                <span class="d-block fw-bold">{{ translate('this_product_is_not_ready_for_any_channel_yet') }}</span>
                <ul class="mb-0 ps-3">
                    @foreach ($productProblems as $problem)
                        <li>{{ $labels->problem($problem) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($variantKey !== '')
            <p class="fs-12 text-muted">{{ translate('showing_the_variant') }}: <code>{{ $variantKey }}</code></p>
        @endif

        @forelse ($rows as $row)
            @include('admin-views.sales-channels.partials._listing-draft', ['row' => $row, 'productId' => $productId, 'variantKey' => $variantKey, 'labels' => $labels])
        @empty
            <x-k.empty :title="translate('no_channel_accounts_yet')" :text="translate('add_a_channel_account_first')" />
        @endforelse
    </div>
@endsection
