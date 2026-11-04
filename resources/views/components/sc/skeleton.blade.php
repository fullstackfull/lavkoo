@props(['height' => 14, 'width' => '100%', 'radius' => null])
<div {{ $attributes->merge(['class' => 'sc-skeleton']) }}
     style="height:{{ is_numeric($height) ? $height . 'px' : $height }};width:{{ is_numeric($width) ? $width . 'px' : $width }}{{ $radius ? ';border-radius:' . $radius : '' }}"></div>
