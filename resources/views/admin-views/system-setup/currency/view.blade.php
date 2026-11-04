@extends('layouts.admin.app')

@section('title', translate('Currency'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/system-setting.png') }}" alt="">
                {{ translate('system_Setup') }}
            </h2>
        </div>

        @include('admin-views.system-setup.system-settings-inline-menu')

        <div class="d-flex flex-column gap-20">
            <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-sm-20">
                <i class="fi fi-sr-lightbulb-on text-info"></i>
                <span>
                    {{ translate('exchange_rate_are_changeable_so_please_keep_updated.') }}
                    {{ translate('if_you_change_the_default_currency_it_will_be_automatically_updated_on_the_business_settings_page') }}
                </span>
            </div>
        </div>

        {{-- The shape of the whole money model, and the only setting on this page that could not be
             changed here: 35 branch sites read `currency_model`, including every conversion in
             app/Utils/currency.php, and the only writer was the installer. --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="mb-3">
                    <h3>{{ translate('currency_model') }}</h3>
                    <p class="mb-0 fs-12">{{ translate('whether_this_marketplace_runs_on_one_currency_or_several_with_exchange_rates') }}.</p>
                </div>
                <form action="{{ route('admin.system-setup.currency.currency-model') }}" method="post" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-md-4">
                        <label class="form-label" for="currency-model">{{ translate('model') }}</label>
                        <select id="currency-model" name="currency_model" class="form-control">
                            <option value="single_currency" {{ ($currencyModel['value'] ?? '') === 'single_currency' ? 'selected' : '' }}>
                                {{ translate('single_currency') }}
                            </option>
                            <option value="multi_currency" {{ ($currencyModel['value'] ?? '') === 'multi_currency' ? 'selected' : '' }}>
                                {{ translate('multi_currency') }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">
                            {{ translate('on_single_currency_exchange_rates_are_not_applied_anywhere_even_where_they_are_stored') }}.
                        </small>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100">{{ translate('save') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="mb-3 mb-sm-20">
                    <h3>{{ translate('Add_New_Currency') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('add_different_types_of_currency_for_different_types_of_country.') }}
                    </p>
                </div>
                <form action="{{ route('admin.system-setup.currency.store') }}" method="post" class="" novalidate="novalidate">
                    @csrf
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label text-capitalize" for="name">
                                        {{ translate('currency_name') }}
                                        <span class="text-danger">*</span>
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right"
                                              aria-label="{{ translate('add_the_name_of_the_currency_you_want_to_add') }}"
                                              data-bs-title="{{ translate('add_the_name_of_the_currency_you_want_to_add') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" name="name" class="form-control" id="name"
                                           placeholder="{{ translate('ex'.':'.translate('United_States_Dollar')) }}"
                                           data-required-msg="{{ translate('currency_name_is_required') }}"
                                           required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label text-capitalize" for="symbol">
                                        {{ translate('currency_symbol') }}
                                        <span class="text-danger">*</span>
                                        <span class="tooltip-icon" data-bs-toggle="tooltip"
                                              data-bs-placement="right"
                                              aria-label="{{ translate('add_the_symbol_of_the_currency_you_want_to_add') }}"
                                              data-bs-title="{{ translate('add_the_symbol_of_the_currency_you_want_to_add') }}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                    </label>
                                    <input type="text" name="symbol" class="form-control" id="symbol"
                                           data-required-msg="{{ translate('currency_symbol_is_required') }}"
                                           placeholder="{{ translate('ex').':'.'$' }}" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label text-capitalize" for="currency_code">
                                        {{ translate('currency_code') }}
                                        <span class="text-danger">*</span>
                                        <span class="tooltip-icon" data-bs-toggle="tooltip"
                                              data-bs-placement="right"
                                              aria-label="{{ translate('add_the_code_of_the_currency_you_want_to_add') }}"
                                              data-bs-title="{{ translate('add_the_code_of_the_currency_you_want_to_add') }}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                    </label>
                                    <input type="text" name="code" class="form-control" id="currency_code"
                                           data-required-msg="{{ translate('currency_code_is_required') }}"
                                           placeholder="{{ translate('ex').':'.'USD' }}" required>
                                </div>
                            </div>

                            @if($currencyModel['value']=='multi_currency')
                                <div class="col-md-6">
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
                                        <input type="number" min="1" max="1000000" name="exchange_rate"
                                               step="any" class="form-control" id="exchange_rate"
                                               data-required-msg="{{ translate('exchange_rate_is_required') }}"
                                               placeholder="{{ translate('ex').':'.'120' }}" required>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <div
                            class="d-flex justify-content-sm-end justify-content-center flex-wrap gap-3 flex-grow-1 flex-grow-sm-0 bg-white">
                            <button type="reset"
                                    class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                            <button type="submit" class="btn btn-primary px-3 px-sm-4">
                                {{ translate('submit') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                    <h3 class="mb-0">
                        {{ translate('Currency_list') }}
                        <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $currencies->count() }}</span>
                    </h3>
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                        <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                            <form action="" method="GET">
                                <div class="input-group">
                                    <input id="datatableSearch_" type="search" name="searchValue" class="form-control" placeholder="Search by currency name" value="{{ request()->searchValue }}" aria-label="Search orders" >
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="k-table-wrap">
                        <table
                            class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th>{{ translate('currency_name') }}</th>
                                <th>{{ translate('currency_symbol') }}</th>
                                <th>{{ translate('currency_code') }}</th>
                                @if($currencyModel['value']=='multi_currency')
                                    <th>{{ translate('exchange_rate') }}
                                        ({{ '1'.' '. getCurrencyCode(type: 'default').' '.'='.'?' }})
                                    </th>
                                @endif
                                <th>{{ translate('status') }}</th>
                                <th class="text-center">{{ translate('action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($currencies as $key =>$currency)
                                <tr>
                                    <td>{{$currencies->firstitem()+ $key }}</td>
                                    <td class="max-w-200 overflow-hidden text-wrap">
                                        <div class="d-flex gap-2 align-items-center">
                                            <span>{{ $currency->name }}</span>
                                            @if(in_array($currency->id, [1, 2, 3, 4, 5, 6, 7]))
                                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                                      aria-label="{{ translate('This currency cannot be deleted as it associated with some features.') .' '. translate('If you wish to remove it from the website, please disable its status instead.') }}"
                                                      data-bs-title="{{ translate('This currency cannot be deleted as it associated with some features.') .' '. translate('If you wish to remove it from the website, please disable its status instead.') }}"
                                                >
                                                    <img width="15" src="{{ dynamicAsset(path: 'public/assets/backend/media/icons/default-currency-icon.svg') }}" alt="">
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="max-w-200 overflow-hidden text-wrap">
                                        {{ $currency->symbol }}
                                    </td>
                                    <td class="max-w-200 overflow-hidden text-wrap">
                                        {{ $currency->code }}
                                    </td>
                                    @if($currencyModel['value']=='multi_currency')
                                        <td>{{ $currency->exchange_rate }}</td>
                                    @endif
                                    <td>
                                        @if($default['value'] != $currency->id)
                                            @if(($currency->status == 0) || ($currency->status == 1 && ($digitalPaymentStatus ? $currency->must_required_for_gateway != 1 : 1)))
                                                @include("admin-views.system-setup.currency._status-button-partial")
                                            @else
                                                <label class="switcher" for="currency-status{{ $currency['id'] }}"
                                                       data-bs-toggle="modal"
                                                       data-bs-target="#currency-modal{{ $currency['id'] }}">
                                                    <input type="checkbox" class="switcher_input" disabled
                                                           id="currency-status{{$currency['id']}}" name="status"
                                                           value="1"
                                                           {{$currency->status ? 'checked' : '' }}
                                                           data-modal-id="toggle-status-modal"
                                                           data-toggle-id="currency-status{{$currency['id']}}"
                                                           data-on-image="currency-on.png"
                                                           data-off-image="currency-off.png"
                                                           data-on-title="{{ translate('Want_to_Turn_ON_Currency_Status').'?' }}"
                                                           data-off-title="{{ translate('Want_to_Turn_OFF_Currency_Status').'?' }}"
                                                           data-on-message="<p>{{ translate('if_enabled_this_currency_will_be_available_throughout_the_entire_system') }}</p>"
                                                           data-off-message="<p>{{ translate('if_disabled_this_currency_will_be_hidden_from_the_entire_system') }}</p>">
                                                    <span class="switcher_control"></span>
                                                </label>
                                            @endif
                                        @else
                                            <label class="badge text-bg-info badge-info badge-sm">{{ translate('default') }}</label>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex justify-content-center">
                                            <div class="dropdown">
                                                <button
                                                    class="btn btn-outline-primary btn-outline-primary-dark icon-btn"
                                                    data-bs-toggle="dropdown">
                                                    <i class="fi fi-sr-menu-dots-vertical"></i>
                                                </button>

                                                <div class="dropdown-menu">
                                                    @if($default['value'] != $currency->id)
                                                    <button
                                                        class="align-items-center d-flex dropdown-item gap-2 mark-as-default-currency"
                                                        data-ajax="{{ route('admin.system-setup.currency.check-currency-update') }}"
                                                        data-code="{{ $currency->code }}">
                                                        <i class="fi fi-rr-check-circle mt-1"></i>
                                                        {{ translate('Mark_As_Default') }}
                                                    </button>
                                                    @endif

                                                    @if($currency->code != 'USD')
                                                        <a class="dropdown-item d-flex align-items-center gap-2 pointer"
                                                           data-bs-toggle="offcanvas"
                                                           data-bs-target="#offcanvas-currency-{{ str_replace(' ', '-', $currency->code) }}">
                                                            <i class="fi fi-rr-pen-circle mt-1"></i>
                                                            {{ translate('edit') }}
                                                        </a>
                                                    @else
                                                        <a class="dropdown-item d-flex align-items-center gap-2">
                                                            <i class="fi fi-rr-pen-circle mt-1"></i>
                                                            {{ translate('Non-editable') }}
                                                        </a>
                                                    @endif

                                                    @if($default['value'] == $currency->id && !in_array($currency->id, [1, 2, 3, 4, 5, 6, 7]))
                                                        <button
                                                            class="align-items-center d-flex dropdown-item gap-2 default-currency-delete-alert">
                                                            <i class="fi fi-rr-trash mt-1"></i>
                                                            {{ translate('delete') }}
                                                        </button>
                                                    @elseif(!in_array($currency->id, [1, 2, 3, 4, 5, 6, 7]))
                                                        <button class="align-items-center d-flex dropdown-item gap-2"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#currencyDeleteModal{{ $currency->id }}">
                                                            <i class="fi fi-rr-trash mt-1"></i>
                                                            {{ translate('delete') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="table-responsive mt-4">
                        <div class="px-4 d-flex justify-content-lg-end">
                            {{ $currencies->links() }}
                        </div>
                    </div>
                    @if(count($currencies) == 0)
                        @include('layouts.admin.partials._empty-state', ['text' => 'no_currency_found'], ['image'=>'default'])
                    @endif
                </div>
            </div>
        </div>

        @include("admin-views.system-setup.currency._currency-offcanvas")
    </div>

    <span id="get-currency-warning-message"
          data-title="{{ translate('Warning') }}!"
          data-message="{{ translate('This currency currently set as the system default currency.').' '. translate('To delete this currency, please assign another currency as a default first.') }}"></span>

    <span id="get-delete-currency-message" data-success="{{ translate('currency_removed_successfully').'!' }}"
          data-warning="{{ translate('this_Currency_cannot_be_removed_due_to_payment_gateway_dependency').'!' }}"></span>

    <div class="modal fade" id="defaultCurrencyChangeModal" tabindex="-1" aria-labelledby="defaultCurrencyChangeModal"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 pb-20 pt-0">
                </div>
            </div>
        </div>
    </div>

    @foreach($currencies as $key =>$currency)
        <form action="{{ route('admin.system-setup.currency.delete') }}" method="post">
            @csrf
            <input type="hidden" name="id" value="{{ $currency->id }}">
            <div class="modal fade" id="currencyDeleteModal{{ $currency->id }}" tabindex="-1"
                 aria-labelledby="currencyDeleteModal{{ $currency->id }}" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                            <button type="button"
                                    class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                    data-bs-dismiss="modal" aria-label="Close">
                            </button>
                        </div>
                        <div class="modal-body px-20 py-0 mb-30">
                            <div class="d-flex flex-column align-items-center text-center mb-30">
                                <img
                                    src="{{dynamicAsset(path: 'public/assets/new/back-end/img/modal/delete.png')}}"
                                    width="80" class="mb-20" id="" alt="">
                                <h2 class="modal-title mb-3">
                                    {{ translate('want_to_clean_database').' ?' }}
                                </h2>
                                <div class="text-center">
                                    {{ translate('are_you_sure_want_to_cleaned_the_selected_database').' ?' }}
                                    {{ translate('it_will_be_permanently_deleted_from_your_database') }}
                                </div>
                            </div>
                            <div class="d-flex justify-content-center gap-3">
                                <button type="button" class="btn btn-secondary max-w-120 flex-grow-1"
                                        data-bs-dismiss="modal">
                                    {{ translate('No') }}
                                </button>
                                <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                        class="btn btn-danger max-w-120 flex-grow-1 {{ getDemoModeFormButton(type: 'class') }}"
                                        data-bs-dismiss="modal">
                                    {{ translate('Yes_Delete') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @endforeach

    @include("layouts.admin.partials.offcanvas._currency-setup")
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/system-setup/system-setup.js') }}"></script>
@endpush
