/*
 | First-party analytics beacon.
 |
 | The server already records every page load, so this file exists only for the things a page load
 | cannot see:
 |
 |  1. The product filter navigates with history.pushState and a jQuery request. Filtering,
 |     sorting and changing a price range are real page changes that return JSON, so the server
 |     counts them as an XHR and — correctly — not as a pageview. Without this they are simply
 |     lost, and on a catalogue site that is a large share of all navigation.
 |  2. Interactions that never navigate and write nothing on the server, should the theme ever add
 |     one. The delegated [data-analytics] handler below is that extension point; today nothing in
 |     the theme carries the attribute, and an event the server already records is refused here
 |     rather than counted twice.
 |
 | Deliberately small, dependency-free and defensive. It runs on every storefront page of a live
 | shop, so it must never throw into a customer's console, never block a click, and never delay a
 | page. Everything is wrapped, everything is queued, and the queue is flushed with sendBeacon so
 | the browser can post it during unload without holding the page open.
 |
 | It sends NOTHING about the person. No form values, no input contents, no scroll heatmap, no
 | fingerprint — an event name, an entity id and a normalised path.
 */
(function () {
    'use strict';

    var root = document.getElementById('analytics-beacon');
    if (!root) return;

    var endpoint = root.getAttribute('data-endpoint');
    if (!endpoint) return;

    var queue = [];
    var flushTimer = null;

    // The server allow-list, mirrored here so a typo never becomes a silent no-op that only shows
    // up as a missing report weeks later.
    //
    // Two entries, on purpose. Anything the server already records — a product view, a wishlist
    // add, a checkout start, the cart page, a compare add — is deliberately absent: sending it from
    // here as well would count it twice, and deduplication would not catch it because the two sides
    // disagree about the path. What is left is the filter's pushState navigation, which returns
    // JSON and is correctly not a pageview to the server, and a banner click: the server sees the
    // page the banner led to, and nothing that says a banner led there.
    var ALLOWED = ['product_list_viewed', 'banner_clicked', 'section_viewed'];

    function push(name, payload) {
        try {
            if (ALLOWED.indexOf(name) === -1) return;

            var event = {name: name};
            if (payload) {
                if (payload.entityType) event.entity_type = String(payload.entityType).slice(0, 24);
                if (payload.entityId) event.entity_id = String(payload.entityId).slice(0, 64);
                if (payload.properties) event.properties = payload.properties;
                if (payload.dedupeKey) event.dedupe_key = String(payload.dedupeKey).slice(0, 64);
            }
            event.path = location.pathname + location.search;

            queue.push(event);

            // A cap, so a runaway loop on a page cannot turn into a flood of requests.
            if (queue.length >= 20) {
                flush();
                return;
            }

            // A banner click is a navigation: the batch timer would still be waiting when the page
            // goes away. pagehide catches most of it, but not a click that opens a new tab, so this
            // one leaves immediately.
            if (name === 'banner_clicked') {
                flush();
                return;
            }

            // Batched: several events usually happen together (a filter changes the list, the
            // list is a pageview), and one request is cheaper for the customer than three.
            if (flushTimer) clearTimeout(flushTimer);
            flushTimer = setTimeout(flush, 1200);
        } catch (error) {
            // Analytics never breaks a shop.
        }
    }

    function flush() {
        try {
            if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
            // Vitals alone are worth a request: a visitor who reads one page and leaves queues no
            // event at all, and that visit is exactly the one whose speed decided they left.
            var unsent = Object.keys(vitals).some(function (name) { return vitals[name] !== sentVitals[name]; });
            if (!queue.length && !unsent) return;

            // A metric repeats across flushes only when it actually moved: CLS and INP keep
            // refining after the first flush, and re-sending an unchanged cumulative value was
            // counting the same jank twice in whatever window it landed in.
            var readings = Object.keys(vitals).filter(function (name) {
                return vitals[name] !== sentVitals[name];
            }).map(function (name) {
                sentVitals[name] = vitals[name];
                return {name: name, value: vitals[name]};
            });
            var body = JSON.stringify({
                events: queue,
                vitals: readings,
                path: location.pathname,
            });
            queue = [];
            // Sent once. A second flush on the same page would report the same numbers again and
            // double their weight in the window they land in.
            vitals = {};

            // sendBeacon survives the page being closed, which is exactly when the last events of
            // a visit are queued. It cannot set headers, which is why the endpoint is same-origin
            // checked rather than CSRF-token checked.
            if (navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, new Blob([body], {type: 'application/json'}));
                return;
            }

            var request = new XMLHttpRequest();
            request.open('POST', endpoint, true);
            request.setRequestHeader('Content-Type', 'application/json');
            request.send(body);
        } catch (error) {
            // As above.
        }
    }

    // The filter's pushState navigations. Patched rather than hooked into the filter's own code so
    // this keeps working if that file is rewritten, and so any other pushState navigation added
    // later is counted without anybody remembering to.
    try {
        // Only meaningful where a product list exists: the patch fires for EVERY pushState on
        // EVERY page, and a gallery arrow or a tab that uses history was being counted as a
        // catalogue view shop-wide.
        var isListPage = function () {
            return !!document.querySelector('[data-product-list], .product-listing, #ajax-products');
        };
        var pushState = history.pushState;
        history.pushState = function () {
            var result = pushState.apply(this, arguments);
            if (isListPage()) push('product_list_viewed');
            return result;
        };
        window.addEventListener('popstate', function () { if (isListPage()) push('product_list_viewed'); });
    } catch (error) {
        // An environment that will not let history be patched simply loses these events.
    }

    // Interactions that never navigate. Delegated from the document so elements the theme renders
    // later — a quick view's markup, an ajax-replaced product grid — are covered without rebinding.
    document.addEventListener('click', function (event) {
        try {
            var target = event.target.closest ? event.target.closest('[data-analytics]') : null;
            if (!target) return;

            push(target.getAttribute('data-analytics'), {
                entityType: target.getAttribute('data-analytics-type'),
                entityId: target.getAttribute('data-analytics-id')
            });
        } catch (error) {
            // A click is never blocked by its own measurement.
        }
    }, true);

    // ---- Sections that came into view ------------------------------------------------------
    //
    // A composed home page is an arrangement, and the merchant's only real question about an
    // arrangement is how far down it anyone got. The server sees the page; it has no idea whether
    // the third rail was ever on screen. Nothing else can answer that, so this does.
    //
    // Counted once per element per page: an observer fires again every time a section re-enters,
    // and a shopper scrolling up and back down has not seen it twice. IntersectionObserver is in
    // every browser this shop supports; where it is missing the sections simply go uncounted,
    // which the report shows as no data rather than as nobody having scrolled.
    try {
        if (window.IntersectionObserver) {
            var seen = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;

                    var element = entry.target;
                    seen.unobserve(element);

                    var viewPayload = {
                        entityType: element.getAttribute('data-analytics-type'),
                        entityId: element.getAttribute('data-analytics-id')
                    };
                    // A section under experiment says which variant was on screen, so exposure
                    // per variant is measurable from the same impressions everything else uses.
                    var experiment = element.getAttribute('data-analytics-experiment');
                    if (experiment) {
                        viewPayload.properties = {experiment: experiment};
                    }
                    push(element.getAttribute('data-analytics-view'), viewPayload);

                    // No dedupe_key on purpose. An explicit key is hashed without the visitor, so
                    // it is unique across the whole shop — right for an order, and for an
                    // impression it would record the first shopper who ever saw this section and
                    // silently reject everyone after them. The unobserve above is what makes this
                    // once per page; the server's own few-second window catches the rest.
                });
            }, {
                // A quarter of the section on screen is "seen"; a single pixel at the bottom edge
                // of a viewport is not, and counting it would make every arrangement look equally
                // well read.
                threshold: 0.25
            });

            document.querySelectorAll('[data-analytics-view]').forEach(function (element) {
                seen.observe(element);
            });
        }
    } catch (error) {
        // A page is never broken by its own measurement.
    }

    // ---- Core Web Vitals -------------------------------------------------------------------
    //
    // Measured here because they cannot be measured anywhere else: the server's own timing can be
    // excellent while the page is unusable — an image that shifts the layout after two seconds, a
    // font that blocks the first paint, a tap the page ignores for half a second. PerformanceObserver
    // is in every browser this shop supports; a library for it would be 3KB to compute five numbers.
    //
    // They ride out on the same request as the events (one trip, not two) but are a separate key,
    // because they are measurements of the shop rather than things a visitor did.
    var vitals = {};
    var sentVitals = {};

    function vital(name, value) {
        try {
            if (typeof value !== 'number' || !isFinite(value) || value < 0) return;
            // Last value wins: LCP and CLS are refined as the page settles, and the final reading
            // is the one that describes what the visitor got.
            vitals[name] = Math.round(value);
        } catch (error) {
            // A page is never broken by its own measurement.
        }
    }

    function observe(type, handler, options) {
        try {
            var observer = new PerformanceObserver(function (list) {
                list.getEntries().forEach(handler);
            });
            observer.observe(Object.assign({type: type, buffered: true}, options || {}));
            return observer;
        } catch (error) {
            // An unsupported entry type simply goes unmeasured, which the panel reports as such
            // rather than as a good score.
            return null;
        }
    }

    try {
        // TTFB and FCP come from the navigation timeline the browser already recorded.
        var navigation = performance.getEntriesByType('navigation')[0];
        if (navigation) vital('ttfb', navigation.responseStart);

        observe('paint', function (entry) {
            if (entry.name === 'first-contentful-paint') vital('fcp', entry.startTime);
        });

        // Largest Contentful Paint: the last one before the visitor interacts is the real one.
        observe('largest-contentful-paint', function (entry) { vital('lcp', entry.startTime); });

        // Cumulative Layout Shift, ×1000 so it travels as an integer like the timings. Shifts the
        // visitor caused by interacting are excluded — that is the metric's own definition.
        var shifted = 0;
        observe('layout-shift', function (entry) {
            if (entry.hadRecentInput) return;
            shifted += entry.value;
            vital('cls', shifted * 1000);
        });

        // Interaction to Next Paint: the worst interaction of the visit is what the visitor
        // remembers, so the maximum is kept rather than the last.
        var worstInteraction = 0;
        observe('event', function (entry) {
            if (typeof entry.duration !== 'number') return;
            if (entry.duration > worstInteraction) {
                worstInteraction = entry.duration;
                vital('inp', worstInteraction);
            }
        }, {durationThreshold: 40});
    } catch (error) {
        // As above: a browser that offers none of this reports no vitals, and the panel says so.
    }

    // Anything still queued when the page goes away. visibilitychange fires on mobile where
    // unload does not, which is where most of this shop's traffic is.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush();
    });
    window.addEventListener('pagehide', flush);

    // A small public surface, so theme code can report something without knowing any of the above.
    window.kohlAnalytics = {track: push, flush: flush};
})();
