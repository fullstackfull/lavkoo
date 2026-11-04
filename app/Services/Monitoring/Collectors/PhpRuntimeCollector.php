<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Environment;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The PHP runtime this shop actually executes on, the accelerator in front of it, and the pool
 * that serves it.
 *
 * Three things this deliberately does NOT do, because each is a standard way a runtime panel
 * misleads the person reading it:
 *
 * 1. It does not describe php.ini. What matters is the configuration this process ended up with
 *    after everything the application did to it at boot — AppServiceProvider sets memory_limit
 *    itself, so the file on disk and the live ceiling disagree. Every directive here is read with
 *    ini_get(), which answers for the running process.
 *
 * 2. It does not treat an unreadable OPcache as an empty one. opcache_get_status() returns false
 *    for three unrelated reasons — the extension is absent, it is switched off for this SAPI, or
 *    opcache.restrict_api forbids this script from asking — and each has a different fix. Any of
 *    them rendered as a 0% hit rate would paint a red number over a cache that is working
 *    perfectly, which is the exact failure this system exists to avoid.
 *
 * 3. It does not infer the pool from php_sapi_name(). The once-a-minute gauge sample runs under
 *    the CLI on machines whose web traffic is served by FPM, so "this process is not FPM" is
 *    evidence about this process and nothing else. Pool state comes from the FPM status page or it
 *    is reported as unconfigured, carrying the pool and nginx lines needed to expose it.
 */
class PhpRuntimeCollector implements Collector
{
    private const RUNTIME_SOURCE = 'PHP runtime';
    private const OPCACHE_SOURCE = 'PHP opcache_get_status()';
    private const FPM_SOURCE = 'PHP-FPM status page';

    private const MEGABYTE = 1048576;

    /** Everything OPcache feeds, so one unreadable status marks exactly these unavailable. */
    private const OPCACHE_METRICS = [
        'opcache_enabled', 'opcache_memory_used', 'opcache_memory_free', 'opcache_wasted',
        'opcache_wasted_pct', 'opcache_hit_rate', 'opcache_hits', 'opcache_misses',
        'opcache_cached_scripts', 'opcache_max_cached_keys', 'opcache_oom_restarts',
        'opcache_hash_restarts', 'opcache_manual_restarts',
    ];

    /** Everything the FPM status page feeds. */
    private const FPM_METRICS = [
        'fpm_pool', 'fpm_active_processes', 'fpm_idle_processes', 'fpm_total_processes',
        'fpm_max_active_processes', 'fpm_listen_queue', 'fpm_max_listen_queue',
        'fpm_listen_queue_len', 'fpm_max_children_reached', 'fpm_slow_requests',
        'fpm_accepted_connections',
    ];

    private array|Metric|null $opcache = null;

    private array|Metric|null $pool = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'php';
    }

    public function collect(): array
    {
        return array_merge(
            $this->runtimeReadings(),
            $this->opcacheReadings(),
            ['is_fpm' => $this->underFpm()],
            $this->fpmReadings(),
        );
    }

    public function gauges(): array
    {
        $collected = $this->collect();

        return array_filter([
            'php.opcache.hit_rate' => $collected['opcache_hit_rate'],
            'php.opcache.memory_used_mb' => $collected['opcache_memory_used'],
            'php.opcache.wasted_pct' => $collected['opcache_wasted_pct'],
            'php.fpm.active_processes' => $collected['fpm_active_processes'],
            'php.fpm.listen_queue' => $collected['fpm_listen_queue'],
        ], fn (Metric $metric) => $metric->isOk() && is_numeric($metric->value));
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<string, Metric> */
    private function runtimeReadings(): array
    {
        return [
            'version' => Metric::of(PHP_VERSION, self::RUNTIME_SOURCE),
            'sapi' => Metric::of(php_sapi_name(), 'PHP php_sapi_name()'),
            'laravel_version' => Metric::probe('Laravel Application::version()', fn () => app()->version()),
            'environment' => Metric::probe('Laravel config app.env', fn () => (string) app()->environment()),
            'debug_mode' => $this->debugMode(),
            'timezone' => $this->timezone(),
            'memory_limit' => $this->memoryLimit(),
            'max_execution_time' => $this->maxExecutionTime(),
            'upload_max_filesize' => $this->uploadMaxFilesize(),
            'post_max_size' => $this->postMaxSize(),
        ];
    }

    /**
     * Whether the application is rendering its own stack traces to visitors.
     *
     * True is a real reading, not a failed probe, so it is reported as a value with the fix beside
     * it rather than as a state — a state would file it among the things monitoring merely could
     * not measure, and this is the single most damaging line an operator can leave in .env.
     */
    private function debugMode(): Metric
    {
        $source = 'Laravel config app.debug';

        return Metric::probe($source, function () use ($source) {
            $debug = (bool) config('app.debug');
            $environment = (string) app()->environment();

            $note = match (true) {
                $debug && app()->isProduction() => 'APP_DEBUG is on in production. Any uncaught exception renders the debug page, which prints .env values, database credentials and payment API keys to whoever triggered it. Set APP_DEBUG=false in .env and run php artisan config:cache.',
                $debug => "Debug is on in the {$environment} environment. Harmless here, and a credential leak the moment this .env is copied to the live site.",
                default => null,
            };

            return Metric::of($debug, $source, null, $note);
        });
    }

    private function timezone(): Metric
    {
        $source = 'Laravel config app.timezone';

        return Metric::probe($source, function () use ($source) {
            $application = (string) config('app.timezone', 'UTC');
            $process = date_default_timezone_get();

            // These two disagreeing is what silently emptied every chart once already; monitoring
            // now stores in UTC through Clock, and the mismatch is stated rather than left to be
            // rediscovered.
            $note = $application === $process
                ? null
                : "PHP's process default is {$process} while app.timezone is {$application}. Monitoring stamps everything in UTC through Clock so the gap cannot move its buckets, but any code that mixes Carbon::now() with a raw timestamp will be off by the difference.";

            return Metric::of($application, $source, null, $note);
        });
    }

    private function memoryLimit(): Metric
    {
        $source = 'PHP ini memory_limit';

        return Metric::probe($source, function () use ($source) {
            $raw = trim((string) ini_get('memory_limit'));
            if ($raw === '') {
                return Metric::noData($source);
            }

            $bytes = $this->shorthandBytes($raw);

            // -1 is a deliberate setting here, not a missing one: AppServiceProvider sets it at
            // boot. It is reported as what it is, with what it costs, because no per-request
            // ceiling means one runaway request can take the whole machine down with it.
            if ($bytes === -1) {
                return Metric::of($raw, $source, null, 'No per-request memory ceiling. A single runaway request can consume every free page on the machine before the kernel OOM killer picks a victim, and the victim it picks is usually MySQL.');
            }

            return Metric::of($raw, $source, null, $bytes === null ? null : round($bytes / self::MEGABYTE, 1) . ' MB per request.');
        });
    }

    private function maxExecutionTime(): Metric
    {
        $source = 'PHP ini max_execution_time';

        return Metric::probe($source, function () use ($source) {
            // ini_get() answers false for a directive that does not exist, and (int) false is 0 —
            // which this metric would then publish as the meaningful reading "no time limit".
            $raw = ini_get('max_execution_time');
            if ($raw === false || trim((string) $raw) === '') {
                return Metric::noData($source);
            }

            $seconds = (int) $raw;

            // Zero is a reading, not a blank. It means no limit, which is the CLI default and the
            // reason a queue worker may run for hours where a web request may not.
            return Metric::of($seconds, $source, 'seconds', $seconds === 0
                ? 'No execution time limit, which is the default for this SAPI rather than a web request timeout.'
                : null);
        });
    }

    private function uploadMaxFilesize(): Metric
    {
        $source = 'PHP ini upload_max_filesize';

        return Metric::probe($source, function () use ($source) {
            $raw = trim((string) ini_get('upload_max_filesize'));

            return $raw === '' ? Metric::noData($source) : Metric::of($raw, $source);
        });
    }

    private function postMaxSize(): Metric
    {
        $source = 'PHP ini post_max_size';

        return Metric::probe($source, function () use ($source) {
            $raw = trim((string) ini_get('post_max_size'));
            if ($raw === '') {
                return Metric::noData($source);
            }

            $upload = trim((string) ini_get('upload_max_filesize'));
            $postBytes = $this->shorthandBytes($raw);
            $uploadBytes = $this->shorthandBytes($upload);

            // The classic silent upload failure: a file within upload_max_filesize but larger than
            // the POST body carrying it is discarded by PHP before Laravel sees the request, so the
            // merchant gets an empty form back and no error anywhere.
            $note = $postBytes !== null && $uploadBytes !== null && $postBytes > 0 && $uploadBytes > $postBytes
                ? "post_max_size ({$raw}) is smaller than upload_max_filesize ({$upload}), so uploads between the two sizes are dropped before any validator runs. Raise post_max_size above upload_max_filesize in php.ini and reload PHP."
                : null;

            return Metric::of($raw, $source, null, $note);
        });
    }

    /**
     * PHP's shorthand size notation, resolved the way PHP itself resolves it.
     *
     * A unit-less value means BYTES. The application's own convertIniSizeToMB() reads a unit-less
     * value as megabytes, which would render a limit written as 134217728 as 134 GB — wrong in the
     * direction that looks reassuring.
     */
    private function shorthandBytes(string $value): ?int
    {
        if (preg_match('/^(-?\d+)\s*([KMG])?$/i', trim($value), $match) !== 1) {
            return null;
        }

        $number = (int) $match[1];
        if ($number < 0) {
            return -1;
        }

        return match (strtoupper($match[2] ?? '')) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<string, Metric> */
    private function opcacheReadings(): array
    {
        $status = $this->opcacheStatus();
        if ($status instanceof Metric) {
            return array_fill_keys(self::OPCACHE_METRICS, $status);
        }

        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];

        $megabytes = fn (?float $bytes) => $bytes === null ? null : round($bytes / self::MEGABYTE, 1);
        $wasted = $this->number($memory, 'current_wasted_percentage');
        $wastedCeiling = $this->wastedCeiling();
        $oomRestarts = $this->integer($statistics, 'oom_restarts');
        $hashRestarts = $this->integer($statistics, 'hash_restarts');

        return [
            'opcache_enabled' => array_key_exists('opcache_enabled', $status)
                ? Metric::of((bool) $status['opcache_enabled'], self::OPCACHE_SOURCE)
                : Metric::noData(self::OPCACHE_SOURCE),
            'opcache_memory_used' => Metric::of($megabytes($this->number($memory, 'used_memory')), self::OPCACHE_SOURCE, 'MB'),
            'opcache_memory_free' => Metric::of($megabytes($this->number($memory, 'free_memory')), self::OPCACHE_SOURCE, 'MB'),
            'opcache_wasted' => Metric::of($megabytes($this->number($memory, 'wasted_memory')), self::OPCACHE_SOURCE, 'MB', 'Memory held by scripts that have since been invalidated; it is only reclaimed by a full restart.'),
            'opcache_wasted_pct' => Metric::of($wasted === null ? null : round($wasted, 1), self::OPCACHE_SOURCE, '%', $wasted !== null && $wastedCeiling !== null && $wasted >= $wastedCeiling
                ? "Wasted memory has reached opcache.max_wasted_percentage ({$wastedCeiling}%), so OPcache is restarting itself and recompiling every file each time. Raise opcache.memory_consumption in php.ini and reload PHP."
                : null),
            'opcache_hit_rate' => $this->opcacheHitRate($statistics),
            'opcache_hits' => Metric::of($this->integer($statistics, 'hits'), self::OPCACHE_SOURCE, null, 'Lookups served from the cache since the last restart, not a rate.'),
            'opcache_misses' => Metric::of($this->integer($statistics, 'misses'), self::OPCACHE_SOURCE, null, 'Compilations since the last restart, not a rate.'),
            'opcache_cached_scripts' => Metric::of($this->integer($statistics, 'num_cached_scripts'), self::OPCACHE_SOURCE, 'scripts'),
            'opcache_max_cached_keys' => Metric::of($this->integer($statistics, 'max_cached_keys'), self::OPCACHE_SOURCE, 'keys', 'The ceiling derived from opcache.max_accelerated_files; scripts past it are compiled on every request.'),
            'opcache_oom_restarts' => Metric::of($oomRestarts, self::OPCACHE_SOURCE, null, $oomRestarts !== null && $oomRestarts > 0
                ? 'OPcache has exhausted its shared memory and flushed itself, recompiling the whole application each time. Raise opcache.memory_consumption in php.ini and reload PHP.'
                : null),
            'opcache_hash_restarts' => Metric::of($hashRestarts, self::OPCACHE_SOURCE, null, $hashRestarts !== null && $hashRestarts > 0
                ? 'The script hash table filled up and forced a restart. Raise opcache.max_accelerated_files above the number of PHP files this application loads.'
                : null),
            'opcache_manual_restarts' => Metric::of($this->integer($statistics, 'manual_restarts'), self::OPCACHE_SOURCE, null, 'Restarts triggered by opcache_reset(), typically a deployment.'),
        ];
    }

    /**
     * The percentage at which this pool restarts itself, as configured rather than as defaulted.
     *
     * The directive defaults to 5, and asserting that default at a host that raised it would put
     * "OPcache is restarting itself" under a number that is nowhere near the real threshold.
     */
    private function wastedCeiling(): ?float
    {
        $configured = ini_get('opcache.max_wasted_percentage');

        return is_numeric($configured) ? (float) $configured : null;
    }

    /**
     * The share of lookups that avoided a compile, since the last restart.
     *
     * This is a ratio rather than a per-second rate, so it needs no delta — but it does need a
     * denominator. A pool that has restarted this second has served no lookups at all, and the 0
     * OPcache reports for that is indistinguishable from a cache that is missing everything.
     */
    private function opcacheHitRate(array $statistics): Metric
    {
        $hits = $this->integer($statistics, 'hits');
        $misses = $this->integer($statistics, 'misses');

        if ($hits === null || $misses === null) {
            return Metric::noData(self::OPCACHE_SOURCE);
        }
        if ($hits + $misses === 0) {
            return Metric::noData(self::OPCACHE_SOURCE, 'No script lookups since the last OPcache restart, so there is no ratio to report yet.');
        }

        return Metric::of(round(100 * $hits / ($hits + $misses), 2), self::OPCACHE_SOURCE, '%');
    }

    /** @return array<string, mixed>|Metric */
    private function opcacheStatus(): array|Metric
    {
        if ($this->opcache === null) {
            try {
                $this->opcache = $this->readOpcacheStatus();
            } catch (\Throwable $exception) {
                $this->opcache = Metric::failed(self::OPCACHE_SOURCE, $exception);
            }
        }

        return $this->opcache;
    }

    /**
     * OPcache's own numbers, or the reason there are none.
     *
     * @return array<string, mixed>|Metric
     */
    private function readOpcacheStatus(): array|Metric
    {
        if (!$this->environment->has('opcache')) {
            return Metric::notSupported(
                self::OPCACHE_SOURCE,
                'The OPcache extension is not loaded, so every request recompiles the application from source.',
                'Install it and add zend_extension=opcache.so plus opcache.enable=1 to php.ini, then reload PHP.',
            );
        }

        // Suppressed because opcache.restrict_api reports refusal as a warning and a false return;
        // the warning would land in the log on every dashboard refresh, and the refusal is handled
        // properly below.
        $status = @opcache_get_status(include_scripts: false);
        if (is_array($status)) {
            return $status;
        }

        $sapi = php_sapi_name();
        $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
        $enabledCli = filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN);

        if (!$enabled) {
            return Metric::notConfigured(
                self::OPCACHE_SOURCE,
                'Set opcache.enable=1 (and opcache.memory_consumption=192, opcache.max_accelerated_files=20000) in php.ini, then reload PHP.',
                'OPcache is loaded but switched off, so every request compiles the application from source.',
            );
        }

        if (in_array($sapi, ['cli', 'phpdbg'], true) && !$enabledCli) {
            return Metric::notConfigured(
                self::OPCACHE_SOURCE,
                "Read this page from a web request, or set opcache.enable_cli=1 in php.ini so the once-a-minute {$sapi} sample can read the same statistics.",
                "OPcache is enabled for web requests but not for the {$sapi} SAPI this process runs under, so its counters cannot be read from here. This says nothing about the pool serving the site.",
            );
        }

        $restrictApi = trim((string) ini_get('opcache.restrict_api'));
        if ($restrictApi !== '' && !str_starts_with(__FILE__, $restrictApi)) {
            return Metric::permissionDenied(
                self::OPCACHE_SOURCE,
                "opcache.restrict_api is set to '{$restrictApi}', and this collector runs from " . __FILE__ . ', so PHP refuses to answer.',
                'Set opcache.restrict_api=' . base_path() . ' in php.ini (or remove the directive) and reload PHP.',
            );
        }

        return Metric::noData(self::OPCACHE_SOURCE, 'OPcache is enabled for this SAPI but returned no status.');
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Whether the counters below describe the process asking for them.
     */
    private function underFpm(): Metric
    {
        $source = 'PHP php_sapi_name()';
        $sapi = php_sapi_name();
        $underFpm = $this->environment->has('fpm');

        return Metric::of($underFpm, $source, null, $underFpm
            ? null
            : "This process runs under the {$sapi} SAPI, so no pool counters belong to it. That is not evidence the site has no FPM pool — the scheduled sample runs from the CLI on servers whose web traffic is served by FPM, which is why the pool is read from its status page instead of guessed at from here.");
    }

    /** @return array<string, Metric> */
    private function fpmReadings(): array
    {
        $pool = $this->fpmStatus();
        if ($pool instanceof Metric) {
            return array_fill_keys(self::FPM_METRICS, $pool);
        }

        $maxChildrenReached = $this->integer($pool, 'max children reached');
        $slowRequests = $this->integer($pool, 'slow requests');
        $queue = $this->queueReadings($pool);

        return [
            'fpm_pool' => Metric::of($pool['pool'] ?? null, self::FPM_SOURCE),
            'fpm_active_processes' => Metric::of($this->integer($pool, 'active processes'), self::FPM_SOURCE, 'processes'),
            'fpm_idle_processes' => Metric::of($this->integer($pool, 'idle processes'), self::FPM_SOURCE, 'processes'),
            'fpm_total_processes' => Metric::of($this->integer($pool, 'total processes'), self::FPM_SOURCE, 'processes'),
            'fpm_max_active_processes' => Metric::of($this->integer($pool, 'max active processes'), self::FPM_SOURCE, 'processes', 'The busiest the pool has been since it started.'),
            'fpm_listen_queue' => $queue['listen_queue'],
            'fpm_max_listen_queue' => $queue['max_listen_queue'],
            'fpm_listen_queue_len' => $queue['listen_queue_len'],
            'fpm_max_children_reached' => Metric::of($maxChildrenReached, self::FPM_SOURCE, null, $maxChildrenReached !== null && $maxChildrenReached > 0
                ? 'The pool has hit pm.max_children and queued requests while it did. Raise pm.max_children in the pool config, having checked the box has the RAM for the extra children.'
                : null),
            'fpm_slow_requests' => Metric::of($slowRequests, self::FPM_SOURCE, null, $slowRequests !== null && $slowRequests > 0
                ? 'Requests have exceeded request_slowlog_timeout; their stack traces are already written to the pool\'s slowlog.'
                : null),
            // Deliberately not divided by "start since": that would report the pool's lifetime
            // average as if it were current throughput.
            'fpm_accepted_connections' => Metric::of($this->integer($pool, 'accepted conn'), self::FPM_SOURCE, null, 'Connections accepted since the pool started — a counter, not a rate.'),
        ];
    }

    /**
     * The three socket-queue numbers, which FPM can only sometimes measure.
     *
     * FPM reads the backlog out of the kernel with TCP_INFO, and the kernel answers that only for
     * a TCP listener. On a UNIX socket — nginx's default and this application's likely setup — the
     * call fails and FPM writes a plain 0 into all three fields. Published as readings they draw a
     * queue that is never busy, so the one chart that would show requests piling up in front of
     * the pool is a flat line on exactly the pools where the pile-up is otherwise invisible. The
     * backlog capacity is the tell: no pool listens with a backlog of nothing.
     *
     * @param  array<string, mixed>  $pool
     * @return array<string, Metric>
     */
    private function queueReadings(array $pool): array
    {
        $capacity = $this->integer($pool, 'listen queue len');

        if ($capacity === null || $capacity === 0) {
            $unmeasurable = Metric::notSupported(
                self::FPM_SOURCE,
                'This pool listens on a UNIX socket, where the kernel does not report the backlog, so FPM publishes its three queue counters as zero whatever the real depth is.',
                'Give the pool a TCP listener (listen = 127.0.0.1:9000 in ' . $this->poolConfigPath() . ', with a matching fastcgi_pass) if the queue depth is worth measuring.',
            );

            return array_fill_keys(['listen_queue', 'max_listen_queue', 'listen_queue_len'], $unmeasurable);
        }

        $waiting = $this->integer($pool, 'listen queue');

        return [
            'listen_queue' => Metric::of($waiting, self::FPM_SOURCE, 'requests', $waiting !== null && $waiting > 0
                ? 'Requests are waiting on the socket for a free child right now. Every one of them is already counting against the visitor\'s page load.'
                : null),
            'max_listen_queue' => Metric::of($this->integer($pool, 'max listen queue'), self::FPM_SOURCE, 'requests', 'The deepest that queue has been since the pool started.'),
            'listen_queue_len' => Metric::of($capacity, self::FPM_SOURCE, 'requests', 'Capacity of the socket backlog; requests arriving past it are refused outright.'),
        ];
    }

    /** @return array<string, mixed>|Metric */
    private function fpmStatus(): array|Metric
    {
        if ($this->pool === null) {
            try {
                $this->pool = $this->readFpmStatus();
            } catch (\Throwable $exception) {
                // Monitoring must not be the thing that breaks the page it is drawn on, and the
                // work below reaches configuration, the network and a JSON body — three places
                // this collector does not own.
                $this->pool = Metric::failed(self::FPM_SOURCE, $exception);
            }
        }

        return $this->pool;
    }

    /**
     * The pool's own numbers, fetched over its status page.
     *
     * Nothing on the machine can be asked for these — not /proc, not the SAPI — so the status page
     * is the whole story. It is also a network call made while a dashboard is rendering, which is
     * why the timeouts are short and every failure returns a Metric: an unreachable pool must cost
     * two seconds and a red panel, never a hung page.
     *
     * @return array<string, mixed>|Metric
     */
    private function readFpmStatus(): array|Metric
    {
        // config() returns whatever is in the file, and casting a non-string to string is a fatal
        // rather than a bad reading — an array left here took the whole dashboard down, not just
        // this panel.
        $configured = config('monitoring.php_fpm_status_url');
        $url = is_string($configured) ? trim($configured) : '';

        if ($url === '') {
            return $configured === null || $configured === ''
                ? Metric::notConfigured(
                    self::FPM_SOURCE,
                    $this->fpmRemedy(),
                    'PHP-FPM publishes pool state only over its status page, and no status URL is set.',
                )
                : $this->malformedUrl();
        }

        // A URL the HTTP client cannot use comes back as an exception quoting the string it was
        // given, which is both unhelpful and the one place a credentialed URL could escape into a
        // note. The scheme is checked too: filter_var passes gopher:// and ftp:// happily, and the
        // client's refusal of them reads as a pool that is down.
        if (!$this->isHttpUrl($url)) {
            return $this->malformedUrl();
        }

        // Notes are stored and drawn on a page, and a status URL may carry basic-auth credentials.
        // The same redactor the request pipeline uses strips them, here and out of anything the
        // HTTP client quotes back.
        $safeUrl = Redactor::make()->url($url);

        // pm.status_path answers HTML unless the json flag is on the query string, and the operator
        // may have put it there already. Matching on the query rather than on the whole URL keeps
        // a host or path that merely contains "json" from suppressing the flag.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $endpoint = array_key_exists('json', $query) ? $url : $url . ($query === [] ? '?' : '&') . 'json';

        try {
            $response = Http::connectTimeout(1)->timeout(2)->acceptJson()->get($endpoint);
        } catch (ConnectionException $exception) {
            return Metric::collectorOffline(
                self::FPM_SOURCE,
                "The status page at {$safeUrl} did not answer: " . $this->withoutCredentials($exception->getMessage()),
                'Check that php-fpm is running (systemctl status php' . $this->phpSeries() . '-fpm) and that the status location is reachable from this host.',
            );
        } catch (\Throwable $exception) {
            return Metric::failed(self::FPM_SOURCE, $exception);
        }

        if (!$response->successful()) {
            return Metric::notConfigured(
                self::FPM_SOURCE,
                $this->statusRemedy($response->status(), $safeUrl),
                'MONITORING_PHP_FPM_STATUS_URL is set but the status page returned HTTP ' . $response->status() . '.',
            );
        }

        $payload = $response->json();
        if (!is_array($payload) || !array_key_exists('pool', $payload)) {
            return Metric::notConfigured(
                self::FPM_SOURCE,
                'Point MONITORING_PHP_FPM_STATUS_URL at pm.status_path itself; the collector appends ?json to it.',
                'The URL answered, but not with FPM status JSON — it is reaching something other than the pool status page.',
            );
        }

        return $payload;
    }

    /**
     * Each way the status URL answers wrongly has its own fix, and "check the access rules" is
     * none of them: a 404 is a routing mistake, a 403 is the allow list, a 502 is the pool itself.
     */
    private function statusRemedy(int $status, string $safeUrl): string
    {
        return match (true) {
            $status === 404 => 'The web server does not route ' . $safeUrl . ' to the pool. ' . $this->fpmRemedy(),
            $status === 401 || $status === 403 => 'The status location refuses this request. Give it "allow 127.0.0.1; deny all;" with no auth_basic, and set MONITORING_PHP_FPM_STATUS_URL to the 127.0.0.1 form so the request comes from the machine itself.',
            $status >= 500 => 'The web server reached the status location but not the pool behind it. Check php-fpm is running (systemctl status php' . $this->phpSeries() . '-fpm) and that fastcgi_pass in that location matches the listen = line in ' . $this->poolConfigPath() . '.',
            default => 'Something in front of the pool answered instead of it — a redirect, a cache or an auth gate on the path. Point MONITORING_PHP_FPM_STATUS_URL straight at pm.status_path on 127.0.0.1.',
        };
    }

    private function malformedUrl(): Metric
    {
        return Metric::notConfigured(
            self::FPM_SOURCE,
            'Set MONITORING_PHP_FPM_STATUS_URL to a full http:// or https:// URL, for example http://127.0.0.1/fpm-status.',
            'MONITORING_PHP_FPM_STATUS_URL is not an http(s) URL, so no request was made.',
        );
    }

    private function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function fpmRemedy(): string
    {
        return 'Set pm.status_path = /fpm-status in the pool config (' . $this->poolConfigPath() . ') and reload php-fpm. '
            . 'Expose it to this host only — nginx: location = /fpm-status { access_log off; allow 127.0.0.1; deny all; include fastcgi_params; '
            . 'fastcgi_param SCRIPT_FILENAME $fastcgi_script_name; fastcgi_pass unix:/run/php/php' . $this->phpSeries() . '-fpm.sock; } '
            . 'Then set MONITORING_PHP_FPM_STATUS_URL=http://127.0.0.1/fpm-status in .env.';
    }

    private function poolConfigPath(): string
    {
        return '/etc/php/' . $this->phpSeries() . '/fpm/pool.d/www.conf';
    }

    /** The running major.minor, so the paths in a remedy match the PHP that is actually installed. */
    private function phpSeries(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * A message from the HTTP client, safe to store.
     *
     * The client quotes the URL it was given back into its error text, and it masks the password
     * there but keeps the username. The userinfo is dropped outright before the redactor handles
     * everything that is not part of a URL.
     */
    private function withoutCredentials(string $text): string
    {
        return Redactor::make()->text(
            (string) preg_replace('#([a-z][a-z0-9+.\-]*://)[^/@\s]+@#i', '$1', $text),
        );
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A numeric field, or null when the source did not carry it.
     *
     * Absent is not zero: a missing "listen queue" means the status page did not report one, and
     * casting that to 0 would draw an empty queue on the chart.
     *
     * @param  array<string, mixed>  $source
     */
    private function number(array $source, string $key): ?float
    {
        $value = $source[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $source */
    private function integer(array $source, string $key): ?int
    {
        $value = $this->number($source, $key);

        return $value === null ? null : (int) $value;
    }
}
