@extends('auction.web-views.user-profile._profile-master')

@section('title', translate('Auctions Request List'))

@section('profile_content')
    @php
        $activeTab = $approvalTabs->firstWhere('key', $activeApprovalTab);
        $activeProducts = $activeTab['products'] ?? collect();
        $hasAnyApprovalProducts = $approvalTabs->sum('total_count') > 0;

        $statusBadgeClasses = [
            \Modules\Auction\app\Enums\ApprovalStatus::PENDING  => 'bg-warning text-dark',
            \Modules\Auction\app\Enums\ApprovalStatus::REJECTED => 'bg-danger',
            \Modules\Auction\app\Enums\AuctionStatus::CANCELED => 'bg-secondary text-body',
        ];
    @endphp
    @if (!$hasAnyApprovalProducts)
        <div class="card bs-border py-lg-5">
            <div class="card-body py-lg-5">
                <div class="empty-content text-center py-5">
                    <div class="text-center mb-20">
                        <img width="50"
                            src="{{ dynamicAsset(path: 'public/assets//front-end/auction/images/bids-empty.png') }}"
                            alt="">
                    </div>
                    <h4 class="fs-20 title-clr mb-lg-3 mb-2 fw-semibold">
                        {{ translate('Turn_Your_Product_into_an_Auction') }}
                    </h4>
                    <p class="fs-14 title-semidark lh-24px mb-4 pb-lg-2 fw-normal">
                        {{ translate('Start_an_auction_and_sell_your_product_at_the_best_price') }}
                    </p>

                    @if (getWebConfig(name: 'active_auction_for_customer') == 1)
                    <div class="d-flex justify-content-center">
                        <a  href="{{ route('auction.acution-create-product') }}"
                            class="btn btn-primary mx-auto w-auto d-flex align-items-center justify-content-center gap-2">
                            <i class="fi fi-sr-add"></i> {{ translate('Create_Auction') }}
                        </a>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="mb-20 d-flex align-items-center gap-1 flex-wrap justify-content-between">
            <h5 class="fs-18 fw-semibold title-clr text-capitalize mb-0">
                {{ translate('Auctions Request List') }}
            </h5>

            @if (getWebConfig(name: 'active_auction_for_customer') == 1)
            <a href="{{ route('auction.acution-create-product') }}"
                class="btn btn-primary d-flex align-items-center justify-content-center gap-2 py-2 px-3">
                <i class="fi fi-sr-add"></i> {{ translate('Create Auction') }}
            </a>
            @endif

        </div>

        <div class="position-relative nav--tab-wrapper mb-3">
            <ul class="nav nav--tab flex-nowrap text-nowrap p-0 overflow-auto nav-rounded scrollbar-none d-flex align-items-center justify-content-start gap-xxl-20px gap-3"
                id="pills-tab" role="tablist">
                @foreach ($approvalTabs as $tab)
                    @if($tab['key'] === \Modules\Auction\app\Enums\ApprovalStatus::APPROVED)
                        @continue
                    @endif
                    <li class="nav-item" role="presentation">
                        <a href="{{ request()->fullUrlWithQuery(['tab' => $tab['key']]) }}"
                            class="nav-link fs-14 {{ $activeApprovalTab === $tab['key'] ? 'active' : '' }}">
                            {{ translate(ucwords(str_replace('_', ' ', $tab['translation_key']))) }}
                            <span class="text-light-gray">({{ $tab['total_count'] }})</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="nav--tab__prev">
                <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                    <i class="fi fi-sr-angle-left"></i>
                </button>
            </div>
            <div class="nav--tab__next">
                <button class="btn btn-circle border-0 bg-white text-primary">
                    <i class="fi fi-sr-angle-right"></i>
                </button>
            </div>
        </div>

        <div class="tab-content" id="pills-tabContent">
            <div class="tab-pane fade show active" role="tabpanel" tabindex="0">
                @if ($activeProducts->count() > 0)
                    <div class="row g-3">
                        @foreach ($activeProducts as $product)
                            <div class="col-md-6">
                                <div class="item">
                                    <div
                                        class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                        @if (isset($statusBadgeClasses[$activeApprovalTab]))
                                            <span
                                                class="badge {{ $statusBadgeClasses[$activeApprovalTab] }} rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-12 z-1">
                                                {{ translate(ucwords($activeApprovalTab)) }}
                                            </span>
                                        @endif

                                        <div class="d-flex gap-10px align-items-start">
                                            <div
                                                class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                <a href="{{ auth('customer')->check() && auth('customer')->id() == $product?->owner_id && $product?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $product->slug]) : route('auction.profile-view.product-details', ['slug' => $product->slug]) }}"
                                                    class="m-thumbnail d-block">
                                                    <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}"
                                                        alt="" class="w-100 h-100">
                                                </a>
                                            </div>

                                            <div class="w-100">
                                                <div class="fs-12 title-semidark mb-1">
                                                    {{ translate('Auction ID') }}#{{ $product->id }}
                                                </div>
                                                <h6 class="mb-10px pe-xl-3 pe-1 max-w-230px">
                                                    <a href="{{ auth('customer')->check() && auth('customer')->id() == $product?->owner_id && $product?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $product->slug]) : route('auction.profile-view.product-details', ['slug' => $product->slug]) }}"
                                                        class="text-decoration-none lh-sm fs-14 text-capitalize fw-semibold title-clr line--limit-1 text-hover-primary">
                                                        {{ $product->name }}
                                                    </a>
                                                </h6>
                                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                    <span
                                                        class="fs-12 title-semidark">{{ translate('Start Price') }}</span>
                                                    <strong class="text-primary fs-14 fw-semibold">
                                                        {{ webCurrencyConverter(amount: $product->starting_price) }}
                                                    </strong>
                                                </div>
                                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                    <span
                                                        class="fs-12 title-semidark">{{ translate('Min Increment') }}</span>
                                                    <strong class="title-clr fs-14 fw-semibold">
                                                        {{ webCurrencyConverter(amount: $product->minimum_increment_amount) }}
                                                    </strong>
                                                </div>
                                                @if(!is_null($product?->maximum_decrement_amount))
                                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                    <span class="fs-12 title-semidark">{{ translate('Max Decrement') }}</span>
                                                    <strong class="title-clr fs-14 fw-semibold">
                                                        {{ webCurrencyConverter(amount: $product->maximum_decrement_amount) }}
                                                    </strong>
                                                </div>
                                                @endif
                                                @if ($product->category)
                                                    <div
                                                        class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                                        <span
                                                            class="fs-12 title-semidark">{{ translate('Category') }}</span>
                                                        <strong
                                                            class="title-clr fs-14 fw-semibold">{{ $product->category->name }}</strong>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                            <div class="btn-group dropstart">
                                                <button type="button"
                                                    class="btn p-0 bg-transparent outline-0 border-0 shadow-none"
                                                    data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                </button>
                                                <ul class="dropdown-menu shadow-sm">
                                                    <li>
                                                        <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                                                            href="{{ auth('customer')->check() && auth('customer')->id() == $product?->owner_id && $product?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $product->slug]) : route('auction.profile-view.product-details', ['slug' => $product->slug]) }}">
                                                            {{ translate('View Details') }}
                                                            <i class="fi fi-sr-eye text-primary"></i>
                                                        </a>
                                                    </li>
                                                    @if(in_array($activeApprovalTab, [\Modules\Auction\app\Enums\ApprovalStatus::PENDING, \Modules\Auction\app\Enums\ApprovalStatus::REJECTED, \Modules\Auction\app\Enums\AuctionStatus::CANCELED]))
                                                    <li>
                                                        <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                                                            href="{{ route('auction.auction-update-product', ['id' => $product->id]) }}">
                                                            {{ translate('Edit Details') }}
                                                            <i class="fi fi-sr-pen-circle text-primary"></i>
                                                        </a>
                                                    </li>
                                                    @endif
                                                    @if(in_array($activeApprovalTab, [\Modules\Auction\app\Enums\ApprovalStatus::PENDING, \Modules\Auction\app\Enums\ApprovalStatus::REJECTED, \Modules\Auction\app\Enums\AuctionStatus::CANCELED]))
                                                    <li>
                                                        <button type="button"
                                                                class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1 text-danger js-delete-auction-btn"
                                                                data-auction-id="{{ $product->id }}">
                                                            {{ translate('Delete') }}
                                                            <i class="fi fi-sr-trash text-danger"></i>
                                                        </button>
                                                    </li>
                                                    @endif
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="bg-light rounded text-center px-2 py-5">
                        <img width="55" height="55" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/auction-emptys.svg') }}" alt="" class="mb-3">
                        <p class="fs-14 title-semidark">{{ translate('No_auctions_found_for_this_tab') }}</p>
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection

@push('script')
    <form id="js-delete-auction-form" method="POST" action="{{ route('auction.delete') }}" class="d-none">
        @csrf
        <input type="hidden" name="auction_product_id" id="js-delete-auction-product-id" value="">
    </form>
    <script>
        (function () {
            document.querySelectorAll('.js-delete-auction-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const auctionId = this.dataset.auctionId;
                    Swal.fire({
                        title: '{{ translate('Delete_Auction') }}',
                        text: '{{ translate('This_will_permanently_delete_the_auction_and_all_associated_data._This_action_cannot_be_undone.') }}',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: '{{ translate('Yes,_Delete') }}',
                        cancelButtonText: '{{ translate('Cancel') }}',
                        reverseButtons: true,
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            document.getElementById('js-delete-auction-product-id').value = auctionId;
                            document.getElementById('js-delete-auction-form').submit();
                        }
                    });
                });
            });
        })();
    </script>
@endpush
