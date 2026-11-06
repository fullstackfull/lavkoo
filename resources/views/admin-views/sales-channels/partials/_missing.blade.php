{{-- A value that is not there: an em dash and a badge that says why, never a 0 or a made-up figure. Expects $label (the translated word: "not set" or "unknown"). --}}
<span class="text-muted">—</span> <x-k.badge :dot="false">{{ $label }}</x-k.badge>
