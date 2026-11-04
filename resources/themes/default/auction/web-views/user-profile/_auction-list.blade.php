@extends('auction.web-views.user-profile._profile-master')

@section('title', translate('Auction_List'))

@section('profile_content')
    @php
        $activeProducts = $auctionProducts ?? collect();

        $statusBadgeClasses = [
            'upcoming' => 'bg-success',
            'live' => 'bg-danger',
            'ready_to_claim' => 'bg-warning text-dark',
            'purchase_complete' => 'bg-success',
            'ready_to_delivery' => 'bg-info text-white',
            'on_the_way' => 'bg-info text-dark',
            'delivered' => 'bg-success',
            'unsold' => 'bg-secondary fw-medium text-dark',
            'canceled' => 'bg-dark text-white',
        ];

        $displayStatusFor = function ($product) {
            if (!empty($product->delivery_status)) {
                return $product->delivery_status;
            }
            return $product->auction_current_status;
        };
    @endphp

    @if (!$hasAuctionProducts)
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
                    @if(getWebConfig(name: 'active_auction_for_customer') == 1)
                    <div class="d-flex justify-content-center">
                        <a href="{{ route('auction.acution-create-product') }}"
                            class="btn btn-primary w-auto mx-auto d-flex align-items-center justify-content-center gap-2">
                            <i class="fi fi-sr-add"></i> {{ translate('Create_Auction') }}
                        </a>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        <div class="mb-20 d-flex align-items-center gap-1 flex-wrap justify-content-between">
            <h5 class="fs-18 fw-semibold title-clr text-capitalize mb-0">{{ translate('Auction List') }}</h5>
            @if(getWebConfig(name: 'active_auction_for_customer') == 1)
            <a href="{{ route('auction.acution-create-product') }}"
                class="btn btn-primary d-flex align-items-center justify-content-center gap-2 py-2 px-3 w-auto">
                <i class="fi fi-sr-add"></i> {{ translate('Create Auction') }}
            </a>
            @endif
        </div>

        <div class="position-relative nav--tab-wrapper mb-3">
            <ul class="nav nav--tab flex-nowrap text-nowrap overflow-auto scrollbar-none p-0 nav-rounded d-flex align-items-center justify-content-start gap-xxl-20px gap-3"
                id="pills-tab" role="tablist">
                @foreach ($auctionTabs as $tab)
                    <li class="nav-item" role="presentation">
                        <a href="{{ request()->fullUrlWithQuery(['status' => $tab['key'], 'page' => null]) }}"
                            class="nav-link fs-14 {{ $activeAuctionTab === $tab['key'] ? 'active' : '' }}">
                            {{ translate(ucwords(str_replace('_', ' ', $tab['translation_key']))) }}
                            <span class="text-light-gray">({{ $tab['count'] }})</span>
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
                @if ($activeProducts->total() > 0)
                    <div class="row g-3">
                        @foreach ($activeProducts as $product)
                            @php
                                $displayStatus = $displayStatusFor($product);
                                $countdownEnd = match ($displayStatus) {
                                    'upcoming' => \Carbon\Carbon::parse($product->start_time)->format('Y-m-d H:i:s'),
                                    'live' => \Carbon\Carbon::parse($product->end_time)->format('Y-m-d H:i:s'),
                                    default => \Carbon\Carbon::now()->format('Y-m-d H:i:s'),
                                };
                                $cardDetailsUrl = auth('customer')->check() && auth('customer')->id() == $product?->owner_id && $product?->owner_type == 'customer'
                                    ? route('auction.profile-view.author-product-details', ['slug' => $product->slug])
                                    : route('auction.profile-view.product-details', ['slug' => $product->slug]);
                            @endphp
                            <div class="col-md-6">
                                <div class="item">
                                    <div
                                        class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                        @if (isset($statusBadgeClasses[$displayStatus]))
                                            <span
                                                class="badge {{ $statusBadgeClasses[$displayStatus] }} rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-12 z-1">
                                                {{ translate(ucwords(str_replace('_', ' ', $displayStatus))) }}
                                            </span>
                                        @endif

                                        <div class="d-flex gap-10px align-items-start">
                                            <div
                                                class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                <a href="{{ $cardDetailsUrl }}"
                                                    class="m-thumbnail d-block">
                                                    <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}"
                                                        alt="" class="w-100 h-100">
                                                </a>
                                                @if(in_array($displayStatus, ['upcoming', 'live']))
                                                <div
                                                    class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                    <div class="flex-aligns gap-1">
                                                        <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                        <div class="d-flex justify-content-center gap-1 countdown auction-countdown"
                                                            data-end="{{ $countdownEnd }}">
                                                            <div
                                                                class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                <span
                                                                    class="time hours fw-semibold text-danger fs-14">00</span>
                                                                <div class="small text-danger fs-14">h</div>
                                                            </div>
                                                            <div
                                                                class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                <span
                                                                    class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                <div class="small text-danger fs-14">m</div>
                                                            </div>
                                                            <div
                                                                class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                <span
                                                                    class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                <div class="small text-danger fs-14">s</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                @endif
                                            </div>

                                            <div class="w-100">
                                                <div class="fs-12 title-semidark mb-1 d-flex align-items-center flex-wrap gap-2">
                                                    <span>{{ translate('Auction ID') }}#{{ $product->id }}</span>
                                                </div>
                                                <h6 class="mb-10px pe-xl-3 pe-1">
                                                    <a href="{{ $cardDetailsUrl }}"
                                                        class="text-decoration-none fs-14 lh-sm text-capitalize fw-semibold title-clr line--limit-1 text-hover-primary">
                                                        {{ $product->name }}
                                                    </a>
                                                </h6>
                                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                                    @if($displayStatus !== 'upcoming' && $product->total_bids > 0)
                                                        <span class="fs-12 title-semidark">{{ translate('Highest Bid') }}</span>
                                                        <strong class="text-primary fs-14 fw-semibold">
                                                            {{ webCurrencyConverter(amount: $product->current_highest_bid_amount) }}
                                                        </strong>
                                                    @else
                                                        <span class="fs-12 title-semidark">{{ translate('Start Price') }}</span>
                                                        <strong class="text-primary fs-14 fw-semibold">
                                                            {{ webCurrencyConverter(amount: $product->starting_price) }}
                                                        </strong>
                                                    @endif
                                                </div>
                                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                                    <span
                                                        class="fs-12 title-semidark">{{ translate('Min Increment') }}</span>
                                                    <strong class="title-clr fs-14 fw-semibold">
                                                        {{ webCurrencyConverter(amount: $product->minimum_increment_amount) }}
                                                    </strong>
                                                </div>
                                                @if(!is_null($product?->maximum_decrement_amount))
                                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                                    <span class="fs-12 title-semidark">{{ translate('Max Decrement') }}</span>
                                                    <strong class="title-clr fs-14 fw-semibold">
                                                        {{ webCurrencyConverter(amount: $product->maximum_decrement_amount) }}
                                                    </strong>
                                                </div>
                                                @endif
                                                <div
                                                    class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                                            {{ formatCompactNumber($product->total_views) }}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                            <span
                                                                class="title-semidark fs-12">{{ translate('Participants') }}</span>
                                                            {{ formatCompactNumber($product->participants_count ?? 0) }}
                                                        </span>
                                                    </div>
                                                </div>

                                                @if($activeAuctionTab === 'delivered')
                                                    @php
                                                        $claimTx      = $product->transactions?->firstWhere('type', 'auction_payment');
                                                        // Only COD routes earnings to the owner (owner owes admin a commission). offline_payment / digital / wallet all settle through the platform, so the admin owes the owner -> withdraw flow. Mirrors the details page (_commission-withdraw-panel).
                                                        $isCod        = $claimTx?->payment_method === 'cash_on_delivery';
                                                        $breakdown    = $payoutBreakdowns[$product->id] ?? null;
                                                        $detailsUrl   = auth('customer')->check() && auth('customer')->id() == $product?->owner_id && $product?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $product->slug]) : route('auction.profile-view.product-details', ['slug' => $product->slug]);
                                                        $withdrawReq  = $product->latestCustomerWithdraw;
                                                        $commissionTx = $product->transactions?->firstWhere('type', 'commission_payment');
                                                        $commissionStatus    = $commissionTx?->payment_status;
                                                        $commissionIsPaid    = in_array($commissionStatus, [\Modules\Auction\app\Enums\PaymentStatus::PAID, \Modules\Auction\app\Enums\PaymentStatus::COMPLETED], true);
                                                        $commissionIsPending = $commissionStatus === \Modules\Auction\app\Enums\PaymentStatus::PENDING;
                                                    @endphp

                                                    @if($isCod)
                                                        {{-- Commission flow: owner owes admin --}}
                                                        @if(!$commissionIsPaid)
                                                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-2">
                                                                @if($product->is_relaunched)
                                                                    <span class="badge bg-info text-white rounded-4px fs-11 px-2 py-5px d-inline-flex align-items-center gap-1">
                                                                        <i class="fi fi-rr-refresh"></i>{{ translate('Recreated') }}
                                                                    </span>
                                                                @endif
                                                                @if($commissionIsPending)
                                                                    <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                                                        <i class="fi fi-rr-time-fast text-warning"></i>
                                                                        {{ translate('Under Verification') }}
                                                                    </span>
                                                                @else
                                                                    <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                                                        {{ translate('Amount to Pay Admin') }}:
                                                                        <strong class="text-danger fs-12">{{ webCurrencyConverter(amount: $breakdown?->commissionAmount ?? 0) }}</strong>
                                                                    </span>
                                                                    <span class="badge bg-info text-white rounded-4px fs-11 px-2 py-5px d-inline-flex align-items-center gap-1">
                                                                        <i class="fi fi-sr-check-circle"></i> {{ translate('Pay Now') }}
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    @else
                                                        {{-- Withdraw flow: admin owes owner --}}
                                                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-2">
                                                            @if($product->is_relaunched)
                                                                <span class="badge bg-info text-white rounded-4px fs-11 px-2 py-5px d-inline-flex align-items-center gap-1">
                                                                    <i class="fi fi-rr-refresh"></i>{{ translate('Recreated') }}
                                                                </span>
                                                            @endif
                                                            @if($withdrawReq?->status === \Modules\Auction\app\Enums\WithdrawStatus::APPROVED)
                                                                <span class="badge bg-success text-white rounded-4px fs-11 px-2 py-5px d-inline-flex align-items-center gap-1">
                                                                    <i class="fi fi-sr-check-circle"></i> {{ translate('Withdrawn') }}
                                                                </span>
                                                            @else
                                                                <span class="d-flex flex-column gap-1 title-semidark fs-12">
                                                                    <span class="d-flex align-items-center gap-1">
                                                                        {{ translate('Withdraw Amount') }}:
                                                                        <strong class="text-success fs-12">{{ webCurrencyConverter(amount: $breakdown?->vendorReceivable ?? 0) }}</strong>
                                                                    </span>
                                                                    @if($withdrawReq?->status === \Modules\Auction\app\Enums\WithdrawStatus::REJECTED)
                                                                        <span class="text-danger fs-11">{{ translate('Previous request rejected') }}</span>
                                                                    @endif
                                                                </span>

                                                                <span class="badge bg-info text-white rounded-4px fs-11 px-2 py-5px d-inline-flex align-items-center gap-1">
                                                                    <i class="fi fi-sr-check-circle"></i> {{ translate('Withdraw Money') }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                @endif

                                                @if($product->is_relaunched && ($activeAuctionTab !== 'delivered' || (($isCod ?? false) && ($commissionIsPaid ?? false))))
                                                    <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                                                        <span class="badge bg-info text-white rounded-4px fs-11 px-2 py-5px d-inline-flex align-items-center gap-1">
                                                            <i class="fi fi-rr-refresh"></i>{{ translate('Recreated') }}
                                                        </span>
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
                                                <ul class="dropdown-menu shadow-sm p-0">
                                                    <li class="p-0">
                                                        <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                                                            href="{{ auth('customer')->check() && auth('customer')->id() == $product?->owner_id && $product?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $product->slug]) : route('auction.profile-view.product-details', ['slug' => $product->slug]) }}">
                                                            {{ translate('View Details') }}
                                                            <i class="fi fi-sr-eye text-primary"></i>
                                                        </a>
                                                    </li>
                                                    @if ($displayStatus === 'upcoming')
                                                        <li class="p-0">
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                                                                href="{{ route('auction.auction-update-product', ['id' => $product->id]) }}">
                                                                {{ translate('Edit Details') }}
                                                                <i class="fi fi-sr-pen-circle text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li class="p-0">
                                                            <button type="button"
                                                                    class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1 text-danger js-delete-auction-btn"
                                                                    data-auction-id="{{ $product->id }}">
                                                                {{ translate('Delete Auction') }}
                                                                <i class="fi fi-sr-trash text-danger"></i>
                                                            </button>
                                                        </li>
                                                    @endif
                                                    @if ($displayStatus === 'live' && ($product->participants_count ?? 0) === 0)
                                                        <li class="p-0">
                                                            <button type="button"
                                                                    class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1 text-danger js-cancel-auction-btn"
                                                                    data-auction-id="{{ $product->id }}">
                                                                {{ translate('Cancel Auction') }}
                                                                <i class="fi fi-sr-ban text-danger"></i>
                                                            </button>
                                                        </li>
                                                    @endif
                                                    @if ($displayStatus === 'unsold')
                                                        <li class="p-0">
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                                                               href="{{ route('auction.auction-recreate-product', ['id' => $product->id]) }}">
                                                                {{ translate('Recreate') }}
                                                                <i class="fi fi-sr-rotate-right text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li class="p-0">
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
                    @if ($activeProducts->hasPages())
                        <div class="d-flex justify-content-end mt-3">
                            {{ $activeProducts->withQueryString()->links() }}
                        </div>
                    @endif
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
    <form id="js-cancel-auction-form" method="POST" action="{{ route('auction.cancel') }}" class="d-none">
        @csrf
        <input type="hidden" name="auction_product_id" id="js-cancel-auction-product-id" value="">
    </form>
    <form id="js-delete-auction-form" method="POST" action="{{ route('auction.delete') }}" class="d-none">
        @csrf
        <input type="hidden" name="auction_product_id" id="js-delete-auction-product-id" value="">
    </form>
    <script>
        (function () {
            document.querySelectorAll('.js-cancel-auction-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const auctionId = this.dataset.auctionId;
                    Swal.fire({
                        title: '{{ translate('Cancel_Auction') }}',
                        text: '{{ translate('Are_you_sure_you_want_to_cancel_this_auction?_This_action_cannot_be_undone.') }}',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: '{{ translate('Yes,_Cancel_It') }}',
                        cancelButtonText: '{{ translate('No,_Keep_It') }}',
                        reverseButtons: true,
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            document.getElementById('js-cancel-auction-product-id').value = auctionId;
                            document.getElementById('js-cancel-auction-form').submit();
                        }
                    });
                });
            });
        })();
    </script>
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
