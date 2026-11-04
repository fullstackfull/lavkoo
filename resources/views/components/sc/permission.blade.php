{{-- Never a redirect with no explanation: name the permission and how to get it (handoff 11 §7). --}}
@props(['module', 'role' => null])
<div {{ $attributes->merge(['class' => 'sc-permission']) }}>
    <span class="sc-empty__glyph"><x-sc.icon name="lock" :size="30" /></span>
    <h5 class="sc-empty__title">{{ translate('you_do_not_have_access_to') }} {{ $module }}</h5>
    <p class="sc-empty__text">{{ $slot->isEmpty() ? translate('this_page_requires_a_permission_your_role_does_not_have_ask_an_owner_or_manager_to_grant_it') : $slot }}</p>
    <div class="sc-empty__actions">
        <x-sc.button variant="secondary" :href="\App\Services\SellerCenter\Shell::route('seller.home') ?? url('vendor/dashboard')">{{ translate('back_to_seller_home') }}</x-sc.button>
    </div>
</div>
