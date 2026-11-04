<div class="offcanvas-sidebar" id="shareProductOffcanvas" style="--bs-offcanvas-width: 650px;">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

    <div class="offcanvas-content bg-white shadow d-flex flex-column">
        <div class="offcanvas-header d-block p-0">
            <div class="bg-section2 px-4 py-3 d-flex gap-3 align-items-center justify-content-between mb-3">
                <h3 class="mb-0">{{ translate('Share_Product') }}</h3>
                <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;"
                    data-dismiss="offcanvas" aria-label="Close">
                    <i class="fi fi-rr-cross"></i>
                </button>
            </div>
            <div class="bg-section p-12 p-sm-20 rounded-10 mx-3">
                <div class="row g-3">
                    <div class="col-md-6">
                        <select class="custom-select" name="" data-placeholder="{{ translate('Select_from_dropdown') }}">
                            <option></option>
                            <option value="1" selected>{{ translate('All_Vendor') }}</option>
                            <option value="2">Test</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <select class="custom-select" name="" data-placeholder="{{ translate('Select_from_dropdown') }}">
                            <option></option>
                            <option value="1" selected>{{ translate('All_Category') }}</option>
                            <option value="2">Test</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="input-group">
                            <input type="search" class="form-control" placeholder="{{ translate('Search_by_product_info') }}">
                            <div class="input-group-append search-submit">
                                <button type="submit">
                                    <i class="fi fi-rr-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
            <div class="p-12 p-sm-20 bg-section rounded-10 mb-5 overflow-wrap-anywhere">
                <h4 class="mb-3">{{ translate('Product_list') }}</h4>
                <div class="row g-3">
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="rounded shadow overflow-hidden h-100 d-flex flex-column">
                            <div class="share-product-item position-relative h-150">
                                <img class="img-fit" src="{{ dynamicAsset(path: 'public/assets/back-end/img/img-view-demo.jpg') }}" alt="">
                                <div class="share-product-item__overlay">
                                    <div class="d-flex align-items-center gap-3">
                                        <button class="btn btn-outline-success bg-white text-success icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-eye d-flex"></i>
                                        </button>
                                        <button class="btn btn-outline-info bg-white text-info icon-btn" style="--size: 25px;">
                                            <i class="fi fi-sr-copy d-flex"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white px-3 py-2 h-100">
                                <h5 class="fw-medium line-1 mb-1">Nestle Every Day Full Cream. Nestle Every Day Full Cream.</h5>
                                <div class="overflow-wrap-anywhere text-center">
                                    <p class="fs-12 mb-0 text-decoration-line-through">$48.00</p>
                                    <h5 class="fw-bold text-primary mb-0">$44.00</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>