<form action="{{ route('vendor.reviews.index') }}" method="get">
    <div class="offcanvas-sidebar offcanvasAddNewCustomer" id="reviewFilterOffcanvas">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content bg-white shadow d-flex flex-column">

            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="m-0">{{ translate('Filter') }}</h3>
                <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">

                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                    <div class="row g-2 date-filter-wrapper">
                        <div class="col-12">
                            <label for="" class="form-label text-dark mb-2">
                                {{ translate('Show_Data') }}
                            </label>
                            <div class="select-wrapper">
                            </div>
                            <select name="" id="" class="form-control date-type-select">
                                <option value="">{{ translate('All_Time') }}</option>
                                <option value="custom" selected>{{ translate('Custom_date') }}</option>
                            </select>
                        </div>
                        <div class="col-sm-6 custom-date-div d--none">
                            <div>
                                <label for="start-date-time" class="form-label text-dark fs-12 mb-2">{{ translate('Start_Date') }}</label>
                                <input type="date"
                                   name="from"
                                   id="start-date-time"
                                   value="{{ $from ?? '' }}"
                                   class="form-control"
                                   title="{{ translate('from_date') }}">
                            </div>
                        </div>
                        <div class="col-sm-6 custom-date-div d--none">
                            <div>
                                <label for="end-date-time" class="form-label text-dark fs-12 mb-2">{{ translate('End_Date') }}</label>
                                <input type="date"
                                   name="to"
                                   id="end-date-time"
                                   value="{{ $to ?? '' }}"
                                   class="form-control"
                                   title="{{ translate('to_date') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                    <div class="mb-20">
                        <h4 class="mb-2 fz-14 fw-normal">{{ translate('Products') }}</h4>
                        <div class="dropdown select-product-search w-100">
                            <input type="text" class="product_id" name="product_id"
                                   value="{{ requestString('product_id') }}" hidden>

                            <button class="form-control text-start selected-product-name text-truncate select-product-button dropdown--toggle"
                                    data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" type="button">
                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        {{ request('product_id') != null ? $product['name'] : translate('select_Product') }}
                                    </div>
                            </button>

                            <div class="dropdown-menu w-100 px-2">
                                <div class="search-form mb-3">
                                    <button type="button" class="btn h-100" aria-label="{{ translate('search') }}"><i class="tio-search"></i></button>
                                    <input type="text" class="js-form-search form-control search-bar-input search-product"
                                           placeholder="{{ translate('search menu').'...' }}">
                                </div>
                                <div class="d-flex flex-column gap-3 max-h-40vh overflow-y-auto overflow-x-hidden search-result-box">
                                    @include('vendor-views.partials._search-product', ['products'=> $products])
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-20">
                        <h4 class="mb-2 fz-14 fw-normal">{{ translate('Customer') }}</h4>

                        <input type="hidden" id="customer_id" name="customer_id"
                               value="{{ request('customer_id') ? request('customer_id') : 'all' }}">
                        <select data-placeholder="
                            @if($customer == 'all')
                                {{ translate('All_Customer') }}
                            @else
                                {{ $customer['text'] ?? translate('All_Customer') }}
                            @endif"
                                class="form-select form-ellipsis set-customer-value custom-select">
                            <option value="all">{{ translate('All_Customer') }}</option>
                            @foreach($customers as $item)
                                <option value="{{ $item['id'] }}" @if(request('customer_id') == $item['id']) selected @endif>
                                    {{ $item['text'] }}
                                </option>
                            @endforeach

                        </select>
                    </div>

                    <div class="mb-0">
                        <h4 class="mb-2 fz-14 fw-normal">{{ translate('Status') }}</h4>

                        <select class="form-control custom-select" name="status">
                            <option value="">{{ '---'.translate('select_status').'---' }}</option>
                            <option value="1" {{ isset($status) && $status == 1 ? 'selected' : '' }}>
                                {{ translate('active') }}
                            </option>
                            <option value="0" {{ isset($status) && $status == 0 ? 'selected' : '' }}>
                                {{ translate('inactive') }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup">
                <div class="d-flex justify-content-center gap-3 bg-white px-3 py-2">
                    <a href="{{ route('vendor.reviews.index') }}" class="btn btn-secondary w-100">
                        {{ translate('Clear Filter') }}
                    </a>

                    <button type="submit" class="btn btn--primary w-100">
                        {{ translate('Apply') }}
                    </button>
                </div>
            </div>

        </div>
    </div>
</form>
