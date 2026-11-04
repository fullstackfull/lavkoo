@props(['steps' => [], 'current' => 0])
<div {{ $attributes->merge(['class' => 'sc-stepper']) }}>
    @foreach ($steps as $index => $step)
        @if ($index > 0)<span class="sc-step__connector"></span>@endif
        @php($state = ($step['state'] ?? null) ?? ($index < $current ? 'done' : ($index === $current ? 'current' : 'upcoming')))
        <div class="sc-step is-{{ $state }}">
            <span class="sc-step__circle">
                @if ($state === 'done')<x-sc.icon name="check" :size="11" />@else{{ $index + 1 }}@endif
            </span>
            <span class="sc-step__label">{{ $step['label'] }}</span>
        </div>
    @endforeach
</div>
