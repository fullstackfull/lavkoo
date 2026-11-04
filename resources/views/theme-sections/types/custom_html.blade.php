{{-- Rendered as MARKUP, exactly as the app draws it through its HTML widget: escaping it showed
     shoppers the literal tags a merchant typed. The author is an admin behind theme_edit — the
     same trust every business-settings HTML field already extends — and coercion has stripped
     executable URL schemes before anything is stored. --}}
<div class="ml-reveal ml-custom-html">{!! $s['content'] ?? '' !!}</div>
