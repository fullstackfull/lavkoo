@foreach($filterBrands as $productBrand)
    <div class="col-sm-6">
        <div class="d-flex gap-2">
            <input class="cursor-pointer" type="checkbox" name="filter_brand_ids[]"
                   id="productBrandId{{ $productBrand['id'] }}" value="{{ $productBrand['id'] }}"
                {{ in_array($productBrand['id'], $oldBrands) ? 'checked' : '' }}
            >
            <label class="form-check-label fs-12 cursor-pointer" for="productBrandId{{ $productBrand['id'] }}">
                {{ $productBrand['defaultname'] }}
            </label>
        </div>
    </div>
@endforeach
