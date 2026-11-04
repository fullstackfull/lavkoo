/*
 | Monitoring — the page's own behaviour.
 |
 | Three jobs, and a rule. The jobs: keep the status header current, draw the small time-series
 | charts, and let the section rail collapse on a phone. The rule: polling must never cost more
 | than the thing it is watching — so the header refreshes on a modest interval, pauses entirely
 | while the tab is hidden, and backs off when a request fails rather than hammering a struggling
 | server with a health check every few seconds.
 */
'use strict';

(function () {
    const root = document.getElementById('monitoring-root');
    if (!root) {
        return;
    }

    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ── Status header ─────────────────────────────────────────────────────── */

    const statusBox = document.getElementById('mon-status');
    const pulseUrl = root.dataset.pulseUrl;

    const BASE_INTERVAL = 15000;
    const MAX_INTERVAL = 120000;
    let interval = BASE_INTERVAL;
    let timer = null;

    function text(name, value) {
        const node = root.querySelector('[data-mon="' + name + '"]');
        if (node) {
            node.textContent = value;
        }
    }

    function chip(name, label, state) {
        const node = root.querySelector('[data-mon="' + name + '"]');
        if (!node) {
            return;
        }
        node.textContent = label;
        node.setAttribute('data-state', state);
    }

    function describeAge(reading) {
        if (!reading || reading.age_seconds === null || reading.age_seconds === undefined) {
            return 'no data';
        }
        const seconds = reading.age_seconds;
        if (seconds < 90) {
            return seconds + 's ago';
        }
        if (seconds < 5400) {
            return Math.round(seconds / 60) + 'm ago';
        }
        return Math.round(seconds / 3600) + 'h ago';
    }

    function applyPulse(payload) {
        if (statusBox) {
            statusBox.setAttribute('data-state', payload.status || 'unknown');
        }
        text('status', payload.status || 'unknown');
        text('score', payload.score === null || payload.score === undefined ? '—' : payload.score);

        if (payload.coverage) {
            text('coverage', 'from ' + payload.coverage.measured + '/' + payload.coverage.total + ' measured signals');
        }

        const self = payload.self || {};
        chip(
            'self-collection',
            self.collection_enabled ? 'collecting' : 'collection off',
            self.collection_enabled ? 'ok' : 'off'
        );
        chip('self-gauges', 'gauges ' + describeAge(self.gauges), (self.gauges && self.gauges.state) || 'no_data');
        chip('self-requests', 'requests ' + describeAge(self.requests), (self.requests && self.requests.state) || 'no_data');

        const bufferNode = root.querySelector('[data-mon="self-buffer"]');
        if (bufferNode && self.buffer) {
            bufferNode.textContent = self.buffer.description;
        }

        // A stale header is the one thing that must never be quiet: everything below it is being
        // read from a system that has stopped reporting.
        if (payload.stale) {
            text('status-detail', 'Monitoring is not receiving data — nothing below is current.');
        } else if (payload.status === 'healthy') {
            text('status-detail', 'across all measured signals');
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(poll, interval);
    }

    function poll() {
        if (document.hidden) {
            // Nobody is looking. Come back when they are, rather than polling a background tab.
            schedule();
            return;
        }

        fetch(pulseUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('pulse ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                interval = BASE_INTERVAL;
                applyPulse(payload);
            })
            .catch(function () {
                // Exponential back-off. A server in trouble does not need a health check every
                // fifteen seconds from every open dashboard.
                interval = Math.min(MAX_INTERVAL, interval * 2);
                chip('self-collection', 'dashboard offline', 'off');
            })
            .finally(schedule);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            clearTimeout(timer);
            poll();
        }
    });

    if (pulseUrl) {
        schedule();
    }

    /* ── Section rail ──────────────────────────────────────────────────────── */

    const railToggle = root.querySelector('[data-mon-rail-toggle]');
    if (railToggle) {
        railToggle.addEventListener('click', function () {
            const rail = railToggle.closest('.mon-rail');
            const open = rail.classList.toggle('is-open');
            railToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    /* ── Charts ────────────────────────────────────────────────────────────── */

    /*
     | Drawn as inline SVG rather than with a charting library.
     |
     | The series is already aggregated server-side to a few dozen points, so there is nothing left
     | for a library to do that costs less than loading it — and this page is heavy enough already.
     | Requests are an area, errors a separate line on the same axis, because an error count is
     | meaningless without the traffic it happened in.
     */
    function drawChart(host) {
        let payload;
        try {
            payload = JSON.parse(host.dataset.monChart);
        } catch (error) {
            return;
        }

        const points = (payload.points || []).filter(function (point) {
            return point && typeof point.hits === 'number';
        });
        if (points.length < 2) {
            host.innerHTML = '<p class="mon-note">Not enough points to draw a line yet.</p>';
            return;
        }

        const width = 1000;
        const height = 200;
        const padding = { top: 8, right: 8, bottom: 18, left: 34 };
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;

        const maxHits = Math.max.apply(null, points.map(function (point) { return point.hits; })) || 1;
        const x = function (index) { return padding.left + (index / (points.length - 1)) * plotWidth; };
        const y = function (value) { return padding.top + plotHeight - (value / maxHits) * plotHeight; };

        const line = points.map(function (point, index) {
            return (index === 0 ? 'M' : 'L') + x(index).toFixed(1) + ' ' + y(point.hits).toFixed(1);
        }).join(' ');

        const area = line + ' L' + x(points.length - 1).toFixed(1) + ' ' + (padding.top + plotHeight) +
            ' L' + x(0).toFixed(1) + ' ' + (padding.top + plotHeight) + ' Z';

        const errorTotal = points.reduce(function (sum, point) { return sum + (point.errors || 0); }, 0);
        const errorLine = errorTotal > 0
            ? points.map(function (point, index) {
                return (index === 0 ? 'M' : 'L') + x(index).toFixed(1) + ' ' + y(point.errors || 0).toFixed(1);
            }).join(' ')
            : null;

        const label = function (value, atY) {
            return '<text class="mon-chart__label" x="4" y="' + (atY + 3).toFixed(1) + '">' + value + '</text>';
        };

        const first = new Date(points[0].t);
        const last = new Date(points[points.length - 1].t);
        const timeLabel = function (date) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        };

        host.innerHTML =
            '<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" role="img" ' +
            'aria-label="Requests over time">' +
            '<path class="mon-chart__area" d="' + area + '"></path>' +
            '<path class="mon-chart__line" d="' + line + '"></path>' +
            (errorLine ? '<path class="mon-chart__errors" d="' + errorLine + '"></path>' : '') +
            '<line class="mon-chart__axis" x1="' + padding.left + '" y1="' + (padding.top + plotHeight) +
            '" x2="' + (width - padding.right) + '" y2="' + (padding.top + plotHeight) + '"></line>' +
            label(maxHits, padding.top) +
            label(0, padding.top + plotHeight) +
            '<text class="mon-chart__label" x="' + padding.left + '" y="' + (height - 4) + '">' + timeLabel(first) + '</text>' +
            '<text class="mon-chart__label" x="' + (width - padding.right - 30) + '" y="' + (height - 4) + '">' + timeLabel(last) + '</text>' +
            '</svg>';
    }

    root.querySelectorAll('[data-mon-chart]').forEach(drawChart);
})();
