@php
    use App\Services\Catalog\ProductCopyToCountry;
    use App\Services\Storefront\ProductCountry;

    $copyTargets = app(ProductCopyToCountry::class)->targetsFor(source: $product);
@endphp
@if (count($copyTargets) > 0)
    <form action="{{ route('admin.products.copy-to-country', ['id' => $product['id']]) }}" method="post"
          class="d-flex gap-2 align-items-center">
        @csrf
        <div class="select-wrapper">
            <select class="form-select" name="storefront_country" required
                    aria-label="{{ translate('copy_this_product_to_another_country') }}">
                <option value="" selected disabled>{{ translate('copy_to_country') }}</option>
                @foreach ($copyTargets as $copyTarget)
                    <option value="{{ $copyTarget }}">{{ ProductCountry::label(country: $copyTarget) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-outline-primary fs-12-mobile px-3">{{ translate('copy') }}</button>
        <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
              aria-label="{{ translate('the_copy_starts_with_no_stock_and_no_price_and_is_not_published_until_you_set_them') }}"
              data-bs-title="{{ translate('the_copy_starts_with_no_stock_and_no_price_and_is_not_published_until_you_set_them') }}">
            <i class="fi fi-sr-info"></i>
        </span>
    </form>
@endif
