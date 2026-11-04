{{-- 52px, never scrolls, never wraps; every flexible child carries min-width:0 (handoff 02 §1). --}}
@php
    use App\Services\SellerCenter\Shell;
    $scSeller = auth('seller')->user();
    $scShopName = $scShop->name ?? trim(($scSeller->f_name ?? '') . ' ' . ($scSeller->l_name ?? ''));
    $scInitials = mb_strtoupper(mb_substr($scShopName ?: 'SC', 0, 2));
@endphp
<header class="sc-topbar">
    <button type="button" class="sc-icon-btn sc-icon-btn--mobile sc-hide-desktop" data-sc-nav-open
            aria-label="{{ translate('menu') }}" style="display:none">
        <x-sc.icon name="list" :size="18" />
    </button>

    <button type="button" class="sc-store" data-sc-menu-toggle="sc-store-menu" aria-haspopup="true">
        <span class="sc-store__mark">{{ $scInitials }}</span>
        <span class="sc-store__lines">
            <span class="sc-store__name sc-truncate" style="display:block">{{ $scShopName }}</span>
            <span class="sc-store__meta sc-truncate" style="display:block">
                {{ translate('seller_id') }} <span class="sc-code">{{ $scSeller->id ?? '' }}</span>{{ $scShop->contact ?? null ? ' · ' . ($scShop->city ?? '') : '' }}
            </span>
        </span>
        <x-sc.icon name="caret-up-down" :size="13" />
    </button>

    <button type="button" class="sc-search-trigger" data-sc-palette-open aria-label="{{ translate('search') }}">
        <x-sc.icon name="magnifying-glass" :size="14" />
        <span class="sc-search-trigger__label">{{ translate('search_orders_products_shipments_finance') }}</span>
        <kbd class="sc-kbd">⌘K</kbd>
    </button>

    {{-- Density and direction persist per user; below `sm` they move into the user menu. --}}
    <div class="sc-seg" role="group" aria-label="{{ translate('table_density') }}">
        <a class="sc-seg__opt{{ Shell::density() === 'compact' ? ' is-active' : '' }}"
           href="{{ route('seller.preferences.density', ['value' => 'compact', 'back' => request()->fullUrl()]) }}">{{ translate('compact') }}</a>
        <a class="sc-seg__opt{{ Shell::density() === 'comfortable' ? ' is-active' : '' }}"
           href="{{ route('seller.preferences.density', ['value' => 'comfortable', 'back' => request()->fullUrl()]) }}">{{ translate('comfortable') }}</a>
    </div>
    <div class="sc-seg" role="group" aria-label="{{ translate('language') }}">
        <a class="sc-seg__opt{{ !Shell::isRtl() ? ' is-active' : '' }}"
           href="{{ route('seller.preferences.direction', ['value' => 'ltr', 'back' => request()->fullUrl()]) }}">EN</a>
        <a class="sc-seg__opt{{ Shell::isRtl() ? ' is-active' : '' }}"
           href="{{ route('seller.preferences.direction', ['value' => 'rtl', 'back' => request()->fullUrl()]) }}">ع</a>
    </div>

    <button type="button" class="sc-icon-btn sc-icon-btn--topbar" data-sc-notifications-toggle
            aria-label="{{ translate('notifications') }}">
        <x-sc.icon name="bell" :size="16" />
        @if (($scNotificationUnread ?? 0) > 0)
            <span class="sc-icon-btn__dot{{ ($scNotificationSeverity ?? null) === 'critical' ? ' sc-icon-btn__dot--critical' : '' }}"></span>
        @endif
    </button>

    <a class="sc-icon-btn sc-icon-btn--topbar" href="{{ route('seller.help') }}" aria-label="{{ translate('help') }}">
        <x-sc.icon name="question" :size="16" />
    </a>

    <div style="position:relative">
        <button type="button" class="sc-icon-btn sc-icon-btn--topbar" data-sc-menu-toggle="sc-user-menu"
                aria-label="{{ translate('account') }}" aria-haspopup="true"
                style="border-radius:50%;background:var(--color-accent-800);color:var(--color-accent-100);font-size:11px">
            {{ mb_strtoupper(mb_substr($scSeller->f_name ?? 'S', 0, 1)) }}
        </button>
        <div class="sc-menu" id="sc-user-menu" hidden style="inset-inline-end:0;top:34px;min-width:220px">
            <div class="sc-menu__group">{{ trim(($scSeller->f_name ?? '') . ' ' . ($scSeller->l_name ?? '')) }} · {{ $scRoleLabel ?? translate('owner') }}</div>
            <a class="sc-menu__item" href="{{ url('vendor/profile/index') }}"><x-sc.icon name="user" :size="14" />{{ translate('my_profile') }}</a>
            @if ($scSettingsUrl = \App\Services\SellerCenter\Shell::route('seller.settings.index'))
                <a class="sc-menu__item" href="{{ $scSettingsUrl }}"><x-sc.icon name="translate" :size="14" />{{ translate('language_and_numerals') }}</a>
                <a class="sc-menu__item" href="{{ $scSettingsUrl }}?section=notifications"><x-sc.icon name="bell" :size="14" />{{ translate('notification_preferences') }}</a>
            @endif
            <button type="button" class="sc-menu__item" data-sc-shortcuts><x-sc.icon name="keyboard" :size="14" />{{ translate('keyboard_shortcuts') }}</button>
            <div class="sc-menu__sep"></div>
            <a class="sc-menu__item sc-menu__item--danger" href="{{ route('vendor.auth.logout') }}"><x-sc.icon name="sign-out" :size="14" />{{ translate('sign_out') }}</a>
        </div>
    </div>

    <div class="sc-menu" id="sc-store-menu" hidden style="inset-inline-start:14px;top:48px;min-width:240px">
        <a class="sc-menu__item" href="{{ url('vendor/shop/index') }}"><x-sc.icon name="storefront" :size="14" />{{ translate('store_profile') }}</a>
        @if ($scStoreSettingsUrl = \App\Services\SellerCenter\Shell::route('seller.settings.index'))
            <a class="sc-menu__item" href="{{ $scStoreSettingsUrl }}?section=store"><x-sc.icon name="gear-six" :size="14" />{{ translate('store_settings') }}</a>
        @endif
        <a class="sc-menu__item" href="{{ url('vendor/staff-auth/login') }}"><x-sc.icon name="users-three" :size="14" />{{ translate('switch_staff_account') }}</a>
    </div>
</header>
