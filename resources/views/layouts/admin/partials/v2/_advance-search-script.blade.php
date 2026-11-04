{{--
    Admin V2 advance-search wiring. Self-contained: only loaded when v2 is
    active. Drives the SAME #advanceSearchModal that classic uses (markup
    in layouts.admin.partials._modals, included inside .v2-main), opened
    from the v2 header search button or ⌘K. The modal is rendered without
    a dimming backdrop per design, and a small CSS override prevents the
    page-level keyword-highlight <mark> wraps from breaking words apart.
--}}
@push('css_or_js')
    <style>
        /* keyword-highlight.js wraps each match in <mark>; Bootstrap +
           sb-admin-2 give <mark> padding which visibly breaks words apart
           ("Lan" + " guage" vs "Language"). Force inline rendering. */
        mark, .mark {
            padding: 0 !important;
            margin: 0 !important;
            background-color: #fff3a4 !important;
            color: inherit !important;
            border-radius: 2px;
        }
        /* No dim backdrop behind the v2 advance-search modal — popover
           feel, not dialog feel. Keep the modal visually elevated via its
           own shadow but leave the page underneath fully readable. */
        body.v2-active .modal-backdrop.show,
        body.v2-active .modal-backdrop { opacity: 0 !important; background: transparent !important; }
        body.v2-active #advanceSearchModal { background: transparent !important; }
        body.v2-active #advanceSearchModal .modal-content {
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.18), 0 2px 6px rgba(15, 23, 42, 0.08);
        }
    </style>
@endpush

@push('script')
    <script>
        (function () {
            'use strict';

            let v2Search_currentIndex = -1;
            // Tracks the keyword whose result HTML is currently in #searchResults.
            // Used by v2Search_runQuery to dedupe back-to-back calls for the same
            // keyword (e.g. shown.bs.modal + the delegated focus handler firing
            // together on modal open) and to skip refetching on a re-open when
            // the input hasn't changed. Reset on hidden.bs.modal so a reopened
            // modal that lost its DOM contents will fetch again.
            let v2Search_lastLoadedKw = null;

            function v2Search_getItems() {
                return $('.search-list .search-item-wrapper');
            }

            function v2Search_updateHighlight(index) {
                const items = v2Search_getItems();
                items.removeClass('active-item');
                if (index >= 0 && index < items.length) {
                    const currentItem = $(items[index]);
                    currentItem.addClass('active-item');
                    const link = currentItem.find('.search-list-item')[0];
                    if (link) {
                        const container = document.querySelector('#searchResults');
                        if (!container) return;
                        const itemRect = link.getBoundingClientRect();
                        const containerRect = container.getBoundingClientRect();
                        if (itemRect.top < containerRect.top || itemRect.bottom > containerRect.bottom) {
                            link.scrollIntoView({ behavior: 'auto', block: 'nearest' });
                        }
                    }
                }
            }

            function v2Search_toggleLoader(type) {
                const loader = $('#searchLoaderOverlay');
                if (type === 'show') loader.removeClass('d-none');
                else if (type === 'hide') loader.addClass('d-none');
            }

            $(document).ready(function () {
                // Open modal from the v2 header search button.
                $(document).on('click', '#v2-header-search', function (e) {
                    e.preventDefault();
                    $('#advanceSearchModal').modal('show');
                });

                // Esc closes the modal. ⌘K is NOT bound here: it belongs to the
                // command palette in admin-v2.js. Both used to answer the same
                // keystroke, so the palette opened and the modal immediately stole
                // its focus — the palette looked broken and could not be typed into.
                // The modal keeps its own entry point, the header search button above.
                $(document).on('keydown', function (event) {
                    if (event.key === 'Escape' && $('#advanceSearchModal').hasClass('show')) {
                        $('#advanceSearchModal').modal('hide');
                    }
                });

                let currentRequest = null;
                let debounceTimer = null;

                function v2Search_runQuery(searchKeyword, opts) {
                    const o = opts || {};
                    const kw = (searchKeyword || '').trim();
                    // Skip if results for this exact keyword are already rendered.
                    // shown.bs.modal calls this directly AND input.focus() inside
                    // that handler triggers the delegated focus listener which
                    // also calls this — without the guard each modal open fires
                    // two identical requests, and every reopen refetches data
                    // that hasn't changed.
                    if (v2Search_lastLoadedKw === kw && $('#searchResults').children().length > 0) return;
                    if (currentRequest && currentRequest.readyState !== 4) currentRequest.abort();
                    v2Search_toggleLoader('show');
                    $.ajaxSetup({
                        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
                    });
                    currentRequest = $.ajax({
                        type: 'GET',
                        url: '{{ route('admin.v2.advanced-search') }}',
                        data: kw ? { keyword: kw } : {},
                        success: function (response) {
                            $('#searchResults').empty().html(response.htmlView);
                            v2Search_lastLoadedKw = kw;
                            if (o.highlight) {
                                if (v2Search_currentIndex === -1) v2Search_currentIndex = 0;
                                v2Search_updateHighlight(v2Search_currentIndex);
                            }
                            v2Search_toggleLoader('hide');
                        },
                        error: function (xhr, status) {
                            if (status !== 'abort') console.error('v2 advance search error', xhr.responseText || status);
                            v2Search_toggleLoader('hide');
                        }
                    });
                }

                $(document).on('input', '#advance-search-input-global', function () {
                    const searchKeyword = $(this).val().trim();
                    clearTimeout(debounceTimer);
                    if (searchKeyword === '') {
                        v2Search_runQuery('', { highlight: false });
                        return;
                    }
                    debounceTimer = setTimeout(function () {
                        v2Search_runQuery(searchKeyword, { highlight: true });
                    }, 300);
                });

                $(document).on('focus', '#advance-search-input-global', function () {
                    const searchKeyword = $(this).val().trim();
                    if (searchKeyword.length > 0) {
                        v2Search_runQuery(searchKeyword, { highlight: true });
                    } else {
                        v2Search_runQuery('', { highlight: false });
                    }
                });

                $(document).on('keydown', function (e) {
                    if (!$('#advanceSearchModal').hasClass('show')) return;
                    const items = v2Search_getItems();
                    if (!items.length) return;
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        if (v2Search_currentIndex < items.length - 1) {
                            v2Search_currentIndex++;
                            v2Search_updateHighlight(v2Search_currentIndex);
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (v2Search_currentIndex > 0) {
                            v2Search_currentIndex--;
                            v2Search_updateHighlight(v2Search_currentIndex);
                        }
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        if (v2Search_currentIndex >= 0 && v2Search_currentIndex < items.length) {
                            const target = $(items[v2Search_currentIndex]).find('.search-list-item')[0];
                            if (target) target.click();
                        }
                    }
                });

                $('#advanceSearchModal').on('shown.bs.modal', function () {
                    v2Search_currentIndex = -1;
                    const input = $('#advance-search-input-global');
                    input.focus();
                    // Always run the query on open. Bootstrap pre-focuses the
                    // modal, so input.focus() above is often a no-op and the
                    // delegated focus handler never fires — calling runQuery
                    // here directly is what makes recent-searches show on
                    // first open (empty kw → server returns the RecentSearch
                    // list) without depending on focus event propagation.
                    const kw = input.val().trim();
                    v2Search_runQuery(kw, { highlight: kw.length > 0 });
                });

                $('#advanceSearchModal').on('hidden.bs.modal', function () {
                    v2Search_currentIndex = -1;
                });

                $(document).on('input focus', '#advance-search-input-global', function () {
                    v2Search_currentIndex = -1;
                });

                // Preload recent searches once on page boot so when the user
                // clicks the header search button (or hits ⌘K) the modal opens
                // with content already painted — no loader flash, no fetch
                // delay. The cache guard inside v2Search_runQuery makes the
                // shown.bs.modal handler short-circuit when this preload has
                // already populated #searchResults for the current keyword.
                v2Search_runQuery('', { highlight: false });
            });
        })();
    </script>
@endpush
