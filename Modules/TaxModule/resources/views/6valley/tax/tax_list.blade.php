@extends('layouts.admin.app')

@section('title', translate('All_VAT_TAX_List'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <h2 class="fs-20 mb-0">
                {{ translate('All_VAT/TAX_List') }}
                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">
                    {{ $vatTaxes->total() }}
                </span>
            </h2>
        </div>

        @if ($existTaxVatData)
            <div class="card mt-4">
                <div class="card-body d-flex flex-column gap-20">
                    <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                        <div class="flex-grow-1 max-w-300 max-w-100-mobile">
                            <form action="{{ url()->current() }}" method="GET">
                                <div class="input-group">
                                    <input id="" type="search" name="search" class="form-control"
                                           placeholder="{{ translate('search_by_tax_name') }}"
                                           value="{{ request('search') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                            <div class="dropdown">
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="dropdown">
                                    <i class="fi fi-sr-inbox-in"></i>
                                    <span class="fs-12">{{ translate('Export') }}</span>
                                    <i class="fi fi-rr-angle-small-down"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-right">
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                           href="{{ route('admin.vat-tax.export', ['type' => 'excel', request()->getQueryString()]) }}">
                                            <span class="text-success pt-1"><i class="fi fi-sr-file-excel"></i></span>
                                            {{ translate('Excel') }}
                                        </a>
                                    </li>

                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                           href="{{ route('admin.vat-tax.export', ['type' => 'csv', request()->getQueryString()]) }}">
                                            <span class="text-info pt-1"><i class="fi fi-sr-file-csv"></i></span>
                                            {{ translate('CSV') }}
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <button type="button" class="btn btn-primary" data-bs-toggle="offcanvas"
                                    data-bs-target="#createVatTaxOffcanvas">
                                {{ translate('Create_Vat/Tax') }}
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-borderless align-middle">
                            <thead class="text-capitalize">
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th>{{ translate('VAT/TAX_Name') }}</th>
                                <th class="text-center">{{ translate('VAT/TAX_Rate') }}</th>
                                <th class="text-center">{{ translate('Status') }}</th>
                                <th class="text-center">{{ translate('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($vatTaxes as $key => $taxVat)
                                <tr>
                                    <td>{{ $key + $vatTaxes->firstItem() }}</td>
                                    <td>
                                        <h4>{{ $taxVat->name }}</h4>
                                        <span class="fs-12">{{ translate('ID') }} #{{ $taxVat['id'] }}</span>
                                    </td>
                                    <td class="text-center">
                                        {{ $taxVat->tax_rate }}%
                                    </td>
                                    <td>
                                        <div class="d-flex justify-content-center">
                                            <form action="{{ route('admin.vat-tax.status', $taxVat->id) }}"
                                                  method="post"
                                                  id="vat-tax-{{ $taxVat->id }}-status-form">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $taxVat->id }}">
                                                <label class="switcher">
                                                    <input
                                                        class="switcher_input custom-modal-plugin"
                                                        type="checkbox" value="1" name="is_active"
                                                        id="vat-tax-{{ $taxVat->id }}-status"
                                                        {{ $taxVat->is_active == 1 ? 'checked' : '' }}
                                                        data-modal-type="input-change-form"
                                                        data-modal-form="#vat-tax-{{ $taxVat->id }}-status-form"
                                                        data-on-image="{{ dynamicAsset(path: 'public/assets/backend/media/toggle-modal-icons/turn-on.svg') }}"
                                                        data-off-image="{{ dynamicAsset(path: 'public/assets/backend/media/toggle-modal-icons/turn-off.svg') }}"
                                                        data-on-title="{{ translate('Turn_ON_The_Status') }} ?"
                                                        data-off-title="{{ translate('Turn_OFF_The_Status') }} ?"
                                                        data-on-message="<p>{{ translate('are_you_sure,_do_you_want_to_turn_on_the_vat_status_from_your_system.').' '.translate('it_will_effect_on_tax_calculation_&_report.') }}</p>"
                                                        data-off-message="<p>{{ translate('are_you_sure,_do_you_want_to_turn_off_the_vat_status_from_your_system.').' '.translate('it_will_effect_on_tax_calculation_&_report.') }}</p>">
                                                    <span class="switcher_control"></span>
                                                </label>
                                            </form>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex justify-content-center">
                                            <a class="btn btn-outline-info icon-btn edit" title="Edit"
                                               data-bs-toggle="offcanvas" href="#editVatTaxOffcanvas-{{ $taxVat->id }}">
                                                <i class="fi fi-sr-pencil"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        @if(count($vatTaxes) === 0)
                            <div class="text-center p-4">
                                <img class="mb-3" alt="{{ translate('image_description') }}"
                                     src="{{ dynamicAsset(path: 'public/assets/back-end/img/tax.png') }}">
                                <p class="mb-0">{{ translate('no_Data_Found') }}</p>
                            </div>
                        @endif

                    </div>

                    <div class="table-responsive mt-2">
                        <div class="d-flex justify-content-lg-end">
                            {!! $vatTaxes->links() !!}
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="card card-body mt-4">
                <div class="bg-section rounded p-3 d-flex justify-content-center align-items-center h-100vh-250">
                    <div class="d-flex flex-column align-items-center text-center">
                        <img width="50" class="aspect-1 mb-4"
                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/tax.png') }}" alt="">
                        <h4 class="mb-2 text-capitalize">{{ translate('currently_you_do_not_have_any_tax') }}</h4>
                        <p class="fs-12 fw-medium max-w-500 mb-30">
                            {{ translate('in_this_page_you_see_all_the_tax_you_added.') }}
                            {{ translate('please_create_new_tax_to_collect_tax.') }}
                        </p>
                        <button type="button" class="btn btn-primary w-100" data-bs-toggle="offcanvas"
                                data-bs-target="#createVatTaxOffcanvas">
                            {{ translate('Create_Vat/Tax') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @include("taxmodule::6valley.tax.partials.vat-tax-create")

    @foreach ($vatTaxes as $key => $vatTax)
        @include("taxmodule::6valley.tax.partials.vat-tax-update", ['vatTax' => $vatTax])
    @endforeach

    @include("taxmodule::6valley.offcanvas._vat-tax-create")
@endsection

@push('script')

@endpush
