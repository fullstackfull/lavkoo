@extends('layouts.admin.app')

@section('title', translate('Settings'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('Settings')"
                         :subtitle="$total . ' ' . translate('settings_pages_across') . ' ' . count($groups) . ' ' . translate('areas')" />

        {{-- Search is the point of this page: with this many destinations, scanning
             groups is slower than typing. Filters titles, descriptions and hidden
             keywords, so "colors" finds Theme Settings and "vat" finds Payments. --}}
        <x-k.card style="margin-block-end:var(--k-size-5)">
            <div class="k-search">
                <x-k.icon name="search" :size="16" />
                <input type="search" id="k-settings-search" class="k-input" autocomplete="off"
                       placeholder="{{ translate('search_settings') }}"
                       aria-label="{{ translate('search_settings') }}"
                       aria-controls="k-settings-groups">
            </div>
            <p class="k-field__hint" style="margin-block-start:var(--k-size-2)" id="k-settings-count" aria-live="polite"></p>
        </x-k.card>

        <div class="k-stack" id="k-settings-groups">
            @foreach ($groups as $group)
                <section class="k-card" data-k-settings-group>
                    <div class="k-card__head">
                        <h2 class="k-card__title k-row" style="gap:var(--k-size-2)">
                            <x-k.icon :name="$group['icon']" :size="17" />
                            {{ $group['title'] }}
                        </h2>
                        <span class="k-tab__count k-num" data-k-group-count>{{ count($group['items']) }}</span>
                    </div>
                    <div class="k-card__body">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:var(--k-size-2)">
                            @foreach ($group['items'] as $item)
                                <a class="k-setting-link" href="{{ $item['url'] }}"
                                   data-k-settings-item data-keywords="{{ $item['keywords'] }}">
                                    <span class="k-setting-link__title">{{ $item['title'] }}</span>
                                    <span class="k-setting-link__desc">{{ $item['description'] }}</span>
                                    <x-k.icon name="chevron-end" :size="15" :directional="true" class="k-setting-link__go" />
                                </a>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach
        </div>

        <div id="k-settings-empty" hidden>
            <x-k.empty icon="search" :title="translate('nothing_matches_that')"
                       :text="translate('try_a_different_word_or_browse_the_groups_above')" />
        </div>
    </div>
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var input = document.getElementById('k-settings-search');
            if (!input) return;

            var items = Array.prototype.slice.call(document.querySelectorAll('[data-k-settings-item]'));
            var groups = Array.prototype.slice.call(document.querySelectorAll('[data-k-settings-group]'));
            var empty = document.getElementById('k-settings-empty');
            var counter = document.getElementById('k-settings-count');
            var labelMatches = @json(translate('matching_settings'));

            function apply() {
                var query = input.value.trim().toLowerCase();
                var shown = 0;

                items.forEach(function (item) {
                    var hit = !query || item.getAttribute('data-keywords').indexOf(query) !== -1;
                    item.hidden = !hit;
                    if (hit) shown++;
                });

                // A group with no surviving items is noise, and its count must reflect
                // what is actually on screen rather than what it holds in total.
                groups.forEach(function (group) {
                    var visible = group.querySelectorAll('[data-k-settings-item]:not([hidden])').length;
                    group.hidden = visible === 0;
                    var badge = group.querySelector('[data-k-group-count]');
                    if (badge) badge.textContent = visible;
                });

                empty.hidden = shown !== 0;
                counter.textContent = query ? shown + ' ' + labelMatches : '';
            }

            input.addEventListener('input', apply);
            // "/" focuses search, the convention for a page whose primary act is finding.
            document.addEventListener('keydown', function (event) {
                if (event.key !== '/' || event.target.matches('input, textarea, select')) return;
                event.preventDefault();
                input.focus();
            });
        })();
    </script>
@endpush
