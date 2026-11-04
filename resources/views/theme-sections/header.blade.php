{{-- Storefront renderer for the Theme Builder's HEADER sections.
     Renders above the storefront header when the published (or previewed) version has visible
     header sections; outputs nothing otherwise, leaving the built-in header untouched.
     Same contract as theme-sections.home: a broken section must never take the page down. --}}
@php
    $__headerSections = app(\App\Services\Theme\StorefrontThemeRenderer::class)->sectionsFor('header');
@endphp

@if (!empty($__headerSections))
    @foreach ($__headerSections as $__section)
        @php $s = $__section['settings'] ?? []; @endphp
        @continue(($s['visible'] ?? true) === false)

        @if (($__section['type'] ?? null) === 'announcement_bar' && trim((string) ($s['text'] ?? '')) !== '')
            <div class="tb-announcement text-center position-relative px-4 py-2"
                 data-tb-section="{{ $__section['id'] ?? '' }}"
                 data-tb-dismiss-key="tb-announcement-{{ $__section['id'] ?? 0 }}"
                 style="background: {{ $s['background'] ?: 'var(--web-primary, #673ab7)' }}; color: #fff;">
                @if (!empty($s['link']))
                    <a href="{{ $s['link'] }}" class="text-white text-decoration-underline">{{ $s['text'] }}</a>
                @else
                    <span>{{ $s['text'] }}</span>
                @endif
                @if ($s['dismissible'] ?? true)
                    <button type="button" class="tb-announcement__close border-0 bg-transparent position-absolute"
                            aria-label="{{ translate('close') }}"
                            style="inset-inline-end: 12px; top: 50%; transform: translateY(-50%); color: inherit; font-size: 18px; line-height: 1;">&times;</button>
                @endif
            </div>
        @endif
    @endforeach

    <script>
        "use strict";
        (function () {
            document.querySelectorAll('.tb-announcement').forEach(function (bar) {
                var key = bar.dataset.tbDismissKey;
                try { if (key && localStorage.getItem(key) === '1') { bar.remove(); return; } } catch (e) {}
                var close = bar.querySelector('.tb-announcement__close');
                if (close) close.addEventListener('click', function () {
                    try { if (key) localStorage.setItem(key, '1'); } catch (e) {}
                    bar.remove();
                });
            });
        })();
    </script>
@endif
