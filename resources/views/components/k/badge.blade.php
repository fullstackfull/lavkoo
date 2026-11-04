@props([
    'tone' => 'neutral',   // neutral | success | warning | danger | info | accent
    'dot' => true,
])
{{-- Status badges carry a dot as well as a colour so the state survives
     greyscale and colour-blind vision. Pass :dot="false" for a plain label. --}}
<span {{ $attributes->merge([
    'class' => 'k-badge' . ($tone !== 'neutral' ? ' k-badge--' . $tone : '') . ($dot ? '' : ' k-badge--plain'),
]) }}>{{ $slot }}</span>
