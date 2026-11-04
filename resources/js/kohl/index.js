/**
 * Kohl runtime.
 *
 * Deliberately dependency-free and tiny: it runs alongside 41 existing admin
 * scripts, so it may not assume jQuery, Bootstrap or any load order, and it may
 * not touch anything it did not render itself. Every behaviour is delegated from
 * the document, so markup swapped in by PJAX works with no re-initialisation.
 */

const THEME_KEY = 'k-theme';

/* ------------------------------------------------------------------ theme */

/**
 * Apply the stored colour-scheme choice.
 *
 * Three states, matching the token layer: 'dark' and 'light' stamp the root and
 * win over the OS; no stored value leaves the attribute off so the OS preference
 * decides. Stamping 'light' by default would override a user's dark OS setting.
 */
function applyStoredTheme() {
    let stored = null;
    try {
        stored = window.localStorage.getItem(THEME_KEY);
    } catch (error) {
        stored = null;                // private mode / storage disabled — fall back to light
    }

    const isConsole = document.body && document.body.classList.contains('k-console');
    const root = document.documentElement;

    // The console never follows the OS silently (see the pre-paint note in the admin layout):
    // anything that is not an explicit 'dark' resolves to light there. The storefront keeps the
    // three-state behaviour, where 'system' means "let the OS decide".
    const theme = isConsole
        ? (stored === 'dark' ? 'dark' : 'light')
        : (stored === 'dark' || stored === 'light' ? stored : null);

    if (theme) {
        root.setAttribute('data-k-theme', theme);
    } else {
        root.removeAttribute('data-k-theme');
    }

    if (!isConsole) {
        return;
    }

    // Dark has to be stamped on all three layers or the page comes out half-dark: Bootstrap 5.3
    // themes its own components off data-bs-theme, and the v2 shell (rail, header, context panel)
    // off its own class.
    root.setAttribute('data-bs-theme', theme);
    document.querySelectorAll('.app-v2').forEach((shell) => {
        shell.classList.toggle('v2-theme-dark', theme === 'dark');
    });
}

function setTheme(value) {
    try {
        if (value === 'system') {
            window.localStorage.removeItem(THEME_KEY);
        } else {
            window.localStorage.setItem(THEME_KEY, value);
        }
    } catch (error) {
        /* not fatal — the choice just will not persist */
    }
    applyStoredTheme();
}

function direction() {
    return document.documentElement.getAttribute('dir') === 'rtl' ? 'rtl' : 'ltr';
}

/* ----------------------------------------------------------------- toasts */

const TOAST_DEFAULT_MS = 5000;

/**
 * Show a toast.
 *
 * `action` gives destructive operations an undo affordance, which is what makes
 * an optimistic write safe: act immediately, offer the way back for a few
 * seconds. A toast carrying an action stays until it is dismissed.
 */
function toast({ title, text = null, tone = 'neutral', action = null, duration = TOAST_DEFAULT_MS } = {}) {
    const host = document.getElementById('k-toasts');
    if (!host || !title) return null;

    const node = document.createElement('div');
    node.className = 'k-toast' + (tone !== 'neutral' ? ` k-toast--${tone}` : '');

    const body = document.createElement('div');
    body.className = 'k-toast__body';

    const heading = document.createElement('div');
    heading.className = 'k-toast__title';
    heading.textContent = title;
    body.appendChild(heading);

    if (text) {
        const detail = document.createElement('div');
        detail.className = 'k-toast__text';
        detail.textContent = text;
        body.appendChild(detail);
    }

    if (action && typeof action.onClick === 'function') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'k-toast__action';
        button.textContent = action.label;
        button.addEventListener('click', () => {
            action.onClick();
            dismiss();
        });
        body.appendChild(button);
    }

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'k-btn k-btn--ghost k-btn--icon k-btn--sm';
    close.setAttribute('aria-label', 'Close');
    close.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
    close.addEventListener('click', () => dismiss());

    node.appendChild(body);
    node.appendChild(close);
    host.appendChild(node);

    let timer = null;
    function dismiss() {
        if (timer) clearTimeout(timer);
        node.remove();
    }
    // A toast offering an action must not expire before it can be acted on.
    if (!action && duration > 0) timer = setTimeout(dismiss, duration);

    return { dismiss };
}

/* ---------------------------------------------------------------- drawers */

let lastFocused = null;

function openDrawer(id) {
    const drawer = document.getElementById(id);
    if (!drawer) return;
    const backdrop = document.querySelector(`[data-k-drawer-backdrop="${id}"]`);

    lastFocused = document.activeElement;
    drawer.classList.add('is-open');
    if (backdrop) backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';

    // Move focus in, or a keyboard user stays stranded on the page behind.
    const target = drawer.querySelector('[autofocus], input, select, textarea, button');
    if (target) target.focus({ preventScroll: true });
}

function closeDrawer(id) {
    const drawer = id ? document.getElementById(id) : document.querySelector('.k-drawer.is-open');
    if (!drawer) return;
    const backdrop = document.querySelector(`[data-k-drawer-backdrop="${drawer.id}"]`);

    drawer.classList.remove('is-open');
    if (backdrop) backdrop.classList.remove('is-open');
    if (!document.querySelector('.k-drawer.is-open')) document.body.style.overflow = '';
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus({ preventScroll: true });
}

/* ------------------------------------------------- row selection & bulk bar */

/**
 * Keep a data-view's bulk bar in step with its checkboxes.
 *
 * The count is shown because a bulk action with an unstated count is how people
 * delete the wrong thing.
 */
function syncSelection(view) {
    const rows = view.querySelectorAll('[data-k-row-select]');
    const selected = [...rows].filter(box => box.checked);
    const bar = view.querySelector('[data-k-bulk]');
    const counter = view.querySelector('[data-k-bulk-count]');
    const master = view.querySelector('[data-k-select-all]');

    rows.forEach(box => {
        const row = box.closest('tr');
        if (row) row.setAttribute('aria-selected', box.checked ? 'true' : 'false');
    });

    if (counter) counter.textContent = String(selected.length);
    if (bar) bar.hidden = selected.length === 0;
    if (master) {
        master.checked = rows.length > 0 && selected.length === rows.length;
        master.indeterminate = selected.length > 0 && selected.length < rows.length;
    }
}

/** Ids currently ticked in a view — what a bulk action submits. */
function selectedIds(view) {
    return [...view.querySelectorAll('[data-k-row-select]:checked')].map(box => box.value);
}



/* ---------------------------------------------------------------- save bar */

/**
 * Snapshot a form's current values.
 *
 * Compared as a string rather than field by field: a settings form has dozens of
 * inputs, and the only question the bar asks is "does this differ from what was
 * loaded". File inputs are excluded — their value cannot be restored on discard,
 * so treating a chosen file as dirty would offer an undo that does not work.
 */
function snapshotForm(form) {
    const parts = [];
    form.querySelectorAll('input, select, textarea').forEach(field => {
        if (field.type === 'file' || field.disabled || !field.name) return;
        parts.push(field.name + '=' + (field.type === 'checkbox' || field.type === 'radio' ? field.checked : field.value));
    });
    return parts.join('&');
}

function initSaveBars() {
    document.querySelectorAll('form[data-k-save-bar]').forEach(form => {
        if (form.dataset.kSaveBarReady === '1') return;
        const bar = form.querySelector('[data-k-savebar]');
        if (!bar) return;

        form.dataset.kSaveBarReady = '1';
        let baseline = snapshotForm(form);

        const refresh = () => {
            const dirty = snapshotForm(form) !== baseline;
            bar.classList.toggle('is-dirty', dirty);
            form.dataset.kDirty = dirty ? '1' : '0';
        };

        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);

        bar.querySelector('[data-k-savebar-save]')?.addEventListener('click', () => {
            // Clear the dirty flag first: submitting is not abandoning, and the
            // unload guard must not challenge the navigation it just caused.
            form.dataset.kDirty = '0';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();       // runs validation and submit handlers
            } else {
                form.submit();
            }
        });

        bar.querySelector('[data-k-savebar-discard]')?.addEventListener('click', () => {
            form.reset();
            baseline = snapshotForm(form);
            refresh();
        });

        form.addEventListener('submit', () => { form.dataset.kDirty = '0'; });
    });
}

// Leaving a page with unsaved settings is the loss this bar exists to prevent, so
// it is also guarded at the browser level.
window.addEventListener('beforeunload', event => {
    if (!document.querySelector('form[data-k-dirty="1"]')) return;
    event.preventDefault();
    event.returnValue = '';
});

document.addEventListener('DOMContentLoaded', initSaveBars);
if (document.readyState !== 'loading') initSaveBars();

/* ------------------------------------------------------ delegated bindings */

document.addEventListener('click', event => {
    const opener = event.target.closest('[data-k-drawer-open]');
    if (opener) {
        event.preventDefault();
        openDrawer(opener.getAttribute('data-k-drawer-open'));
        return;
    }

    if (event.target.closest('[data-k-drawer-close]')) {
        event.preventDefault();
        closeDrawer();
        return;
    }

    const backdrop = event.target.closest('[data-k-drawer-backdrop]');
    if (backdrop) {
        closeDrawer(backdrop.getAttribute('data-k-drawer-backdrop'));
    }
});

document.addEventListener('change', event => {
    const master = event.target.closest('[data-k-select-all]');
    if (master) {
        const view = master.closest('[data-k-selectable]');
        if (!view) return;
        view.querySelectorAll('[data-k-row-select]').forEach(box => { box.checked = master.checked; });
        syncSelection(view);
        return;
    }

    const box = event.target.closest('[data-k-row-select]');
    if (box) {
        const view = box.closest('[data-k-selectable]');
        if (view) syncSelection(view);
    }
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.querySelector('.k-drawer.is-open')) {
        closeDrawer();
    }
});


applyStoredTheme();

window.Kohl = Object.assign(window.Kohl || {}, {
    version: '0.2.0',
    setTheme,
    direction,
    toast,
    openDrawer,
    closeDrawer,
    selectedIds,
    initSaveBars,
});
