@foreach($currencies as $key =>$currency)
    <form action="{{ route('admin.system-setup.currency.update') }}" method="post">
        @csrf
        <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas-currency-{{ str_replace(' ', '-', $currency->code) }}"
             aria-labelledby="offcanvas-currency-{{ str_replace(' ', '-', $currency->code) }}-Label">
            <div class="offcanvas-header bg-body">
                <h3 class="mb-0">
                    {{ translate('Edit_Currency') }} - {{ $currency->code }} ({{ $currency->symbol }})
                </h3>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body">
                <div class="mb-3 mb-sm-20">
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="form-group">
                            <input type="hidden" name="id" value="{{ $currency->id }}">
                            <label class="form-label text-capitalize" for="name-{{ $currency['code'] }}">
                                {{ translate('currency_name') }}
                                <span class="text-danger">*</span>
                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                      data-bs-placement="right"
                                      aria-label="{{ translate('add_the_name_of_the_currency_you_want_to_add') }}"
                                      data-bs-title="{{ translate('add_the_name_of_the_currency_you_want_to_add') }}">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </label>
                            <input type="text" name="name" class="form-control" id="name-{{ $currency['code'] }}"
                                   placeholder="{{ translate('ex'.':'.translate('United_States_Dollar')) }}"
                                   value="{{ $currency['name'] }}" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label text-capitalize" for="symbol-{{ $currency['code'] }}">
                                {{ translate('currency_symbol') }}
                                 <span class="text-danger">*</span>
                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                      data-bs-placement="right"
                                      aria-label="{{ translate('add_the_symbol_of_the_currency_you_want_to_add') }}"
                                      data-bs-title="{{ translate('add_the_symbol_of_the_currency_you_want_to_add') }}">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </label>
                            <input type="text" name="symbol" class="form-control"
                                   id="symbol-{{ $currency['code'] }}" value="{{ $currency['symbol'] }}"
                                   placeholder="{{ translate('ex').':'.'$' }}" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label text-capitalize"
                                   for="currency_code-{{ $currency['code'] }}">
                                {{ translate('currency_code') }}
                                <span class="text-danger">*</span>
                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                      data-bs-placement="right"
                                      aria-label="{{ translate('add_the_code_of_the_currency_you_want_to_add') }}"
                                      data-bs-title="{{ translate('add_the_code_of_the_currency_you_want_to_add') }}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                            </label>
                            <input type="text" name="code" class="form-control"
                                   id="currency_code-{{ $currency['code'] }}"
                                   value="{{ $currency['code'] }}"
                                   placeholder="{{ translate('ex').':'.'USD' }}" required>
                        </div>

                        @if($currencyModel['value']=='multi_currency')
                            <div class="form-group">
                                <label class="form-label text-capitalize" for="exchange_rate">
                                    {{ translate('exchange_rate') }}
                                    <span class="text-danger">*</span>
                                    <span class="tooltip-icon" data-bs-toggle="tooltip"
                                          data-bs-placement="right"
                                          aria-label="{{ translate('based_on_your_region_set_the_exchange_rate_of_the_currency_you_want_to_add') }}"
                                          data-bs-title="{{ translate('based_on_your_region_set_the_exchange_rate_of_the_currency_you_want_to_add') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <input type="number" min="0" max="1000000"
                                       name="exchange_rate" step="any"
                                       placeholder="{{translate('exchange_Rate')}}"
                                       class="form-control" id="exchange_rate"
                                       value="{{ $currency['exchange_rate'] }}">
                            </div>
                        @else
                            <input type="hidden" min="0" max="1000000"
                                   name="exchange_rate" step="any"
                                   placeholder="{{translate('exchange_Rate')}}"
                                   class="form-control" id="exchange_rate"
                                   value="1">
                        @endif
                    </div>
                </div>
            </div>
            <div class="offcanvas-footer shadow-lg">
                <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 flex-grow-1">
                        {{ translate('reset') }}
                    </button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4 flex-grow-1">
                        {{ translate('submit') }}
                    </button>
                </div>
            </div>
        </div>
    </form>
@endforeach
