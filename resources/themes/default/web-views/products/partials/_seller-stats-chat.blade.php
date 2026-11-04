{{-- Shared tail of the PDP seller card: the reviews/products tiles and the
     chat button. Rendered identically for vendor and in-house shops; only the
     ability check differs, passed in as $chatDisabled. Guests always land on
     the login page — the in-house branch used to send them to the shop page. --}}
<div class="col-12 mt-2">
    <div class="row d-flex justify-content-between">
        <div class="col-6 ">
            <div class="d-flex justify-content-center align-items-center rounded __h-79px hr-right-before">
                <div class="text-center">
                    <img src="{{ theme_asset(path: 'public/assets/front-end/img/rating.svg') }}"
                         class="mb-2" alt="">
                    <div class="__text-12px text-base">
                        <strong>{{ $totalReviews }}</strong>
                        {{ translate('reviews') }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6">
            <div class="d-flex justify-content-center align-items-center rounded __h-79px">
                <div class="text-center">
                    <img src="{{ theme_asset(path: 'public/assets/front-end/img/products.svg') }}"
                         class="mb-2" alt="">
                    <div class="__text-12px text-base">
                        <strong>{{ $productsForReview->total() }}</strong>
                        {{ translate('products') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="col-12 position-static mt-3">
    <div class="chat_with_seller-buttons">
        @if (auth('customer')->id())
            <button class="btn w-100 d-block text-center web--bg-primary text-white"
                    data-toggle="modal"
                    data-target="#chatting_modal"
                    {{ $chatDisabled ? 'disabled' : '' }}>
                <img class="mb-1" alt=""
                     src="{{ theme_asset(path: 'public/assets/front-end/img/chat-16-filled-icon.png') }}">
                <span class="d-none d-sm-inline-block text-capitalize">
                    {{ translate('chat_with_vendor') }}
                </span>
            </button>
        @else
            <a href="{{ route('customer.auth.login') }}"
               class="btn w-100 d-block text-center web--bg-primary text-white">
                <img class="mb-1" alt=""
                     src="{{ theme_asset(path: 'public/assets/front-end/img/chat-16-filled-icon.png') }}">
                <span class="d-none d-sm-inline-block text-capitalize">
                    {{ translate('chat_with_vendor') }}
                </span>
            </a>
        @endif
    </div>
</div>
