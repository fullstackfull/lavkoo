{{-- Every timeline shows automatic steps explicitly; automatic events use the accent (handoff 04 §30). --}}
@props(['tone' => 'neutral', 'time' => null, 'meta' => null])
<div class="sc-tl">
    @if ($time !== null)<div class="sc-tl__time">{{ $time }}</div>@endif
    <div class="sc-tl__rail">
        <span class="sc-tl__dot sc-tl__dot--{{ $tone }}"></span>
        <span class="sc-tl__line"></span>
    </div>
    <div class="sc-tl__body">
        <div class="sc-tl__text">{{ $slot }}</div>
        @if ($meta)<div class="sc-tl__meta">{{ $meta }}</div>@endif
    </div>
</div>
