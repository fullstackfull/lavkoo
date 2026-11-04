<div>
    <div class="price-range-wrapper">
        <h6 class="fs-13 fw-semibold mb-15">{{ translate('Price Range') }}</h6>
        <div class="">
            <div class="d-flex align-items-end gap-2 mt-1">
                <div class="form-group mb-0">
                    <label for="min_price" class="fs-10">{{ translate('Min') }}</label>
                    <input type="text" name="min_price" value="{{ request('min_price') }}" class="min_price auction-decimal-input form-control border py-1 fs-12 title-clr min-h-30px" placeholder=""
                           inputmode="decimal" autocomplete="off" data-default-value="{{ $priceRange['min'] }}">
                </div>
                <div class="mb-0">-</div>
                <div class="form-group mb-0">
                    <label for="max_price" class="fs-10">{{ translate('Max') }}</label>
                    <input type="text" name="max_price" value="{{ request('max_price') }}" class="max_price auction-decimal-input form-control border py-1 fs-12 title-clr min-h-30px" placeholder=""
                           inputmode="decimal" autocomplete="off" data-default-value="{{ $priceRange['max'] }}">
                </div>
                <button type="button" class="btn btn-primary p-1 border-0 w-30px h-30px min-w-30px fs-12 action-search-products-by-price" id="">
                    <i class="fi fi-rr-angle-right"></i>
                </button>
            </div>
            <div class="price_range_slider mt-3 mb-1 rounded-10" data-max-value="{{ $priceRange['max'] }}" data-min-value="{{ $priceRange['min'] }}">
                <div class="slider-range"></div>
                <div class="slider-thumb thumb_min" data-bs-toggle="tooltip"
                     data-bs-placement="top"
                     title="{{ translate('Min') }}">
                </div>
                <div class="slider-thumb thumb_max" data-bs-toggle="tooltip"
                     data-bs-placement="top"
                     title="{{ translate('Max') }}">
                </div>
            </div>
        </div>
    </div>
</div>
