<form action="{{ route('admin.business-settings.inhouse-shop-vacation-update') }}" method="post"
      id="vendor-vacation-offcanvas-form">
    @csrf
    <div class="offcanvas offcanvas-end w-sm-500" tabindex="-1" id="offcanvasVacationMode"
        aria-labelledby="offcanvasVacationModeLabel">
        <div class="offcanvas-header bg-body">
            <h2 class="mb-0">{{ translate('Vacation_Mode_Setup') }}</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-3 mb-sm-20 d-flex flex-column gap-3 gap-sm-20">
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="mb-3">
                        <h4>{{ translate('Vacation_Mode') }}</h4>
                        <p class="fs-12 mb-0">
                            {{ translate('if_you_turn_on_your_shop_will_go_to_vacation_mode.') }}
                            {{ translate('customer_can_view_your_shop_but_can_not_order_to_you') }}
                        </p>
                    </div>
                    <label
                        class="d-flex justify-content-between align-items-center gap-3 border rounded px-3 py-2 user-select-none">
                        <span class="fw-medium text-dark">{{ translate('Status') }}</span>
                        <label class="switcher">
                            <input type="checkbox" name="status" class="switcher_input" value="1"
                                id="vacation_close" {{ $vacation['status'] == 1 ? 'checked' : '' }}>
                            <span class="switcher_control"></span>
                        </label>
                    </label>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="form-group m-0">
                        <label class="form-label">
                            {{ translate('Vacation_Mode_Duration') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                aria-label="{{ translate('Select_the_preferred_vacation_dates_when_you_will_not_be_available_to_fulfill_orders') }}"
                                data-bs-title="{{ translate('Select_the_preferred_vacation_dates_when_you_will_not_be_available_to_fulfill_orders') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                            <span class="text-danger">*</span>
                        </label>
                        <div
                            class="min-h-40 d-flex align-items-center flex-wrap gap-3 gap-sm-4 border rounded mb-2 px-3 py-1 user-select-none">
                            <div class="d-flex align-items-center gap-5px p-0 pe-1">
                                <input class="form-check-input radio--input mt-0" type="radio"
                                       name="vacation_duration_type" id="duration_type2" value="until_change"
                                    {{ isset($vacation['vacation_duration_type']) && $vacation['vacation_duration_type'] == 'until_change' ? 'checked' : '' }}>
                                <label class="form-check-label cursor-pointer" for="duration_type2">
                                    {{ translate('Until_i_Change') }}
                                </label>
                            </div>
                            <div class="d-flex align-items-center gap-5px p-0 pe-1">
                                <input class="form-check-input radio--input mt-0" type="radio"
                                    name="vacation_duration_type" id="duration_type1" value="one_day"
                                    {{ isset($vacation['vacation_duration_type']) && $vacation['vacation_duration_type'] == 'one_day' ? 'checked' : '' }}>
                                <label class="form-check-label cursor-pointer" for="duration_type1">
                                    {{ translate('24_hours') }}
                                </label>
                            </div>
                            <div class="d-flex align-items-center gap-5px p-0 pe-1">
                                <input class="form-check-input radio--input mt-0" type="radio"
                                    name="vacation_duration_type" id="duration_type3" value="custom"
                                    {{ !isset($vacation['vacation_duration_type']) || $vacation['vacation_duration_type'] == 'custom' ? 'checked' : '' }}>
                                <label class="form-check-label cursor-pointer" for="duration_type3">
                                    {{ translate('Custom_Time') }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mt-4 custom-time-range-container">
                        <?php
                        use Carbon\Carbon;
                        $vacationStartDate = isset($vacation['vacation_start_date']) ? Carbon::parse($vacation['vacation_start_date']) : now();
                        $vacationEndDate = isset($vacation['vacation_end_date']) ? Carbon::parse($vacation['vacation_end_date']) : now();
                        ?>
                        <div class="row g-3 start-and-end-date">
                            <div class="col-md-12">
                                <label class="form-label mb-1">
                                    {{ translate('Start_Date') }}
                                    <span class="text-danger vacation-time-required">*</span>
                                </label>
                                <input type="datetime-local" class="form-control" name="vacation_start_date"
                                    id="custom_time_range_start" step="any"
                                    value="{{ old('vacation_start_date', $vacationStartDate ?? '') }}" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label mb-1">
                                    {{ translate('End_Date') }}
                                    <span class="text-danger vacation-time-required">*</span>
                                </label>
                                <input type="datetime-local" class="form-control" name="vacation_end_date"
                                    id="custom_time_range_end" step="any"
                                    value="{{ old('vacation_end_date', $vacationEndDate ?? '') }}" required>
                            </div>
                            <div class="col-12">
                                <small id="dateError" class="form-text text-danger" style="display: none;">
                                    {{ translate('start_date_cannot_be_greater_than_end_date.') }}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded">
                    <div class="form-group">
                        <label class="form-label" for="vacation_note">
                            {{ translate('Vacation_Note') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                aria-label="{{ translate('Vacation_Note') }}"
                                data-bs-title="{{ translate('Add_a_note_for_the_admin_that_will_be_displayed_while_you_are_in_vacation_mode.') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <textarea class="form-control" name="vacation_note" id="vacation_note" rows="5"
                            placeholder="{{ translate('type_about_the_description') }}">{{ $vacation['vacation_note'] }}</textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-lg">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset"
                    class="btn btn-secondary px-3 px-sm-4 flex-grow-1">{{ translate('reset') }}</button>
                <button type="submit" class="btn btn-primary px-3 px-sm-4 flex-grow-1">
                    {{ translate('update') }}
                </button>
            </div>
        </div>
    </div>
</form>

