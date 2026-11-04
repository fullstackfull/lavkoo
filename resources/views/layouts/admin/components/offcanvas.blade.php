<h2 class="text-primary text-uppercase my-3">Offcanvas</h2>
<div class="row g-3">
    <div class="col-lg-6">

        <div class="component-snippets-container">
            <div class="component-snippets-preview">
                <div id="liveAlertPlaceholder">
                    <div></div>
                </div>
               <div>
                    <!-- Triggering button -->
                    <buton type="btn" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSubCatFilter">
                        <i class="fi fi-rr-bars-filter"></i> {{ translate('Filter') }}
                    </buton>

                    <!-- Offcanvas -->
                    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSubCatFilter"
                        aria-labelledby="offcanvasSubCatFilterLabel">
                        <div class="offcanvas-header bg-body">
                            <h3 class="mb-0">{{ translate('Filter') }}</h3>
                            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                                <i class="fi fi-rr-cross"></i>
                            </button>
                        </div>
                        <div class="offcanvas-body">
                            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                                <label for="" class="form-label">{{ translate('Sorting') }}</label>
                                <div class="bg-white rounded p-3">
                                    <div class="row g-3">
                                        <div class="col-sm-6">

                                            <div class="d-flex gap-2">
                                                <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                                    id="inlineRadio1" value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineRadio1">{{ translate('New to Oldest') }}</label>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                                    id="inlineRadio2" value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineRadio2">{{ translate('Oldest to New') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                                    id="inlineRadio3" value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineRadio3">{{ translate('Last modify') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                                    id="inlineRadio4" value="option1">
                                                <label class="form-check-label fs-12" for="inlineRadio4">{{ translate('A - Z') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                                    id="inlineRadio5" value="option1">
                                                <label class="form-check-label fs-12" for="inlineRadio5">{{ translate('Z - A') }}</label>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                                <label for="" class="form-label">{{ translate('Category') }}</label>
                                <div class="bg-white rounded p-3">
                                    <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                                        <div class="col-sm-6">

                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox1"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox1">{{ translate('Fashion & Life Style') }}</label>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox2"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox2">{{ translate('Books & Stationery') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox3"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox3">{{ translate('Men Fashion') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox4"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox4">{{ translate('Women Fashion') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox5"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox5">{{ translate('Shoe Fashion') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox5"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox5">{{ translate('Bags & Life Style') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox6"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox6">{{ translate('Bags & Life Style') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox7"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox7">{{ translate('Bags & Life Style') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox8"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox8">{{ translate('Bags & Life Style') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox9"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox9">{{ translate('Bags & Life Style') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-sm-6">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox10"
                                                    value="option1">
                                                <label class="form-check-label fs-12"
                                                    for="inlineCheckbox10">{{ translate('Bags & Life Style') }}</label>
                                            </div>

                                        </div>
                                        <div class="col-12 text-center">
                                            <a href="javascript:" class="text-info-dark fw-semibold">{{ translate('See_More') }}
                                                <span>(6)</span></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="offcanvas-footer shadow-popup">
                            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                                <button type="reset" class="btn btn-secondary flex-grow-1">{{ translate('Reset_Filter') }}</button>
                                <button type="submit" class="btn btn-primary flex-grow-1">{{ translate('Apply') }}</button>
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
<pre><code><!-- Triggering button -->
<buton type="btn" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSubCatFilter">
    <i class="fi fi-rr-bars-filter"></i> {{ translate('Filter') }}
</buton>

<!-- Offcanvas -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSubCatFilter"
    aria-labelledby="offcanvasSubCatFilterLabel">
    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Filter') }}</h3>
        <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
            <i class="fi fi-rr-cross"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
            <label for="" class="form-label">{{ translate('Sorting') }}</label>
            <div class="bg-white rounded p-3">
                <div class="row g-3">
                    <div class="col-sm-6">

                        <div class="d-flex gap-2">
                            <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                id="inlineRadio1" value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineRadio1">{{ translate('New to Oldest') }}</label>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                id="inlineRadio2" value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineRadio2">{{ translate('Oldest to New') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                id="inlineRadio3" value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineRadio3">{{ translate('Last modify') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                id="inlineRadio4" value="option1">
                            <label class="form-check-label fs-12" for="inlineRadio4">{{ translate('A - Z') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input radio--input" type="radio" name="inlineRadioOptions"
                                id="inlineRadio5" value="option1">
                            <label class="form-check-label fs-12" for="inlineRadio5">{{ translate('Z - A') }}</label>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
            <label for="" class="form-label">{{ translate('Category') }}</label>
            <div class="bg-white rounded p-3">
                <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                    <div class="col-sm-6">

                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox1"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox1">{{ translate('Fashion & Life Style') }}</label>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox2"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox2">{{ translate('Books & Stationery') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox3"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox3">{{ translate('Men Fashion') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox4"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox4">{{ translate('Women Fashion') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox5"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox5">{{ translate('Shoe Fashion') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox5"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox5">{{ translate('Bags & Life Style') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox6"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox6">{{ translate('Bags & Life Style') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox7"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox7">{{ translate('Bags & Life Style') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox8"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox8">{{ translate('Bags & Life Style') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox9"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox9">{{ translate('Bags & Life Style') }}</label>
                        </div>

                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input" type="checkbox" id="inlineCheckbox10"
                                value="option1">
                            <label class="form-check-label fs-12"
                                for="inlineCheckbox10">{{ translate('Bags & Life Style') }}</label>
                        </div>

                    </div>
                    <div class="col-12 text-center">
                        <a href="javascript:" class="text-info-dark fw-semibold">{{ translate('See_More') }}
                            <span>(6)</span></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="offcanvas-footer shadow-popup">
        <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
            <button type="reset" class="btn btn-secondary flex-grow-1">{{ translate('Reset_Filter') }}</button>
            <button type="submit" class="btn btn-primary flex-grow-1">{{ translate('Apply') }}</button>
        </div>
    </div>
</div></code></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <div class="col-lg-6">

        <div class="component-snippets-container">
            <div class="component-snippets-preview">
                <div id="liveAlertPlaceholder">
                    <div></div>
                </div>
               <div>
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                                <div>
                                    <h2>
                                        Our Commitments
                                    </h2>
                                    <p class="mb-0 fs-12">
                                        This page you can setup you website Company Reliability section.
                                    </p>
                                </div>
                                <div>
                                    <!-- Triggering button -->
                                    <button class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvasSetupGuide">
                                        <i class="fi fi-sr-eye"></i>
                                        View OffCanvas
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Offcanvas -->
                    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel">
                        <div class="offcanvas-header bg-body">
                            <h3 class="mb-0">Business Setup Guideline</h3>
                            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                                <i class="fi fi-rr-cross"></i>
                            </button>
                        </div>
                        <div class="offcanvas-body">
                            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                                <div class="d-flex gap-3 align-items-center justify-content-between">
                                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseGS">
                                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                                            <i class="fi fi-sr-angle-right"></i>
                                        </div>
                                        <span class="fw-bold">{{ translate('General Settings') }}</span>
                                    </button>

                                        <a href="#" class="btn btn-text btn-sm text-decoration-underline">{{ translate('Let’s Setup') }}</a>
                                    </div>

                                <div class="collapse mt-3" id="collapseGS">
                                    <div class="card card-body">
                                        <h4>Maintenance Mode</h4>
                                        <p class="fs-12">Turn on the Maintenance Mode will temporarily deactivate your selected systems as of your chosen date and time. Select the systems you want to temporarily deactivate for maintenance mode.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                                <div class="d-flex gap-3 align-items-center justify-content-between">
                                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseBI">
                                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark">
                                            <i class="fi fi-sr-angle-right"></i>
                                        </div>
                                        <span class="fw-bold">{{ translate('Basic Information') }}</span>
                                    </button>

                                    <a href="#" class="btn btn-text btn-sm text-decoration-underline">{{ translate('Let’s Setup') }}</a>
                                </div>

                                <div class="collapse mt-3" id="collapseBI">
                                    <div class="card card-body">
                                        <!-- Swiper -->
                                        <div class="swiper mySwiper2 mw-100">
                                            <div class="swiper-wrapper">
                                                <div class="swiper-slide">
                                                    <h4>Address</h4>
                                                    <p class="js-truncate-text fs-12">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam odio tellus, laoreet pharetra auctor eget, fringilla nec lectus. Nullam in feugiat est. Nam in interdum ligula, non elementum purus. Aenean eu lectus diam. Cras elementum neque sed nibh consequat, nec gravida purus vehicula. Morbi  Learn more </p>
                                                    <div class="position-relative mt-3">
                                                        <!-- Swiper -->
                                                        <div class="swiper mySwiper  mw-100">
                                                            <div class="swiper-wrapper">
                                                                <div class="swiper-slide">
                                                                    <img class="w-100" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}" alt="">
                                                                </div>
                                                                <div class="swiper-slide">
                                                                    <img class="w-100" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}" alt="">
                                                                </div>
                                                                <div class="swiper-slide">
                                                                    <img class="w-100" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}" alt="">
                                                                </div>
                                                            </div>
                                                            <div class="swiper-pagination1 d-flex justify-content-center mt-2"></div>
                                                        </div>
                                                        <div class="swiper-button-next swiper-button-next1"></div>
                                                        <div class="swiper-button-prev swiper-button-prev1"></div>
                                                    </div>
                                                </div>
                                                <div class="swiper-slide">
                                                    <h4>Address</h4>
                                                    <p class="js-truncate-text fs-12">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam odio tellus, laoreet pharetra auctor eget, fringilla nec lectus. Nullam in feugiat est. Nam in interdum ligula, non elementum purus. Aenean eu lectus diam. Cras elementum neque sed nibh consequat, nec gravida purus vehicula. Morbi  Learn more </p>
                                                    <div class="position-relative mt-3">
                                                        <!-- Swiper -->
                                                        <div class="swiper mySwiper  mw-100">
                                                            <div class="swiper-wrapper">
                                                                <div class="swiper-slide">
                                                                    <img class="w-100" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}" alt="">
                                                                </div>
                                                                <div class="swiper-slide">
                                                                    <img class="w-100" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}" alt="">
                                                                </div>
                                                                <div class="swiper-slide">
                                                                    <img class="w-100" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}" alt="">
                                                                </div>
                                                            </div>
                                                            <div class="swiper-pagination1 d-flex justify-content-center mt-2"></div>
                                                        </div>
                                                        <div class="swiper-button-next swiper-button-next1"></div>
                                                        <div class="swiper-button-prev swiper-button-prev1"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-center align-items-center gap-3 mt-3">
                                        <div class="swiper-button-prev swiper-button-prev2 position-static m-0"></div>
                                        <div class="swiper-pagination2 w-auto"></div>
                                        <div class="swiper-button-next swiper-button-next2 position-static m-0"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                                <div class="d-flex gap-3 align-items-center justify-content-between">
                                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNewSlider">
                                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark">
                                            <i class="fi fi-sr-angle-right"></i>
                                        </div>
                                        <span class="fw-bold">{{ translate('New_Slider') }}</span>
                                    </button>
                                </div>

                                <div class="collapse mt-3" id="collapseNewSlider">
                                    <div class="card card-body">
                                        <!-- Swiper -->
                                        <h4>Address</h4>
                                        <p class="fs-12">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam odio tellus, laoreet
                                            pharetra auctor eget, fringilla nec lectus. Nullam in feugiat est. Nam in interdum ligula, non elementum purus.
                                            Aenean eu lectus diam. Cras elementum neque sed nibh consequat, nec gravida purus vehicula. Morbi Learn more </p>
                                        <div class="myOffcanvasSwiper_Wrapper position-relative mt-3">
                                            <!-- Swiper -->
                                            <div class="swiper myOffcanvasSwiper  mw-100">
                                                <div class="swiper-wrapper">
                                                    <div class="swiper-slide">
                                                        <div class="border rounded-10 h-100 d-flex justify-content-center align-items-center">
                                                            <img class="w-100 rounded-10" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/guideline-slider-demo-1.png')}}" alt="">
                                                        </div>
                                                    </div>
                                                    <div class="swiper-slide">
                                                        <div class="border rounded-10 h-100 d-flex justify-content-center align-items-center">
                                                            <img class="w-100 rounded-10" src="{{dynamicAsset(path: 'public/assets/new/back-end/img/guideline-slider-demo-2.png')}}" alt="">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="swiper-pagination-bullets d-flex justify-content-center mt-2"></div>
                                            </div>
                                            <div class="swiper-button-next bullet-next"></div>
                                            <div class="swiper-button-prev bullet-prev"></div>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-center align-items-center gap-3 mt-3">
                                        <div class="swiper-button-prev swiper-button-prev-offcanvas position-static m-0"></div>
                                        <div class="swiper-pagination-fraction w-auto"></div>
                                        <div class="swiper-button-next swiper-button-next-offcanvas position-static m-0"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="offcanvas-footer">
                            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                                <button type="button" class="d-flex align-items-center gap-2 text-primary bg-transparent border-0 p-0">{{ translate('see_More') }} <i class="fi fi-rr-angle-right"></i></button>
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
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h2>
                    Our Commitments
                </h2>
                <p class="mb-0 fs-12">
                    This page you can setup you website Company Reliability section.
                </p>
            </div>
            <div>
                <!-- Triggering button -->
                <button class="btn btn-outline-primary" data-bs-toggle="offcanvas"
                    data-bs-target="#offcanvasSetupGuide">
                    <i class="fi fi-sr-eye"></i>
                    View OffCanvas
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Offcanvas -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel">
    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">Business Setup Guideline</h3>
        <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
            <i class="fi fi-rr-cross"></i>
        </button>
    </div>
    <div class="offcanvas-body">
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                    data-bs-toggle="collapse" data-bs-target="#collapseGS">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold">{{ translate('General Settings') }}</span>
                </button>

                <a href="#" class="btn btn-text btn-sm text-decoration-underline">{{ translate('Let’s Setup') }}</a>
            </div>

            <div class="collapse mt-3" id="collapseGS">
                <div class="card card-body">
                    <h4>Maintenance Mode</h4>
                    <p class="fs-12">Turn on the Maintenance Mode will temporarily deactivate your selected systems as
                        of your chosen date and time. Select the systems you want to temporarily deactivate for
                        maintenance mode.</p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed"
                    type="button" data-bs-toggle="collapse" data-bs-target="#collapseBI">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold">{{ translate('Basic Information') }}</span>
                </button>

                <a href="#" class="btn btn-text btn-sm text-decoration-underline">{{ translate('Let’s Setup') }}</a>
            </div>

            <div class="collapse mt-3" id="collapseBI">
                <div class="card card-body">
                    <!-- Swiper -->
                    <div class="swiper mySwiper2 mw-100">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <h4>Address</h4>
                                <p class="js-truncate-text fs-12">Lorem ipsum dolor sit amet, consectetur adipiscing
                                    elit. Aliquam odio tellus, laoreet pharetra auctor eget, fringilla nec lectus.
                                    Nullam in feugiat est. Nam in interdum ligula, non elementum purus. Aenean eu lectus
                                    diam. Cras elementum neque sed nibh consequat, nec gravida purus vehicula. Morbi
                                    Learn more </p>
                                <div class="position-relative mt-3">
                                    <!-- Swiper -->
                                    <div class="swiper mySwiper  mw-100">
                                        <div class="swiper-wrapper">
                                            <div class="swiper-slide">
                                                <img class="w-100"
                                                    src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}"
                                                    alt="">
                                            </div>
                                            <div class="swiper-slide">
                                                <img class="w-100"
                                                    src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}"
                                                    alt="">
                                            </div>
                                            <div class="swiper-slide">
                                                <img class="w-100"
                                                    src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}"
                                                    alt="">
                                            </div>
                                        </div>
                                        <div class="swiper-pagination1 d-flex justify-content-center mt-2"></div>
                                    </div>
                                    <div class="swiper-button-next swiper-button-next1"></div>
                                    <div class="swiper-button-prev swiper-button-prev1"></div>
                                </div>
                            </div>
                            <div class="swiper-slide">
                                <h4>Address</h4>
                                <p class="js-truncate-text fs-12">Lorem ipsum dolor sit amet, consectetur adipiscing
                                    elit. Aliquam odio tellus, laoreet pharetra auctor eget, fringilla nec lectus.
                                    Nullam in feugiat est. Nam in interdum ligula, non elementum purus. Aenean eu lectus
                                    diam. Cras elementum neque sed nibh consequat, nec gravida purus vehicula. Morbi
                                    Learn more </p>
                                <div class="position-relative mt-3">
                                    <!-- Swiper -->
                                    <div class="swiper mySwiper  mw-100">
                                        <div class="swiper-wrapper">
                                            <div class="swiper-slide">
                                                <img class="w-100"
                                                    src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}"
                                                    alt="">
                                            </div>
                                            <div class="swiper-slide">
                                                <img class="w-100"
                                                    src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}"
                                                    alt="">
                                            </div>
                                            <div class="swiper-slide">
                                                <img class="w-100"
                                                    src="{{dynamicAsset(path: 'public/assets/new/back-end/img/map.webp')}}"
                                                    alt="">
                                            </div>
                                        </div>
                                        <div class="swiper-pagination1 d-flex justify-content-center mt-2"></div>
                                    </div>
                                    <div class="swiper-button-next swiper-button-next1"></div>
                                    <div class="swiper-button-prev swiper-button-prev1"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-center align-items-center gap-3 mt-3">
                    <div class="swiper-button-prev swiper-button-prev2 position-static m-0"></div>
                    <div class="swiper-pagination2 w-auto"></div>
                    <div class="swiper-button-next swiper-button-next2 position-static m-0"></div>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed"
                    type="button" data-bs-toggle="collapse" data-bs-target="#collapseNewSlider">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold">{{ translate('New_Slider') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseNewSlider">
                <div class="card card-body">
                    <!-- Swiper -->
                    <h4>Address</h4>
                    <p class="fs-12">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam odio tellus,
                        laoreet
                        pharetra auctor eget, fringilla nec lectus. Nullam in feugiat est. Nam in interdum ligula, non
                        elementum purus.
                        Aenean eu lectus diam. Cras elementum neque sed nibh consequat, nec gravida purus vehicula.
                        Morbi Learn more </p>
                    <div class="myOffcanvasSwiper_Wrapper position-relative mt-3">
                        <!-- Swiper -->
                        <div class="swiper myOffcanvasSwiper  mw-100">
                            <div class="swiper-wrapper">
                                <div class="swiper-slide">
                                    <div
                                        class="border rounded-10 h-100 d-flex justify-content-center align-items-center">
                                        <img class="w-100 rounded-10"
                                            src="{{dynamicAsset(path: 'public/assets/new/back-end/img/guideline-slider-demo-1.png')}}"
                                            alt="">
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div
                                        class="border rounded-10 h-100 d-flex justify-content-center align-items-center">
                                        <img class="w-100 rounded-10"
                                            src="{{dynamicAsset(path: 'public/assets/new/back-end/img/guideline-slider-demo-2.png')}}"
                                            alt="">
                                    </div>
                                </div>
                            </div>
                            <div class="swiper-pagination-bullets d-flex justify-content-center mt-2"></div>
                        </div>
                        <div class="swiper-button-next bullet-next"></div>
                        <div class="swiper-button-prev bullet-prev"></div>
                    </div>
                </div>
                <div class="d-flex justify-content-center align-items-center gap-3 mt-3">
                    <div class="swiper-button-prev swiper-button-prev-offcanvas position-static m-0"></div>
                    <div class="swiper-pagination-fraction w-auto"></div>
                    <div class="swiper-button-next swiper-button-next-offcanvas position-static m-0"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="offcanvas-footer">
        <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
            <button type="button"
                class="d-flex align-items-center gap-2 text-primary bg-transparent border-0 p-0">{{ translate('see_More') }}
                <i class="fi fi-rr-angle-right"></i></button>
        </div>
    </div>
</div></code></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
