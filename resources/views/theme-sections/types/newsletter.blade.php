{{-- Panel is a block of its own, inline is a single line that sits between two
     sections without interrupting them, split puts the promise beside the field. --}}

@php $newsStyle = $s['style'] ?? 'panel'; @endphp
<div class="ml-news ml-news--{{ $newsStyle }} ml-reveal">
    @if (!empty($s['title']))<h3>{{ $s['title'] }}</h3>@endif
    @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
    <form class="ml-news-form" onsubmit="return false;">
        <input type="email" placeholder="{{ translate('your_email_address') }}" aria-label="{{ translate('email') }}">
        <button type="submit" class="ml-btn ml-btn-gold">{{ translate('subscribe') }}</button>
    </form>
</div>
