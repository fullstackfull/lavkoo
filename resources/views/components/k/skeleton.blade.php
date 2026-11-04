@props(['variant' => 'text', 'width' => null, 'height' => null, 'count' => 1])
@for ($i = 0; $i < (int) $count; $i++)
    <span {{ $attributes->merge(['class' => 'k-skeleton k-skeleton--' . $variant]) }}
          @if ($width || $height) style="@if($width)inline-size:{{ $width }};@endif @if($height)block-size:{{ $height }};@endif" @endif
          aria-hidden="true"></span>
@endfor
