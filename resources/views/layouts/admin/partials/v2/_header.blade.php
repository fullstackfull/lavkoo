@php
    use App\Utils\Helpers;
    $v2Logo = getWebConfig(name: 'company_web_logo');
    $v2Favicon = getWebConfig(name: 'company_fav_icon');
    $v2Local = session()->has('local') ? session('local') : 'en';
    $v2Languages = getWebConfig(name: 'language') ?? [];
    // Resolve the currently-active language entry from the DB-backed
    // business_settings → language config (the same source v1 uses).
    // Display name + flag code for the trigger both come from that row
    // so there's no client-side guessing.
    $v2LocalFlag = $v2Local;
    $v2LocalLangName = '';
    foreach ($v2Languages as $v2LangRow) {
        if ($v2LangRow['code'] === $v2Local) {
            $v2LocalFlag = \Illuminate\Support\Str::contains($v2LangRow['code'], '-')
                ? strtolower(explode('-', $v2LangRow['code'])[0])
                : strtolower($v2LangRow['code']);
            $v2LocalLangName = $v2LangRow['name'] ?? '';
        }
    }
    $v2PendingOrderCount = $sidebarOrderCounts['pending'] ?? 0;
    $v2UnseenMessages = $sidebarUnseenMessages ?? 0;
    $v2Last5Orders = \App\Models\Order::where('order_status', 'pending')
        ->with('details.productAllStatus')
        ->orderBy('id', 'desc')
        ->take(5)
        ->get();
@endphp

<header class="v2-header">
    <div class="v2-header-brand">
        <a class="v2-brand-logo" href="{{ route('admin.dashboard.index') }}" title="{{ translate('dashboard') }}">
            <img src="{{ getStorageImages(path: $v2Logo, type: 'backend-logo') }}" alt="{{ translate('logo') }}">
        </a>
        {{-- Compact "mini" mark shown only when the sidebar is collapsed. --}}
        <a class="v2-brand-logo-mini" href="{{ route('admin.dashboard.index') }}" title="{{ translate('dashboard') }}">
            <img src="{{ getStorageImages(path: $v2Favicon, type: 'backend-logo') }}" alt="{{ translate('logo') }}">
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
        {{-- Search --}}
        <button class="v2-search-btn" id="v2-header-search" type="button"
                data-v2-tooltip="{{ translate('Search') }} · ⌘K" aria-label="{{ translate('Search') }}">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
            </svg>
            <span>{{ translate('Search') }}…</span>
            <span class="v2-kbd-group"><span class="v2-kbd">⌘</span><span class="v2-kbd">K</span></span>
        </button>

        {{-- Website link --}}
        <a class="v2-icon-btn" href="{{ route('home') }}" target="_blank"
           data-v2-tooltip="{{ translate('Website') }}" aria-label="{{ translate('Website') }}">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/>
                <path d="M3 12h18"/>
                <path d="M12 3a13.5 13.5 0 0 1 0 18"/>
                <path d="M12 3a13.5 13.5 0 0 0 0 18"/>
            </svg>
        </a>

        {{-- Pending orders --}}
        @if (Helpers::module_permission_check('orders'))
            <div class="v2-dropdown">
                <button class="v2-icon-btn" type="button" data-v2-dropdown-toggle
                        data-v2-tooltip="{{ translate('pending_Orders') }}" aria-label="{{ translate('pending_Orders') }}">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 8h14l-1 12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 8Z"/>
                        <path d="M9 8V6a3 3 0 0 1 6 0v2"/>
                    </svg>
                    @if ($v2PendingOrderCount > 0)
                        <span class="v2-badge-dot">{{ $v2PendingOrderCount > 99 ? '99+' : $v2PendingOrderCount }}</span>
                    @endif
                </button>
                {{-- 1:1 port of the v1 .dropdown-cart popup with v2-prefixed
                     class names. Same <table>-based 3-column layout (thumbs /
                     order info / action), same stacked-thumbnail pattern with
                     +N overflow chip on the third tile. CSS lives in admin-v2.css
                     under .v2-pop-* selectors so v2 doesn't depend on Bootstrap
                     dropdown-cart, ms-n-6px, extra-images, btn-square, etc. --}}
                <div class="v2-dropdown-menu v2-orders-menu">
                    <div class="v2-pop">
                        <div class="v2-pop-head">
                            <div class="v2-pop-head-title">
                                <h4>{{ translate('total_orders') }}</h4>
                                <span>{{ $v2PendingOrderCount }}</span>
                            </div>
                            <a class="v2-pop-view-all" href="{{ route('admin.orders.list', ['status' => 'pending']) }}">
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
                                    @forelse ($v2Last5Orders as $v2Order)
                                        @php
                                            $v2Thumbs = [];
                                            foreach ($v2Order->details as $d) {
                                                $img = $d?->productAllStatus?->thumbnail_full_url;
                                                if ($img && ($img['status'] ?? 0) === 200) {
                                                    $v2Thumbs[] = $img;
                                                }
                                            }
                                        @endphp
                                        <tr>
                                            <td>
                                                <div class="v2-pop-thumbs">
                                                    @if (count($v2Thumbs))
                                                        @foreach ($v2Thumbs as $idx => $v2ThumbItem)
                                                            <div class="v2-pop-thumb-wrap{{ $idx > 2 ? ' is-hidden' : '' }}">
                                                                <img src="{{ getStorageImages(path: $v2ThumbItem, type: 'backend-product') }}" alt="product image">
                                                                @if ($idx == 2 && count($v2Thumbs) > 3)
                                                                    <div class="v2-pop-thumb-more">
                                                                        <span>+ {{ count($v2Thumbs) - 3 }}</span>
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
                                                        <span class="v2-pop-value">{{ $v2Order->id }}</span>
                                                    </div>
                                                    <div class="v2-pop-row">
                                                        <div class="v2-pop-label">{{ translate('Order_Amount') }}</div>
                                                        :
                                                        <span class="v2-pop-value">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $v2Order->order_amount), currencyCode: getCurrencyCode()) }}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="v2-pop-action-wrap">
                                                    <a class="v2-pop-action" href="{{ route('admin.orders.details', ['id' => $v2Order->id]) }}"
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
        @endif

        {{-- Messages --}}
        @if (Helpers::module_permission_check('people'))
            <a class="v2-icon-btn" href="{{ route('admin.contact.list') }}"
               data-v2-tooltip="{{ translate('message') }}" aria-label="{{ translate('message') }}">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 5h16a1 1 0 0 1 1 1v12a2 2 0 0 1-2 2H8l-4 3V6a1 1 0 0 1 1-1Z"/>
                    <path d="M8 11h8M8 15h5"/>
                </svg>
                @if ($v2UnseenMessages > 0)
                    <span class="v2-badge-dot">{{ $v2UnseenMessages > 99 ? '99+' : $v2UnseenMessages }}</span>
                @endif
            </a>
        @endif

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
                        $v2LangCode = $v2Lang['code'];
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

        {{-- Colour scheme: the only way into dark mode, and it flips every layer at once. --}}
        <button class="v2-icon-btn" type="button" id="v2-theme-toggle"
                data-v2-tooltip="{{ translate('dark_mode') }}" aria-label="{{ translate('dark_mode') }}">
            <svg class="v2-theme-icon v2-theme-moon" width="19" height="19" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>
            </svg>
            <svg class="v2-theme-icon v2-theme-sun" width="19" height="19" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>
            </svg>
        </button>

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

        {{-- Profile --}}
        <div class="v2-dropdown">
            <button class="v2-avatar-wrap" type="button" data-v2-dropdown-toggle
                    data-v2-tooltip="{{ auth('admin')->user()->name }}" aria-label="{{ auth('admin')->user()->name }}">
                <span class="v2-avatar">
                    <img src="{{ getStorageImages(path: auth('admin')->user()->image_full_url, type: 'backend-profile') }}"
                         alt="{{ translate('image_description') }}">
                    <span class="v2-avatar-status" aria-hidden="true"></span>
                </span>
                <span class="v2-profile-inline" aria-hidden="true">
                    <span class="v2-profile-inline-name">{{ auth('admin')->user()->name }}</span>
                    <span class="v2-profile-inline-role">{{ ucwords(auth('admin')->user()?->role?->name ?? '') }}</span>
                </span>
                <span class="v2-avatar-caret" aria-hidden="true">
                    <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 5 5 5-5"/></svg>
                </span>
            </button>
            <div class="v2-dropdown-menu">
                <div class="v2-dropdown-header">
                    <img src="{{ getStorageImages(path: auth('admin')->user()->image_full_url, type: 'backend-profile') }}"
                         alt="{{ translate('image_description') }}">
                    <div>
                        <div class="v2-profile-name">{{ auth('admin')->user()->name }}</div>
                        <div class="v2-profile-role">{{ ucwords(auth('admin')->user()?->role?->name ?? '') }}</div>
                    </div>
                </div>
                <a class="v2-dropdown-item"
                   href="{{ route('admin.profile.update', ['id' => auth('admin')->user()->id]) }}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>
                    </svg>
                    <span>{{ translate('settings') }}</span>
                </a>
                <div class="v2-dropdown-divider"></div>
                <a class="v2-dropdown-item v2-dropdown-item-danger" href="javascript:"
                   data-bs-toggle="modal" data-bs-target="#sign-out-modal">
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
