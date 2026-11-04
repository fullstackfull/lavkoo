/* ============================================================================
   SELLER CENTER — shell behaviour.

   No framework and no build step: the panel is server-rendered Blade, and every
   behaviour here is a progressive enhancement over markup that already works.
   A seller with a failed script still has links, forms and a readable table.

   Reference: design_handoff_seller_center/02 §5–8, 04, 05 A3–A6, 11
   ============================================================================ */
(function () {
    'use strict';

    var config = window.scConfig || {};
    var root = document.body;

    /* ── small helpers ──────────────────────────────────────────────────── */
    function qs(selector, scope) { return (scope || document).querySelector(selector); }
    function qsa(selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); }
    function show(el) { if (el) { el.hidden = false; } }
    function hide(el) { if (el) { el.hidden = true; } }
    function isVisible(el) { return el && !el.hidden; }

    /* Focus is returned to whatever opened the overlay, every time (handoff 02 §8). */
    var lastInvoker = null;
    function remember() { lastInvoker = document.activeElement; }
    function restoreFocus() { if (lastInvoker && lastInvoker.focus) { lastInvoker.focus(); } lastInvoker = null; }

    /* ── overlays: palette, notifications, drawers, modals, menus ───────── */
    function closeAllMenus() {
        qsa('.sc-menu:not([data-sc-filter-panel])').forEach(hide);
        qsa('[data-sc-notifications]').forEach(hide);
    }

    function openPalette() {
        var palette = qs('[data-sc-palette]');
        if (!palette) { return; }
        remember();
        show(qs('[data-sc-palette-scrim]'));
        show(palette);
        var input = qs('[data-sc-palette-input]');
        if (input) { input.value = ''; input.focus(); }
    }

    function closePalette() {
        hide(qs('[data-sc-palette]'));
        hide(qs('[data-sc-palette-scrim]'));
        restoreFocus();
    }

    function openDrawer(id) {
        var drawer = document.getElementById(id);
        if (!drawer) { return; }
        remember();
        show(qs('[data-sc-drawer-scrim="' + id + '"]'));
        show(drawer);
        var heading = qs('.sc-drawer__title', drawer);
        if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus(); }
    }

    function closeDrawer(drawer) {
        if (!drawer) { return; }
        hide(drawer);
        hide(qs('[data-sc-drawer-scrim="' + drawer.id + '"]'));
        restoreFocus();
    }

    function openModal(id) {
        var modal = document.getElementById(id);
        if (!modal) { return; }
        remember();
        show(qs('[data-sc-modal-scrim="' + id + '"]'));
        show(modal);
        /* Initial focus goes to the least destructive action (handoff 04 §21). */
        var safe = qs('.sc-modal__actions .sc-btn:not(.sc-btn--danger)', modal) || qs('.sc-btn', modal);
        if (safe) { safe.focus(); }
    }

    function closeModal(modal) {
        if (!modal) { return; }
        hide(modal);
        hide(qs('[data-sc-modal-scrim="' + modal.id + '"]'));
        restoreFocus();
    }

    function closeTopOverlay() {
        var palette = qs('[data-sc-palette]');
        if (isVisible(palette)) { closePalette(); return true; }

        var modal = qsa('.sc-modal').filter(isVisible)[0];
        if (modal) { closeModal(modal); return true; }

        var drawer = qsa('.sc-drawer').filter(isVisible)[0];
        if (drawer) { closeDrawer(drawer); return true; }

        var navDrawer = qs('[data-sc-nav-drawer]');
        if (isVisible(navDrawer)) { hide(navDrawer); hide(qs('[data-sc-nav-scrim]')); return true; }

        if (qsa('.sc-menu').some(isVisible) || qsa('[data-sc-notifications]').some(isVisible)) {
            closeAllMenus();
            return true;
        }

        return false;
    }

    document.addEventListener('click', function (event) {
        var target = event.target;

        var paletteOpen = target.closest('[data-sc-palette-open]');
        if (paletteOpen) { event.preventDefault(); openPalette(); return; }

        if (target.closest('[data-sc-palette-scrim]')) { closePalette(); return; }

        var drawerOpen = target.closest('[data-sc-drawer-open]');
        if (drawerOpen) { event.preventDefault(); openDrawer(drawerOpen.getAttribute('data-sc-drawer-open')); return; }

        if (target.closest('[data-sc-drawer-close]')) { closeDrawer(target.closest('.sc-drawer')); return; }

        var drawerScrim = target.closest('[data-sc-drawer-scrim]');
        if (drawerScrim) { closeDrawer(document.getElementById(drawerScrim.getAttribute('data-sc-drawer-scrim'))); return; }

        var modalOpen = target.closest('[data-sc-modal-open]');
        if (modalOpen) { event.preventDefault(); openModal(modalOpen.getAttribute('data-sc-modal-open')); return; }

        if (target.closest('[data-sc-modal-close]')) { closeModal(target.closest('.sc-modal')); return; }

        var modalScrim = target.closest('[data-sc-modal-scrim]');
        if (modalScrim) { closeModal(document.getElementById(modalScrim.getAttribute('data-sc-modal-scrim'))); return; }

        var menuToggle = target.closest('[data-sc-menu-toggle]');
        if (menuToggle) {
            event.preventDefault();
            var menu = document.getElementById(menuToggle.getAttribute('data-sc-menu-toggle'));
            var wasOpen = isVisible(menu);
            closeAllMenus();
            if (!wasOpen) { show(menu); }
            return;
        }

        var bellToggle = target.closest('[data-sc-notifications-toggle]');
        if (bellToggle) {
            event.preventDefault();
            var panel = qs('[data-sc-notifications]');
            var open = isVisible(panel);
            closeAllMenus();
            if (!open) { show(panel); }
            return;
        }

        if (target.closest('[data-sc-nav-open]')) {
            event.preventDefault();
            show(qs('[data-sc-nav-drawer]'));
            show(qs('[data-sc-nav-scrim]'));
            return;
        }
        if (target.closest('[data-sc-nav-scrim]')) {
            hide(qs('[data-sc-nav-drawer]'));
            hide(qs('[data-sc-nav-scrim]'));
            return;
        }

        /* Filter panel: open on click, one editor at a time. */
        var filterToggle = target.closest('[data-sc-filter-toggle]');
        if (filterToggle) {
            event.preventDefault();
            var panelEl = qs('[data-sc-filter-panel]', filterToggle.closest('[data-sc-filter-root]'));
            if (isVisible(panelEl)) { hide(panelEl); } else { closeAllMenus(); show(panelEl); }
            return;
        }
        var filterOpen = target.closest('[data-sc-filter-open]');
        if (filterOpen) {
            event.preventDefault();
            var field = filterOpen.closest('[data-sc-filter-field]');
            var editor = qs('[data-sc-filter-editor]', field);
            qsa('[data-sc-filter-editor]', field.parentElement).forEach(function (other) {
                if (other !== editor) { other.style.display = 'none'; }
            });
            editor.style.display = editor.style.display === 'none' ? '' : 'none';
            return;
        }

        if (!target.closest('.sc-menu') && !target.closest('[data-sc-menu-toggle]')
            && !target.closest('[data-sc-notifications]') && !target.closest('[data-sc-notifications-toggle]')
            && !target.closest('[data-sc-filter-root]')) {
            closeAllMenus();
            qsa('[data-sc-filter-panel]').forEach(hide);
        }
    });

    /* ── keyboard map (handoff 02 §5) ───────────────────────────────────── */
    document.addEventListener('keydown', function (event) {
        var typing = /^(input|textarea|select)$/i.test(event.target.tagName) || event.target.isContentEditable;

        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            if (isVisible(qs('[data-sc-palette]'))) { closePalette(); } else { openPalette(); }
            return;
        }

        if (event.key === '/' && !typing) { event.preventDefault(); openPalette(); return; }

        if (event.key === 'Escape') {
            if (closeTopOverlay()) { event.preventDefault(); }
            return;
        }

        /* ⌘1…⌘9 jump to a rail group. */
        if ((event.metaKey || event.ctrlKey) && /^[1-9]$/.test(event.key)) {
            var rail = qsa('.sc-rail__item')[Number(event.key) - 1];
            if (rail) { event.preventDefault(); window.location.href = rail.href; }
            return;
        }

        /* Palette navigation. */
        if (isVisible(qs('[data-sc-palette]'))) {
            var rows = qsa('[data-sc-palette-row]').filter(function (row) { return row.offsetParent !== null; });
            if (!rows.length) { return; }
            var current = rows.findIndex(function (row) { return row.classList.contains('is-active'); });

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                var next = event.key === 'ArrowDown'
                    ? (current + 1) % rows.length
                    : (current <= 0 ? rows.length - 1 : current - 1);
                rows.forEach(function (row) { row.classList.remove('is-active'); });
                rows[next].classList.add('is-active');
                rows[next].scrollIntoView({ block: 'nearest' });
            } else if (event.key === 'Enter' && current >= 0) {
                event.preventDefault();
                if (event.metaKey || event.ctrlKey) { window.open(rows[current].href, '_blank'); }
                else { window.location.href = rows[current].href; }
            }
        }
    });

    /* ── palette search (handoff 02 §5 states) ──────────────────────────── */
    var searchTimer = null;
    var searchToken = 0;
    document.addEventListener('input', function (event) {
        if (!event.target.matches('[data-sc-palette-input]')) { return; }
        var query = event.target.value.trim();
        var results = qs('[data-sc-palette-results]');
        if (!results || !config.searchUrl) { return; }

        window.clearTimeout(searchTimer);
        if (query.length < 2) { renderInitial(results); return; }

        searchTimer = window.setTimeout(function () {
            var token = ++searchToken;
            renderLoading(results);
            fetch(config.searchUrl + '?q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                .then(function (payload) { if (token === searchToken) { renderResults(results, payload, query); } })
                .catch(function () {
                    if (token !== searchToken) { return; }
                    /* The palette stays open and offers a retry; it never blocks the input. */
                    results.innerHTML = '<div class="sc-palette__row"><span>' + escapeHtml(config.strings.searchUnavailable) + '</span></div>';
                });
        }, 200);
    });

    function renderInitial(results) {
        var initial = results.getAttribute('data-sc-initial');
        if (initial !== null) { results.innerHTML = initial; }
    }

    function renderLoading(results) {
        results.innerHTML = '<div class="sc-palette__row"><div class="sc-skeleton" style="height:14px;width:60%"></div></div>'
            + '<div class="sc-palette__row"><div class="sc-skeleton" style="height:14px;width:45%"></div></div>'
            + '<div class="sc-palette__row"><div class="sc-skeleton" style="height:14px;width:52%"></div></div>';
    }

    function renderResults(results, payload, query) {
        var groups = payload && payload.groups ? payload.groups : [];
        if (!groups.length) {
            results.innerHTML = '<div style="padding:14px">'
                + '<div style="font-size:13px">' + escapeHtml(config.strings.noMatch) + ' "' + escapeHtml(query) + '"</div>'
                + '<div class="sc-muted" style="font-size:12px;margin-top:4px">' + escapeHtml(config.strings.searchHint) + '</div>'
                + '</div>';
            return;
        }

        var html = '';
        groups.forEach(function (group) {
            html += '<div class="sc-group-label">' + escapeHtml(group.label) + '</div>';
            (group.rows || []).forEach(function (row) {
                html += '<a class="sc-palette__row" data-sc-palette-row href="' + escapeAttr(row.href) + '">'
                    + '<span>' + escapeHtml(row.label) + '</span>'
                    + (row.meta ? '<span class="sc-palette__row-meta">' + escapeHtml(row.meta) + '</span>' : '')
                    + '</a>';
            });
            if (group.moreHref) {
                html += '<a class="sc-palette__row" data-sc-palette-row href="' + escapeAttr(group.moreHref) + '">'
                    + '<span class="sc-muted">' + escapeHtml(config.strings.seeAll + ' ' + group.total + ' · ' + group.label) + '</span></a>';
            }
        });
        results.innerHTML = html;
        var first = qs('[data-sc-palette-row]', results);
        if (first) { first.classList.add('is-active'); }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character];
        });
    }
    function escapeAttr(value) { return escapeHtml(value); }

    /* ── table: row click, selection, bulk bar (handoff 05 A3) ──────────── */
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-sc-stop]') || event.target.closest('a, button, label, input')) { return; }
        var row = event.target.closest('[data-sc-row-href]');
        if (row) { window.location.href = row.getAttribute('data-sc-row-href'); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') { return; }
        var row = event.target.closest && event.target.closest('[data-sc-row-href]');
        if (row && event.target === row) { window.location.href = row.getAttribute('data-sc-row-href'); }
    });

    var lastCheckedRow = null;

    document.addEventListener('change', function (event) {
        var target = event.target;

        if (target.matches('[data-sc-select-all]')) {
            var table = target.closest('table');
            qsa('[data-sc-row-select]', table).forEach(function (box) {
                box.checked = target.checked;
                markRow(box);
            });
            syncBulkBar(table);
            return;
        }

        if (target.matches('[data-sc-row-select]')) {
            var tableEl = target.closest('table');

            /* Shift-click extends a range within the current page. */
            if (event.shiftKey && lastCheckedRow) {
                var boxes = qsa('[data-sc-row-select]', tableEl);
                var from = boxes.indexOf(lastCheckedRow);
                var to = boxes.indexOf(target);
                if (from > -1 && to > -1) {
                    boxes.slice(Math.min(from, to), Math.max(from, to) + 1).forEach(function (box) {
                        box.checked = target.checked;
                        markRow(box);
                    });
                }
            }

            lastCheckedRow = target;
            markRow(target);
            syncBulkBar(tableEl);
        }
    });

    function markRow(box) {
        var row = box.closest('tr');
        if (row) { row.classList.toggle('is-selected', box.checked); }
    }

    function syncBulkBar(table) {
        if (!table) { return; }
        var boxes = qsa('[data-sc-row-select]', table);
        var selected = boxes.filter(function (box) { return box.checked; });
        var bar = qs('[data-sc-bulk-bar]');
        var all = qs('[data-sc-select-all]', table);

        if (all) {
            all.checked = selected.length > 0 && selected.length === boxes.length;
            all.indeterminate = selected.length > 0 && selected.length < boxes.length;
        }

        if (!bar) { return; }
        var counter = qs('[data-sc-bulk-count]', bar);
        if (counter) { counter.textContent = String(selected.length); }
        bar.hidden = selected.length === 0;

        /* Every bulk form learns the selection, so an action applies to what is ticked and never
           to the filter behind it (handoff 04 §39). */
        qsa('[data-sc-bulk-ids]', bar).forEach(function (input) {
            input.value = selected.map(function (box) { return box.value; }).join(',');
        });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-sc-bulk-clear]')) { return; }
        qsa('[data-sc-row-select]').forEach(function (box) { box.checked = false; markRow(box); });
        syncBulkBar(qs('.sc-table'));
    });

    /* ── tooltips for truncated cells and icon-only buttons (handoff 04 §23) ── */
    var tooltipEl = null;
    var tooltipTimer = null;

    document.addEventListener('mouseover', function (event) {
        var host = event.target.closest('[data-sc-tip]');
        if (!host) { return; }
        window.clearTimeout(tooltipTimer);
        tooltipTimer = window.setTimeout(function () {
            if (!tooltipEl) {
                tooltipEl = document.createElement('div');
                tooltipEl.className = 'sc-tooltip';
                document.body.appendChild(tooltipEl);
            }
            tooltipEl.textContent = host.getAttribute('data-sc-tip');
            var box = host.getBoundingClientRect();
            tooltipEl.style.top = (box.bottom + 8) + 'px';
            tooltipEl.style.insetInlineStart = box.left + 'px';
            tooltipEl.hidden = false;
        }, 400);
    });

    document.addEventListener('mouseout', function (event) {
        if (!event.target.closest('[data-sc-tip]')) { return; }
        window.clearTimeout(tooltipTimer);
        if (tooltipEl) { tooltipEl.hidden = true; }
    });

    /* ── SLA countdowns tick without re-ordering the table (handoff 06 §5) ── */
    function tickCountdowns() {
        qsa('[data-sc-countdown]').forEach(function (node) {
            var due = Number(node.getAttribute('data-sc-countdown'));
            if (!due) { return; }
            var minutes = Math.round((due * 1000 - Date.now()) / 60000);
            node.textContent = node.getAttribute(minutes < 0 ? 'data-sc-breached' : 'data-sc-left')
                .replace('{time}', formatDuration(Math.abs(minutes)));
        });
    }

    function formatDuration(minutes) {
        var hours = Math.floor(minutes / 60);
        var rest = minutes % 60;
        return hours > 0 ? hours + 'h ' + rest + 'm' : rest + 'm';
    }

    if (qs('[data-sc-countdown]')) {
        tickCountdowns();
        window.setInterval(tickCountdowns, 30000);
        window.addEventListener('focus', tickCountdowns);
    }

    /* ── toasts auto-dismiss; errors persist (handoff 04 §24) ───────────── */
    qsa('.sc-toast:not(.sc-toast--error)').forEach(function (toast) {
        window.setTimeout(function () { toast.remove(); }, 5000);
    });

    /* ── file upload dragover state (handoff 04 §33) ────────────────────── */
    qsa('[data-sc-upload]').forEach(function (zone) {
        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (event) { event.preventDefault(); zone.classList.add('is-dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function () { zone.classList.remove('is-dragover'); });
        });
        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            var input = qs('input[type="file"]', zone);
            if (input && event.dataTransfer.files.length) { input.files = event.dataTransfer.files; }
        });
    });

    /* ── confirmation contract: a dangerous action never fires on one click ── */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var confirmMessage = form.getAttribute('data-sc-confirm');
        if (confirmMessage && !window.confirm(confirmMessage)) { event.preventDefault(); }
    });

    /* The mobile hamburger only exists below `sm`; showing it is a CSS concern the shell
       cannot express without a container query, so it is set once here. */
    function syncMobileChrome() {
        var hamburger = qs('[data-sc-nav-open].sc-hide-desktop');
        if (hamburger) { hamburger.style.display = window.innerWidth < 1024 ? '' : 'none'; }
    }
    syncMobileChrome();
    window.addEventListener('resize', syncMobileChrome);

    /* Selection is cleared when the filter set changes, and the seller is told why
       (handoff 05 A3). The note is rendered server-side; this only removes stale ticks. */
    if (root.hasAttribute('data-sc-filters-changed')) {
        qsa('[data-sc-row-select]').forEach(function (box) { box.checked = false; });
    }
}());
