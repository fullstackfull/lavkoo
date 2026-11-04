@extends("auction.web-views.user-profile._profile-master")

@section('title', translate('My_Bids_List'))

@section('profile_content')
    <div class="">
        <h5 class="fs-18 fw-semibold title-clr text-capitalize mb-12px">{{ translate('My Bids') }}</h5>

        <div class="">
            <div class="position-relative nav--tab-wrapper mb-3">
                <ul class="nav nav--tab flex-nowrap text-nowrap p-0 overflow-auto nav-rounded scrollbar-none d-flex align-items-center justify-content-start gap-xxl-20px gap-3" id="pills-tab" role="tablist">
                    @foreach($tabs as $key => $tab)
                        @if($key === 'all') @continue @endif
                        <li class="nav-item" role="presentation">
                            <a href="{{ request()->fullUrlWithQuery(['auction_status' => $key, 'page' => null]) }}"
                               class="nav-link fs-14 {{ $auctionStatus === $key ? 'active' : '' }}">
                                {{ $tab['label'] }} <span class="text-light-gray">({{ $tab['count'] }})</span>
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
                    <div class="mb-3">
                        <h5 class="fw-semibold fs-16 title-clr mb-1">
                            {{ $tabs[$auctionStatus]['label'] ?? translate('All') }} {{ translate('Auctions') }}
                        </h5>
                        <p class="m-0 fs-14 title-semidark">{{ $tabDescriptions[$auctionStatus] ?? $tabDescriptions['all'] }}</p>
                    </div>

                    @if($auctionProducts->count() > 0)
                        <div class="row g-3">
                            @foreach($auctionProducts as $auctionProduct)
                                <div class="col-md-6">
                                    <div class="item">
                                        @include('auction.web-views.partials._auction-product-horizontal', ['auctionProduct' => $auctionProduct, 'actionBtn' => true, 'bidListContext' => true, 'bidListTab' => $auctionStatus])
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 d-flex justify-content-center">
                            {{ $auctionProducts->appends(request()->query())->links() }}
                        </div>
                    @else
                        <div class="bg-light rounded text-center px-2 py-5">
                            <img width="55" height="55" src="{{ dynamicAsset(path: 'public/assets/front-end/img/empty-icons/auction-pending-empty.svg') }}" alt="" class="mb-3">                            
                            <p class="fs-14 title-semidark">{{ translate('No auctions found for this filter') }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
<script>
    $(document).on('click', '.js-withdraw-bid-btn', function (e) {
        e.preventDefault();
        const btn = $(this);
        const auctionProductId = btn.data('auction-product-id');
        const withdrawUrl = btn.data('withdraw-url');

        Swal.fire({
            title: '{{ translate('are_you_sure') }}?',
            text: '{{ translate('Do you want to withdraw your bid from this auction?') }}',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '{{ translate('Yes, Withdraw') }}',
            cancelButtonText: '{{ translate('No') }}',
            reverseButtons: true,
        }).then(function (result) {
            if (!result.isConfirmed) return;

            $.ajax({
                url: withdrawUrl,
                method: 'POST',
                data: {
                    _token: $('meta[name="_token"]').attr('content'),
                    auction_product_id: auctionProductId,
                },
                success: function (response) {
                    if (response.status === 1) {
                        toastr.success(response.message);
                        setTimeout(function () { location.reload(); }, 800);
                    } else {
                        toastr.error(response.message);
                    }
                },
                error: function (xhr) {
                    const message = xhr.responseJSON?.message ?? '{{ translate('Something went wrong') }}';
                    toastr.error(message);
                },
            });
        });
    });
</script>
@endpush
