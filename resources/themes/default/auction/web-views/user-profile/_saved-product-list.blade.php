@extends("auction.web-views.user-profile._profile-master")

@section('title', translate('Saved Product List'))

@section('profile_content')
    <div>
        <h5 class="fs-18 fw-semibold title-clr text-capitalize mb-12px">
            {{ translate('Saved Product List') }}
            <span class="title-clr fw-normal js-saved-count">({{ $auctionProducts->total() }})</span>
        </h5>

        <div class="">
            <div class="">
                <div class="row g-3">
                    @if(count($auctionProducts) > 0)
                        @foreach($auctionProducts as $auctionProduct)
                            <div class="col-md-6" data-saved-item-id="{{ $auctionProduct->id }}">
                                <div class="item">
                                    @include('auction.web-views.partials._auction-product-horizontal', [
                                        'auctionProduct' => $auctionProduct,
                                        'savedListContext' => true,
                                    ])
                                </div>
                            </div>
                        @endforeach

                        <div class="col-md-12">
                            {!! $auctionProducts->links() !!}
                        </div>
                    @else
                        <div class="d-flex justify-content-center align-items-center w-100 py-5">
                            <div>
                                <img src="{{ theme_asset(path: 'public/assets/front-end/img/media/product.svg') }}" class="img-fluid" alt="">
                                <h6 class="text-muted">{{ translate('No_Product_Found') }}</h6>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
<script>
    $(document).on('click', '.js-remove-saved-auction', function (e) {
        e.preventDefault();
        const btn = $(this);
        const auctionProductId = btn.data('auction-product-id');
        const toggleUrl = btn.data('toggle-url');

        Swal.fire({
            title: '{{ translate('are_you_sure') }}?',
            text: '{{ translate('Do you want to remove this auction from your saved list?') }}',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '{{ translate('Yes, Remove') }}',
            cancelButtonText: '{{ translate('No') }}',
            reverseButtons: true,
        }).then(function (result) {
            if (!result.isConfirmed) return;

            $.ajax({
                url: toggleUrl,
                method: 'POST',
                data: {
                    _token: $('meta[name="_token"]').attr('content'),
                    auction_product_id: auctionProductId,
                },
                success: function (response) {
                    toastr.success(response.message);
                    $('[data-saved-item-id="' + auctionProductId + '"]').remove();
                    const countEl = $('.js-saved-count');
                    const current = parseInt(countEl.text().replace(/[()]/g, '')) || 0;
                    countEl.text('(' + Math.max(0, current - 1) + ')');
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
