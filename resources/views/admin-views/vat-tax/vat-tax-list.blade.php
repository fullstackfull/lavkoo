@extends('layouts.admin.app')

@section('title', translate('Vat/Tax'))

@push('css_or_js')
    <style>
        /*css Code*/
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <h2 class="fs-20 mb-0">
                 {{ translate('All_VAT/TAX_List') }}
            </h2>
        </div>
        <div class="card card-body mt-4">
            <div class="bg-section rounded p-3 d-flex justify-content-center align-items-center h-100vh-250">
                <div class="d-flex flex-column align-items-center text-center">
                    <img width="50" class="aspect-1 mb-2" src="{{ dynamicAsset(path: 'public/assets/back-end/img/tax.png') }}" alt="">
                     <h4 class="mb-2">{{ translate('currently_you_don’t_have_any_tax') }}</h4>
                     <p class="fs-12 fw-medium max-w-500 mb-30">
                        {{ translate('in_this_page_you_see_all_the_tax_you_added._please_create_new_tax_to_collect_tax.') }}
                     </p>
                    <button type="button" class="btn btn-primary w-100" data-bs-toggle="offcanvas" data-bs-target="#createVatTaxOffcanvas">{{ translate('Create_Vat/Tax') }}</button>
                </div>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-body d-flex flex-column gap-20">
                <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                    <h3 class="mb-0">
                        {{ translate('List_of_VAT/TAX') }}
                        <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">23</span>
                    </h3>
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                        <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                            <form action="{{ url()->current() }}" method="GET">
                                <div class="input-group">
                                    <input id="" type="search" name="searchValue" class="form-control"
                                        placeholder="{{ translate('search_by_tax_name') }}"
                                        value="{{ requestString('searchValue') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="dropdown">
                            <a type="button" class="btn btn-outline-primary"
                                href="{{ route('admin.category.export',['searchValue'=>request('searchValue')]) }}">
                                <i class="fi fi-sr-inbox-in"></i>
                                <span class="fs-12">{{ translate('export') }}</span>
                            </a>
                        </div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="offcanvas" data-bs-target="#createVatTaxOffcanvas">{{ translate('Create_Vat/Tax') }}</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle">
                        <thead class="text-capitalize">
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th>{{ translate('VAT/TAX_Name') }}</th>
                                <th>{{ translate('VAT/TAX_Rate') }}</th>
                                <th class="text-center">{{ translate('Status') }}</th>
                                <th class="text-center">{{ translate('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>
                                    <span class="line-1 max-w-200">Income Tax Income TaxIncome TaxIncome TaxIncome TaxIncome TaxIncome TaxIncome Tax</span>
                                </td>
                                <td>5%</td>
                                <td class="text-center">
                                    <form action=""
                                        method="post" id="">
                                        <input type="hidden" name="_token" value="WZ8YNBCiXnK0cq5yxNSoDIy8EG9hxlLfdBsUMUYt"
                                            autocomplete="off"> <input type="hidden" name="id" value="294">
                                        <label class="switcher mx-auto">
                                            <input type="checkbox" class="switcher_input toggle-switch-message"
                                                name="home_status" id="category-status294" value="1" checked
                                                data-modal-id="toggle-status-modal" data-bs-toggle-id="category-status294"
                                                data-on-image="category-status-on.png" data-off-image="category-status-off.png"
                                                data-on-title="Want to Turn ON demoo Status"
                                                data-off-title="Want to Turn OFF demoo Status"
                                                data-on-message="<p>If enabled this category it will be visible from the category wise product section in the website and customer app in the homepage</p>"
                                                data-off-message="<p>If disabled this category it will be hidden from the category wise product section in the website and customer app in the homepage</p>">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </form>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center gap-3">
                                        <a class="btn btn-outline-info icon-btn edit" title="Edit"
                                            data-bs-toggle="offcanvas" href="#editVatTaxOffcanvas">
                                            <i class="fi fi-sr-pencil"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="table-responsive mt-4">
                    <div class="d-flex justify-content-lg-end">

                        <nav>
                            <ul class="pagination">
                                <li class="page-item disabled" aria-disabled="true" aria-label="« Previous">
                                    <span class="page-link" aria-hidden="true">‹</span>
                                </li>
                                <li class="page-item active" aria-current="page"><span class="page-link">1</span></li>
                                <li class="page-item"><a class="page-link"
                                        href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/report/inhouse-product-sale?added_by=in_house&amp;page=2">2</a>
                                </li>
                                <li class="page-item"><a class="page-link"
                                        href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/report/inhouse-product-sale?added_by=in_house&amp;page=3">3</a>
                                </li>
                                <li class="page-item"><a class="page-link"
                                        href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/report/inhouse-product-sale?added_by=in_house&amp;page=4">4</a>
                                </li>
                                <li class="page-item"><a class="page-link"
                                        href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/report/inhouse-product-sale?added_by=in_house&amp;page=5">5</a>
                                </li>
                                <li class="page-item"><a class="page-link"
                                        href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/report/inhouse-product-sale?added_by=in_house&amp;page=6">6</a>
                                </li>
                                <li class="page-item"><a class="page-link"
                                        href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/report/inhouse-product-sale?added_by=in_house&amp;page=7">7</a>
                                </li>
                                <li class="page-item"><a class="page-link"
                                        href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/report/inhouse-product-sale?added_by=in_house&amp;page=8">8</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link"
                                        href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/report/inhouse-product-sale?added_by=in_house&amp;page=2"
                                        rel="next" aria-label="Next »">›</a>
                                </li>
                            </ul>
                        </nav>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="createVatTaxOffcanvas"
        aria-labelledby="offcanvasSubCatFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Create_Vat/Tax') }}</h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-4">
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                    <div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold mb-2" for="">{{ translate('Availability') }}</label>
                            <p class="fs-12 mb-0">{{ translate('if_you_turn_off_this_status_your_tax_calculation_will_effect.') }}</p>
                        </div>
                        <label class="bg-white d-flex justify-content-between align-items-center gap-3 border rounded px-3 py-10 user-select-none">
                            <span class="fw-medium text-dark line-1">{{ translate('Status') }}</span>
                            <label class="switcher">
                                <input type="checkbox" class="switcher_input" value="" checked="" id="" name="">
                                <span class="switcher_control"></span>
                            </label>
                        </label>
                    </div>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                    <div class="form-group mb-20">
                        <label class="form-label" for="">{{ translate('VAT/TAX_Name') }}</label>
                        <input type="text" class="form-control" placeholder="{{translate('Ex:_VAT')}}">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label" for="">{{ translate('VAT/TAX_Rate') }}</label>
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="{{translate('Ex:_5')}}">
                            <div class="input-group-append select-wrapper">
                                <select class="form-select shadow-none">
                                    <option value="1" selected="">%</option>
                                    <option value="2">%</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary flex-grow-1">{{ translate('Reset') }}</button>
                <button type="submit" class="btn btn-primary flex-grow-1">{{ translate('Save') }}</button>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="editVatTaxOffcanvas"
        aria-labelledby="offcanvasSubCatFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Edit_Vat/Tax') }}</h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-4">
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                    <div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold mb-2" for="">{{ translate('Availability') }}</label>
                            <p class="fs-12 mb-0">{{ translate('if_you_turn_off_this_status_your_tax_calculation_will_effect.') }}</p>
                        </div>
                        <label class="bg-white d-flex justify-content-between align-items-center gap-3 border rounded px-3 py-10 user-select-none">
                            <span class="fw-medium text-dark line-1">{{ translate('Status') }}</span>
                            <label class="switcher">
                                <input type="checkbox" class="switcher_input" value="" checked="" id="" name="">
                                <span class="switcher_control"></span>
                            </label>
                        </label>
                    </div>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                    <div class="form-group mb-20">
                        <label class="form-label" for="">{{ translate('VAT/TAX_Name') }}</label>
                        <input type="text" value="vat" class="form-control" placeholder="{{translate('Ex:_VAT')}}">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label" for="">{{ translate('VAT/TAX_Rate') }}</label>
                        <div class="input-group">
                            <input type="text" value="5" class="form-control" placeholder="{{translate('Ex:_5')}}">
                            <div class="input-group-append select-wrapper">
                                <select class="form-select shadow-none">
                                    <option value="1" selected="">%</option>
                                    <option value="2">%</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                    <i class="fi fi-sr-info text-warning"></i>
                    <span>
                        {{ translate('recheck_your_changes_&_make_sure_before_update._when_you_change_it_will_effect_on_all_related') }}
                        <span class="fw-semibold">{{ translate('vat/tax_calculation.') }}</span>
                    </span>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary flex-grow-1">{{ translate('Reset') }}</button>
                <button type="submit" class="btn btn-primary flex-grow-1">{{ translate('Save') }}</button>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        // js Code
    </script>
@endpush
