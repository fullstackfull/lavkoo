<div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">{{ translate('ready_to_Leave') . '?' }}</h5>
                <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">
                {{ translate('Select_Logout_below_if_you_are_ready_to_end_your_current_session') . '.' }}</div>
            <div class="modal-footer">
                <form action="{{ route('admin.logout') }}" method="post">
                    @csrf
                    <button class="btn btn-danger" type="button"
                        data-dismiss="modal">{{ translate('cancel') }}</button>
                    <button class="btn btn--primary" type="submit">{{ translate('logout') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="popup-modal">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-body py-4">
                <div class="row">
                    <div class="col-12">
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <div class="text-center w-100">
                                <h4 class="__color-8a8a8a">
                                    <i class="tio-shopping-cart-outlined"></i>
                                    {{ translate('you_have_new_order') . ', ' . translate('check_please') . '.' }}
                                </h4>
                            </div>
                            <div class="d-flex justify-content-center gap-2">
                                <button class="btn btn-danger ignore-check-order">
                                    {{ translate('Ignore_this_now') }}
                                </button>
                                <button class="btn btn-primary check-order">
                                    {{ translate('ok') . ', ' . translate('let_me_check') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="advanceSearchModal" tabindex="-1" aria-labelledby="advanceSearchModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered advanced-search-modal-dialog">
        <div class="modal-content">
            <div class="modal-header pb-0">
                <div class="d-flex flex-column gap-2 w-100">
                    <div class="bg-info bg-opacity-10 rounded p-2 fs-12">
                        <span>
                            Use UP/DOWN
                            <span class="p-1 bg-white rounded lh-1"><i class="fi fi-rr-arrow-small-up"></i></span>
                            <span class="p-1 bg-white rounded lh-1"><i class="fi fi-rr-arrow-small-down"></i></span>
                            to browse, ENTER
                            <span class="p-1 bg-white rounded lh-1 fs-10"><i
                                    class="fi fi-rr-arrow-turn-down-left"></i></span>
                            to select.
                        </span>
                    </div>
                    <div class="d-flex gap-2 align-items-center position-relative">
                        <form class="flex-grow-1" id="searchForm" action="javascript:">
                            @csrf
                            <div class="d-flex align-items-center global-search-container">
                                <input autocomplete="off" class="form-control flex-grow-1 rounded-10 search-input"
                                    id="advance-search-input-global" maxlength="255" name="search" type="search"
                                    placeholder="{{ translate('Search_by_keyword') }}" aria-label="Search" autofocus>
                            </div>
                        </form>
                        <div class="position-absolute esc-button">
                            <button class="border-0 fs-12 fw-normal px-2 py-1 rounded text-dark" type="button"
                                data-bs-dismiss="modal">
                                {{ translate('Esc') }}
                            </button>
                        </div>
                    </div>

                </div>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-column gap-3">
                    <div class="min-h-300">
                        <div class="search-result position-relative" id="searchResults">
                            <div id="searchLoaderOverlay" class="search-loader-overlay d-none">
                                <div class="loader-spinner"></div>
                            </div>
                            <div
                                class="d-flex flex-column gap-3 align-items-center justify-content-center min-h-300 rounded text-body-light">
                                <img width="40" height="40" class="svg"
                                    src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/empty-state-icon/before-search.svg') }}"
                                    alt="Image">
                                <span class="fs-16">{{ translate('write_something_to_get_search_result') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
