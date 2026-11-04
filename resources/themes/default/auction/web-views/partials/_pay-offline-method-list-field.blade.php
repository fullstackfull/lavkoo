@if ($method)
    <div class="payment-list-area">
        <div>
            <div class="bg-primary-light rounded p-4 mt-4">
                <h6 class="text-capitalize">{{ $method['method_name'] }} {{translate('info')}}</h6>
                <div class="row g-2 fs-12">
                    @foreach ($method['method_fields'] as $methodField)
                        <div class="col-xl-5 col-sm-6">
                            <div class="d-flex gap-2">
                                <span class="text-muted text-capitalize">{{ translate($methodField['input_name']) }}</span>
                                : <span class="text-dark">{{ translate($methodField['input_data']) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <h4 class="mt-4 pb-1 fw-semibold text-center">
                {{translate('amount')}} : {{ webCurrencyConverter(amount: $totalOfflineAmount) }}
            </h4>

            <div class="row g-3">
                <input type="hidden" value="offline_payment" name="payment_method">
                <input type="hidden" value="{{ $method['id'] }}" name="method_id">
                <input type="hidden" value="{{ $method['method_name'] }}" name="method_name">
                    <?php
                    $count = count($method['method_informations']);
                    $count_status = $count % 2 == 1 ? 'odd' : 'even';
                    ?>
                @foreach ($method['method_informations'] as $key => $information)
                    <div class="col-sm-{{$key == 0 && $count_status==="odd" ? 12 : 6}}">
                        <div class="form-group">
                            <label class="form-label" for="payment_by">{{ translate($information['customer_input']) }}
                                <span class="text-danger">{{ $information['is_required'] == 1?'*':''}}</span>
                            </label>
                            <input type="text" name="method_informations[{{ $information['customer_input'] }}]" class="form-control"
                                   placeholder="{{ translate($information['customer_placeholder']) }}" {{ $information['is_required'] == 1?'required':''}}>
                        </div>
                    </div>
                @endforeach

                <div class="col-12">
                    <div class="form-group">
                        <label class="form-label" for="account_no">{{translate('payment_note')}}</label>
                        <textarea class="form-control" name="offline_payment[payment_note]" rows="4"
                                  placeholder="{{translate('insert_note')}}"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
