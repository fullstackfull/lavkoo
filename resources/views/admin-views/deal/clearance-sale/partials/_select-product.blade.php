@if(isset($selectedProducts))
    @foreach($selectedProducts as $key=>$product)
        <div class="col-12 remove-selected-clearance-parent">

            <div class="mt-20">
                <div class="right-absolute-btn">
                    <button type="button" class="btn btn-circle p-0 text-danger bg-danger bg-opacity-10 remove-selected-clearance-product" style="--size: 2rem;" data-product-id="{{$product['id']}}">
                        <i class="fi fi-sr-cross"></i>
                    </button>
                </div>
                <div class="media gap-3 p-3 rounded border cursor-pointer justify-content-between align-items-center flex-wrap flex-lg-nowrap"
                     data-id="1">
                    <div class="d-flex align-items-center gap-3">
                        <img width="60" height="60"
                             src="{{ getStorageImages(path:$product->thumbnail_full_url , type: 'backend-basic') }}"
                             class="aspect-1 border rounded border" alt="">
                        <div class="media-body d-flex flex-column gap-1">
                            <h5 class="product-id" hidden="">1</h5>
                            <h4 class="mb-1 product-name line-1">
                                {{$product['name']}}

                            </h4>
                            <input type="hidden" name="productIds[]" value="{{ $product->id }}">
                            <div class="fs-12 text-dark d-flex flex-wrap gap-2 flex-column flex-sm-row">
                                <span class="text-nowrap cat-item border--end">
                                    <span class="opacity-75">
                                        {{ translate('Price') }}:
                                    </span>

                                    <strong>{{setCurrencySymbol(usdToDefaultCurrency(amount: $product['unit_price']))}}</strong>
                                </span>
                                @if($product->product_type == 'physical')
                                    <span class="text-nowrap cat-item border--end">
                                        <span class="opacity-75">
                                            {{ translate('current_stock') }}:
                                        </span>
                                        {{ $product->current_stock }}
                                    </span>
                                @endif
                                <span class="text-nowrap cat-item border--end">
                                    <span class="opacity-75">
                                        {{ translate('Category') }}
                                        :
                                    </span>
                                    {{ isset($product->category) ? $product->category->name : translate('category_not_found') }}
                                </span>
                                @if($product->product_type != 'digital')
                                    <span class="text-nowrap cat-item border--end">
                                        <span class="opacity-75"> {{ translate('Brand') }}
                                            :</span>
                                        {{ isset($product->brand) ? $product->brand?->name : translate('brands_not_found') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @if(isset($clearanceConfig) && $clearanceConfig->discount_type == 'product_wise')
                        <div class="d-flex flex-column align-items-end">
                            <label
                                class="form-label fw-medium fs-12">{{ translate('Discount Amount') }}
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" placeholder="Ex : 10" name="discount_amount[{{ $product->id }}]" required>
                                <div class="input-group-append select-wrapper">
                                    <select name="discount_type[{{ $product->id }}]" id="" class="form-select shadow-none h-auto border-0">
                                        <option value="percentage">%</option>
                                        <option value="flat">{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
@endif
