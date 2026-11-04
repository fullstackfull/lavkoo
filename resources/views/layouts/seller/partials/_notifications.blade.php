{{-- Identical events collapse into one row; an incident supersedes its child alerts; a resolved
     issue's notification is replaced in place (handoff 02 §6 anti-spam rules). --}}
<div class="sc-notifications" data-sc-notifications hidden style="inset-inline-end:14px;top:56px">
    <header class="sc-notifications__head">
        <strong style="font-size:13px">{{ translate('notifications') }}</strong>
        <div class="sc-spacer"></div>
        @if ($scReadAllUrl = \App\Services\SellerCenter\Shell::route('seller.notifications.read-all'))
            <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ $scReadAllUrl }}">{{ translate('mark_all_read') }}</a>
        @endif
        @if ($scPrefsUrl = \App\Services\SellerCenter\Shell::route('seller.settings.index'))
            <a class="sc-icon-btn" href="{{ $scPrefsUrl }}?section=notifications" aria-label="{{ translate('notification_preferences') }}">
                <x-sc.icon name="gear" :size="14" />
            </a>
        @endif
    </header>
    <div class="sc-notifications__list">
        @forelse ($scNotifications ?? [] as $notification)
            <a class="sc-notification{{ empty($notification['read']) ? ' is-unread' : '' }}" href="{{ $notification['href'] ?? '#' }}">
                <x-sc.dot :tone="$notification['tone'] ?? 'info'" :hollow="!empty($notification['read'])" />
                <div style="min-width:0;flex:1 1 auto">
                    <div class="sc-notification__title">{{ $notification['title'] }}</div>
                    <div class="sc-notification__meta">{{ $notification['meta'] ?? '' }}</div>
                </div>
            </a>
        @empty
            <x-sc.empty glyph="check-circle" tone="good" :title="translate('you_are_up_to_date')"
                        :text="translate('notifications_about_orders_stock_payouts_and_compliance_appear_here')" />
        @endforelse
    </div>
    <footer class="sc-notifications__head" style="border-top:1px solid var(--sc-line-soft);border-bottom:0">
        @if ($scIssuesUrl = \App\Services\SellerCenter\Shell::route('seller.issues.index'))
            <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ $scIssuesUrl }}">{{ translate('open_issue_center') }}</a>
        @endif
    </footer>
</div>
