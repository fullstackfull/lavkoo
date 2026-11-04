/* =====================================================================
   v2 PJAX — soft-navigate ONLY while native browser fullscreen is
   active, so the fullscreen state survives clicks. Outside fullscreen
   every link is a normal Laravel nav (no interception, no risk).

   Why scope to fullscreen?
   The Fullscreen API drops on every page navigation (security: it
   needs a user gesture and cannot be auto-invoked on page load).
   Per-page scripts in this codebase (@push('script') blocks at body
   end, _script-partials, etc.) include lots of top-level let/const
   declarations that throw "already declared" when re-executed in a
   long-lived document — that's why the previous always-on PJAX broke
   non-sidebar interactivity. Limiting PJAX to fullscreen contains the
   blast radius to a session the user explicitly opted into.

   Behavior:
   - Fullscreen entered  → start intercepting <a href> clicks anywhere
   - Click while in FS   → fetch + swap <main id="content"> in place
   - Fullscreen exited   → no-op; whatever PJAX-swapped state the user
                           sees stays put until they navigate themselves
   - Per-link opt-out    → data-pjax-skip="true"
   - Forms              → not intercepted (POST/delete still full-nav)
   ===================================================================== */
(function () {
  'use strict';

  if (!window.history || !window.history.pushState || !window.fetch) return;
  if (!document.querySelector('.app-v2')) return;

  const SWAP_SEL = '#content';
  let inflight = null;
  let isFsActive = false;

  /* ---------- loading bar (one-time inject) ----------
     Two-layer indicator so the user can clearly tell a soft-nav is
     in flight even on a wide fullscreen viewport:
       ::before — faint full-width track tinted with the active primary
       ::after  — bright 40% segment that glides L→R with a glow halo

     Color is a fixed brand blue (#1F4FD6). Intentionally not tied to
     --panel-sidebar-primary — the loader is its own visual element
     and shouldn't shift with admin theme changes.

     Smoothness:
       - Both layers fade in from opacity 0 over 220ms when loading
         starts, so the bar arrives gently instead of popping in.
       - The slide uses ease-in-out (cubic-bezier(0.45,0,0.55,1)) so
         each pass accelerates into the viewport, glides through the
         middle, and decelerates off the right. The loop snap happens
         off-screen so it reads as a continuous breathing motion.

     z-index 100000 sits above the demo announcement bar (which is
     position:fixed; top:0; z-index:99999 — see public/css/demo.css).
     In demo mode we also offset the loader BELOW the announcement bar
     so the two don't visually collide at the top edge. The banner is
     50px tall on desktop and grows to 96px at ≤486px, matching the
     same breakpoint demo.css uses for its padding-top compensation. */
  if (!document.getElementById('v2-pjax-style')) {
    const s = document.createElement('style');
    s.id = 'v2-pjax-style';
    s.textContent =
      'body.v2-pjax-loading::before,body.v2-pjax-loading::after{' +
        'content:"";position:fixed;top:0;left:0;right:0;height:3px;' +
        'z-index:100000;pointer-events:none;' +
        'animation:v2pjax-fadein 220ms ease-out both}' +
      'body.v2-pjax-loading::before{' +
        'background:#1F4FD6;opacity:0.18}' +
      'body.v2-pjax-loading::after{' +
        'right:auto;width:40%;' +
        'background:linear-gradient(90deg,' +
          'transparent 0%,' +
          '#1F4FD6 50%,' +
          'transparent 100%);' +
        'box-shadow:' +
          '0 0 10px #1F4FD6,' +
          '0 0 18px #1F4FD6;' +
        'animation:' +
          'v2pjax-fadein 220ms ease-out both,' +
          'v2pjax-slide 1.4s cubic-bezier(0.45,0,0.55,1) 220ms infinite}' +
      'body.demo.v2-pjax-loading::before,body.demo.v2-pjax-loading::after{top:50px}' +
      '@media (max-width:486px){' +
        'body.demo.v2-pjax-loading::before,body.demo.v2-pjax-loading::after{top:96px}' +
      '}' +
      '@keyframes v2pjax-fadein{from{opacity:0}to{opacity:1}}' +
      '@keyframes v2pjax-slide{' +
        '0%{transform:translateX(-100%)}' +
        '100%{transform:translateX(250%)}' +
      '}';
    document.head.appendChild(s);
  }

  /* ---------- fullscreen tracking ---------- */
  function readFsState() {
    return !!(document.fullscreenElement || document.webkitFullscreenElement);
  }

  isFsActive = readFsState();

  function onFsChange() {
    isFsActive = readFsState();
  }
  document.addEventListener('fullscreenchange', onFsChange);
  document.addEventListener('webkitfullscreenchange', onFsChange);

  /* ---------- link eligibility ---------- */
  function isPjaxLink(link) {
    if (!link || !link.href) return false;
    if (link.target && link.target !== '_self') return false;
    if (link.hasAttribute('download')) return false;
    if (link.dataset.pjaxSkip === 'true') return false;
    if (link.dataset.bsToggle) return false;
    const raw = link.getAttribute('href');
    if (!raw || raw.startsWith('#')) return false;
    if (/^(javascript:|mailto:|tel:)/i.test(raw)) return false;
    let url;
    try { url = new URL(link.href, location.href); }
    catch (_) { return false; }
    if (url.origin !== location.origin) return false;
    if (url.pathname === location.pathname && url.search === location.search) return false;
    return true;
  }

  /* ---------- core load ---------- */
  function load(url, opts) {
    opts = opts || {};
    if (inflight) inflight.abort();
    const controller = new AbortController();
    inflight = controller;

    document.body.classList.add('v2-pjax-loading');

    fetch(url, {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-PJAX': 'true',
        'Accept': 'text/html, */*; q=0.5',
      },
      credentials: 'same-origin',
      redirect: 'follow',
      signal: controller.signal,
    })
      .then(function (res) {
        if (!res.ok) throw new Error('http ' + res.status);
        const ct = res.headers.get('content-type') || '';
        if (ct && ct.indexOf('text/html') === -1) throw new Error('non-html response');
        return res.text().then(function (html) {
          return { html: html, finalUrl: res.url || url };
        });
      })
      .then(function (payload) {
        applyPayload(payload.html, payload.finalUrl, opts);
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        console.warn('v2-pjax: falling back to full nav —', err);
        location.href = url;
      })
      .finally(function () {
        document.body.classList.remove('v2-pjax-loading');
        if (inflight === controller) inflight = null;
      });
  }

  function applyPayload(html, finalUrl, opts) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const newMain = doc.querySelector(SWAP_SEL);
    const oldMain = document.querySelector(SWAP_SEL);
    if (!newMain || !oldMain) throw new Error('missing swap target');

    if (doc.title) document.title = doc.title;

    /* New stylesheets */
    const existingHrefs = new Set(
      Array.prototype.slice.call(
        document.head.querySelectorAll('link[rel="stylesheet"][href]')
      ).map(function (l) { return l.href; })
    );
    doc.head.querySelectorAll('link[rel="stylesheet"][href]').forEach(function (l) {
      if (!existingHrefs.has(l.href)) document.head.appendChild(l.cloneNode(true));
    });

    /* Swap main content */
    oldMain.innerHTML = newMain.innerHTML;
    runScripts(oldMain);
    runTailScripts(doc);

    if (opts.push) history.pushState({ pjax: true }, '', finalUrl);

    rehydrateSidebar();

    document.dispatchEvent(new CustomEvent('v2:page-loaded', {
      detail: { url: finalUrl, rehydrated: true },
    }));
    document.dispatchEvent(new CustomEvent('v2:ready', {
      detail: { rehydrated: true },
    }));
    /* Re-fire fullscreenchange so the admin-v2.js fullscreen icon
       updater re-runs and stays in sync after the swap. */
    document.dispatchEvent(new Event('fullscreenchange'));

    window.scrollTo(0, 0);
  }

  /* ---------- script handling ---------- */
  function isExecutableScript(s) {
    const t = s.getAttribute('type');
    if (!t) return true;
    return /^(?:application|text)\/(?:javascript|ecmascript)$|^module$/i.test(t);
  }

  function cloneScript(old) {
    const fresh = document.createElement('script');
    Array.prototype.slice.call(old.attributes).forEach(function (a) {
      fresh.setAttribute(a.name, a.value);
    });
    /* Dynamically-inserted scripts default to async=true per the HTML
       spec, which means two external <script src="..."> tags re-inserted
       in DOM order can finish loading and execute in any order. The
       original parser-inserted scripts on the full page load executed
       in DOM order, so any pair where one defines a global the next
       consumes (e.g. quill-editor.js → quill-editor-init.js doing
       Quill.import) breaks after a pjax swap with "X is not defined".
       Setting async=false on the cloned <script> preserves DOM-insertion
       order for execution while still allowing parallel download. */
    if (old.src) fresh.async = false;
    if (!old.src) {
      /* Wrap inline content in an IIFE + try/catch.
         The original page-load already executed these scripts at the
         document's top level, so any `let`/`const` declarations are
         bound in the global lexical environment. Re-running them
         verbatim on a PJAX swap throws "Identifier 'X' has already
         been declared" (e.g. `let placeholderImageUrl = ...` in
         _script-partials) and aborts the rest of the swap.
         The IIFE re-scopes those declarations to the function body so
         there's no collision; explicit `window.X = ...` assignments
         inside still update globals as before. The inner try/catch
         keeps any other runtime error from killing later tail
         scripts in the same swap. */
      fresh.textContent =
        '(function(){try{\n' + old.textContent + '\n}catch(e){' +
          'console.warn("v2-pjax: inline script error",e)' +
        '}})();';
    }
    return fresh;
  }

  function runScripts(container) {
    Array.prototype.slice.call(container.querySelectorAll('script')).forEach(function (old) {
      if (!isExecutableScript(old)) return;
      try {
        const fresh = cloneScript(old);
        old.parentNode.replaceChild(fresh, old);
      } catch (e) {
        console.warn('v2-pjax: content script failed', e);
      }
    });
  }

  function runTailScripts(doc) {
    const newMain = doc.querySelector(SWAP_SEL);
    const existingSrcs = new Set(
      Array.prototype.slice.call(document.querySelectorAll('script[src]')).map(function (s) { return s.src; })
    );
    Array.prototype.slice.call(doc.body.querySelectorAll('script')).forEach(function (s) {
      if (newMain && newMain.contains(s)) return;
      if (!isExecutableScript(s)) return;
      if (s.src) {
        let abs;
        try { abs = new URL(s.getAttribute('src'), location.href).href; }
        catch (_) { abs = s.src; }
        if (existingSrcs.has(abs)) return;
      }
      try {
        document.body.appendChild(cloneScript(s));
      } catch (e) {
        console.warn('v2-pjax: tail script failed', e);
      }
    });
  }

  /* ---------- sidebar/rail active-state hydration ---------- */
  function normalizeHref(raw) {
    if (!raw) return '';
    return raw.replace(/^https?:\/\/[^/]+/, '').split('#')[0];
  }

  function rehydrateSidebar() {
    const path = location.pathname;
    const currentParams = new URLSearchParams(location.search);
    const matchesPath = function (href) {
      if (!href || href === '/') return false;
      const qIdx = href.indexOf('?');
      const hPath = qIdx === -1 ? href : href.slice(0, qIdx);
      const hQuery = qIdx === -1 ? '' : href.slice(qIdx + 1);
      const trimmed = hPath.replace(/\/$/, '');
      const pathHit = path === hPath || path === trimmed || path.indexOf(trimmed + '/') === 0;
      if (!pathHit) return false;
      /* When the link declares query params (e.g. ?request_status=0),
         require those params to match the current URL — otherwise three
         sibling links that share a path but differ only by query (vendor
         products: new/approved/denied) would all light up at once. */
      if (!hQuery) return true;
      const linkParams = new URLSearchParams(hQuery);
      const iter = typeof linkParams.entries === 'function' ? linkParams.entries() : null;
      if (iter) {
        let next = iter.next();
        while (!next.done) {
          const k = next.value[0];
          const v = next.value[1];
          if (currentParams.get(k) !== v) return false;
          next = iter.next();
        }
      }
      return true;
    };

    const sections = document.querySelectorAll('#v2-ctxpanel .v2-ctx-section');
    let match = null;
    Array.prototype.some.call(sections, function (sec) {
      return Array.prototype.some.call(sec.querySelectorAll('a[href]'), function (a) {
        if (matchesPath(normalizeHref(a.getAttribute('href') || ''))) {
          match = sec.getAttribute('data-section');
          return true;
        }
        return false;
      });
    });
    if (match) {
      sections.forEach(function (s) {
        s.classList.toggle('v2-is-on', s.getAttribute('data-section') === match);
      });
      document.querySelectorAll('.v2-rail .v2-rail-btn[data-section]').forEach(function (r) {
        r.classList.toggle('v2-is-active', r.getAttribute('data-section') === match);
      });
    }

    // Anchor-style nav items (a.v2-nav-item) — leaf top-level links.
    document.querySelectorAll('#v2-ctxpanel a.v2-nav-item[href]').forEach(function (a) {
      a.classList.toggle('v2-is-active', matchesPath(normalizeHref(a.getAttribute('href') || '')));
    });

    // Submenu children (.v2-nav-child) — leaf links inside dropdowns.
    // Without refreshing these, autoExpandActive opens the wrong dropdown
    // and the breadcrumb's buildMenuBreadcrumb (which keys off
    // .v2-nav-child.v2-is-on) keeps showing the previous page's trail.
    document.querySelectorAll('#v2-ctxpanel a.v2-nav-child[href]').forEach(function (c) {
      c.classList.toggle('v2-is-on', matchesPath(normalizeHref(c.getAttribute('href') || '')));
    });

    // Dropdown PARENTS (div.v2-nav-item.v2-has-children) — active when
    // any of their children's hrefs matches the new path. The Blade-baked
    // v2-is-active was computed for the previous URL via Request::is and
    // is now stale; recomputing here keeps autoExpandActive and the
    // breadcrumb's buildMenuBreadcrumb in sync with the soft-nav target.
    document.querySelectorAll('#v2-ctxpanel .v2-nav-item.v2-has-children').forEach(function (item) {
      const id = item.getAttribute('data-item');
      if (!id) {
        item.classList.toggle('v2-is-active', false);
        return;
      }
      const kids = document.querySelector(
        '.v2-nav-children[data-children-for="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]:not([data-v2-pinned])'
      );
      let active = false;
      if (kids) {
        const childAnchors = kids.querySelectorAll('a.v2-nav-child[href]');
        for (let i = 0; i < childAnchors.length; i++) {
          if (matchesPath(normalizeHref(childAnchors[i].getAttribute('href') || ''))) {
            active = true;
            break;
          }
        }
      }
      item.classList.toggle('v2-is-active', active);
    });
  }

  /* ---------- wiring ---------- */
  history.replaceState({ pjax: true }, '', location.href);

  document.addEventListener('click', function (e) {
    /* GATE: only intercept while the browser is actually in native
       fullscreen. Outside fullscreen, everything is normal Laravel
       navigation — no risk to non-sidebar interactivity. */
    if (!isFsActive) return;

    if (e.defaultPrevented) return;
    if (e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    /* Skip clicks on pin buttons / nav-right buttons inside sidebar
       links — they trigger their own handlers, not navigation. */
    if (e.target.closest('.v2-pin-btn, .v2-nav-right button')) return;

    const link = e.target.closest('a[href]');
    if (!link) return;

    /* Same-URL click on the already-active sidebar item: the browser's
       default is a full reload, which drops native fullscreen. Re-route
       it through PJAX (refresh-in-place) so the page content reloads
       but fullscreen survives. Gate on same-origin + plain navigation
       link so we don't interfere with mailto:, javascript:, downloads,
       target=_blank, modal toggles, etc. */
    if (link.target === '' || link.target === '_self') {
      const raw = link.getAttribute('href') || '';
      if (raw && !raw.startsWith('#') && !/^(javascript:|mailto:|tel:)/i.test(raw)
        && !link.hasAttribute('download')
        && link.dataset.pjaxSkip !== 'true'
        && !link.dataset.bsToggle) {
        let sameUrl = false;
        try {
          const u = new URL(link.href, location.href);
          sameUrl = u.origin === location.origin
            && u.pathname === location.pathname
            && u.search === location.search;
        } catch (_) { /* fall through */ }
        if (sameUrl) {
          e.preventDefault();
          load(link.href, { push: false });
          return;
        }
      }
    }

    if (!isPjaxLink(link)) return;

    e.preventDefault();
    load(link.href, { push: true });
  }, false);

  window.addEventListener('popstate', function (e) {
    if (!isFsActive) return;
    if (!e.state || !e.state.pjax) return;
    load(location.href, { push: false });
  });
})();
