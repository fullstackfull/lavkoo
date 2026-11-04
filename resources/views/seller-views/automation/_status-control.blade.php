{{-- Pause, resume, or nothing at all.

     A rule the marketplace stopped gets no resume control, because the server refuses one: a button
     that always fails is worse than its absence (handoff 08 A1). The seller is pointed at support
     instead. --}}
@php($statusUrl = \App\Services\SellerCenter\Shell::route('seller.automation.status', $rule['id']))

@if ($rule['stopped_by_marketplace'])
    @if ($helpUrl = \App\Services\SellerCenter\Shell::route('seller.help'))
        <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ $helpUrl }}">{{ translate('contact_support') }}</a>
    @endif
@elseif ($statusUrl)
    <form method="POST" action="{{ $statusUrl }}" style="display:inline">
        @csrf
        @method('PUT')
        <input type="hidden" name="status" value="{{ $rule['status'] === 'active' ? 'paused' : 'active' }}">
        <button type="submit" class="sc-btn sc-btn--ghost sc-btn--sm">
            {{ $rule['status'] === 'active' ? translate('pause') : ($rule['may_resume'] ? translate('resume') : translate('activate')) }}
        </button>
    </form>
@endif
