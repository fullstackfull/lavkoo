@foreach($filterShops as $productShop)
    <div class="col-sm-6">
        <div class="d-flex gap-2">
            <input class="" type="checkbox" name="filter_shop_ids[]"
                   id="productShopId{{ $productShop['id'] }}"
                   value="{{ $productShop['id'] }}">
            <label class="form-check-label fs-12 mt-1" for="productShopId{{ $productShop['id'] }}">
                {{ $productShop['name'] }}
            </label>
        </div>
    </div>
@endforeach
