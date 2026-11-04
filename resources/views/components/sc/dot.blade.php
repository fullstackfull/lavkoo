@props(['tone' => 'neutral', 'hollow' => false, 'size' => 7])
<span {{ $attributes->merge(['class' => 'sc-dot sc-dot--' . $tone . ($hollow ? ' sc-dot--hollow' : '')]) }}
      style="width:{{ $size }}px;height:{{ $size }}px"></span>
