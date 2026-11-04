<div class="pos-product-item card action-select-product" data-id="{{ $product['id'] }}">
    <div class="product-add-count btn btn-danger btn-circle fs-12 p-1 border border-2 border-white d-none" id="product-added-to-cart-{{$product['id']}}" style="--size: 30px;">
    </div>
    <div class="pos-product-item_thumb position-relative">
        @if($product?->clearanceSale)
            <div class="position-absolute badge badge-soft-warning user-select-none m-2">
                {{ translate('Clearance_Sale') }}
            </div>
        @endif
        <img class="img-fit aspect-1" src="{{ getStorageImages(path:$product->thumbnail_full_url, type: 'backend-product') }}"
             alt="{{ $product['name'] }}">
        <div class="pos-product-item_hover-content">
            <div class="d-flex justify-content-center align-items-center">
                <div class="text-center">
                    @if ($product->product_type === 'physical' && max(0, $product->current_stock) > 0)
                        <h4 class="text-white fw-medium mb-1">{{ translate('Total_stock') }}</h4>
                    @endif
                    <h2 class="text-white mb-0">
                        {{
                            $product->product_type === 'physical' ? (max(0, $product->current_stock) > 0 ? max(0, $product->current_stock) : translate('out_of_stock') . '.') : translate('click_for_details') . '.'
                        }}
                    </h2>

                </div>
            </div>
        </div>
    </div>

    <div class="pos-product-item_content clickable">
        <div class="pos-product-item_title fw-medium">
            {{ $product['name'] }}
        </div>
        @if (getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
            <div class="fs-12 fw-medium text-decoration-line-through">{{ webCurrencyConverter(amount: $product['unit_price']) }}</div>
        @endif
        <div class="pos-product-item_price">
            {{ getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'string', price: $product['unit_price'], from: 'panel') }}
        </div>
    </div>
</div>
