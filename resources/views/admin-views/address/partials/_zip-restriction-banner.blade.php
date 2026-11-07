{{-- What the delivery restriction page says about the unified address. Expects $notice (DeliveryRestrictionNotice::forPage), never null. --}}
@use('App\Services\Address\DeliveryRestrictionNotice')
@php
    $warning = $notice['level'] === DeliveryRestrictionNotice::LEVEL_WARNING;
    $text = ! $warning
        ? 'address_zip_banner_info'
        : ($notice['mode_chosen'] === 'off' ? 'address_zip_banner_blocks_mode' : 'address_zip_banner_conflict');
@endphp
<div class="alert {{ $warning ? 'alert-warning' : 'alert-info' }} fs-12 mb-4" role="alert">
    <p class="mb-2">{{ translate($text) }}</p>
    <div class="d-flex flex-wrap gap-3 align-items-center">
        <span>{{ translate('address_zip_banner_count') }}: <strong>{{ number_format($notice['saudi_without_zip']) }}</strong></span>
        @if (Route::has('admin.address-system.index'))
            <a href="{{ route('admin.address-system.index') }}">{{ translate('address_open_address_system') }}</a>
        @endif
    </div>
</div>
