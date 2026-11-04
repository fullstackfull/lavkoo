<h2 class="text-primary text-uppercase my-3">Tables cards</h2>
<div class="d-flex flex-column gap-4">

    <div class="component-snippets-container">
        <div class="component-snippets-preview">
            <div id="liveAlertPlaceholder">
                <div></div>
            </div>
            <div class="card">
                <div class="card-body d-flex flex-column gap-20">
                    <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                        <h3 class="mb-0">{{ translate('list_of_order_wise_shipping_method') }}</h3>
                        <div class="flex-grow-1 max-w-280">
                            <form action="{{ url()->current() }}" method="get">
                                @csrf
                                <div class="input-group">
                                    <input type="search" name="order_search" class="form-control"
                                        placeholder="{{ translate('Search_by_topic') }}" value="{{ requestString('order_search') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-borderless align-middle">
                            <thead class="text-capitalize">
                                <tr>
                                    <th>{{ translate('SL') }}</th>
                                    <th>{{ translate('Title') }}</th>
                                    <th>{{ translate('Shipping Duration') }}</th>
                                    <th>{{ translate('Cost') }} ($)</th>
                                    <th class="text-center">{{ translate('Status') }}</th>
                                    <th class="text-center">{{ translate('action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th>1</th>
                                    <td>order wise shipping</td>
                                    <td>
                                        5-6
                                    </td>
                                    <td>
                                        $100.00
                                    </td>
                                    <td>
                                        <label class="switcher mx-auto">
                                            <input type="checkbox" class="switcher_input toggle-switch-message"
                                                id="shipping-methods-1" name="status" value="1" checked
                                                data-modal-id="toggle-status-modal" data-toggle-id="shipping-methods-1"
                                                data-on-image="category-status-on.png" data-off-image="category-status-off.png"
                                                data-on-title="{{ translate('want_to_Turn_ON_This_Shipping_Method') . '?' }}"
                                                data-off-title="{{ translate('want_to_Turn_OFF_This_Shipping_Method') . '?' }}"
                                                data-on-message="<p>{{ translate('if_you_enable_this_shipping_method_will_be_shown_in_the_user_app_and_website_for_customer_checkout') }}</p>"
                                                data-off-message="<p>{{ translate('if_you_disable_this_shipping_method_will_not_be_shown_in_the_user_app_and_website_for_customer_checkout') }}</p>">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="d-flex justify-content-center gap-3">
                                            <a class="btn btn-outline-info icon-btn edit" title="{{ translate('edit') }}" href="#">
                                                <i class="fi fi-sr-pencil"></i>
                                            </a>
                                            <a title="{{ translate('delete') }}" class="btn btn-outline-danger icon-btn">
                                                <i class="fi fi-rr-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex gap-3 flex-wrap align-items-baseline justify-content-center justify-content-md-between">
                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <div class="select-wrapper">
                                <select class="form-select" name="" data-without-search="-1">
                                    <option value="10">10 Items</option>
                                    <option value="20">20 Items</option>
                                    <option value="30">30 Items</option>
                                    <option value="40">40 Items</option>
                                    <option value="50">50 Items</option>
                                </select>
                            </div>
                            <p class="mb-0 fs-12 fw-medium">Showing 1 To 20 Of 100 Records</p>
                        </div>

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
        <div class="position-relative snippets-code-hover">
            <div class="component-snippets-code-header">
                <ul class="nav nav-tabs" id="myTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#html-tab-pane"
                            type="button" role="tab" aria-controls="html-tab-pane" aria-selected="true">Html</button>
                </ul>
                <button class="btn btn-icon copy-button">
                    <i class="fi fi-rr-copy"></i>
                </button>
            </div>
            <div class="tab-content content position-absolute start-0 top-100 shadow-lg bg-white p-1 z-3 mw-100"
                id="myTabContent">
                <div class="tab-pane fade show active" id="html-tab-pane" role="tabpanel" aria-labelledby="home-tab"
                    tabindex="0">
                    <div class="component-snippets-code-container">
<pre><code><div class="card">
    <div class="card-body d-flex flex-column gap-20">
        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
            <h3 class="mb-0">{{ translate('list_of_order_wise_shipping_method') }}</h3>
            <div class="flex-grow-1 max-w-280">
                <form action="{{ url()->current() }}" method="get">
                    @csrf
                    <div class="input-group">
                        <input type="search" name="order_search" class="form-control"
                            placeholder="{{ translate('Search_by_topic') }}" value="{{ requestString('order_search') }}">
                        <div class="input-group-append search-submit">
                            <button type="submit">
                                <i class="fi fi-rr-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-borderless align-middle">
                <thead class="text-capitalize">
                    <tr>
                        <th>{{ translate('SL') }}</th>
                        <th>{{ translate('Title') }}</th>
                        <th>{{ translate('Shipping Duration') }}</th>
                        <th>{{ translate('Cost') }} ($)</th>
                        <th class="text-center">{{ translate('Status') }}</th>
                        <th class="text-center">{{ translate('action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th>1</th>
                        <td>order wise shipping</td>
                        <td>
                            5-6
                        </td>
                        <td>
                            $100.00
                        </td>
                        <td>
                            <label class="switcher mx-auto">
                                <input type="checkbox" class="switcher_input toggle-switch-message"
                                    id="shipping-methods-1" name="status" value="1" checked
                                    data-modal-id="toggle-status-modal" data-toggle-id="shipping-methods-1"
                                    data-on-image="category-status-on.png" data-off-image="category-status-off.png"
                                    data-on-title="{{ translate('want_to_Turn_ON_This_Shipping_Method') . '?' }}"
                                    data-off-title="{{ translate('want_to_Turn_OFF_This_Shipping_Method') . '?' }}"
                                    data-on-message="<p>{{ translate('if_you_enable_this_shipping_method_will_be_shown_in_the_user_app_and_website_for_customer_checkout') }}</p>"
                                    data-off-message="<p>{{ translate('if_you_disable_this_shipping_method_will_not_be_shown_in_the_user_app_and_website_for_customer_checkout') }}</p>">
                                <span class="switcher_control"></span>
                            </label>
                        </td>
                        <td>
                            <div class="d-flex justify-content-center gap-3">
                                <a class="btn btn-outline-info icon-btn edit" title="{{ translate('edit') }}" href="#">
                                    <i class="fi fi-sr-pencil"></i>
                                </a>
                                <a title="{{ translate('delete') }}" class="btn btn-outline-danger icon-btn">
                                    <i class="fi fi-rr-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="d-flex gap-3 flex-wrap align-items-baseline justify-content-center justify-content-md-between">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div class="select-wrapper">
                    <select class="form-select" name="" data-without-search="-1">
                        <option value="10">10 Items</option>
                        <option value="20">20 Items</option>
                        <option value="30">30 Items</option>
                        <option value="40">40 Items</option>
                        <option value="50">50 Items</option>
                    </select>
                </div>
                <p class="mb-0 fs-12 fw-medium">Showing 1 To 20 Of 100 Records</p>
            </div>

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
</div></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <div class="component-snippets-container">
        <div class="component-snippets-preview">
            <div id="liveAlertPlaceholder">
                <div></div>
            </div>
            <div class="select-wrapper">
                <div class="card">
                    <div class="card-body d-flex flex-column gap-20">
                        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                            <h3 class="mb-0">
                                {{ translate('category_list') }}
                                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">23</span>
                            </h3>
                            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                                <div class="flex-grow-1 max-w-280">
                                    <form action="{{ url()->current() }}" method="GET">
                                        <div class="input-group flex-grow-1 max-w-280">
                                            <input id="" type="search" name="searchValue" class="form-control"
                                                placeholder="{{ translate('search_by_category_name') }}"
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
                                    <a type="button" class="btn btn-outline-primary text-nowrap"
                                        href="{{ route('admin.category.export',['searchValue'=>request('searchValue')]) }}">
                                        <img width="14" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/excel.png')}}"
                                            class="excel" alt="">
                                        <span class="ps-2">{{ translate('export') }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-borderless align-middle">
                                <thead class="text-capitalize">
                                    <tr>
                                        <th>{{ translate('ID') }}</th>
                                        <th class="text-center">{{ translate('category_Image') }}</th>
                                        <th>{{ translate('name') }}</th>
                                        <th class="text-center">{{ translate('priority') }}</th>
                                        <th class="text-center">{{ translate('home_category_status') }}</th>
                                        <th class="text-center">{{ translate('action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>294</td>
                                        <td class="d-flex justify-content-center">
                                            <div class="avatar-60 d-flex align-items-center rounded">
                                                <img class="img-fluid" alt=""
                                                    src="http://localhost/Backend-6Valley-eCommerce-CMS/storage/app/public/category/2025-03-17-67d7b1ec80932.webp">
                                            </div>
                                        </td>
                                        <td>demoo</td>
                                        <td class="text-center">
                                            2
                                        </td>
                                        <td class="text-center">

                                            <form action="http://localhost/Backend-6Valley-eCommerce-CMS/admin/category/status"
                                                method="post" id="category-status294-form">
                                                <input type="hidden" name="_token" value="WZ8YNBCiXnK0cq5yxNSoDIy8EG9hxlLfdBsUMUYt"
                                                    autocomplete="off"> <input type="hidden" name="id" value="294">
                                                <label class="switcher mx-auto">
                                                    <input type="checkbox" class="switcher_input toggle-switch-message"
                                                        name="home_status" id="category-status294" value="1"
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
                                                    href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/category/update/294">
                                                    <i class="fi fi-sr-pencil"></i>
                                                </a>
                                                <a class="btn btn-outline-danger icon-btn delete-category" title="Delete"
                                                    data-product-count="0"
                                                    data-text="There were 0 products under this category.Please update their category from the below list before deleting this one."
                                                    id="294">
                                                    <i class="fi fi-rr-trash"></i>
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
        </div>
        <div class="position-relative snippets-code-hover">
            <div class="component-snippets-code-header">
                <ul class="nav nav-tabs" id="myTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#html-tab-pane"
                            type="button" role="tab" aria-controls="html-tab-pane" aria-selected="true">Html</button>
                </ul>
                <button class="btn btn-icon copy-button">
                    <i class="fi fi-rr-copy"></i>
                </button>
            </div>
            <div class="tab-content content position-absolute start-0 top-100 shadow-lg bg-white p-1 z-3 mw-100"
                id="myTabContent">
                <div class="tab-pane fade show active" id="html-tab-pane" role="tabpanel" aria-labelledby="home-tab"
                    tabindex="0">
                    <div class="component-snippets-code-container">
<pre><code><div class="card">
    <div class="card-body d-flex flex-column gap-20">
        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
            <h3 class="mb-0">
                {{ translate('category_list') }}
                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">23</span>
            </h3>
            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                <div class="flex-grow-1 max-w-280">
                    <form action="{{ url()->current() }}" method="GET">
                        <div class="input-group flex-grow-1 max-w-280">
                            <input id="" type="search" name="searchValue" class="form-control"
                                placeholder="{{ translate('search_by_category_name') }}"
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
                    <a type="button" class="btn btn-outline-primary text-nowrap"
                        href="{{ route('admin.category.export',['searchValue'=>request('searchValue')]) }}">
                        <img width="14" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/excel.png')}}"
                            class="excel" alt="">
                        <span class="ps-2">{{ translate('export') }}</span>
                    </a>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-borderless align-middle">
                <thead class="text-capitalize">
                    <tr>
                        <th>{{ translate('ID') }}</th>
                        <th class="text-center">{{ translate('category_Image') }}</th>
                        <th>{{ translate('name') }}</th>
                        <th class="text-center">{{ translate('priority') }}</th>
                        <th class="text-center">{{ translate('home_category_status') }}</th>
                        <th class="text-center">{{ translate('action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>294</td>
                        <td class="d-flex justify-content-center">
                            <div class="avatar-60 d-flex align-items-center rounded">
                                <img class="img-fluid" alt=""
                                    src="http://localhost/Backend-6Valley-eCommerce-CMS/storage/app/public/category/2025-03-17-67d7b1ec80932.webp">
                            </div>
                        </td>
                        <td>demoo</td>
                        <td class="text-center">
                            2
                        </td>
                        <td class="text-center">

                            <form action="http://localhost/Backend-6Valley-eCommerce-CMS/admin/category/status"
                                method="post" id="category-status294-form">
                                <input type="hidden" name="_token" value="WZ8YNBCiXnK0cq5yxNSoDIy8EG9hxlLfdBsUMUYt"
                                    autocomplete="off"> <input type="hidden" name="id" value="294">
                                <label class="switcher mx-auto">
                                    <input type="checkbox" class="switcher_input toggle-switch-message"
                                        name="home_status" id="category-status294" value="1"
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
                                    href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/category/update/294">
                                    <i class="fi fi-sr-pencil"></i>
                                </a>
                                <a class="btn btn-outline-danger icon-btn delete-category" title="Delete"
                                    data-product-count="0"
                                    data-text="There were 0 products under this category.Please update their category from the below list before deleting this one."
                                    id="294">
                                    <i class="fi fi-rr-trash"></i>
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
</div></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h3 class="text-primary text-uppercase my-3">Tables</h3>

            <div class="component-snippets-container">
                <div class="component-snippets-preview">
                    <div id="liveAlertPlaceholder">
                        <div></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-borderless align-middle">
                            <thead class="text-capitalize">
                                <tr>
                                    <th>{{ translate('ID') }}</th>
                                    <th class="text-center">{{ translate('category_Image') }}</th>
                                    <th>{{ translate('name') }}</th>
                                    <th class="text-center">{{ translate('priority') }}</th>
                                    <th class="text-center">{{ translate('home_category_status') }}</th>
                                    <th class="text-center">{{ translate('action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>294</td>
                                    <td class="d-flex justify-content-center">
                                        <div class="avatar-60 d-flex align-items-center rounded">
                                            <img class="img-fluid" alt=""
                                                src="http://localhost/Backend-6Valley-eCommerce-CMS/storage/app/public/category/2025-03-17-67d7b1ec80932.webp">
                                        </div>
                                    </td>
                                    <td>demoo</td>
                                    <td class="text-center">
                                        2
                                    </td>
                                    <td class="text-center">

                                        <form action="http://localhost/Backend-6Valley-eCommerce-CMS/admin/category/status" method="post"
                                            id="category-status294-form">
                                            <input type="hidden" name="_token" value="WZ8YNBCiXnK0cq5yxNSoDIy8EG9hxlLfdBsUMUYt"
                                                autocomplete="off"> <input type="hidden" name="id" value="294">
                                            <label class="switcher mx-auto">
                                                <input type="checkbox" class="switcher_input toggle-switch-message" name="home_status"
                                                    id="category-status294" value="1" data-modal-id="toggle-status-modal"
                                                    data-bs-toggle-id="category-status294" data-on-image="category-status-on.png"
                                                    data-off-image="category-status-off.png" data-on-title="Want to Turn ON demoo Status"
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
                                                href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/category/update/294">
                                                <i class="fi fi-sr-pencil"></i>
                                            </a>
                                            <a class="btn btn-outline-danger icon-btn delete-category" title="Delete" data-product-count="0"
                                                data-text="There were 0 products under this category.Please update their category from the below list before deleting this one."
                                                id="294">
                                                <i class="fi fi-rr-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="position-relative snippets-code-hover">
                    <div class="component-snippets-code-header">
                        <ul class="nav nav-tabs" id="myTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#html-tab-pane"
                                    type="button" role="tab" aria-controls="html-tab-pane" aria-selected="true">Html</button>
                        </ul>
                        <button class="btn btn-icon copy-button">
                            <i class="fi fi-rr-copy"></i>
                        </button>
                    </div>
                    <div class="tab-content content position-absolute start-0 top-100 shadow-lg bg-white p-1 z-3 mw-100"
                        id="myTabContent">
                        <div class="tab-pane fade show active" id="html-tab-pane" role="tabpanel" aria-labelledby="home-tab"
                            tabindex="0">
                            <div class="component-snippets-code-container">
<pre><code><div class="table-responsive">
    <table class="table table-hover table-borderless align-middle">
        <thead class="text-capitalize">
            <tr>
                <th>{{ translate('ID') }}</th>
                <th class="text-center">{{ translate('category_Image') }}</th>
                <th>{{ translate('name') }}</th>
                <th class="text-center">{{ translate('priority') }}</th>
                <th class="text-center">{{ translate('home_category_status') }}</th>
                <th class="text-center">{{ translate('action') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>294</td>
                <td class="d-flex justify-content-center">
                    <div class="avatar-60 d-flex align-items-center rounded">
                        <img class="img-fluid" alt=""
                            src="http://localhost/Backend-6Valley-eCommerce-CMS/storage/app/public/category/2025-03-17-67d7b1ec80932.webp">
                    </div>
                </td>
                <td>demoo</td>
                <td class="text-center">
                    2
                </td>
                <td class="text-center">

                    <form action="http://localhost/Backend-6Valley-eCommerce-CMS/admin/category/status" method="post"
                        id="category-status294-form">
                        <input type="hidden" name="_token" value="WZ8YNBCiXnK0cq5yxNSoDIy8EG9hxlLfdBsUMUYt"
                            autocomplete="off"> <input type="hidden" name="id" value="294">
                        <label class="switcher mx-auto">
                            <input type="checkbox" class="switcher_input toggle-switch-message" name="home_status"
                                id="category-status294" value="1" data-modal-id="toggle-status-modal"
                                data-bs-toggle-id="category-status294" data-on-image="category-status-on.png"
                                data-off-image="category-status-off.png" data-on-title="Want to Turn ON demoo Status"
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
                            href="http://localhost/Backend-6Valley-eCommerce-CMS/admin/category/update/294">
                            <i class="fi fi-sr-pencil"></i>
                        </a>
                        <a class="btn btn-outline-danger icon-btn delete-category" title="Delete" data-product-count="0"
                            data-text="There were 0 products under this category.Please update their category from the below list before deleting this one."
                            id="294">
                            <i class="fi fi-rr-trash"></i>
                        </a>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</div></code></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <h3 class="text-primary text-uppercase my-3">Paginations</h3>
            <div class="mb-3">

                <div class="component-snippets-container">
                    <div class="component-snippets-preview">
                        <div id="liveAlertPlaceholder">
                            <div></div>
                        </div>
                        <div class="table-responsive mt-4">
                            <div class="d-flex justify-content-lg-end">
                                <!-- dynamic code will be here -->
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
                                <!-- dynamic code ends -->
                            </div>
                        </div>
                    </div>
                    <div class="position-relative snippets-code-hover">
                        <div class="component-snippets-code-header">
                            <ul class="nav nav-tabs" id="myTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#html-tab-pane"
                                        type="button" role="tab" aria-controls="html-tab-pane" aria-selected="true">Html</button>
                            </ul>
                            <button class="btn btn-icon copy-button">
                                <i class="fi fi-rr-copy"></i>
                            </button>
                        </div>
                        <div class="tab-content content position-absolute start-0 top-100 shadow-lg bg-white p-1 z-3 mw-100"
                            id="myTabContent">
                            <div class="tab-pane fade show active" id="html-tab-pane" role="tabpanel" aria-labelledby="home-tab"
                                tabindex="0">
                                <div class="component-snippets-code-container">
<pre><code><div class="table-responsive mt-4">
    <div class="d-flex justify-content-lg-end">
        <!-- dynamic code will be here -->
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
        <!-- dynamic code ends -->
    </div>
</div></code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div>

                <div class="component-snippets-container">
                    <div class="component-snippets-preview">
                        <div id="liveAlertPlaceholder">
                            <div></div>
                        </div>
                         <!-- pagination  -->
                        <div class="d-flex gap-3 flex-wrap align-items-baseline justify-content-center justify-content-md-between">
                            <div class="d-flex gap-2 flex-wrap align-items-center">
                                <div class="select-wrapper">
                                    <select class="form-select" name="" data-without-search="-1">
                                        <option value="10">10 Items</option>
                                        <option value="20">20 Items</option>
                                        <option value="30">30 Items</option>
                                        <option value="40">40 Items</option>
                                        <option value="50">50 Items</option>
                                    </select>
                                </div>
                                <p class="mb-0 fs-12 fw-medium">Showing 1 To 20 Of 100 Records</p>
                            </div>
                            <!-- dynamic code will be here -->
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
                            <!-- dynamic code ends -->
                        </div>
                    </div>
                    <div class="position-relative snippets-code-hover">
                        <div class="component-snippets-code-header">
                            <ul class="nav nav-tabs" id="myTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#html-tab-pane"
                                        type="button" role="tab" aria-controls="html-tab-pane" aria-selected="true">Html</button>
                            </ul>
                            <button class="btn btn-icon copy-button">
                                <i class="fi fi-rr-copy"></i>
                            </button>
                        </div>
                        <div class="tab-content content position-absolute start-0 top-100 shadow-lg bg-white p-1 z-3 mw-100"
                            id="myTabContent">
                            <div class="tab-pane fade show active" id="html-tab-pane" role="tabpanel" aria-labelledby="home-tab"
                                tabindex="0">
                                <div class="component-snippets-code-container">
<pre><code> <!-- pagination  -->
 <div class="d-flex gap-3 flex-wrap align-items-baseline justify-content-center justify-content-md-between">
     <div class="d-flex gap-2 flex-wrap align-items-center">
         <div class="select-wrapper">
             <select class="form-select" name="" data-without-search="-1">
                 <option value="10">10 Items</option>
                 <option value="20">20 Items</option>
                 <option value="30">30 Items</option>
                 <option value="40">40 Items</option>
                 <option value="50">50 Items</option>
             </select>
         </div>
         <p class="mb-0 fs-12 fw-medium">Showing 1 To 20 Of 100 Records</p>
     </div>
     <!-- dynamic code will be here -->
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
     <!-- dynamic code ends -->
 </div></code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
