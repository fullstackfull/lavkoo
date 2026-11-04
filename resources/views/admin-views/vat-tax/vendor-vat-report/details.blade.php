@extends('layouts.admin.app')

@section('title', translate('Vendor_VAT_Report-Details'))

@push('css_or_js')
    <style>
        /*css Code*/
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-20">
            <div class="flex-grow-1">
                <h2 class="fs-20 mb-1">
                 {{ translate('VAT_Details') }} - {{ translate('Hungary_Puppet') }}
                </h2>
                <p class="mb-0">Date: 25 Feb, 2024 - 25 Apr, 2024</p>
            </div>
            <button class="btn btn-primary min-w-120">
                <i class="fi fi-rr-arrow-small-left"></i>
                {{ translate('Back_to_List') }}
            </button>
        </div>
        <div class="card card-body mb-20">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="d-flex align-items-center h-100">
                        <a class="w-100 h-100 bg-info-dark bg-opacity-10 d-flex align-items-center gap-2 justify-content-between p-3 rounded-10"
                            href="#">
                            <div class="d-flex gap-2 align-items-center">
                                <img width="30" class="aspect-1" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-order-amount.png') }}" alt="">
                                <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Order_Amount') }}">{{ translate('Total_Order_Amount') }}</h4>
                            </div>
                            <span class="overflow-wrap-anywhere fw-bold text-info h2 mb-0">$12,345.25</span>
                        </a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="d-flex align-items-center h-100">
                        <a class="w-100 h-100 bg-warning-dark bg-opacity-10 d-flex align-items-center gap-2 justify-content-between p-3 rounded-10"
                            href="#">
                            <div class="d-flex gap-2 align-items-center">
                                <img width="30" class="aspect-1" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-vat-amount.png') }}" alt="">
                                <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_VAT_Amount') }}">{{ translate('Total_VAT_Amount') }}</h4>
                            </div>
                            <span class="overflow-wrap-anywhere fw-bold text-warning h2 mb-0">$325.00</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body d-flex flex-column gap-20">
                <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                    <h3 class="mb-0">
                        {{ translate('all_vendor_vat_list') }}
                        <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">23</span>
                    </h3>
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                        <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                            <form action="{{ url()->current() }}" method="GET">
                                <div class="input-group">
                                    <input id="" type="search" name="searchValue" class="form-control"
                                        placeholder="{{ translate('search_by_VAT_name') }}"
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
                    </div>
                </div>
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('SL') }}</th>
                                <th>{{ translate('Order_ID') }}</th>
                                <th>{{ translate('Order_Amount') }}</th>
                                <th>{{ translate('VAT_Type') }}</th>
                                <th>{{ translate('VAT_Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>#100124</td>
                                <td>$52</td>
                                <td>$Order Wise</td>
                                <td>
                                    <div>
                                        <div class="d-flex gap-2">
                                            <span class="min-w-40"> {{ translate('Total') }}:</span>
                                            <span>$ 6.00</span>
                                        </div>
                                        <div class="d-flex gap-2 fs-12 text-body">
                                            <span class="min-w-40"> {{ translate('VAT') }}:</span>
                                            <span>$ 6.00</span>
                                        </div>
                                        <div class="d-flex gap-2 fs-12 text-body">
                                            <span class="min-w-40"> {{ translate('GST') }}:</span>
                                            <span>$ 6.00</span>
                                        </div>
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
@endsection

@push('script')
    <script>
        // js Code
    </script>
@endpush
