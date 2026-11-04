{{-- The Control Tower's unit: severity, count, deadline, one action, one drill-down
     (handoff 07.2). Body is always a sentence with the number and the cause. --}}
@props(['severity' => 'medium', 'title', 'code' => null, 'affected' => null, 'due' => null, 'dueTone' => null, 'detected' => null, 'impact' => null, 'isNew' => false])
@php($resolved = \App\Services\SellerCenter\Status::severity($severity))
<article {{ $attributes->merge(['class' => 'sc-issue sc-issue--' . $resolved['key'] . ($isNew ? ' sc-issue--new' : '')]) }}>
    <span class="sc-issue__glyph"><x-sc.icon :name="$resolved['glyph']" :size="16" /></span>
    <div class="sc-issue__main">
        <div class="sc-row" style="gap:8px">
            <span class="sc-issue__title">{{ $title }}</span>
            <x-sc.badge :severity="$severity" />
            @if ($code)<span class="sc-muted sc-code" style="font-size:11px">{{ $code }}</span>@endif
            @isset($flag){{ $flag }}@endisset
        </div>
        <p class="sc-issue__body">{{ $slot }}</p>
        <div class="sc-issue__meta">
            @if ($affected !== null)
                <span class="sc-issue__meta-item"><x-sc.icon name="stack" :size="11" />{{ $affected }}</span>
            @endif
            @if ($due !== null)
                <span class="sc-issue__meta-item" @if ($dueTone) style="color:var(--st-{{ $dueTone }})" @endif>
                    <x-sc.icon name="clock" :size="11" />{{ $due }}
                </span>
            @endif
            @if ($detected !== null)
                <span class="sc-issue__meta-item"><x-sc.icon name="eye" :size="11" />{{ $detected }}</span>
            @endif
            @if ($impact !== null)
                <span class="sc-issue__meta-item"><x-sc.icon name="target" :size="11" />{{ translate('impact') }} {{ $impact }}</span>
            @endif
        </div>
    </div>
    @isset($actions)<div class="sc-issue__actions">{{ $actions }}</div>@endisset
</article>
