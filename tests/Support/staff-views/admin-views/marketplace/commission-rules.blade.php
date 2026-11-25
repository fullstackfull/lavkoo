{{-- Test-only stand-in: the commission rules the screen lists, and which rule the preview resolved. --}}
@foreach ($rules as $rule)[rule:{{ $rule->id }} scope:{{ $rule->scope_type }}:{{ $rule->scope_id }}]@endforeach
@if ($preview)[preview-rule:{{ $preview['result']['rule_id'] ?? 'none' }}]@endif
