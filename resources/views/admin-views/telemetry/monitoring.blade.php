@extends('layouts.admin.app')

@section('title', translate('monitoring'))

@section('content')
    <div class="content container-fluid k" id="monitoring-root"
         data-pulse-url="{{ route('admin.monitoring.pulse') }}">
        <x-k.page-header :title="translate('monitoring')"
                         :subtitle="translate('the_live_state_of_the_server_the_store_and_both_apps')">
            <x-slot:actions>
                <span class="k-badge" id="pulse-clock" title="{{ translate('auto_refreshes_every_ten_seconds') }}">
                    <span id="pulse-state">{{ translate('live') }}</span>
                </span>
            </x-slot:actions>
        </x-k.page-header>

        {{-- Service health strip: anything red here explains everything below it. --}}
        <div class="k-row" style="flex-wrap:wrap;gap:8px;margin-block-end:16px" id="service-strip">
            <span class="k-badge" data-svc="db">DB <span class="k-num" data-v="db_ms">–</span>ms</span>
            <span class="k-badge" data-svc="cache">{{ translate('cache') }} <span class="k-num" data-v="cache_ms">–</span>ms</span>
            <span class="k-badge" data-svc="scheduler">{{ translate('scheduler') }}</span>
            <span class="k-badge" data-svc="storage">{{ translate('storage') }}</span>
            <span class="k-badge" data-svc="queue">{{ translate('queue') }} <span class="k-num" data-v="queue_pending">–</span></span>
            <span class="k-badge" data-svc="failed">{{ translate('failed_jobs_24h') }} <span class="k-num" data-v="failed_24h">–</span></span>
        </div>

        <div class="k-stats" style="margin-block-end:16px">
            <x-k.stat :label="translate('requests_per_minute')" value="–" icon="trend-up" data-stat="requests_per_min" />
            <x-k.stat :label="translate('active_visitors')" value="–" icon="customers" data-stat="active_visitors" />
            <x-k.stat :label="translate('visitors_today')" value="–" icon="customers" data-stat="visitors_today" />
            <x-k.stat :label="translate('avg_response_last_hour')" value="–" icon="clock" data-stat="avg_ms_hour" />
            <x-k.stat :label="translate('errors_last_hour')" value="–" icon="alert" data-stat="errors_hour" />
        </div>

        <div class="k-stats" style="margin-block-end:16px">
            <x-k.stat :label="translate('orders_today')" value="–" icon="orders" data-stat="orders_today" />
            <x-k.stat :label="translate('revenue_today')" value="–" icon="reports" data-stat="revenue_today" />
            <x-k.stat :label="translate('pending_orders')" value="–" icon="clock" data-stat="pending_orders" />
            <x-k.stat :label="translate('api_requests_last_hour')" value="–" icon="external" data-stat="api_hits_hour" />
            <x-k.stat :label="translate('api_error_rate')" value="–" icon="alert" data-stat="api_error_rate" />
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <x-k.card :title="translate('server')">
                    <table class="k-table" id="server-table">
                        <tbody>
                        <tr><td>{{ translate('cpu_load') }} (1m / 5m / 15m)</td><td class="k-table__num"><span class="k-num" data-v="load">–</span></td></tr>
                        <tr><td>{{ translate('memory') }}</td><td class="k-table__num"><span class="k-num" data-v="memory">–</span></td></tr>
                        <tr><td>{{ translate('disk') }}</td><td class="k-table__num"><span class="k-num" data-v="disk">–</span></td></tr>
                        <tr><td>PHP / Laravel</td><td class="k-table__num"><span class="k-num" data-v="runtime">–</span></td></tr>
                        <tr><td>{{ translate('scheduler_last_run') }}</td><td class="k-table__num"><span class="k-num" data-v="scheduler_last_run">–</span></td></tr>
                        </tbody>
                    </table>
                </x-k.card>
            </div>
            <div class="col-lg-6">
                <x-k.card :title="translate('hot_paths_last_15_minutes')">
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                            <tr>
                                <th>{{ translate('path') }}</th>
                                <th>{{ translate('channel') }}</th>
                                <th class="k-table__num">{{ translate('hits') }}</th>
                                <th class="k-table__num">{{ translate('avg') }}</th>
                                <th class="k-table__num">{{ translate('errors') }}</th>
                            </tr>
                            </thead>
                            <tbody id="hot-paths"><tr><td colspan="5">{{ translate('collecting') }}…</td></tr></tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var root = document.getElementById('monitoring-root');
            var url = root.getAttribute('data-pulse-url');
            var stateEl = document.getElementById('pulse-state');

            function tone(el, ok, warn) {
                el.classList.remove('k-badge--success', 'k-badge--warning', 'k-badge--danger');
                el.classList.add(ok ? 'k-badge--success' : (warn ? 'k-badge--warning' : 'k-badge--danger'));
            }
            function set(name, value) {
                var stat = root.querySelector('[data-stat="' + name + '"] .k-stat__value');
                if (stat) stat.textContent = value;
            }
            function num(value) { return new Intl.NumberFormat().format(value); }
            function esc(s) {
                var d = document.createElement('div');
                d.textContent = String(s);
                return d.innerHTML;
            }

            function apply(s) {
                if (s.traffic && !s.traffic.error) {
                    set('requests_per_min', num(s.traffic.requests_per_min));
                    set('active_visitors', num(s.traffic.active_visitors));
                    set('visitors_today', num(s.traffic.visitors_today));
                    // Null is "no request to average", which is not the same as an instant one.
                    set('avg_ms_hour', s.traffic.avg_ms_hour === null
                        ? '{{ translate('no_data') }}'
                        : num(s.traffic.avg_ms_hour) + ' ms');
                    set('errors_hour', num(s.traffic.errors_hour));
                }
                if (s.commerce && !s.commerce.error) {
                    set('orders_today', num(s.commerce.orders_today));
                    set('revenue_today', num(Math.round(s.commerce.revenue_today)));
                    set('pending_orders', num(s.commerce.pending_orders));
                }
                if (s.api && !s.api.error) {
                    set('api_hits_hour', num(s.api.hits_hour));
                    set('api_error_rate', s.api.error_rate_pct + '%');
                }
                if (s.services && !s.services.error) {
                    var strip = document.getElementById('service-strip');
                    var db = strip.querySelector('[data-svc="db"]');
                    db.querySelector('[data-v="db_ms"]').textContent = s.services.db_ms;
                    tone(db, s.services.db_ms < 50, s.services.db_ms < 250);
                    var cache = strip.querySelector('[data-svc="cache"]');
                    cache.querySelector('[data-v="cache_ms"]').textContent = s.services.cache_ms;
                    tone(cache, s.services.cache_ok, false);
                    tone(strip.querySelector('[data-svc="scheduler"]'), s.services.scheduler_ok, false);
                    tone(strip.querySelector('[data-svc="storage"]'), s.services.storage_writable, false);
                    root.querySelector('[data-v="scheduler_last_run"]').textContent = s.services.scheduler_last_run || '—';
                }
                if (s.queue && !s.queue.error) {
                    var q = document.querySelector('[data-svc="queue"]');
                    q.querySelector('[data-v="queue_pending"]').textContent = num(s.queue.pending);
                    tone(q, s.queue.pending < 100, s.queue.pending < 1000);
                    var failed = document.querySelector('[data-svc="failed"]');
                    failed.querySelector('[data-v="failed_24h"]').textContent = num(s.queue.failed_24h);
                    tone(failed, s.queue.failed_24h === 0, s.queue.failed_24h < 10);
                }
                if (s.server && !s.server.error) {
                    root.querySelector('[data-v="load"]').textContent = s.server.load
                        ? s.server.load.join(' / ') + '  (' + s.server.cpu_count + ' cpu)' : '—';
                    root.querySelector('[data-v="memory"]').textContent = s.server.memory
                        ? s.server.memory.used_mb + ' / ' + s.server.memory.total_mb + ' MB (' + s.server.memory.used_pct + '%)' : '—';
                    root.querySelector('[data-v="disk"]').textContent = s.server.disk
                        ? s.server.disk.free_gb + ' GB free of ' + s.server.disk.total_gb + ' GB' : '—';
                    root.querySelector('[data-v="runtime"]').textContent = s.server.php + ' / ' + s.server.laravel;
                }
                if (Array.isArray(s.top_paths)) {
                    document.getElementById('hot-paths').innerHTML = s.top_paths.length
                        ? s.top_paths.map(function (p) {
                            return '<tr><td class="k-truncate" style="max-inline-size:220px">' + esc(p.path) + '</td>'
                                + '<td>' + esc(p.channel) + '</td>'
                                + '<td class="k-table__num k-num">' + num(p.hits) + '</td>'
                                + '<td class="k-table__num k-num">' + num(p.avg_ms) + ' ms</td>'
                                + '<td class="k-table__num k-num">' + num(p.errors) + '</td></tr>';
                        }).join('')
                        : '<tr><td colspan="5">—</td></tr>';
                }
            }

            apply(@json($snapshot));

            function pull() {
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (s) { apply(s); stateEl.textContent = @json(translate('live')); })
                    .catch(function () { stateEl.textContent = @json(translate('offline')); });
            }
            setInterval(pull, 10000);
        })();
    </script>
@endpush
