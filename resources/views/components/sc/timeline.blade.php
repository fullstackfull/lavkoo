@props(['withTime' => false])
<div {{ $attributes->merge(['class' => 'sc-timeline']) }}>{{ $slot }}</div>
