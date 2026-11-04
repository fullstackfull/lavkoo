@if(isset($product))
    @php($overallRating = getOverallRating($product?->reviews))
    <div class="product-single-hover shadow-none rtl">
        @if(getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
            <span class="for-discount-value p-1 pl-2 pr-2 font-bold fs-13">
           <span class="direction-ltr d-block">
               -{{ getProductPriceByType(product: $product, type: 'discount', result: 'string') }}</span>
       </span>
        @else
            <span class="for-discount-value-null"></span>
        @endif
        <div class="overflow-hidden position-relative">
            <div class="inline_product clickable">
                <span class="for-discount-value-null"></span>
                <a href="{{route('product',$product->slug)}}" class="rounded">
                    <img loading="lazy" class="border border-black-50" src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}" alt="{{ $product['name'] }}" width="300" height="300" decoding="async">
                </a>
                <div class="quick-view">
                    <div class="d-none d-md-flex gap-2 align-items-center quick-view-tag">
                        @if($product->product_type == 'digital')
                            <div class="bg-white btn-circle" style="--size: 26px" data-toggle="tooltip" title="{{ translate('Digital_Product') }}" data-placement="left">
                                <img class="h-16px aspect-1 svg" src="{{ theme_asset(path: "public/assets/front-end/img/icons/digital-product.svg") }}" alt="{{ translate('Digital_Product') }}">
                            </div>
                        @else
                            <div class="bg-white btn-circle" style="--size: 26px" data-toggle="tooltip" title="{{ translate('Physical_Product') }}" data-placement="left">
                                <img class="h-16px aspect-1 svg" src="{{ theme_asset(path: "public/assets/front-end/img/icons/physical-product.svg") }}" alt="{{ translate('Physical_Product') }}">
                            </div>
                        @endif
                    </div>
                    <a class="btn-circle stopPropagation action-product-quick-view" href="javascript:" data-product-id="{{$product->id}}">
                        <i class="czi-eye align-middle"></i>
                    </a>
                    @if($product->product_type == 'physical' && $product->current_stock <= 0)
                        <span class="out_fo_stock">{{translate('out_of_stock')}}</span>
                    @endif
                </div>
            </div>
            <div class="single-product-details px-0">
                <h3 class="mb-0 letter-spacing-0">
                    <a href="{{route('product',$product->slug)}}" class="text-capitalize fw-semibold">
                        {{ $product['name'] }}
                    </a>
                </h3>
                <div class="justify-content-between mb-1">
                    <h4 class="product-price lh-1 mb-0 letter-spacing-0">
                        @if(getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
                            <del class="category-single-product-price fs-14 font-weight-normal">
                                {{ webCurrencyConverter(amount: $product->unit_price) }}
                            </del>
                        @endif
                        <span class="text-accent text-dark fs-15">
                       {{ getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'string') }}
                   </span>
                    </h4>
                </div>
                @if($overallRating[0] != 0)
                    <div class="rating-show justify-content-between">
                   <span class="d-inline-block font-size-sm text-body">
                       @for($inc=1;$inc<=5;$inc++)
                           @if ($inc <= (int)$overallRating[0])
                               <i class="tio-star text-warning"></i>
                           @elseif ($overallRating[0] != 0 && $inc <= (int)$overallRating[0] + 1.1 && $overallRating[0] > ((int)$overallRating[0]))
                               <i class="tio-star-half text-warning"></i>
                           @else
                               <i class="tio-star-outlined text-warning"></i>
                           @endif
                       @endfor
                       <label class="badge-style fs-12">( {{ count($product->reviews) }} )</label>
                   </span>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif
