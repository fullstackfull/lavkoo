
<div class="p-12px bg-white rounded shadow-sm">
    <div class="d-flex flex-column gap-4">
        <div class="profile-content-area d-flex flex-column gap-10px">
            <a href="{{ route('user-account') }}" class="aside-items-profile {{ Request::is('user-account*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/profile.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                {{ translate('My Profile') }}
            </a>
            <a href="{{ route('account-oder') }}" class="aside-items-profile {{ Request::is('account-oder*') || Request::is('account-order-details*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/orders.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                {{ translate('Orders') }}
            </a>
            <a href="{{ route('user-restock-requests') }}" class="aside-items-profile {{ Request::is('user-restock-requests*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <i class="fi fi-rr-refresh fs-14"></i>
                {{ translate('Restock Requests') }}
            </a>
            <a href="{{ route('wishlists') }}" class="aside-items-profile {{ Request::is('wishlists*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <i class="fi fi-sr-circle-heart"></i>
                {{ translate('Wish List') }}
            </a>
            @if(getWebConfig(name: 'wallet_status') == 1)
            <a href="{{ route('wallet') }}" class="aside-items-profile {{ Request::is('wallet') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/wallet.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                {{ translate('Wallet') }}
            </a>
            @endif
            @if(getWebConfig(name: 'loyalty_point_status') == 1)
            <a href="{{ route('loyalty') }}" class="aside-items-profile {{ Request::is('loyalty') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/loylty-point.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                {{ translate('Loyalty Point') }}
            </a>
            @endif
            @php($business_mode = getWebConfig(name: 'business_mode'))
            <a href="{{ route('chat', ['type' => $business_mode == 'multi' ? 'vendor' : 'delivery-man']) }}" class="aside-items-profile {{ Request::is('chat/vendor') || Request::is('chat/delivery-man') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/inboxs.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                {{ translate('Inbox') }}
            </a>
            <a href="{{ route('account-tickets') }}" class="aside-items-profile {{ Request::is('account-ticket*') || Request::is('support-ticket*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/support-ticket.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                {{ translate('Support Ticket') }}
            </a>
            <a href="{{ route('account-address') }}" class="aside-items-profile {{ Request::is('account-address*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/address.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                {{ translate('Address') }}
            </a>
            @if(getWebConfig(name: 'ref_earning_status'))
            <a href="{{ route('refer-earn') }}" class="aside-items-profile {{ Request::is('refer-earn*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <i class="fi fi-rr-share fs-14"></i>
                {{ translate('Refer & Earn') }}
            </a>
            @endif
            <a href="{{ route('user-coupons') }}" class="aside-items-profile {{ Request::is('user-coupons*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <i class="fi fi-rr-ticket fs-14"></i>
                {{ translate('Coupons') }}
            </a>
            <a href="{{ route('track-order.index') }}" class="aside-items-profile {{ Request::is('track-order*') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/track-order.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                {{ translate('Track Order') }}
            </a>
        </div>

        <div class="border-bottom"></div>

        @if(function_exists('shouldShowAuctionMenu') && shouldShowAuctionMenu())
        <div class="light-box rounded p-10px">
            <h6 class="fs-14 mb-10px title-semidark fw-normal">{{ translate('Auction') }}</h6>
            <div class="d-flex flex-column gap-10px">
                <a href="{{ route('auction.bids.list') }}" class="aside-items-profile aside-items-auction {{ Route::is('auction.bids.list', 'auction.bids-product-details*', 'auction.profile-view.product-details') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                    <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/my-bids.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                    {{ translate('My Bids') }}
                </a>

                @php($my_auctions_active = Route::is('auction.acution-create-product', 'auction.list', 'auction.auctions-request-list', 'auction.auction-update-product', 'auction.auction-recreate-product', 'auction.profile-view.author-product-details'))
                <div class="menu--caret-accordion">
                    <div class="card-header aside-items-profile aside-items-auction {{ $my_auctions_active ? 'active' : '' }} rounded menu--caret-box p-10px sub-header">
                        <div class="menu--caret cursor--pointer d-flex  align-items-center gap-1 justify-content-between {{ $my_auctions_active ? 'active' : '' }}">
                            <a href="javascript:void(0)" class="names cursor--pointer d-flex gap-10px align-items-center title-semidark">
                                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/my-auctions.svg') }}" alt="" class="w-20px h-20px svg">
                                <span class="line--limit-1 fs-14 fw-normal">
                                   {{ translate('My Auctions') }}
                                </span>
                            </a>
                            <div class="cursor--pointer arrow_rot_icon">
                                <strong><i class="fi fi-rr-angle-right fs-12 title-clr"></i></strong>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0 pb-2 ps-2 {{ $my_auctions_active ? '' : 'd--none' }} bg-white">
                        @if(getWebConfig(name: 'auction_feature_status') && getWebConfig(name: 'active_auction_for_customer') == 1)
                        <a href="{{ route('auction.acution-create-product') }}" class="py-2 fs-13 {{ Route::is('auction.acution-create-product') ? 'text-primary' : 'title-clr' }} d-flex align-items-center gap-1 acount-items">
                            <i class="fi fi-sr-wifi-1 fs-14"></i> {{ translate('Create Auction') }}
                        </a>
                        @endif
                        <a href="{{ route('auction.list') }}" class="py-2 fs-13 {{ Route::is('auction.list') || (Route::is('auction.profile-view.author-product-details') && empty($isAuctionInactiveState)) ? 'text-primary' : 'title-clr' }} d-flex align-items-center gap-1 acount-items">
                            <i class="fi fi-sr-wifi-1 fs-14"></i> {{ translate('Auction List') }}
                        </a>
                        <a href="{{ route('auction.auctions-request-list') }}" class="py-2 fs-13 {{ Route::is('auction.auctions-request-list', 'auction.auction-update-product', 'auction.auction-recreate-product') || (Route::is('auction.profile-view.author-product-details') && !empty($isAuctionInactiveState)) ? 'text-primary' : 'title-clr' }} d-flex align-items-center gap-1 acount-items">
                            <i class="fi fi-sr-wifi-1 fs-14"></i> {{ translate('Auctions Request List') }}
                        </a>
                    </div>
                </div>
                <a href="{{ route('auction.saved-products-list') }}" class="aside-items-profile aside-items-auction {{ Route::is('auction.saved-products-list') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                    <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/badge.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                    {{ translate('Saved Auction') }}
                </a>
                <a href="{{ route('auction.sales-report') }}" class="aside-items-profile aside-items-auction {{ Route::is('auction.sales-report') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                    <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/display-chartup.svg') }}" alt="" class="w-20px h-20px object-contain svg">
                    {{ translate('Auction Sales Report') }}
                </a>
                <a href="{{ route('auction.transaction-history') }}" class="aside-items-profile aside-items-auction {{ Route::is('auction.transaction-history') ? 'active' : '' }} rounded-1 py-2 px-2 d-flex align-items-center gap-10px text-light-gray fs-14 fw-normal">
                    <i class="fi fi-rr-list fs-14"></i>
                    {{ translate('Transaction with Admin') }}
                </a>
            </div>
        </div>
        @endif
    </div>
</div>
