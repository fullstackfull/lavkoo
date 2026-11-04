<div class="pb-4">
    <a class="d-flex align-items-center gap-3" href="{{ route('admin.products.view', ['addedBy' => ($product['added_by']=='seller'?'vendor' : 'in-house'), 'id' => $product['id']]) }}">
        <div class="avatar rounded avatar-70 border">
            <img class="avatar-img" src="{{ getStorageImages(path: $product->thumbnail_full_url, type:'backend-product') }}" alt="">
        </div>
        <div>
            <div class="d-block">
                <span class="line-2 h4 text-hover-primary mb-2">
               {{ $product['name'] }}
            </span>
            </div>
            <span class="d-block fs-12 text-body">
                {{ translate('Price') }} : {{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $product['unit_price']), currencyCode: getCurrencyCode()) }}
            </span>
        </div>
    </a>
</div>
<div class="card-body bg-section rounded mb-4 p-12 p-sm-20">
    <input name="product_id" value="{{$product['id']}}" class="d-none">
    <div id="quantity" class="mb-3">
        <label class="form-label text-dark">{{ translate('main_stock') }}</label>
        <input type="number" min="0" value={{ $product->current_stock }} step="1" placeholder="{{ translate('main_stock') }}" name="current_stock" class="form-control bg-white"@if (!empty($product['variation']) && count(json_decode($product['variation'], true)) > 0)  readonly @endif required>
    </div>
    @if($product['variation'] && count(json_decode($product['variation'], true)) > 0)
        <div>
            <label class="form-label text-dark">{{ translate('Variations_Stock') }}</label>
            <div class="bg-white p-2 rounded">
                {{-- table-responsive to match the other two include sites of this partial: the
                     variation table is five columns of inputs and pushed the modal sideways here. --}}
                <div class="sku_combination table-responsive" id="sku_combination">
                    @if($restockId)
                        @include('admin-views.product.partials._edit-restock-combinations', ['combinations'=>json_decode($product['variation'], true)])
                        <input type="hidden" name="restock_id" id="" value="{{ $restockId }}">
                    @else
                        @include('admin-views.product.partials._edit-sku-combinations', ['combinations'=>json_decode($product['variation'], true)])
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
