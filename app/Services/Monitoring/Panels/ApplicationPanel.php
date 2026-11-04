<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Ingest\MetricSink;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use FilesystemIterator;
use Illuminate\Http\Request;
use SplFileInfo;

/**
 * The runtime: what this shop is actually executing on, and how it is configured to behave.
 *
 * Everything else in the operations centre measures what happened. This section states what the
 * process IS — the PHP it runs, the accelerator in front of it, the pool that serves it, the
 * caches it boots from, the build it is, and what monitoring has been told to record. Those facts
 * are the frame every other section is read inside: a p95 on a deployment with no OPcache and a
 * p95 on one with a warm config cache are not the same measurement, and a section that shows
 * nothing may be showing nothing because the sample rate here says so.
 *
 * Two rules shape it.
 *
 * The runtime numbers are never described twice. Every version, limit, OPcache counter and pool
 * figure comes from the `php` collector, which reads the LIVE process rather than php.ini and
 * reports each of the three separate reasons OPcache can be unreadable rather than folding them
 * into an empty cache. This panel adds no arithmetic on top of them: it groups them, and nothing
 * more.
 *
 * A configuration fact is a reading, not a verdict — except where the fact is a live risk. Debug
 * mode on in production, a cold config cache in production and an APP_URL that is not https are
 * not neutral settings; each is drawn at the top as a finding carrying the exact command that
 * fixes it. Everything else is stated as it is, because a page that shouts at a developer for a
 * local `http://127.0.0.1` teaches the operator to stop reading the shouting.
 */
class ApplicationPanel implements Panel
{
    /** The collector this section is made of. Read exactly once per request. */
    private const COLLECTOR = 'php';

    /** How far back the last recorded deployment may be looked for. Bounds the only shop-free query here. */
    private const DEPLOYMENT_WINDOW_DAYS = 400;

    /** Counting compiled Blade files stops here: this page must never be the expensive one. */
    private const COMPILED_VIEW_CAP = 2000;

    /**
     * The `php` collector's readings, grouped the way somebody diagnoses a runtime.
     *
     * Each entry is `label => metric name`. The label is what the card shows, so the group's own
     * prefix is dropped from it — the OPcache card does not need to repeat "opcache" thirteen
     * times, and the prefix is still in the payload as the metric it came from.
     *
     * @var array<string, array{why: string, metrics: array<string, string>}>
     */
    private const COLLECTOR_GROUPS = [
        'runtime' => [
            'why' => 'the_configuration_this_process_ended_up_with_after_boot_rather_than_what_php_ini_says_on_disk',
            'metrics' => [
                'php_version' => 'version',
                'sapi' => 'sapi',
                'laravel_version' => 'laravel_version',
                'environment' => 'environment',
                'debug_mode' => 'debug_mode',
                'timezone' => 'timezone',
                'memory_limit' => 'memory_limit',
                'max_execution_time' => 'max_execution_time',
                'upload_max_filesize' => 'upload_max_filesize',
                'post_max_size' => 'post_max_size',
            ],
        ],
        'opcache' => [
            'why' => 'whether_the_application_is_compiled_once_and_kept_or_compiled_again_on_every_single_request',
            'metrics' => [
                'enabled' => 'opcache_enabled',
                'hit_rate' => 'opcache_hit_rate',
                'cached_scripts' => 'opcache_cached_scripts',
                'max_cached_keys' => 'opcache_max_cached_keys',
                'memory_used' => 'opcache_memory_used',
                'memory_free' => 'opcache_memory_free',
                'wasted' => 'opcache_wasted',
                'wasted_pct' => 'opcache_wasted_pct',
                'hits' => 'opcache_hits',
                'misses' => 'opcache_misses',
                'out_of_memory_restarts' => 'opcache_oom_restarts',
                'hash_table_restarts' => 'opcache_hash_restarts',
                'manual_restarts' => 'opcache_manual_restarts',
            ],
        ],
        'php_fpm' => [
            'why' => 'the_pool_that_serves_the_site_read_from_its_own_status_page_rather_than_guessed_at_from_the_process_asking',
            'metrics' => [
                'process_is_fpm' => 'is_fpm',
                'pool' => 'fpm_pool',
                'active_processes' => 'fpm_active_processes',
                'idle_processes' => 'fpm_idle_processes',
                'total_processes' => 'fpm_total_processes',
                'busiest_since_start' => 'fpm_max_active_processes',
                'max_children_reached' => 'fpm_max_children_reached',
                'listen_queue' => 'fpm_listen_queue',
                'max_listen_queue' => 'fpm_max_listen_queue',
                'listen_queue_capacity' => 'fpm_listen_queue_len',
                'slow_requests' => 'fpm_slow_requests',
                'accepted_connections' => 'fpm_accepted_connections',
            ],
        ],
    ];

    /**
     * Groups this panel builds itself, from configuration rather than from a collector.
     *
     * @var array<string, string>
     */
    private const CONFIGURATION_GROUPS = [
        'caches' => 'the_compiled_caches_laravel_boots_from_and_the_drivers_behind_cache_queue_and_session',
        'release' => 'which_build_is_running_so_an_error_on_any_other_section_can_be_traced_to_the_code_that_produced_it',
        'monitoring' => 'what_this_dashboard_is_configured_to_record_which_is_the_ceiling_on_what_every_other_section_can_show',
    ];

    /**
     * The gauges the `php` collector stores every minute.
     *
     * `source` names the live reading each one is written from, which is what lets an empty chart
     * say WHY it is empty: the sampler only stores a reading that is OK, so a flat gauge on a host
     * without OPcache is a missing accelerator rather than a missing scheduler.
     *
     * @var array<string, array{metric: string, unit: string, title: string, source: string}>
     */
    private const GAUGES = [
        'opcache_hit_rate' => [
            'metric' => 'php.opcache.hit_rate',
            'unit' => '%',
            'title' => 'opcache_hit_rate_over_time',
            'source' => 'opcache_hit_rate',
        ],
        'opcache_memory_used' => [
            'metric' => 'php.opcache.memory_used_mb',
            'unit' => 'MB',
            'title' => 'opcache_memory_used_over_time',
            'source' => 'opcache_memory_used',
        ],
        'opcache_wasted_pct' => [
            'metric' => 'php.opcache.wasted_pct',
            'unit' => '%',
            'title' => 'opcache_wasted_memory_over_time',
            'source' => 'opcache_wasted_pct',
        ],
        'fpm_active_processes' => [
            'metric' => 'php.fpm.active_processes',
            'unit' => 'processes',
            'title' => 'fpm_active_processes_over_time',
            'source' => 'fpm_active_processes',
        ],
        'fpm_listen_queue' => [
            'metric' => 'php.fpm.listen_queue',
            'unit' => 'requests',
            'title' => 'fpm_listen_queue_over_time',
            'source' => 'fpm_listen_queue',
        ],
    ];

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
        private readonly MetricSink $sink,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $readings = $this->collectors->collect(self::COLLECTOR);
        $constructed = $this->constructedReadings();

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'collector' => $this->collectorFault($readings),
            'findings' => $this->findings(),
            'groups' => $this->groups($readings, $constructed),
            'gauges' => $this->gauges($range, $window['resolution'], $readings),
            'deployment' => $this->lastDeployment($constructed['release']['release_version'] ?? null),
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What an operator should act on

    /**
     * The three settings that are not neutral facts.
     *
     * Each one is a live risk rather than a preference, so each is drawn at the top with the exact
     * command that resolves it. Two are gated on production because they are the correct settings
     * anywhere else; the third is stated everywhere, at the severity the environment earns, because
     * an http APP_URL on a laptop becomes an http APP_URL on the live site the first time somebody
     * copies the .env across.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        try {
            $production = app()->isProduction();
            $findings = [];

            if ((bool) config('app.debug') && $production) {
                $findings[] = [
                    'severity' => 'critical',
                    'title' => 'app_debug_is_on_in_production',
                    'detail' => 'any_uncaught_exception_renders_the_debug_page_which_prints_env_values_database_credentials_and_payment_api_keys_to_whoever_triggered_it',
                    'remedy' => 'Set APP_DEBUG=false in .env, then run php artisan config:cache.',
                ];
            }

            if ($production && !app()->configurationIsCached()) {
                $findings[] = [
                    'severity' => 'warning',
                    'title' => 'the_configuration_cache_is_cold_in_production',
                    'detail' => 'every_request_parses_each_file_in_the_config_directory_and_reads_the_env_file_before_it_does_any_of_the_work_it_was_made_for',
                    'remedy' => 'php artisan config:cache — run it as the last step of every deployment, after .env is in place.',
                ];
            }

            $url = trim((string) config('app.url', ''));
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                $findings[] = [
                    'severity' => $production ? 'warning' : 'info',
                    'title' => 'app_url_is_not_an_https_address',
                    'detail' => $production
                        ? 'password_resets_email_buttons_and_payment_callbacks_are_all_generated_from_this_value_so_every_one_of_them_leaves_the_site_over_plain_http'
                        : 'correct_for_a_local_environment_and_a_plain_http_live_site_the_moment_this_env_file_is_copied_to_one',
                    'remedy' => 'Set APP_URL=https://your-domain in .env, then run php artisan config:cache.',
                ];
            }

            return $findings;
        } catch (\Throwable $exception) {
            // PanelRegistry would catch this and blank the whole section. Failing the checks by
            // name leaves every card below readable and says which part is missing.
            return [[
                'severity' => 'info',
                'title' => 'the_configuration_checks_could_not_run',
                'detail' => 'the_cards_below_are_unaffected_but_nothing_on_this_page_is_asserting_that_the_settings_are_safe',
                'remedy' => Metric::describeFailure($exception),
            ]];
        }
    }

    /**
     * The collector failing to answer at all, said once.
     *
     * Normally null. A missing or throwing collector produces three dozen identical unavailable
     * rows underneath, and three dozen copies of one fault reads as three dozen faults.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>|null
     */
    private function collectorFault(array $readings): ?array
    {
        if ($readings === []) {
            return [
                'state' => 'not_supported',
                'note' => 'The php collector is not installed in this build, so no runtime, OPcache or pool reading on this page can be taken.',
            ];
        }

        $failure = $readings['__collector'] ?? null;

        return $failure instanceof Metric ? ['state' => 'failed', 'note' => $failure->note] : null;
    }

    // -------------------------------------------------------------------------------------------
    // The cards

    /**
     * @param  array<string, Metric>  $readings
     * @param  array<string, array<string, Metric>>  $constructed
     * @return array<int, array<string, mixed>>
     */
    private function groups(array $readings, array $constructed): array
    {
        $groups = [];

        foreach (self::COLLECTOR_GROUPS as $key => $definition) {
            $metrics = [];
            foreach ($definition['metrics'] as $label => $name) {
                $metrics[$label] = $readings[$name] ?? null;
            }

            $groups[] = [
                'key' => $key,
                'why' => $definition['why'],
                'metrics' => $this->renderable(array_merge($metrics, $constructed[$key] ?? [])),
            ];
        }

        foreach (self::CONFIGURATION_GROUPS as $key => $why) {
            $groups[] = [
                'key' => $key,
                'why' => $why,
                'metrics' => $this->renderable($constructed[$key] ?? []),
            ];
        }

        return array_values(array_filter($groups, static fn (array $group) => $group['metrics'] !== []));
    }

    /**
     * The readings a one-line card can honestly draw.
     *
     * An unavailable reading goes in whole — its state and its remedy ARE the content. A reading
     * that is OK but not scalar has no honest single-value rendering, and handing an array to the
     * metric partial prints a PHP warning where a value should be.
     *
     * @param  array<string, Metric|null>  $metrics
     * @return array<string, Metric>
     */
    private function renderable(array $metrics): array
    {
        return array_filter(
            $metrics,
            static fn (?Metric $metric) => $metric instanceof Metric && (!$metric->isOk() || is_scalar($metric->value)),
        );
    }

    /**
     * Everything this panel reads for itself, keyed by the group it belongs to.
     *
     * @return array<string, array<string, Metric>>
     */
    private function constructedReadings(): array
    {
        return [
            'runtime' => ['app_url' => $this->appUrl()],
            'caches' => $this->cacheReadings(),
            'release' => $this->releaseReadings(),
            'monitoring' => $this->monitoringReadings(),
        ];
    }

    /**
     * The address every generated link is built from.
     *
     * It matters most where there is no request to fall back on: a queued password-reset email is
     * rendered by a worker, and the host in it comes from here or from nowhere.
     */
    private function appUrl(): Metric
    {
        $source = 'Laravel config app.url';

        return Metric::probe($source, function () use ($source) {
            $url = trim((string) config('app.url', ''));

            if ($url === '') {
                return Metric::notConfigured(
                    source: $source,
                    remedy: 'Set APP_URL=https://your-domain in .env, then run php artisan config:cache.',
                    note: 'APP_URL is empty, so a link generated outside a request — a queued email, a scheduled report — has no host to build on.',
                );
            }

            $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';

            return Metric::of(
                value: $url,
                source: $source,
                note: $https ? null : 'Every link this application generates carries this scheme, including the ones that leave the building.',
            );
        });
    }

    /**
     * The caches Laravel boots from, and the drivers behind the three stores a request touches.
     *
     * Warmth is read as the file on disk rather than as a config flag, because that file IS the
     * cache: `configurationIsCached()` answers the same question by the same means, and reporting
     * the path makes the answer checkable from a shell.
     *
     * @return array<string, Metric>
     */
    private function cacheReadings(): array
    {
        return [
            'config_cache' => $this->compiledCache(
                path: app()->getCachedConfigPath(),
                warm: 'Every config() call is answered from one compiled file. Anything edited in .env after it was written is invisible until it is rebuilt.',
                cold: 'Every request parses each file in config/ and reads .env before it does any of its own work. Correct while developing, expensive in production.',
            ),
            'route_cache' => $this->compiledCache(
                path: app()->getCachedRoutesPath(),
                warm: 'The route table is loaded from one compiled file rather than by executing every route file at boot.',
                cold: 'The admin, vendor, web and API route files plus each module\'s own are executed on every request. Caching them requires every route to point at a controller action — php artisan route:cache fails loudly on a closure rather than silently.',
            ),
            'event_cache' => $this->compiledCache(
                path: app()->getCachedEventsPath(),
                warm: 'Listener discovery is compiled rather than scanned at boot.',
                cold: 'Listeners are discovered at boot. Harmless on a small graph, and free to remove with php artisan event:cache.',
            ),
            'compiled_views' => $this->compiledViews(),
            'cache_driver' => $this->driver(
                key: 'cache.default',
                notes: [
                    'array' => 'The array store lives for one request and is thrown away with it, so nothing is cached between requests at all.',
                    'file' => 'The file store serialises concurrent writes to the same key behind a lock, which is the point it stops scaling.',
                ],
            ),
            'queue_connection' => $this->driver(
                key: 'queue.default',
                notes: [
                    'sync' => 'Jobs are not queued: every dispatch runs inline, inside the request that made it, and the visitor waits for it.',
                    'null' => 'Dispatched jobs are discarded. Anything that relies on a queued job — order mail, push notifications — never happens.',
                ],
            ),
            'session_driver' => $this->driver(
                key: 'session.driver',
                notes: [
                    'array' => 'Sessions are not persisted, so nobody can stay logged in between requests.',
                    'file' => 'Sessions are files on this machine, which means a second web server would not see them.',
                ],
            ),
        ];
    }

    /**
     * One compiled bootstrap cache, read as the file it is.
     */
    private function compiledCache(string $path, string $warm, string $cold): Metric
    {
        $source = 'file_exists(' . $this->relative($path) . ')';

        return Metric::probe($source, static function () use ($path, $warm, $cold, $source) {
            $exists = file_exists($path);

            // False is a reading here, not a failure: a cold cache is a real, correct state on a
            // development box, and it is the finding at the top of the page that decides whether
            // this particular deployment should care.
            return Metric::of(value: $exists, source: $source, note: $exists ? $warm : $cold);
        });
    }

    /**
     * How many Blade templates are compiled on disk.
     *
     * Deliberately not reported as warm or cold. Blade compiles on demand, so this directory fills
     * up on its own as pages are visited and an empty one is a fresh cache rather than a broken
     * deployment — the two things worth knowing are that the directory is usable at all, and that
     * something is in it.
     */
    private function compiledViews(): Metric
    {
        $source = 'Laravel config view.compiled';

        return Metric::probe($source, function () use ($source) {
            $path = (string) config('view.compiled', '');

            if ($path === '' || !is_dir($path)) {
                return Metric::notConfigured(
                    source: $source,
                    remedy: 'mkdir -p ' . ($path !== '' ? $path : 'storage/framework/views') . ' && php artisan file:permission',
                    note: 'The compiled view directory does not exist, so rendering any page throws rather than writing its compiled template.',
                );
            }

            if (!is_readable($path)) {
                return Metric::permissionDenied(
                    source: $source,
                    note: 'The compiled view directory cannot be listed by the user this process runs as.',
                    remedy: 'php artisan file:permission',
                );
            }

            $counted = 0;
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                    $counted++;
                }
                // Counting stops rather than walking a directory that can hold tens of thousands
                // of files: this page must never become the slow one on the site.
                if ($counted >= self::COMPILED_VIEW_CAP) {
                    break;
                }
            }

            $note = $counted >= self::COMPILED_VIEW_CAP
                ? 'At least ' . self::COMPILED_VIEW_CAP . '; counting stops there. Blade compiles a template the first time it is rendered, so this grows as pages are visited.'
                : 'Blade compiles a template the first time it is rendered, so this grows as pages are visited rather than being warmed in one step.';

            return Metric::of(
                value: $counted,
                source: $source,
                unit: 'templates',
                note: is_writable($path)
                    ? $note
                    : $note . ' The directory is not writable by this process, so any template not already compiled here will throw when it is first rendered.',
            );
        });
    }

    /**
     * A configured driver, with the note that matters only for the ones that change behaviour.
     *
     * @param  array<string, string>  $notes
     */
    private function driver(string $key, array $notes): Metric
    {
        $source = 'Laravel config ' . $key;

        return Metric::probe($source, static function () use ($key, $notes, $source) {
            $driver = config($key);
            if (!is_string($driver) || trim($driver) === '') {
                return Metric::noData(source: $source, note: 'No driver is configured under ' . $key . '.');
            }

            return Metric::of(value: $driver, source: $source, note: $notes[$driver] ?? null);
        });
    }

    /**
     * Which build this is.
     *
     * The version is what the merchant sees; the commit is what an engineer needs to know which
     * code produced an error. Neither is invented — a deployment that exports the source without
     * .git has no commit to report, and that is stated with the way to ship one instead.
     *
     * @return array<string, Metric>
     */
    private function releaseReadings(): array
    {
        return [
            'release_version' => Metric::probe(
                'version.json + .git/HEAD',
                static fn () => Metric::of(
                    value: app_release_version(),
                    source: 'version.json + .git/HEAD',
                    note: 'Stamped onto every error, trace and deployment record, which is what lets a spike be tied to a release.',
                ),
            ),
            'commit' => Metric::probe('.git/HEAD', static function () {
                $sha = app_commit_sha();

                if ($sha === null) {
                    return Metric::notConfigured(
                        source: '.git/HEAD',
                        remedy: 'Deploy with the .git directory present, or write the deployed sha into version.json as part of the build.',
                        note: 'This deployment has no readable .git, so the exact commit behind the running code cannot be read here.',
                    );
                }

                // Abbreviated because a 40-character sha has no break opportunity in it and pushes
                // straight through the next column of the card; 12 is git's own abbreviation, and
                // the full value is one `cat .git/HEAD` away rather than lost.
                return Metric::of(
                    value: substr($sha, 0, 12),
                    source: '.git/HEAD',
                    note: 'The first 12 characters, as git abbreviates a commit. The full 40 are in .git/HEAD on this deployment.',
                );
            }),
            'release_channel' => Metric::probe(
                'version.json',
                static fn () => Metric::of(value: getAppVersion()['channel'] ?? null, source: 'version.json'),
            ),
            'released_at' => Metric::probe(
                'version.json',
                static fn () => Metric::of(value: getAppVersion()['released_at'] ?? null, source: 'version.json'),
            ),
        ];
    }

    /**
     * What monitoring has been told to record.
     *
     * This is the honest ceiling on the whole dashboard: a section showing two traces on a busy
     * afternoon is showing the sample rate, not the traffic, and an operator who cannot see that
     * number will read the gap as a quiet shop.
     *
     * @return array<string, Metric>
     */
    private function monitoringReadings(): array
    {
        $readings = [
            'collection_enabled' => Metric::probe('Laravel config monitoring.enabled', static function () {
                $enabled = (bool) config('monitoring.enabled', true);

                return $enabled
                    ? Metric::of(value: true, source: 'Laravel config monitoring.enabled')
                    : Metric::of(
                        value: false,
                        source: 'Laravel config monitoring.enabled',
                        note: 'Nothing is being recorded. Every chart on every section will keep drawing whatever was stored before this was switched off. Set MONITORING_ENABLED=true in .env and run php artisan optimize:clear.',
                    );
            }),
            'buffer_driver' => Metric::probe('MetricSink::driver()', fn () => Metric::of(
                value: $this->sink->driver(),
                source: 'MetricSink::driver()',
                note: $this->sink->describe() . '.',
            )),
            'monitoring_connection' => $this->monitoringConnection(),
            'tracing_enabled' => Metric::probe(
                'Laravel config monitoring.tracing.enabled',
                static fn () => Metric::of(value: (bool) config('monitoring.tracing.enabled', true), source: 'Laravel config monitoring.tracing.enabled'),
            ),
            'trace_sample_rate' => Metric::probe('Laravel config monitoring.tracing.sample_rate', static fn () => Metric::of(
                value: round(100 * (float) config('monitoring.tracing.sample_rate', 0), 3),
                source: 'Laravel config monitoring.tracing.sample_rate',
                unit: '%',
                note: 'Of ordinary requests. Slow and failed ones are always traced whatever this rate says, because those are the ones anybody goes looking for.',
            )),
            'always_trace_slower_than' => Metric::probe('Laravel config monitoring.tracing.always_trace_slower_than_ms', static fn () => Metric::of(
                value: (int) config('monitoring.tracing.always_trace_slower_than_ms', 0),
                source: 'Laravel config monitoring.tracing.always_trace_slower_than_ms',
                unit: 'ms',
            )),
            'slow_query_threshold' => Metric::probe('Laravel config monitoring.tracing.slow_query_ms', static fn () => Metric::of(
                value: (int) config('monitoring.tracing.slow_query_ms', 0),
                source: 'Laravel config monitoring.tracing.slow_query_ms',
                unit: 'ms',
                note: 'A query slower than this is fingerprinted into monitoring_slow_queries.',
            )),
            'stale_after' => Metric::probe('Laravel config monitoring.stale_after_seconds', static fn () => Metric::of(
                value: (int) config('monitoring.stale_after_seconds', 180),
                source: 'Laravel config monitoring.stale_after_seconds',
                unit: 'seconds',
                note: 'How long the header may go without fresh telemetry before it stops claiming the shop is healthy and reports that monitoring itself is blind.',
            )),
            'display_timezone' => Metric::probe('Clock::displayTimezone()', static fn () => Metric::of(
                value: Clock::displayTimezone(),
                source: 'Clock::displayTimezone()',
                note: 'Every measurement is stored in UTC and converted once, here, for rendering.',
            )),
            'prometheus_exposition' => Metric::probe('Laravel config monitoring.prometheus', static function () {
                $enabled = (bool) config('monitoring.prometheus.enabled', false);
                $token = (string) config('monitoring.prometheus.token', '');

                return Metric::of(
                    value: $enabled,
                    source: 'Laravel config monitoring.prometheus.enabled',
                    note: $enabled && $token === ''
                        ? 'GET /monitoring/metrics is exposed with no token set, so anything that can reach this host can read the shop\'s metrics. Set MONITORING_PROMETHEUS_TOKEN in .env.'
                        : null,
                );
            }),
        ];

        return array_merge($readings, $this->retentionReadings());
    }

    /**
     * Which database monitoring writes to, and what that permits.
     *
     * The answer is load-bearing beyond storage: a separate database is the reason no query in
     * this system may join a monitoring table to a shop table.
     */
    private function monitoringConnection(): Metric
    {
        $source = 'Laravel config monitoring.connection';

        return Metric::probe($source, static function () use ($source) {
            $name = (string) config('monitoring.connection', 'monitoring');
            $monitoringDatabase = config('database.connections.' . $name . '.database');
            $shopDatabase = config('database.connections.' . config('database.default') . '.database');

            $note = is_scalar($monitoringDatabase) && is_scalar($shopDatabase) && $monitoringDatabase !== $shopDatabase
                ? 'Monitoring writes to its own database, which is why nothing in this system joins a monitoring table to a shop table in SQL.'
                : 'Monitoring shares the shop database. Its tables take the most writes on the box; set MONITORING_DB_* to move them once the volume starts to matter.';

            return Metric::of(value: $name, source: $source, note: $note);
        });
    }

    /**
     * How long each resolution is kept.
     *
     * These are the reason a 90-day chart can be empty while last night's is full, so they belong
     * on the page rather than in the config file alone.
     *
     * @return array<string, Metric>
     */
    private function retentionReadings(): array
    {
        $source = 'monitoring_settings, falling back to config monitoring.retention';
        $settings = app(MonitoringSettings::class);
        $configured = [];

        foreach ((array) config('monitoring.retention', []) as $kind => $default) {
            $configured[$kind] = $settings->retentionDays($kind, (int) $default);
        }

        if (!is_array($configured) || $configured === []) {
            return ['retention' => Metric::notConfigured(
                source: $source,
                remedy: 'Set the monitoring.retention block in config/monitoring.php; without it the pruner has no window to keep and nothing is ever deleted.',
                note: 'No retention window is configured.',
            )];
        }

        $readings = [];
        foreach ($configured as $key => $days) {
            if (!is_numeric($days)) {
                continue;
            }

            $readings['keep_' . $key] = Metric::of(
                value: (int) $days,
                source: $source . '.' . $key,
                unit: 'days',
            );
        }

        return $readings;
    }

    // -------------------------------------------------------------------------------------------
    // The last deployment

    /**
     * The most recent recorded deployment, and whether it is the build that is running.
     *
     * Bounded by a window on the indexed `deployed_at` and a single row: this page is opened while
     * something is already wrong, and it may never be the query that makes it worse.
     *
     * @return array<string, mixed>
     */
    private function lastDeployment(?Metric $runningRelease): array
    {
        try {
            $row = $this->reader->connection()->table('monitoring_deployments')
                ->where('deployed_at', '>=', Clock::daysAgo(self::DEPLOYMENT_WINDOW_DAYS))
                ->orderByDesc('deployed_at')
                ->limit(1)
                ->first(['release', 'commit_sha', 'branch', 'environment', 'deployed_by', 'status', 'migrations_run', 'deployed_at']);

            if ($row === null) {
                return [
                    'state' => 'no_data',
                    'note' => 'No deployment has been recorded in the last ' . self::DEPLOYMENT_WINDOW_DAYS . ' days, so nothing here can tie a change in behaviour to a release.',
                    'remedy' => 'Run `php artisan monitoring:deploy-recorded --release=…` as the last step of the deployment script; the timeline and incident sections read the same table.',
                ];
            }

            $running = $runningRelease instanceof Metric && $runningRelease->isOk() ? (string) $runningRelease->value : null;

            return [
                'state' => 'ok',
                'release' => $row->release,
                'commit_sha' => $row->commit_sha,
                'branch' => $row->branch,
                'environment' => $row->environment,
                'deployed_by' => $row->deployed_by,
                'status' => $row->status,
                'migrations_run' => $row->migrations_run === null ? null : (int) $row->migrations_run,
                'deployed_at' => Clock::display($row->deployed_at)->toDateTimeString(),
                // Null rather than false when the running release could not be read: "the record
                // does not match what is running" and "we do not know what is running" are
                // different statements, and only one of them is a reason to go looking.
                'matches_running_release' => $running === null ? null : $running === (string) $row->release,
                'running_release' => $running,
                'source' => 'monitoring_deployments',
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
            ];
        }
    }

    // -------------------------------------------------------------------------------------------
    // Stored gauges

    /**
     * Every php.* gauge in the window, each carrying why it has no line when it has none.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, array<string, mixed>>
     */
    private function gauges(string $range, string $resolution, array $readings): array
    {
        $gauges = [];

        foreach (self::GAUGES as $key => $definition) {
            $live = $readings[$definition['source']] ?? null;

            try {
                $series = $this->reader->series($definition['metric'], $range);
            } catch (\Throwable $exception) {
                // Failing one gauge by name leaves every card above readable, which is more than
                // PanelRegistry can do: it can only blank the section.
                $gauges[$key] = array_merge($definition, [
                    'key' => $key,
                    'state' => 'failed',
                    'note' => Metric::describeFailure($exception),
                    'remedy' => null,
                    'latest' => null,
                    'samples' => 0,
                    'points' => [],
                ]);

                continue;
            }

            $points = array_values(array_filter(
                $series['points'],
                static fn (array $point) => ($point['v'] ?? null) !== null,
            ));

            $gauge = array_merge($definition, [
                'key' => $key,
                'latest' => $series['latest'],
                'samples' => count($points),
                'points' => $points,
            ]);

            // One point is a reading; a line needs two. Saying which of those it is stops a single
            // sample being read as a flat trend.
            $gauges[$key] = count($points) < 2
                ? array_merge($gauge, $this->gaugeGap($resolution, count($points), $live))
                : array_merge($gauge, ['state' => 'ok', 'note' => null, 'remedy' => null]);
        }

        return $gauges;
    }

    /**
     * Why a gauge has no line.
     *
     * Four different silences with four different answers, and the empty chart they all draw looks
     * identical.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function gaugeGap(string $resolution, int $points, ?Metric $live): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no gauge has been sampled since it was disabled.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        if ($live instanceof Metric && !$live->isOk()) {
            // The sampler only stores a reading that is OK, so an unreadable metric has never been
            // written. The gap is this host, not the scheduler, and the reading says which.
            return [
                'state' => $live->state,
                'note' => 'This gauge is only stored while the reading behind it is available, and it is not on this host. '
                    . ($live->note ?? 'The collector returned no value for it.'),
                'remedy' => $live->remedy,
            ];
        }

        if ($resolution !== 'minute') {
            // Gauges are written once a minute. Longer ranges read rolled-up rows, which the rollup
            // produces — so this window can be empty while the minute rows under it are full.
            return [
                'state' => 'no_data',
                'note' => 'This range reads ' . $resolution . ' rows, which the monitoring rollup builds from the minute samples rather than the sampler writing directly.',
                'remedy' => 'Choose a shorter range to read the minute samples, or check the hourly rollup is running: `php artisan schedule:list`.',
            ];
        }

        // Every gauge on this page is sampled by the scheduled CLI process, and the CLI reads its
        // own php.ini. A counter this page can read says nothing about what the sampler can read,
        // so the gap is not blamed on the scheduler alone.
        return [
            'state' => 'no_data',
            'note' => ($points === 1
                ? 'Only one sample has been stored in this window, and one point is not a line.'
                : 'No sample of this gauge has been stored in this window.')
                . ' The sample is taken by a command-line process, which loads a different php.ini from the one serving this page — a counter readable here is not necessarily readable there.',
            'remedy' => 'Gauges are sampled by `php artisan monitoring:flush`, scheduled every minute: check the scheduler is running with `php artisan schedule:list`. For the OPcache counters that sample also needs opcache.enable_cli=1 in the CLI php.ini.',
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Collector readings this page draws nowhere.
     *
     * Normally empty. It exists so a collector that grows a reading cannot have it silently
     * disappear: an undrawn measurement is indistinguishable from an unmeasured one, and that is
     * the confusion this whole system is built to avoid.
     *
     * @param  array<string, Metric>  $readings
     * @return array<int, array{metric: string, state: string}>
     */
    private function unrendered(array $readings): array
    {
        $claimed = [];
        foreach (self::COLLECTOR_GROUPS as $definition) {
            foreach ($definition['metrics'] as $name) {
                $claimed[] = $name;
            }
        }

        $unrendered = [];
        foreach ($readings as $name => $metric) {
            // __collector is the registry's own failure marker; it is reported at the top of the
            // page rather than as a reading the collector produced.
            if ($name === '__collector' || !$metric instanceof Metric || in_array($name, $claimed, true)) {
                continue;
            }

            $unrendered[] = ['metric' => $name, 'state' => $metric->state];
        }

        return $unrendered;
    }

    /** A path as it is written in the repository, so a remedy can be pasted into a shell. */
    private function relative(string $path): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
