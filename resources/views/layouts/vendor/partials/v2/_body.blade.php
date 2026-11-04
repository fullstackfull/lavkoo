{{-- v1 body — wraps the rail + header + ctxpanel + @yield('content') in the grid shell.
     All shell markup uses v2- prefixed classes so no styling leaks into @yield('content'). --}}
@if (config('app.mode') == 'demo')
    <div class="__announcement-bar" style="background-image: url({{ dynamicAsset(path: 'public/assets/website-top-header.png') }})">
        <div class="container">
            <div class="wrapper">
                <div class="txt">
                    This is a demo website - Buy genuine 6Valley using our official link !
                </div>
                <a href="https://codecanyon.net/item/6valley-multivendor-ecommerce-complete-ecommerce-mobile-app-web-and-admin-panel/31448597?s_rank=19" class="click" target="_blank">Click Now <img src="{{ asset('public/assets/arrowww.png') }}" alt=""></a>
                <a href="https://codecanyon.net/item/6valley-multivendor-ecommerce-complete-ecommerce-mobile-app-web-and-admin-panel/31448597?s_rank=19" class="px-3 py-1 rounded" style="background-color: #FF7500; color:#ffffff" target="_blank">Buy Now</a>
            </div>
        </div>
    </div>
@endif

{{-- v2 sidebar/header chrome uses its own built-in colour palette (the
     defaults defined inside .app-v2 in vendor-v2.css). The system
     primary / secondary picker in business settings is intentionally
     NOT propagated here — v2 stays on its design colours. --}}

<div class="app-v2" id="appV2">
    @include('layouts.vendor.partials.v2._header')
    @include('layouts.vendor.partials.v2._rail')
    @include('layouts.vendor.partials.v2._side-bar')

    {{-- Pre-paint sidebar hydration. Runs synchronously while the parser is
         still walking the document, so the rail's v2-is-active and the
         correct ctx-section's v2-is-on are set BEFORE the browser paints
         the sidebar. Without this the home section is hard-shown until
         vendor-v2.js's DOMContentLoaded boot runs hydrateActive() — that
         gap is the visible "Dashboard flicker" on every navigation.
         Reads the rendered hrefs (same source-of-truth vendor-v2.js uses
         for pickActiveSectionFromURL), so URL changes flow through with
         no separate pattern map to maintain. --}}
    <script>
    (function () {
        var path = window.location.pathname;
        var sections = document.querySelectorAll('#v2-ctxpanel .v2-ctx-section');
        if (!sections.length) return;
        var match = null;
        for (var i = 0; i < sections.length; i++) {
            var sec = sections[i];
            var anchors = sec.querySelectorAll('a[href]');
            for (var j = 0; j < anchors.length; j++) {
                var href = anchors[j].getAttribute('href') || '';
                href = href.replace(/^https?:\/\/[^/]+/, '').split('?')[0].split('#')[0];
                if (!href || href === '/') continue;
                if (path === href || path.indexOf(href.replace(/\/$/, '') + '/') === 0) {
                    match = sec.getAttribute('data-section');
                    break;
                }
            }
            if (match) break;
        }
        if (!match) return;
        for (var k = 0; k < sections.length; k++) {
            sections[k].classList.toggle('v2-is-on', sections[k].getAttribute('data-section') === match);
        }
        var rails = document.querySelectorAll('.v2-rail .v2-rail-btn[data-section]');
        for (var m = 0; m < rails.length; m++) {
            rails[m].classList.toggle('v2-is-active', rails[m].getAttribute('data-section') === match);
        }
    })();
    </script>

    <main class="v2-main" id="content" role="main">
        @yield('content')
        @include('layouts.vendor.partials.image-modal')
    </main>
</div>

{{-- Stub wrappers that HSDemo() in back-end/js/custom.js expects to find and remove.
     Without these, custom.js throws on page load and the .change-language click
     handler (and everything after line 352 in that file) never binds. --}}
<div id="headerMain" class="d-none"></div>
<div id="headerFluid" class="d-none"></div>
<div id="headerDouble" class="d-none"></div>
<div id="sidebarMain" class="d-none"></div>

{{-- Re-use project modals. v1 uses its own setup popover (below) instead of the v0 modal. --}}
@include('layouts.vendor.partials._modals')
@include('layouts.vendor.partials._toggle-modal')
@include('layouts.vendor.partials._sign-out-modal')
@include('layouts.vendor.partials._alert-message')
@include('layouts.vendor.partials.v2._setup-popover')

{{-- Server-side pin shortcuts: load the current seller's saved pin
     list so the v2 JS can render the pin section on first paint. --}}
@php
    $v2InitialPins = [];
    try {
        $__v2Seller = auth('seller')->user();
        if ($__v2Seller) {
            $v2InitialPins = \App\Models\V2SidebarPin::pinsFor(\App\Models\V2SidebarPin::TYPE_SELLER, (int)$__v2Seller->id);
        }
    } catch (\Throwable $e) { /* table not migrated yet — fall through with empty list */ }
@endphp
<script>
    window.__v2InitialPins = {!! json_encode($v2InitialPins) !!};
    window.__v2PinsToggleUrl  = "{{ route('vendor.v2.sidebar-pins.toggle') }}";
    window.__v2PinsReplaceUrl = "{{ route('vendor.v2.sidebar-pins.replace') }}";
    window.__v2PinsCsrf       = "{{ csrf_token() }}";
</script>
