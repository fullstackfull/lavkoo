@php
    use App\Models\Seller;
    use Illuminate\Support\Facades\Session;
    $v2Vendor = Seller::with(['shop'])->where('id', auth('seller')->id())->first();
    $v2Direction = Session::get('direction');
    $v2Local = session()->has('local') ? session('local') : 'en';
    $v2LangRow = \App\Models\BusinessSetting::where('type', 'language')->first();
    $v2Languages = $v2LangRow ? json_decode($v2LangRow['value'] ?? '[]', true) : [];
    $v2LogoPath = getWebConfig(name: 'company_web_logo');
    $v2FaviconMini = getWebConfig(name: 'company_fav_icon');

    // Vendor brand: prefer the logged-in seller's shop logo for both the
    // full and mini header marks. Falls back to the platform site logo /
    // favicon when the shop hasn't uploaded an image yet (storageLink
    // returns status 404 in that case). Shop name is used as alt text
    // when available so the brand mark is self-describing.
    $v2ShopImage = $v2Vendor?->shop?->image_full_url;
    $v2HasShopLogo = is_array($v2ShopImage) && (($v2ShopImage['status'] ?? 0) === 200) && !empty($v2ShopImage['path']);
    $v2BrandLogoSrc = $v2HasShopLogo
        ? $v2ShopImage['path']
        : getStorageImages(path: $v2LogoPath, type: 'backend-logo');
    $v2BrandLogoMiniSrc = $v2HasShopLogo
        ? $v2ShopImage['path']
        : getStorageImages(path: $v2FaviconMini, type: 'backend-logo');
    $v2BrandLogoAlt = !empty($v2Vendor?->shop?->name) ? $v2Vendor->shop->name : translate('logo');
    // Resolve the currently-active language entry from the DB-backed
    // business_settings → language row (the same source v1 uses).
    // Display name + flag code for the trigger both come from that row
    // so there's no client-side guessing.
    $v2LocalFlag = $v2Local;
    $v2LocalLangName = '';
    foreach ($v2Languages as $v2LangItem) {
        if (($v2LangItem['code'] ?? '') === $v2Local) {
            $v2LocalFlag = \Illuminate\Support\Str::contains($v2LangItem['code'], '-')
                ? strtolower(explode('-', $v2LangItem['code'])[0])
                : strtolower($v2LangItem['code']);
            $v2LocalLangName = $v2LangItem['name'] ?? '';
        }
    }
@endphp

<header class="v2-header">
    <div class="v2-header-brand">
        <a class="v2-brand-logo" href="{{ route('vendor.dashboard.index') }}" title="{{ $v2BrandLogoAlt }}">
            <img src="{{ $v2BrandLogoSrc }}" alt="{{ $v2BrandLogoAlt }}">
        </a>
        {{-- Compact "mini" mark shown only when the sidebar is collapsed.
             Uses the same shop image when available so the brand identity
             stays consistent in both expanded and collapsed states. --}}
        <a class="v2-brand-logo-mini" href="{{ route('vendor.dashboard.index') }}" title="{{ $v2BrandLogoAlt }}">
            <img src="{{ $v2BrandLogoMiniSrc }}" alt="{{ $v2BrandLogoAlt }}">
        </a>
        <button class="v2-sidebar-toggle" id="v2-sidebar-toggle" type="button"
                data-v2-tooltip="{{ translate('Collapse_sidebar') }}" aria-label="{{ translate('Toggle_sidebar') }}"
                data-v2-tip-expanded="{{ translate('Collapse_sidebar') }}"
                data-v2-tip-collapsed="{{ translate('Expand_sidebar') }}">
            {{-- Shown when sidebar is expanded → "collapse" arrow pointing inward --}}
            <svg class="v2-sb-icon v2-sb-collapse" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 6l-6 6 6 6"/>
                <path d="M21 4v16"/>
            </svg>
            {{-- Shown when sidebar is collapsed → "expand" arrow pointing outward --}}
            <svg class="v2-sb-icon v2-sb-expand" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 6l6 6-6 6"/>
                <path d="M3 4v16"/>
            </svg>
        </button>
    </div>

    <nav class="v2-crumbs" id="v2-header-crumbs" aria-label="{{ translate('Breadcrumb') }}"></nav>

    <div class="v2-header-right">
        {{-- Website shop view (vendor storefront) --}}
        <a class="v2-icon-btn"
           href="{{ !empty($v2Vendor?->shop) ? route('vendor-shop', ['slug' => $v2Vendor?->shop?->slug ?? '']) : 'javascript:;' }}"
           target="_blank"
           data-v2-tooltip="{{ translate('website_shop_view') }}"
           aria-label="{{ translate('website_shop_view') }}">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/>
                <path d="M3 12h18"/>
                <path d="M12 3a13.5 13.5 0 0 1 0 18"/>
                <path d="M12 3a13.5 13.5 0 0 0 0 18"/>
            </svg>
        </a>

        {{-- Notifications. Class names notification-data-view /
             notification_data_new_count / notification_data_new_badge{id}
             are kept verbatim so the existing jQuery handler in
             resources/views/layouts/vendor/partials/_script-partials.blade.php
             continues to fire (mark-as-seen + badge fadeout + count update). --}}
        @php
            $v2VendorNotifBase = \App\Models\Notification::whereBetween('created_at', [
                    auth('seller')->user()->created_at,
                    \Carbon\Carbon::now(),
                ])
                ->where('sent_to', 'seller');
            $v2VendorNotifList = (clone $v2VendorNotifBase)
                ->with('notificationSeenBy')
                ->latest()
                ->get();
            $v2VendorNotifNew = (clone $v2VendorNotifBase)
                ->whereDoesntHave('notificationSeenBy')
                ->count();

            $v2AuctionSellerNotifs      = collect();
            $v2AuctionSellerNotifUnread = 0;
            if (getCheckAddonPublishedStatus(moduleName: 'Auction') && auth('seller')->id()) {
                $v2AuctionSellerNotifs = \Modules\Auction\app\Models\AuctionNotification::where([
                    'receiver_id'   => auth('seller')->id(),
                    'receiver_type' => \Modules\Auction\app\Enums\OwnerType::SELLER,
                ])->with('auctionProduct:id,name')->latest()->take(20)->get();
                $v2AuctionSellerNotifUnread = $v2AuctionSellerNotifs->where('is_read', false)->count();
                $v2VendorNotifNew += $v2AuctionSellerNotifUnread;
            }
        @endphp
        <div class="v2-dropdown">
            <button class="v2-icon-btn" type="button" data-v2-dropdown-toggle
                    data-v2-tooltip="{{ translate('Notifications') }}"
                    aria-label="{{ translate('Notifications') }}">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                    <path d="M10 21a2 2 0 0 0 4 0"/>
                </svg>
                @if ($v2VendorNotifNew > 0)
                    <span class="v2-badge-dot notification_data_new_count">{{ $v2VendorNotifNew > 99 ? '99+' : $v2VendorNotifNew }}</span>
                @endif
            </button>
            <div class="v2-dropdown-menu v2-notif-menu">
                @foreach ($v2VendorNotifList as $v2VendorNotifItem)
                    <button type="button" class="v2-dropdown-item notification-data-view w-100"
                            data-id="{{ $v2VendorNotifItem->id }}">
                        <span class="v2-notif-title">{{ translate($v2VendorNotifItem->title) }}</span>
                        <span class="v2-notif-time">{{ $v2VendorNotifItem->created_at->diffforHumans() }}</span>
                        @if ($v2VendorNotifItem->notification_seen_by == null)
                            <span class="v2-notif-new notification_data_new_badge{{ $v2VendorNotifItem->id }}">
                                {{ translate('new') }}
                            </span>
                        @endif
                    </button>
                @endforeach

                @foreach ($v2AuctionSellerNotifs as $v2AuctNotif)
                    @php
                        $v2AuctNotifLabel = ($vendorAuctionNotifTitles ?? [])[$v2AuctNotif->type]
                            ?? ucwords(str_replace('_', ' ', (string) $v2AuctNotif->type));
                        $v2AuctNotifProductUrl = $v2AuctNotif->auctionProduct
                            ? route('vendor.auction.products.view', ['id' => $v2AuctNotif->auction_product_id])
                            : '';
                    @endphp
                    <button type="button" class="v2-dropdown-item auction-notif-read w-100"
                            data-auction-notif-id="{{ $v2AuctNotif->id }}"
                            data-title="{{ $v2AuctNotifLabel }}"
                            data-message="{{ $v2AuctNotif->message }}"
                            data-product-url="{{ $v2AuctNotifProductUrl }}">
                        <span class="v2-notif-title">{{ $v2AuctNotifLabel }}</span>
                        <span class="v2-notif-time">{{ $v2AuctNotif->created_at->diffForHumans() }}</span>
                        @if (!$v2AuctNotif->is_read)
                            <span class="v2-notif-new auction_notif_badge_{{ $v2AuctNotif->id }}">
                                {{ translate('new') }}
                            </span>
                        @endif
                    </button>
                @endforeach

                @if ($v2VendorNotifList->isEmpty() && $v2AuctionSellerNotifs->isEmpty())
                    <div class="v2-dropdown-empty">{{ translate('no_notifications') }}</div>
                @endif
            </div>
        </div>

        {{-- Messages / Inbox. Vendor v1 splits unread into customer vs
             delivery-man, each with its own count — preserved here as a
             two-row dropdown. --}}
        @php
            $v2VendorMsgBase = \App\Models\Chatting::where([
                'seen_by_seller' => 0,
                'seller_id'      => auth('seller')->id(),
            ]);
            $v2VendorMsgTotal       = (clone $v2VendorMsgBase)->count();
            $v2VendorMsgCustomer    = (clone $v2VendorMsgBase)->whereNotNull('user_id')->count();
            $v2VendorMsgDeliveryMan = (clone $v2VendorMsgBase)->whereNotNull('delivery_man_id')->count();
        @endphp
        <div class="v2-dropdown">
            <button class="v2-icon-btn" type="button" data-v2-dropdown-toggle
                    data-v2-tooltip="{{ translate('Inbox') }}"
                    aria-label="{{ translate('Inbox') }}">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 5h16a1 1 0 0 1 1 1v12a2 2 0 0 1-2 2H8l-4 3V6a1 1 0 0 1 1-1Z"/>
                    <path d="M8 11h8M8 15h5"/>
                </svg>
                @if ($v2VendorMsgTotal > 0)
                    <span class="v2-badge-dot">{{ $v2VendorMsgTotal > 99 ? '99+' : $v2VendorMsgTotal }}</span>
                @endif
            </button>
            <div class="v2-dropdown-menu v2-msg-menu">
                <a class="v2-dropdown-item" href="{{ route('vendor.messages.index', ['type' => 'customer']) }}">
                    <span>{{ translate('customer') }}</span>
                    @if ($v2VendorMsgCustomer > 0)
                        <span class="v2-item-count">{{ $v2VendorMsgCustomer }}</span>
                    @endif
                </a>
                <a class="v2-dropdown-item" href="{{ route('vendor.messages.index', ['type' => 'delivery-man']) }}">
                    <span>{{ translate('delivery_man') }}</span>
                    @if ($v2VendorMsgDeliveryMan > 0)
                        <span class="v2-item-count">{{ $v2VendorMsgDeliveryMan }}</span>
                    @endif
                </a>
            </div>
        </div>

        {{-- Pending orders. Vendor-scoped Order query + admin-v2 .v2-pop-*
             popup design. The +N thumbnail chip and view-eye action mirror
             the admin v2 header so both panels share the same visual. --}}
        @php
            $v2VendorPendingBase = \App\Models\Order::where([
                'seller_is'    => 'seller',
                'seller_id'    => auth('seller')->id(),
                'order_status' => 'pending',
            ]);
            $v2VendorPendingCount = (clone $v2VendorPendingBase)->count();
            $v2VendorPendingOrders = (clone $v2VendorPendingBase)
                ->with('details.productAllStatus')
                ->orderBy('id', 'desc')
                ->take(5)
                ->get();
        @endphp
        <div class="v2-dropdown">
            <button class="v2-icon-btn" type="button" data-v2-dropdown-toggle
                    data-v2-tooltip="{{ translate('pending_Orders') }}"
                    aria-label="{{ translate('pending_Orders') }}">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 8h14l-1 12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 8Z"/>
                    <path d="M9 8V6a3 3 0 0 1 6 0v2"/>
                </svg>
                @if ($v2VendorPendingCount > 0)
                    <span class="v2-badge-dot">{{ $v2VendorPendingCount > 99 ? '99+' : $v2VendorPendingCount }}</span>
                @endif
            </button>
            <div class="v2-dropdown-menu v2-orders-menu">
                <div class="v2-pop">
                    <div class="v2-pop-head">
                        <div class="v2-pop-head-title">
                            <h4>{{ translate('total_orders') }}</h4>
                            <span>{{ $v2VendorPendingCount }}</span>
                        </div>
                        <a class="v2-pop-view-all" href="{{ route('vendor.orders.list', ['pending']) }}">
                            {{ translate('view_all') }}
                            <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 10h10"/><path d="m11 6 4 4-4 4"/>
                            </svg>
                        </a>
                    </div>
                    <div class="v2-pop-scroll">
                        <table class="v2-pop-table">
                            <tbody>
                                @forelse ($v2VendorPendingOrders as $v2VendorOrder)
                                    @php
                                        $v2VendorThumbs = [];
                                        foreach ($v2VendorOrder->details as $v2OrderDetail) {
                                            $v2OrderImg = $v2OrderDetail?->productAllStatus?->thumbnail_full_url;
                                            if ($v2OrderImg && ($v2OrderImg['status'] ?? 0) === 200) {
                                                $v2VendorThumbs[] = $v2OrderImg;
                                            }
                                        }
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="v2-pop-thumbs">
                                                @if (count($v2VendorThumbs))
                                                    @foreach ($v2VendorThumbs as $v2VendorThumbIdx => $v2VendorThumb)
                                                        <div class="v2-pop-thumb-wrap{{ $v2VendorThumbIdx > 2 ? ' is-hidden' : '' }}">
                                                            <img src="{{ getStorageImages(path: $v2VendorThumb, type: 'backend-product') }}" alt="product image">
                                                            @if ($v2VendorThumbIdx == 2 && count($v2VendorThumbs) > 3)
                                                                <div class="v2-pop-thumb-more">
                                                                    <span>+ {{ count($v2VendorThumbs) - 3 }}</span>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                @else
                                                    <img src="{{ getStorageImages(path: '', type: 'backend-product') }}" alt="placeholder">
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            <div class="v2-pop-info">
                                                <div class="v2-pop-row">
                                                    <div class="v2-pop-label">{{ translate('Order_id') }}</div>
                                                    :
                                                    <span class="v2-pop-value">{{ $v2VendorOrder->id }}</span>
                                                </div>
                                                <div class="v2-pop-row">
                                                    <div class="v2-pop-label">{{ translate('Order_Amount') }}</div>
                                                    :
                                                    <span class="v2-pop-value">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $v2VendorOrder->order_amount), currencyCode: getCurrencyCode()) }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="v2-pop-action-wrap">
                                                <a class="v2-pop-action" href="{{ route('vendor.orders.details', ['id' => $v2VendorOrder->id]) }}"
                                                   title="{{ translate('view') }}">
                                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6Z"/><circle cx="10" cy="10" r="2.5"/>
                                                    </svg>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="v2-pop-empty">{{ translate('no_pending_orders') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Language switcher --}}
        <div class="v2-dropdown">
            <button class="v2-lang-btn" type="button" data-v2-dropdown-toggle
                    aria-label="{{ translate('Language') }}">
                <img class="v2-flag-img" width="18" height="18"
                     src="{{ dynamicAsset(path: 'public/assets/front-end/img/flags/' . $v2LocalFlag . '.png') }}"
                     alt="{{ $v2Local }}">
                {{-- Compact locale code (EN, BN, …) so the header chip
                     stays narrow. The dropdown below shows the full DB
                     name for each language. --}}
                <span class="v2-lang-name">{{ strtoupper($v2LocalFlag ?: $v2Local) }}</span>
                <svg class="v2-lang-caret" width="10" height="10" viewBox="0 0 20 20" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m5 8 5 5 5-5"/>
                </svg>
            </button>
            <div class="v2-dropdown-menu">
                @foreach ($v2Languages as $v2Lang)
                    @php
                        $v2LangCode = $v2Lang['code'] ?? '';
                        $v2LangFlagCode = \Illuminate\Support\Str::contains($v2LangCode, '-')
                            ? strtolower(explode('-', $v2LangCode)[0])
                            : strtolower($v2LangCode);
                    @endphp
                    @if (($v2Lang['status'] ?? 0) == 1)
                        <a class="v2-dropdown-item change-language {{ $v2LangCode == $v2Local ? 'v2-is-active' : '' }}"
                           href="javascript:"
                           data-action="{{ route('change-language') }}"
                           data-language-code="{{ $v2LangCode }}">
                            <img width="20" height="20" style="border-radius:50%;object-fit:cover;"
                                 src="{{ dynamicAsset(path: 'public/assets/front-end/img/flags/' . $v2LangFlagCode . '.png') }}"
                                 alt="{{ $v2Lang['name'] ?? $v2LangCode }}">
                            <span class="text-capitalize">{{ $v2Lang['name'] ?? $v2LangCode }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- Fullscreen toggle --}}
        <button class="v2-icon-btn" type="button" id="v2-fullscreen-toggle"
                data-v2-tooltip="{{ translate('Enter_fullscreen') }}" aria-label="{{ translate('Enter_fullscreen') }}"
                data-v2-tip-enter="{{ translate('Enter_fullscreen') }}"
                data-v2-tip-exit="{{ translate('Exit_fullscreen') }}">
            <svg class="v2-fs-icon v2-fs-enter" width="19" height="19" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4 9V5a1 1 0 0 1 1-1h4"/>
                <path d="M15 4h4a1 1 0 0 1 1 1v4"/>
                <path d="M20 15v4a1 1 0 0 1-1 1h-4"/>
                <path d="M9 20H5a1 1 0 0 1-1-1v-4"/>
            </svg>
            <svg class="v2-fs-icon v2-fs-exit" style="display:none" width="19" height="19" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 4v4a1 1 0 0 1-1 1H4"/>
                <path d="M15 4v4a1 1 0 0 0 1 1h4"/>
                <path d="M15 20v-4a1 1 0 0 1 1-1h4"/>
                <path d="M9 20v-4a1 1 0 0 0-1-1H4"/>
            </svg>
        </button>

        {{-- Profile dropdown --}}
        <div class="v2-dropdown">
            <button class="v2-avatar-wrap" type="button" data-v2-dropdown-toggle
                    data-v2-tooltip="{{ $v2Vendor->f_name ?? '' }}" aria-label="{{ $v2Vendor->f_name ?? '' }}">
                <span class="v2-avatar">
                    <img src="{{ getStorageImages(path: $v2Vendor->image_full_url ?? '', type: 'backend-profile') }}"
                         alt="{{ translate('image_description') }}">
                    <span class="v2-avatar-status" aria-hidden="true"></span>
                </span>
                <span class="v2-profile-inline" aria-hidden="true">
                    <span class="v2-profile-inline-name">{{ $v2Vendor->f_name ?? '' }}</span>
                    <span class="v2-profile-inline-role">{{ $v2Vendor->email ?? '' }}</span>
                </span>
                <span class="v2-avatar-caret" aria-hidden="true">
                    <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 5 5 5-5"/></svg>
                </span>
            </button>
            <div class="v2-dropdown-menu">
                <div class="v2-dropdown-header">
                    <img src="{{ getStorageImages(path: $v2Vendor->image_full_url ?? '', type: 'backend-profile') }}"
                         alt="{{ translate('image_description') }}">
                    <div>
                        <div class="v2-profile-name">{{ $v2Vendor->f_name ?? '' }}</div>
                        <div class="v2-profile-role">{{ $v2Vendor->email ?? '' }}</div>
                    </div>
                </div>
                <a class="v2-dropdown-item"
                   href="{{ route('vendor.profile.update', [$v2Vendor->id ?? '']) }}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>
                    </svg>
                    <span>{{ translate('settings') }}</span>
                </a>
                <div class="v2-dropdown-divider"></div>
                <a class="v2-dropdown-item v2-dropdown-item-danger" href="javascript:"
                   data-toggle="modal" data-target="#sign-out-modal">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <path d="m16 17 5-5-5-5M21 12H9"/>
                    </svg>
                    <span>{{ translate('logout') }}</span>
                </a>
            </div>
        </div>
    </div>
</header>
