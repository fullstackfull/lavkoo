@php
    $last5Orders = \App\Models\Order::where('order_status', 'pending')
        ->with('details.productAllStatus')
        ->orderBy('id', 'desc')
        ->take(5)
        ->get();
@endphp

@php($direction = session('direction'))
@if (env('APP_MODE')=='demo')
    <div class="__announcement-bar" style="background-image: url({{ dynamicAsset(path: 'public/assets/website-top-header.png') }})">
        <div class="container">
            <div class="wrapper">
                <div class="txt">
                    This is a demo website - Buy genuine 6Valley using our official link !
                </div>
                <a href="https://codecanyon.net/item/6valley-multivendor-ecommerce-complete-ecommerce-mobile-app-web-and-admin-panel/31448597?s_rank=19" class="click" target="_blank">Click Now <img src="{{ asset('public/assets/arrowww.png') }}" alt=""></a>
                <a href="https://codecanyon.net/item/6valley-multivendor-ecommerce-complete-ecommerce-mobile-app-web-and-admin-panel/31448597?s_rank=19" class="px-3 py-1 rounded" style="background-color: #FF7500; color:#ffffff" target="_blank">Buy Now</a>
            </div>
        </div>
    </div>
@endif

<header class="header fixed-top navbar-fixed shadow-sm bg-white">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div class="">
            <button type="button" class="d-none d-lg-block btn-icon border-0">
                <i class="fi fi-rr-menu-burger" data-bs-toggle="tooltip" data-bs-title="Expand"></i>
            </button>
            <button type="button" class="d-lg-none p-0 bg-transparent border-0" data-bs-toggle="offcanvas"
                data-bs-target="#offcanvasAside">
                <i class="fi fi-rr-menu-burger"></i>
            </button>
        </div>

        <div class="navbar-nav-wrap-content-right">
            <ul class="navbar-nav align-items-center flex-row gap-3 pe-0">

                <li class="nav-item">
                    <button type="button" id="search-btn"
                        class="btn border bg-section2 d-flex gap-1 max-w-200 px-2 px-3 px-md-3 py-1 rounded w-100"
                        data-bs-toggle="modal" data-bs-target="#advanceSearchModal">
                        <span class="align-items-center d-flex flex-grow-1 gap-3 justify-content-between">
                            <span id="search-input"
                                class="align-items-center d-none d-xxl-flex gap-2 text-muted fs-12 fw-normal">
                                <span class="search-placeholder">{{ __('Search or') }}</span>
                                <span class="search-shortcut-key-wrapper">
                                    <span class="search-shortcut-key"></span>
                                </span>
                            </span>
                            <span class="lh-1 me-lg-n1">
                                <i class="fi fi-rr-search"></i>
                            </span>
                        </span>
                    </button>
                </li>
                <li class="nav-item">
                    <a class="btn-icon" href="{{ route('home') }}" target="_blank" data-bs-toggle="tooltip"
                        data-bs-title="{{ translate('Website') }}">
                        <i class="fi fi-rr-globe fs-18"></i>
                    </a>
                </li>

                <li class="nav-item">
                    @php($local = session()->has('local') ? session('local') : 'en')
                    <div class="topbar-text dropdown">
                        <a class="btn-icon topbar-link" href="javascript:" data-bs-toggle="dropdown">
                            @foreach ((getWebConfig(name: 'language') ?? []) as $data)
                                    <?php
                                    $langFlagCode = $data['code'];
                                    if (\Illuminate\Support\Str::contains($data['code'], '-')) {
                                        $countryCodeArr = explode('-', $data['code']);
                                        $langFlagCode = $countryCodeArr[0];
                                    }
                                    ?>
                                @if ($data['code'] == $local)
                                    <img width="20"
                                        src="{{ dynamicAsset(path: 'public/assets/front-end/img/flags/' . strtolower($langFlagCode) . '.png') }}"
                                        alt="{{ $data['name'] }}">
                                @endif
                            @endforeach
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            @foreach ((getWebConfig(name: 'language') ?? []) as $key => $data)
                                    <?php
                                    $langFlagCode = $data['code'];
                                    if (\Illuminate\Support\Str::contains($data['code'], '-')) {
                                        $countryCodeArr = explode('-', $data['code']);
                                        $langFlagCode = $countryCodeArr[0];
                                    }
                                    ?>
                                @if ($data['status'] == 1)
                                    <li class="change-language" data-action="{{ route('change-language') }}"
                                        data-language-code="{{ $data['code'] }}">
                                        <a class="d-flex gap-2 align-items-center justify-content-between dropdown-item {{ $data['code'] == $local ? 'active' : ':' }}"
                                            href="javascript:">
                                            <div class="d-flex gap-2 align-items-center">
                                                <img width="20"
                                                    src="{{ dynamicAsset(path: 'public/assets/front-end/img/flags/' .strtolower($langFlagCode) . '.png') }}"
                                                    alt="{{ $data['name'] }}" />
                                                <span class="text-capitalize">{{ $data['name'] }}</span>
                                            </div>
                                            {!! $data['code'] == $local ? '<i class="fi fi-rr-check"></i>' : '' !!}
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                </li>

                @if (\App\Utils\Helpers::module_permission_check('orders'))
                    <li class="dropdown nav-item">
                        <a class="btn-icon" href="{{ route('admin.orders.list', ['status' => 'pending']) }}"
                            data-bs-toggle="dropdown">
                            @php($pendingOrderCount = $sidebarOrderCounts['pending'] ?? 0)
                            <div class="position-relative lh-1 mt-1">
                                <i class="fi fi-sr-shopping-cart fs-18"></i>
                                @if ($pendingOrderCount > 0)
                                    <span
                                        class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        {{ $pendingOrderCount > 99 ? '99+' : $pendingOrderCount }}
                                        <span class="visually-hidden">{{ translate('pending_Orders') }}</span>
                                    </span>
                                @endif
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-cart dropdown-menu-end">
                            <div class="d-flex flex-column gap-2 px-3 pb-2">
                                <button type="button"
                                    class="d-flex d-sm-none btn-close border-0 btn-circle w-20 h-20 p-1 fs-10 bg-section2 shadow-none position-absolute top-0 inset-inline-end-0 m-2"
                                    data-bs-dismiss="modal" aria-label="Close"></button>
                                <div
                                    class="d-flex flex-wrap flex-column flex-sm-row column-gap-1 row-gap-3 justify-content-between py-2">
                                    <div class="d-flex gap-2 align-items-center">
                                        <h4 class="text-capitalize mb-0">{{ translate('total_orders') }}</h4>
                                        <span class="text-body-light">
                                            {{ $sidebarOrderCounts['pending'] ?? 0 }}
                                        </span>
                                    </div>
                                    <a href="{{ route('admin.orders.list', ['status' => 'pending']) }}"
                                        class="text-primary d-flex gap-1 lh-1">{{ translate('view_all') }} <i
                                            class="fi fi-rr-arrow-small-right"></i></a>
                                </div>
                                <div class="overflow-auto max-h-65vh bg-body rounded">
                                    <div class="py-2 bg-body rounded">
                                        <table class="table bg-transparent table-borderless align-middle">
                                            <tbody>
                                                @foreach ($last5Orders as $order)
                                                    <tr>
                                                        <td>
                                                            <div>
                                                                <div
                                                                    class="d-flex align-items-center flex-shrink-0 w-100px">
                                                                    @php($productImages = [])
                                                                    @foreach ($order->details as $details)
                                                                        @if (
                                                                            $details?->productAllStatus?->thumbnail_full_url &&
                                                                                $details?->productAllStatus?->thumbnail_full_url['status'] === 200)
                                                                            @php($productImages[] = $details?->productAllStatus?->thumbnail_full_url)
                                                                        @endif
                                                                    @endforeach
                                                                    @if (count($productImages))
                                                                        @foreach ($productImages as $imageKey => $productImage)
                                                                            <div
                                                                                class="w-100 ms-n-6px dropdown-cart-image {{ $imageKey == 2 ? 'position-relative' : '' }} {{ $imageKey > 2 ? 'd-none' : '' }}">
                                                                                <img class="border bg-white rounded object-fit-cover w-100 shadow-left"
                                                                                    height="36" alt="product image"
                                                                                    src="{{ getStorageImages(path: $productImage, type: 'backend-product') }}">
                                                                                @if ($imageKey == 2 && count($productImages) > 3)
                                                                                    <div class="extra-images rounded">
                                                                                        <span class="extra-image-count">
                                                                                            +
                                                                                            {{ count($productImages) - 3 }}
                                                                                        </span>
                                                                                    </div>
                                                                                @endif
                                                                            </div>
                                                                        @endforeach
                                                                    @else
                                                                        <img class="border bg-white rounded object-fit-cover w-100"
                                                                            height="36"
                                                                            src="{{ getStorageImages(path: '', type: 'backend-product') }}"
                                                                            alt="placeholder">
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-column gap-1 fs-12">
                                                                <div class="d-flex gap-2 align-items-center">
                                                                    <div class="min-w-80 text-start">
                                                                        {{ translate('Order_id') }}</div>
                                                                    :
                                                                    <span class="text-dark">{{ $order->id }}</span>
                                                                </div>
                                                                <div class="d-flex gap-2 align-items-center">
                                                                    <div class="min-w-80 text-start">
                                                                        {{ translate('Order_Amount') }}</div>
                                                                    :
                                                                    <span class="text-dark">
                                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order->order_amount), currencyCode: getCurrencyCode()) }}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex justify-content-end">
                                                                <a href="{{ route('admin.orders.details', ['id' => $order['id']]) }}"
                                                                    class="btn btn-outline-primary btn-square">
                                                                    <i class="fi fi-sr-eye"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </li>
                @endif

                @if (\App\Utils\Helpers::module_permission_check('people'))
                    <li class="nav-item">
                        <a class="btn-icon" href="{{ route('admin.contact.list') }}"
                            data-bs-title="{{ translate('message') }}" data-bs-toggle="tooltip">
                            <div class="position-relative lh-1 mt-1">
                                <i class="fi fi-sr-comment-alt-dots fs-18"></i>

                                @if ($sidebarUnseenMessages > 0)
                                    <span
                                        class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        {{ $sidebarUnseenMessages > 99 ? '99+' : $sidebarUnseenMessages }}
                                    </span>
                                @endif
                            </div>
                        </a>
                    </li>
                @endif

                <li class="nav-item">
                    <div class="dropdown">
                        <a class="d-flex" href="javascript:" data-bs-toggle="dropdown">
                            <img class="rounded-circle border border-2 min-w-36 aspect-1" width="36"
                                src="{{ getStorageImages(path: auth('admin')->user()->image_full_url, type: 'backend-profile') }}"
                                alt="{{ translate('image_description') }}">
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <div class="dropdown-item">
                                <div class="media gap-2 align-items-center">
                                    <img class="rounded-circle border border-2 aspect-1" width="40"
                                        src="{{ getStorageImages(path: auth('admin')->user()->image_full_url, type: 'backend-profile') }}"
                                        alt="{{ translate('image_description') }}">

                                    <div class="media-body overflow-hidden w-100">
                                        <h4 class="fw-bold max-w-200 mb-1 text-truncate">
                                            {{ auth('admin')->user()->name }}
                                        </h4>
                                        <p class="fs-12 text-body-light fw-medium max-w-200 text-truncate">
                                            {{ ucwords(auth('admin')->user()?->role?->name) ?? '' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item media gap-2 align-items-center"
                                href="{{ route('admin.profile.update', ['id' => auth('admin')->user()->id]) }}">
                                <i class="fi fi-rr-settings text-body-light"></i>
                                <span class="text-truncate media-body" title="{{ translate('settings') }}">
                                    {{ translate('settings') }}
                                </span>
                            </a>
                            <a class="dropdown-item media gap-2 align-items-center" href="javascript:"
                                data-bs-toggle="modal" data-bs-target="#sign-out-modal">
                                <i class="fi fi-sr-sign-out-alt text-body-light"></i>
                                <span class="text-truncate media-body" title="{{ translate('logout') }}">
                                    {{ translate('logout') }}
                                </span>
                            </a>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</header>


@push('script')
    <script>
        let currentIndex = -1;

        function getItems() {
            return $('.search-list .search-item-wrapper');
        }

        function updateHighlight(index) {
            const items = getItems();
            items.removeClass('active-item');

            if (index >= 0 && index < items.length) {
                const currentItem = $(items[index]);
                currentItem.addClass('active-item');

                const link = currentItem.find('.search-list-item')[0];

                if (link) {
                    const container = document.querySelector('#searchResults');
                    const itemRect = link.getBoundingClientRect();
                    const containerRect = container.getBoundingClientRect();

                    if (itemRect.top < containerRect.top || itemRect.bottom > containerRect.bottom) {
                        link.scrollIntoView({
                            behavior: 'auto',
                            block: 'nearest'
                        });
                    }
                }
            }
        }

        $(document).ready(function() {

            $('#search-btn').on('click', function() {
                $('#advanceSearchModal').modal('show');
                $('#advanceSearchModal').on('shown.bs.modal', function() {
                    $('#advance-search-input-global').focus();
                });
            });

            const platform = navigator.platform;
            let shortcutText = '';
            let isMac = false;

            if (platform.toLowerCase().includes('mac')) {
                shortcutText = 'Cmd+K';
                isMac = true;
            } else if (platform.toLowerCase().includes('linux') || platform.toLowerCase().includes('win')) {
                shortcutText = 'Ctrl+K';
                isMac = false;
            } else {
                shortcutText = 'Ctrl+K';
                isMac = false;
            }

            const currentPlaceholder = "{{ translate('Search_or') }}";
            $('#search-input').html(
                `${currentPlaceholder} <span class="search-shortcut-key-wrapper">${shortcutText}</span>`);

            $(document).keydown(function(event) {
                if ((event.ctrlKey && !isMac) || (event.metaKey && isMac)) {
                    if (event.key === 'k' || event.key === 'K') {
                        event.preventDefault();

                        $('#advanceSearchModal').modal('show');
                        $('#advanceSearchModal').on('shown.bs.modal', function() {
                            $('#advance-search-input-global').focus();
                        });
                    }
                }
                if (event.key === 'Escape') {
                    if ($('#advanceSearchModal').hasClass('show')) {
                        $('#advanceSearchModal').modal('hide');
                    }
                }
            });

            $('#advanceSearchModal').on('hidden.bs.modal', function() {
                $('#advanceSearchModal').off('shown.bs.modal');
            });


            let currentRequest = null;
            let debounceTimer = null;

            $('#advance-search-input-global').on('input', function() {
                const searchKeyword = $(this).val().trim();

                clearTimeout(debounceTimer);


                if (searchKeyword === '') {
                    if (currentRequest && currentRequest.readyState !== 4) {
                        currentRequest.abort();
                    }
                    toggleSearchLoader('show');
                    $.ajax({
                        type: 'GET',
                        url: '{{ route('admin.advanced-search') }}',
                        success: function(response) {
                            $('#searchResults').empty().html(response.htmlView);
                            toggleSearchLoader('hide');
                        },
                        error: function(xhr, status, error) {
                            console.error(xhr.responseText);
                        }
                    });

                    return;
                }

                debounceTimer = setTimeout(function() {
                    if (currentRequest && currentRequest.readyState !== 4) {
                        currentRequest.abort();
                    }

                    toggleSearchLoader('show');

                    $.ajaxSetup({
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        }
                    });
                    currentRequest = $.ajax({
                        type: 'GET',
                        url: '{{ route('admin.advanced-search') }}',
                        data: {
                            keyword: searchKeyword,

                        },
                        success: function(response) {
                            $('#searchResults').empty().html(response.htmlView);

                            if (currentIndex === -1) {
                                currentIndex = 0;
                            }
                            updateHighlight(currentIndex);
                            toggleSearchLoader('hide');
                        },
                        error: function(xhr, status, error) {
                            if (status !== 'abort') {
                                console.error('Search error:', error);
                            }
                            toggleSearchLoader('hide');
                        }
                    });

                }, 300);
            });
            $('#advance-search-input-global').on('focus', function() {
                const searchKeyword = $(this).val().trim();

                if (searchKeyword.length > 0) {
                    if (currentRequest && currentRequest.readyState !== 4) {
                        currentRequest.abort();
                    }
                    toggleSearchLoader('show');
                    $.ajaxSetup({
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        }
                    });
                    currentRequest = $.ajax({
                        type: 'GET',
                        url: '{{ route('admin.advanced-search') }}',
                        data: {
                            keyword: searchKeyword
                        },
                        success: function(response) {
                            $('#searchResults').empty().html(response.htmlView);

                            if (currentIndex === -1) {
                                currentIndex = 0;
                            }
                            toggleSearchLoader('hide');
                            updateHighlight(currentIndex);
                        },
                        error: function(xhr, status, error) {
                            if (status !== 'abort') {
                                console.error('Search error:', error);
                            }
                        }
                    });
                } else {
                    $.ajax({
                        type: 'GET',
                        url: '{{ route('admin.advanced-search') }}',
                        success: function(response) {
                            $('#searchResults').empty().html(response.htmlView);
                        },
                        error: function(xhr, status, error) {
                            console.error(xhr.responseText);
                             toggleSearchLoader('hide');
                        }
                    });
                }
            });

        });

        function toggleSearchLoader(type) {
            const loader = $('#searchLoaderOverlay');
            if (type === 'show') {
                loader.removeClass('d-none');
            } else if (type === 'hide') {
                loader.addClass('d-none');
            }
        }


        $(document).on('keydown', function(e) {
            if (!$('#advanceSearchModal').hasClass('show')) return;

            const items = getItems();

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (currentIndex < items.length - 1) {
                    currentIndex++;
                    updateHighlight(currentIndex);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (currentIndex > 0) {
                    currentIndex--;
                    updateHighlight(currentIndex);
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentIndex >= 0 && currentIndex < items.length) {
                    const target = $(items[currentIndex]).find('.search-list-item')[0];
                    if (target) target.click();
                }
            }
        });

        $('#advanceSearchModal').on('shown.bs.modal', function() {
            currentIndex = -1;
            const input = $('#advance-search-input-global');
            const keyword = input.val().trim();
            input.focus();
            if (keyword.length > 0) {
                input.trigger('focus');
            }
        });

        $('#advanceSearchModal').on('hidden.bs.modal', function() {
            currentIndex = -1;
        });

        $('#advance-search-input-global').on('input focus', function() {
            currentIndex = -1;
        });
    </script>
@endpush
