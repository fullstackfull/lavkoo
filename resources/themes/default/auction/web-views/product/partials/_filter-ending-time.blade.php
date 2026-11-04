<div>
    <div class="price-range-wrapper">
        <div class="d-flex gap-2 justify-content-between align-items-center fllex-wrap mb-3">
            <h6 class="fs-13 fw-semibold mb-0">{{ translate('Auction Ending Time') }}</h6>
            <div>
                <select name="duration_time_type" id="" class="form-select min-w-70px h-30px fs-11 duration_time_type" data-default-value="min">
                    <option value="min" {{ request('duration_time_type', 'min') === 'min' ? 'selected' : '' }}>{{ translate('Minutes') }}</option>
                    <option value="hour" {{ request('duration_time_type') === 'hour' ? 'selected' : '' }}>{{ translate('Hours') }}</option>
                    <option value="day" {{ request('duration_time_type') === 'day' ? 'selected' : '' }}>{{ translate('Day') }}</option>
                </select>
            </div>
        </div>
        <div class="d-flex align-items-end flex-wrap flex-sm-nowrap gap-2">
            <div class="d-flex align-items-end gap-2">
                <div class="form-group mb-0">
                    <label for="duration_min" class="mb-1 fs-10">{{ translate('Min') }}</label>
                    <input type="text" id="duration_min" class="form-control border py-1 fs-12 title-clr min-h-30px duration_min auction-decimal-input" name="ending_time_min"
                           placeholder="{{ translate('ex: 0') }}" value="{{ request('ending_time_min', 0) }}"
                           inputmode="decimal" autocomplete="off" data-default-value="0">
                </div>
                <div class="mt-4">-</div>
                <div class="form-group mb-0">
                    <label for="duration_max" class="mb-1 fs-10">{{ translate('Max') }}</label>
                    <input type="text" id="duration_max" class="form-control border py-1 fs-12 title-clr min-h-30px duration_max auction-decimal-input"
                           name="ending_time_max" value="{{ request('ending_time_max', $endingTimeRange['max_min']) }}"
                           placeholder="{{ translate('ex: 60') }}"
                           inputmode="decimal" autocomplete="off" data-default-value="{{ $endingTimeRange['max_min'] }}">
                </div>
                <button type="button" class="btn btn-primary p-1 border-0 w-30px h-30px min-w-30px fs-12 action-search-products-by-price" id="">
                    <i class="fi fi-rr-angle-right"></i>
                </button>
            </div>
        </div>

        <div class="duration_range_slider range_slider_div mt-3 mb-1 rounded-10"
             data-max-min="{{ $endingTimeRange['max_min'] }}"
             data-max-hour="{{ $endingTimeRange['max_hour'] }}"
             data-max-day="{{ $endingTimeRange['max_day'] }}"
             data-min-value="0"
             data-max-value="{{ $endingTimeRange['max_min'] }}"
        >
            <div class="slider-range duration_slider_range"></div>
            <div class="slider-thumb duration_thumb_min" data-bs-toggle="tooltip"
                 data-bs-placement="top"
                 title="{{ translate('Min') }}"></div>
            <div class="slider-thumb duration_thumb_max" data-bs-toggle="tooltip"
                 data-bs-placement="top"
                 title="{{ translate('Max') }}"></div>
        </div>
    </div>
</div>
