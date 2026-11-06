{{-- Chooses one of our products by searching for it. Works without JavaScript too: the id can be typed.
     Expects $id (unique), $name (the field), $label; optional $value, $error. --}}
<div data-product-picker data-url="{{ route('admin.sales-channels.products.product-search') }}">
    <label class="form-label fs-12" for="{{ $id }}-search">{{ $label }}</label>
    <input class="form-control form-control-sm mb-1" type="search" id="{{ $id }}-search" data-product-search autocomplete="off" placeholder="{{ translate('search_our_products') }}">
    <div class="d-flex flex-wrap gap-1 mb-1" data-product-results aria-live="polite"></div>
    <label class="form-label fs-12 mb-0" for="{{ $id }}">{{ translate('product_id') }}</label>
    <input class="form-control form-control-sm" type="number" min="1" id="{{ $id }}" name="{{ $name }}" data-product-id value="{{ $value ?? '' }}">
    @isset($error)<div class="text-danger fs-12" role="alert">{{ $error }}</div>@endisset
</div>
