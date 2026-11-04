<div class="modal fade" id="add-discount" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">{{ translate('update_discount') }}</h3>
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="title-color mb-1">{{ translate('type') }}</label>
                    <select name="type" id="type_ext_dis" class="form-control">
                        <option value="amount" {{ isset($currentCustomerSessionInfo['ext_discount_type']) && $currentCustomerSessionInfo['ext_discount_type'] == 'amount' ? 'selected' : '' }}>
                            {{ translate('amount') }}
                        </option>
                        <option
                            value="percent" {{ isset($currentCustomerSessionInfo['ext_discount_type']) && $currentCustomerSessionInfo['ext_discount_type'] == 'percent' ? 'selected' : '' }}>
                            {{ translate('percent') }}(%)
                        </option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="title-color mb-1">{{ translate('discount') }}</label>
                    <input type="text" value="{{ $currentCustomerSessionInfo['ext_discount'] ?? 0 }}" id="dis_amount" class="form-control no-negative-input only-number-input-with-decimal-point" name="discount" placeholder="{{ translate('ex').':500'}}">
                </div>
                <div class="form-group">
                    <button class="btn btn-primary action-extra-discount" data-error-message="{{ translate('please_enter_discount_amount')}}">
                        {{ translate('submit') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
