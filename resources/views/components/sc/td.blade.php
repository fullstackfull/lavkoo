@props(['num' => false, 'drop' => null, 'sub' => null, 'action' => false, 'select' => false, 'tone' => null])
<td {{ $attributes->merge([
        'class' => ($num ? 'sc-cell--num' : '') . ($action ? ' sc-cell--action' : '') . ($select ? ' sc-cell--select' : '') . ($drop ? ' sc-col--' . $drop : ''),
    ]) }}
    @if ($action || $select) data-sc-stop @endif
    @if ($tone) style="color:var(--st-{{ $tone }})" @endif>
    {{ $slot }}
    @if ($sub)<div class="sc-subline">{{ $sub }}</div>@endif
</td>
