<div class="offcanvas-sidebar offcanvas-vacation-mode">
    <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

    <div class="offcanvas-content bg-white shadow d-flex flex-column">
        <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
            <h3 class="text-capitalize m-0">{{ translate('vacation_mode') }}</h3>
            <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>

        <form action="{{ route('vendor.shop.update-vacation') }}" method="post" class="d-flex flex-column flex-grow-1"
            id="vendor-vacation-offcanvas-form">
            @csrf
            <input type="hidden" name="id" value="{{ $shop['id'] }}">
            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                <div class="bg-light p-3 rounded mb-3">
                    <h4>{{ translate('Vacation_Mode') }}</h4>
                    <p class="fs-12">
                        {{ translate('if_you_turn_on_your_shop_will_go_to_vacation_mode.') }}
                        {{ translate('customer_can_view_your_shop_but_can_not_order_to_you') }}
                    </p>

                    <div class="border rounded bg-white p-3 d-flex justify-content-between gap-3 mb-1 user-select-none">
                        <h5 class="mb-0 d-flex gap-1 c1">
                            {{ translate('status') }}
                        </h5>
                        <label class="switcher">
                            <input type="checkbox" name="vacation_status" value="1" class="switcher_input"
                                {{ $shop['vacation_status'] == 1 ? 'checked' : '' }}>
                            <span class="switcher_control"></span>
                        </label>
                    </div>
                </div>

                <div class="bg-light p-3 rounded mb-3">
                    <div class="form-group m-0">
                        <label class="form-label">
                            {{ translate('Vacation_Mode_Duration') }}
                            <span class="tooltip-icon" data-toggle="tooltip" data-placement="top"
                                aria-label="{{ translate('select_the_preferred_vacation_dates_when_you_will_not_be_available_to_fulfill_orders') }}"
                                data-title="{{ translate('select_the_preferred_vacation_dates_when_you_will_not_be_available_to_fulfill_orders') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                            <span class="text-danger">*</span>
                        </label>
                        <div
                            class="min-h-40 d-flex align-items-center flex-wrap gap-3 gap-sm-4 border rounded mb-2 px-3 py-1 bg-white user-select-none">
                            <div class="form-check d-flex align-items-center gap-5px">
                                <input class="form-check-input radio--input mt-0 ms-0 position-static" type="radio"
                                    name="vacation_duration_type" id="duration_type2" value="until_change"
                                    {{ isset($shop['vacation_duration_type']) && $shop['vacation_duration_type'] == 'until_change' ? 'checked' : '' }}>
                                <label class="form-check-label cursor-pointer" for="duration_type2">
                                    {{ translate('Until_i_Change') }}
                                </label>
                            </div>
                            <div class="form-check d-flex align-items-center gap-5px">
                                <input class="form-check-input radio--input mt-0 ms-0 position-static" type="radio"
                                    name="vacation_duration_type" id="duration_type1" value="one_day"
                                    {{ isset($shop['vacation_duration_type']) && $shop['vacation_duration_type'] == 'one_day' ? 'checked' : '' }}>
                                <label class="form-check-label cursor-pointer" for="duration_type1">
                                    {{ translate('24_hours') }}
                                </label>
                            </div>
                            <div class="d-flex align-items-center gap-5px">
                                <input class="form-check-input radio--input mt-0 ms-0 position-static" type="radio"
                                    name="vacation_duration_type" id="duration_type3" value="custom"
                                    {{ !isset($shop['vacation_duration_type']) || $shop['vacation_duration_type'] == 'custom' ? 'checked' : '' }}>
                                <label class="form-check-label cursor-pointer" for="duration_type3">
                                    {{ translate('Custom_Time') }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group m-0 custom-time-range-container">
                        <?php
                        use Carbon\Carbon;
                        $shopStartDate = isset($shop['vacation_start_date']) ? Carbon::parse($shop['vacation_start_date']) : now();
                        $shopEndDate = isset($shop['vacation_end_date']) ? Carbon::parse($shop['vacation_end_date']) : now();
                        ?>
                        <div class="row g-3 start-and-end-date">
                            <div class="col-md-12">
                                <label class="form-label mb-1">
                                    {{ translate('Start_Date') }}
                                    <span class="text-danger vacation-time-required">*</span>
                                </label>
                                <input type="datetime-local" class="form-control" name="vacation_start_date"
                                    id="custom_time_range_start" step="any" min="{{ now()->format('Y-m-d') }}T00:00"
                                    value="{{ old('start_date', $shopStartDate ?? '') }}" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label mb-1">
                                    {{ translate('End_Date') }}
                                    <span class="text-danger vacation-time-required">*</span>
                                </label>
                                <input type="datetime-local" class="form-control" name="vacation_end_date"
                                    id="custom_time_range_end" step="any" min="{{ now()->format('Y-m-d') }}T00:00"
                                    value="{{ old('end_date', $shopEndDate ?? '') }}" required>

                            </div>
                            <div class="col-12" id="dateError" style="display: none;">
                                <small class="form-text text-danger">
                                    {{ translate('start_date_cannot_be_greater_than_end_date.') }}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-light p-3 rounded mb-3">
                    <div class="">
                        <label class="text-dark text-capitalize">
                            {{ translate('vacation_note') }}
                            <i class="fi fi-sr-info cursor-pointer text-muted" data-toggle="tooltip"
                                title="{{ translate('add_a_note_for_the_admin_that_will_be_displayed_while_you_are_in_vacation_mode') }}"></i>
                        </label>
                        <textarea class="form-control" rows="4" name="vacation_note"
                            placeholder="{{ translate('Type_about_the_description') }}">{{ $shop->vacation_note }}</textarea>
                    </div>
                </div>
            </div>

            <div class="offcanvas-footer offcanvas-footer-sticky p-3 border-top bg-white d-flex gap-3">
                <button type="reset" class="btn btn-secondary w-100">{{ translate('reset') }}</button>
                <button type="submit" class="btn btn--primary w-100">{{ translate('save') }}</button>
            </div>
        </form>
    </div>
</div>
