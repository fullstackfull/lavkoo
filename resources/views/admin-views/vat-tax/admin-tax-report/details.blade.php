@extends('layouts.admin.app')

@section('title', translate('admin_tax_report_-_tax_details_view'))

@push('css_or_js')
    <style>

    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-20">
            <div class="flex-grow-1">
                <h2 class="fs-20 mb-1">
                 {{ translate('Tax_Report') }} - {{ translate('Order_Commission') }}
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
                <div class="col-xl-3 col-sm-6">
                    <a class="bg-body p-3 rounded d-flex align-items-center h-100"
                        href="#">
                        <div>
                            <h2 class="overflow-wrap-anywhere fw-bold text-success mb-1">$12,345.25</h2>
                            <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Order') }}">{{ translate('Total_Order') }}</h4>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <a class="bg-body p-3 rounded d-flex align-items-center h-100"
                        href="#">
                        <div>
                            <h2 class="overflow-wrap-anywhere fw-bold text-info mb-1">$12,345.25</h2>
                            <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Order_Amount') }}">{{ translate('Total_Order_Amount') }}</h4>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <a class="bg-body p-3 rounded d-flex align-items-center h-100"
                        href="#">
                        <div>
                            <h2 class="overflow-wrap-anywhere fw-bold text-warning mb-1">$325.00</h2>
                            <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Commission') }}">{{ translate('Total_Commission') }}</h4>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <a class="bg-body p-3 rounded d-flex align-items-center h-100"
                        href="#">
                        <div>
                            <h2 class="overflow-wrap-anywhere fw-bold text-warning-dark mb-1">$325.00</h2>
                            <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Tax_Amount') }}">{{ translate('Total_Tax_Amount') }}</h4>
                        </div>
                    </a>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body d-flex flex-column gap-20">
                <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                    <h3 class="mb-0">
                        {{ translate('Order_List') }}
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
                                <th>{{ translate('Order') }}</th>
                                <th>{{ translate('Transection') }}</th>
                                <th>{{ translate('Commission') }}</th>
                                <th>{{ translate('Tax') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>
                                    <div class="fw-semibold">$250.25</div>
                                    <div class="fs-12 text-body">#100124</div>
                                </td>
                                <td>
                                    <div>ET20740032</div>
                                    <div class="fs-12 text-body">24 Jul 2025</div>
                                </td>
                                <td>$50.25</td>
                                <td>
                                    <div>
                                        <div class="d-flex gap-1">
                                            <span class="min-w-120"> {{ translate('Total_Tax') }} (20%)</span>:
                                            <span>$ 10.05</span>
                                        </div>
                                        <div class="d-flex gap-1 fs-12 text-body">
                                            <span class="min-w-120"> {{ translate('VAT') }} (5%)</span>:
                                            <span>$ 2.51</span>
                                        </div>
                                        <div class="d-flex gap-1 fs-12 text-body">
                                            <span class="min-w-120"> {{ translate('GST') }} (15%)</span>:
                                            <span>$ 7.54</span>
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

    </script>
@endpush
