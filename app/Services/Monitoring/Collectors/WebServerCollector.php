<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Environment;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The server in front of PHP: which one is actually serving, and what it is doing right now.
 *
 * Three things this deliberately does NOT do, because each is a standard way a web server panel
 * misleads the person reading it:
 *
 * 1. It does not answer "which web server" from the SAPI alone. The once-a-minute gauge sample
 *    runs under the CLI, where SERVER_SOFTWARE does not exist and php_sapi_name() describes this
 *    process and nothing else — so detection falls through to the process table, and a box running
 *    `php artisan serve` is named as the development server it is. Reporting a development server
 *    as an nginx that fails every probe would put a page of red on a machine that is working
 *    exactly as intended.
 *
 * 2. It does not divide since-start counters by uptime. stub_status `requests` and mod_status
 *    `Total Accesses` both climb from the moment the server started, and Apache even hands over a
 *    ready-made `ReqPerSec` computed that way — the server's lifetime average dressed as current
 *    load, a number that stays calm through the busiest hour it has ever had. Every rate here is a
 *    delta against a cached previous reading, and the first sample says so rather than guessing.
 *
 * 3. It does not go blank when no status endpoint is configured, which is most deployments. The
 *    status-class mix and the request rate come from the shop's own per-minute buckets, measured
 *    on the way out of PHP. Those two sources are never mixed under one name: nginx counts every
 *    static asset it answered by itself, PHP counts only what reached PHP, and folding one into
 *    the other would put a step change in a chart the day someone exposes stub_status.
 */
class WebServerCollector implements Collector
{
    private const NGINX = 'nginx';
    private const APACHE = 'apache';
    private const DEVELOPMENT = 'development';
    private const UNKNOWN = 'unknown';

    private const NGINX_SOURCE = 'nginx stub_status';
    private const APACHE_SOURCE = 'Apache mod_status ?auto';
    private const BUCKET_SOURCE = 'monitoring_request_buckets';

    private const PREVIOUS_KEY = 'monitoring:webserver:previous';
    private const SAMPLE_TTL_SECONDS = 600;

    /** Below this the window is short enough that one health check decides the whole rate. */
    private const MIN_INTERVAL_SECONDS = 1.0;

    private const TRAFFIC_WINDOW_MINUTES = 5;

    /** Everything the upstream status endpoint feeds, so one unavailable fetch marks exactly these. */
    private const STATUS_METRICS = [
        'active_connections', 'accepts', 'handled', 'dropped_connections', 'requests',
        'requests_per_s', 'accepted_per_s', 'reading', 'writing', 'waiting',
        'busy_workers', 'idle_workers', 'worker_saturation_pct',
    ];

    /** Everything the request buckets feed. */
    private const TRAFFIC_METRICS = [
        'app_requests_5m', 'app_requests_per_s', 'app_status_mix',
        'app_4xx_rate_pct', 'app_5xx_rate_pct', 'app_timeouts',
    ];

    /** @var array{name: ?string, type: string, detected_by: string, note: ?string}|null */
    private ?array $identity = null;

    /** @var array<string, Metric>|null */
    private ?array $status = null;

    /** @var array<string, Metric>|null */
    private ?array $traffic = null;

    /** Everything identityReadings() answers, so one throw in detection marks exactly these. */
    private const IDENTITY_METRICS = ['server', 'server_type', 'sapi', 'is_development_server'];

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'webserver';
    }

    public function collect(): array
    {
        return array_merge(
            $this->identityReadings(),
            $this->statusReadings(),
            $this->trafficReadings(),
        );
    }

    public function gauges(): array
    {
        $collected = $this->collect();

        return array_filter([
            'webserver.active_connections' => $collected['active_connections'],
            'webserver.requests_per_s' => $collected['requests_per_s'],
            'webserver.waiting' => $collected['waiting'],
        ], fn (Metric $metric) => $metric->isOk() && is_numeric($metric->value));
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<string, Metric> */
    private function identityReadings(): array
    {
        return $this->guard(fn () => $this->readIdentity(), self::IDENTITY_METRICS, 'Linux process table (ps)');
    }

    /**
     * A group of readings, or the same keys all reporting the throw.
     *
     * The contract is that nothing here reaches the page it is drawn on as an exception, and the
     * three groups fail independently: an unreadable bucket table must not also blank the name of
     * the web server, which needs no database at all.
     *
     * @param  callable(): array<string, Metric>  $readings
     * @param  array<int, string>  $keys
     * @return array<string, Metric>
     */
    private function guard(callable $readings, array $keys, string $source): array
    {
        try {
            return $readings();
        } catch (\Throwable $exception) {
            return array_fill_keys($keys, Metric::failed($source, $exception));
        }
    }

    /** @return array<string, Metric> */
    private function readIdentity(): array
    {
        $identity = $this->detect();
        $source = $identity['detected_by'];

        return [
            'server' => $identity['name'] === null
                ? Metric::noData($source, $identity['note'])
                : Metric::of($identity['name'], $source, null, $identity['note']),
            'server_type' => Metric::of($identity['type'], $source),
            'sapi' => Metric::of(php_sapi_name(), 'PHP php_sapi_name()'),
            // A flat `false` here reads as "this is a production server", which is a different
            // claim from "nothing that serves could be found" — and the second is what we have
            // whenever the name is unknown.
            'is_development_server' => $identity['name'] === null
                ? Metric::noData($source, 'What serves this site could not be identified, so whether it is a development server is not known either.')
                : Metric::of(
                    $identity['type'] === self::DEVELOPMENT,
                    $source,
                    null,
                    $identity['type'] === self::DEVELOPMENT
                        ? 'php artisan serve runs one request at a time in a single process, keeps no connection counters and has no status endpoint. It is a development server; nothing measured here describes production.'
                        : null,
                ),
        ];
    }

    /**
     * Which web server is serving this site, and how we know.
     *
     * The three sources are tried in order of how directly they answer the question, not in order
     * of convenience: what the server told PHP about itself, then what this SAPI implies, then
     * what is running on the box. fpm-fcgi and cgi-fcgi are skipped at the SAPI step on purpose —
     * they prove PHP sits behind *something* and say nothing about which, so answering from them
     * would be a guess wearing a source label.
     *
     * @return array{name: ?string, type: string, detected_by: string, note: ?string}
     */
    private function detect(): array
    {
        if ($this->identity !== null) {
            return $this->identity;
        }

        $advertised = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if ($advertised !== '') {
            return $this->identity = [
                'name' => $advertised,
                'type' => $this->classify($advertised),
                'detected_by' => "PHP \$_SERVER['SERVER_SOFTWARE']",
                'note' => null,
            ];
        }

        $sapi = php_sapi_name();
        $fromSapi = match ($sapi) {
            'cli-server' => ['PHP ' . PHP_VERSION . ' Development Server', self::DEVELOPMENT],
            'apache2handler', 'apache2filter', 'apache' => ['Apache (mod_php)', self::APACHE],
            'litespeed' => ['LiteSpeed', self::UNKNOWN],
            default => null,
        };

        if ($fromSapi !== null) {
            return $this->identity = [
                'name' => $fromSapi[0],
                'type' => $fromSapi[1],
                'detected_by' => 'PHP php_sapi_name()',
                'note' => null,
            ];
        }

        return $this->identity = $this->fromProcessTable($sapi);
    }

    private function classify(string $software): string
    {
        $lower = strtolower($software);

        return match (true) {
            str_contains($lower, 'nginx') => self::NGINX,
            str_contains($lower, 'apache'), str_contains($lower, 'httpd') => self::APACHE,
            str_contains($lower, 'development server') => self::DEVELOPMENT,
            default => self::UNKNOWN,
        };
    }

    /**
     * What is serving, read off the machine rather than off this process.
     *
     * This is the path the scheduled sample takes, and the only one that can tell an operator
     * anything at all from a CLI run.
     *
     * @return array{name: ?string, type: string, detected_by: string, note: ?string}
     */
    private function fromProcessTable(string $sapi): array
    {
        $source = 'Linux process table (ps)';
        $blind = "This process runs under the {$sapi} SAPI, which serves nothing and carries no SERVER_SOFTWARE, ";

        if (!$this->environment->has('shell')) {
            return [
                'name' => null,
                'type' => self::UNKNOWN,
                'detected_by' => $source,
                'note' => $blind . 'and shell functions are disabled here, so the process table cannot be read either. Open the dashboard in a browser: served over HTTP, the web server names itself in SERVER_SOFTWARE.',
            ];
        }

        $processes = @shell_exec('ps -eo comm=,args= 2>/dev/null');
        if (!is_string($processes) || trim($processes) === '') {
            return [
                'name' => null,
                'type' => self::UNKNOWN,
                'detected_by' => $source,
                'note' => $blind . 'and ps returned nothing, so what serves this site could not be determined from here.',
            ];
        }

        $found = [];
        foreach (preg_split('/\R/', $processes) ?: [] as $line) {
            // `comm` is an executable basename and never contains a space, so the first run of
            // whitespace is the column break. Daemons are matched on comm rather than on the full
            // command line, so that `tail -f /var/log/nginx/error.log` is not read as an nginx.
            $columns = preg_split('/\s+/', trim($line), 2);
            $command = $columns[0] ?? '';
            $arguments = $columns[1] ?? '';

            if ($command === 'nginx') {
                $found[self::NGINX] = 'nginx';
            } elseif ($command === 'apache2' || $command === 'httpd') {
                $found[self::APACHE] = $command === 'httpd' ? 'httpd (Apache)' : 'Apache (apache2)';
            } elseif (str_starts_with($command, 'php') && preg_match('/\bartisan\b.*\bserve\b|\bphp[0-9.]*\s+-S\s/', $arguments) === 1) {
                $found[self::DEVELOPMENT] = 'PHP ' . PHP_VERSION . ' Development Server (php artisan serve)';
            }
        }

        // A real server outranks the development one: on a box where both are up, nginx is what
        // the public reaches and artisan serve is a leftover terminal.
        foreach ([self::NGINX, self::APACHE, self::DEVELOPMENT] as $type) {
            if (isset($found[$type])) {
                return [
                    'name' => $found[$type],
                    'type' => $type,
                    'detected_by' => $source,
                    'note' => count($found) > 1
                        ? 'More than one server process is running on this host (' . implode(', ', $found) . '); the one reported is the one that normally fronts the others.'
                        : $blind . 'so this was read from the process table instead.',
                ];
            }
        }

        return [
            'name' => null,
            'type' => self::UNKNOWN,
            'detected_by' => $source,
            'note' => $blind . 'and no nginx, apache2 or httpd process is running on this host. Either the site is fronted by a web server on another machine, or this box is not serving it.',
        ];
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<string, Metric> */
    private function statusReadings(): array
    {
        return $this->status ??= $this->guard(
            fn () => $this->readStatus(),
            array_merge(self::STATUS_METRICS, ['status_endpoint']),
            self::NGINX_SOURCE,
        );
    }

    /** @return array<string, Metric> */
    private function readStatus(): array
    {
        $status = $this->fetchStatus();
        if ($status instanceof Metric) {
            return array_merge(
                array_fill_keys(self::STATUS_METRICS, $status),
                ['status_endpoint' => $status],
            );
        }

        $readings = $status['format'] === self::NGINX
            ? $this->nginxReadings($status['body'], $status['url'])
            : $this->apacheReadings($status['fields'], $status['url']);

        return array_merge($readings, [
            'status_endpoint' => Metric::of($status['url'], $status['source'], null, 'The endpoint these connection counters were read from.'),
        ]);
    }

    /**
     * The status body, or the reason there is not one.
     *
     * This is a network call made while a dashboard renders, which is why the timeouts are short
     * and every failure comes back as a Metric: an unreachable status endpoint must cost two
     * seconds and one red panel, never a hung page.
     *
     * @return array{format: string, source: string, url: string, body: string, fields: array<string, string>}|Metric
     */
    private function fetchStatus(): array|Metric
    {
        $url = $this->configString('monitoring.nginx_status_url');

        if ($url === '') {
            return $this->detect()['type'] === self::DEVELOPMENT
                ? Metric::notSupported(
                    'PHP built-in development server',
                    'The PHP development server behind php artisan serve has no status endpoint and keeps no connection, worker or request counters — there is nothing here to expose. These numbers exist only under nginx, Apache or another production server.',
                )
                : Metric::notConfigured(
                    self::NGINX_SOURCE,
                    $this->statusRemedy(),
                    'Connection counters live only on the web server\'s own status endpoint, and no status URL is set.',
                );
        }

        // A URL the HTTP client cannot parse comes back as an exception quoting the string it was
        // given, which is both unhelpful and the one place a credentialed URL could escape into a
        // stored note. It is answered as the configuration mistake it is instead.
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return Metric::notConfigured(
                self::NGINX_SOURCE,
                'Set MONITORING_NGINX_STATUS_URL to a full URL, for example http://127.0.0.1/nginx_status.',
                'MONITORING_NGINX_STATUS_URL is set to something that is not a URL.',
            );
        }

        // Notes are stored and drawn on a page, and a status URL may carry basic-auth credentials.
        $safeUrl = Redactor::make()->url($url);

        // stub_status ignores the query string outright, while mod_status returns an HTML page
        // without ?auto — so one request shape serves both, and the operator does not have to
        // remember which server they are on when they set the variable.
        $endpoint = str_contains($url, '?') ? $url : $url . '?auto';

        try {
            $response = Http::connectTimeout(1)->timeout(2)->get($endpoint);
        } catch (ConnectionException $exception) {
            return Metric::collectorOffline(
                self::NGINX_SOURCE,
                "The status endpoint at {$safeUrl} did not answer: " . $this->withoutCredentials($exception->getMessage()),
                'Check that the web server is running (systemctl status nginx, or systemctl status apache2) and that the status location is reachable from this host.',
            );
        } catch (\Throwable $exception) {
            // Metric::failed keeps an exception message word for word, and the HTTP client quotes
            // the URL it was handed — userinfo included — into several of them. The ConnectionException
            // above is only the common case; anything else reaching a stored note is scrubbed here.
            return Metric::failed(self::NGINX_SOURCE, new \RuntimeException(
                class_basename($exception) . ': ' . $this->withoutCredentials($exception->getMessage()),
                previous: $exception,
            ));
        }

        if (!$response->successful()) {
            return $this->unsuccessful($response->status(), $safeUrl);
        }

        $body = (string) $response->body();

        if (str_contains($body, 'Active connections:')) {
            return ['format' => self::NGINX, 'source' => self::NGINX_SOURCE, 'url' => $safeUrl, 'body' => $body, 'fields' => []];
        }

        $fields = $this->autoFields($body);
        if (isset($fields['busyworkers']) || isset($fields['totalaccesses']) || isset($fields['scoreboard'])) {
            return ['format' => self::APACHE, 'source' => self::APACHE_SOURCE, 'url' => $safeUrl, 'body' => $body, 'fields' => $fields];
        }

        if (stripos($body, '<html') !== false || stripos($body, 'Apache Server Status') !== false) {
            return Metric::notConfigured(
                self::APACHE_SOURCE,
                'Point MONITORING_NGINX_STATUS_URL directly at the status location — the collector appends ?auto itself — and make sure nothing between here and Apache strips the query string.',
                "{$safeUrl} answered with mod_status' HTML page rather than its machine-readable ?auto format.",
            );
        }

        return Metric::notConfigured(
            self::NGINX_SOURCE,
            $this->statusRemedy(),
            "{$safeUrl} answered, but not with an nginx stub_status or Apache mod_status body — it is reaching something other than the status endpoint.",
        );
    }

    private function unsuccessful(int $status, string $safeUrl): Metric
    {
        if ($status === 401 || $status === 403) {
            return Metric::permissionDenied(
                self::NGINX_SOURCE,
                "{$safeUrl} answered HTTP {$status}: the status endpoint exists but refuses this host.",
                $status === 401
                    ? 'Put the credentials in the URL itself — MONITORING_NGINX_STATUS_URL=http://user:password@127.0.0.1/nginx_status — or drop the auth requirement and restrict the location by address instead.'
                    : 'Add the address this request comes from to the allow list: location /nginx_status { stub_status; allow 127.0.0.1; deny all; } on nginx, or Require local inside <Location /server-status> on Apache. Then reload the web server.',
            );
        }

        return Metric::notConfigured(
            self::NGINX_SOURCE,
            $status === 404
                ? 'The web server has no status location at that path. ' . $this->statusRemedy()
                : "The status endpoint answered HTTP {$status}; check the access rules on the status location.",
            "MONITORING_NGINX_STATUS_URL is set but {$safeUrl} returned HTTP {$status}.",
        );
    }

    /**
     * Exactly what to type to make these numbers real.
     *
     * The same variable serves both servers, so both blocks are here; the one for the server that
     * was actually detected comes first, because an operator reading a remedy is looking for the
     * line they can paste, not for a decision to make.
     */
    private function statusRemedy(): string
    {
        $nginx = 'On nginx, add the stub_status endpoint to the server block: '
            . 'location /nginx_status { stub_status; allow 127.0.0.1; deny all; } '
            . 'then check and reload it with nginx -t && systemctl reload nginx.';

        $apache = 'On Apache, enable the module and its extended counters: a2enmod status, ExtendedStatus On, and '
            . '<Location /server-status> SetHandler server-status; Require local </Location> '
            . 'then reload it with apachectl configtest && systemctl reload apache2.';

        $path = $this->detect()['type'] === self::APACHE ? '/server-status' : '/nginx_status';
        $env = "Finally set MONITORING_NGINX_STATUS_URL=http://127.0.0.1{$path} in .env and run php artisan config:clear.";

        return $this->detect()['type'] === self::APACHE
            ? $apache . ' ' . $nginx . ' ' . $env
            : $nginx . ' ' . $apache . ' ' . $env;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * nginx stub_status, whose entire body is four lines of integers.
     *
     * @return array<string, Metric>
     */
    private function nginxReadings(string $body, string $url): array
    {
        $active = $this->matchInt('/Active connections:\s*(\d+)/i', $body);

        // The counter line is three bare integers on their own line: accepts, handled, requests.
        $accepts = $handled = $requests = null;
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/m', $body, $counters) === 1) {
            [, $accepts, $handled, $requests] = array_map('intval', $counters);
        }

        $reading = $this->matchInt('/Reading:\s*(\d+)/i', $body);
        $writing = $this->matchInt('/Writing:\s*(\d+)/i', $body);
        $waiting = $this->matchInt('/Waiting:\s*(\d+)/i', $body);

        $workerNote = 'nginx serves connections from an event loop rather than a worker per request, so it keeps no busy or idle worker count. Reading, writing and waiting are the equivalent breakdown.';

        return array_merge([
            'active_connections' => Metric::of($active, self::NGINX_SOURCE, 'connections', 'Includes the idle keep-alive connections counted separately as waiting.'),
            'accepts' => Metric::of($accepts, self::NGINX_SOURCE, 'connections', 'Accepted since nginx started — a counter, not a rate.'),
            'handled' => Metric::of($handled, self::NGINX_SOURCE, 'connections', 'Handled since nginx started. Equal to accepts on a healthy server.'),
            'dropped_connections' => $this->dropped($accepts, $handled),
            'requests' => Metric::of($requests, self::NGINX_SOURCE, 'requests', 'Served since nginx started — a counter, not a rate.'),
            'reading' => Metric::of($reading, self::NGINX_SOURCE, 'connections', 'Connections nginx is reading a request header from.'),
            'writing' => Metric::of($writing, self::NGINX_SOURCE, 'connections', 'Connections nginx is writing a response back to — the ones waiting on PHP.'),
            'waiting' => Metric::of($waiting, self::NGINX_SOURCE, 'connections', 'Idle keep-alive connections. A large number here is cheap; it is not backlog.'),
            'busy_workers' => Metric::notSupported(self::NGINX_SOURCE, $workerNote),
            'idle_workers' => Metric::notSupported(self::NGINX_SOURCE, $workerNote),
            'worker_saturation_pct' => Metric::notSupported(self::NGINX_SOURCE, $workerNote),
        ], $this->rates($requests, $accepts, self::NGINX_SOURCE, $url));
    }

    /**
     * Apache mod_status in ?auto form: one `Key: value` per line, plus the scoreboard.
     *
     * @param  array<string, string>  $fields
     * @return array<string, Metric>
     */
    private function apacheReadings(array $fields, string $url): array
    {
        $busy = $this->field($fields, 'busyworkers');
        $idle = $this->field($fields, 'idleworkers');
        $connections = $this->field($fields, 'connstotal');
        $requests = $this->field($fields, 'totalaccesses');
        $board = $this->scoreboard($fields);

        // ConnsTotal only exists on the event MPM. Everywhere else the closest true statement is
        // the number of workers currently holding a request, which is what busy workers means.
        $activeNote = $connections === null
            ? 'This MPM does not report a connection total, so the count is the workers currently serving a request.'
            : 'Every connection Apache currently holds, including those parked in keep-alive.';

        $extendedStatus = 'Set ExtendedStatus On in the Apache config (mod_status ships it commented out) and reload apache2.';
        $noSplit = 'mod_status does not report accepted against handled connections; that split is specific to nginx\'s stub_status, and Apache exposes no equivalent.';

        return array_merge([
            'active_connections' => Metric::of($connections ?? $busy, self::APACHE_SOURCE, 'connections', $activeNote),
            'accepts' => Metric::notSupported(self::APACHE_SOURCE, $noSplit),
            'handled' => Metric::notSupported(self::APACHE_SOURCE, $noSplit),
            'dropped_connections' => Metric::notSupported(self::APACHE_SOURCE, $noSplit . ' Connections Apache refused appear in the error log as "server reached MaxRequestWorkers".'),
            'requests' => $requests === null
                ? Metric::notConfigured(self::APACHE_SOURCE, $extendedStatus, 'mod_status is answering, but without ExtendedStatus it keeps no request counter.')
                : Metric::of($requests, self::APACHE_SOURCE, 'requests', 'Served since Apache started — a counter, not a rate.'),
            'reading' => $board === null
                ? Metric::noData(self::APACHE_SOURCE, 'This status page carried no scoreboard, so per-connection states cannot be counted.')
                : Metric::of($board['R'], self::APACHE_SOURCE, 'connections', 'Workers reading a request, counted from the scoreboard.'),
            'writing' => $board === null
                ? Metric::noData(self::APACHE_SOURCE, 'This status page carried no scoreboard, so per-connection states cannot be counted.')
                : Metric::of($board['W'], self::APACHE_SOURCE, 'connections', 'Workers sending a reply, counted from the scoreboard.'),
            'waiting' => $this->apacheWaiting($fields, $board),
            'busy_workers' => Metric::of($busy, self::APACHE_SOURCE, 'workers', 'Workers currently serving a request.'),
            'idle_workers' => Metric::of($idle, self::APACHE_SOURCE, 'workers', 'Workers ready for the next request. When this reaches zero, requests queue in the kernel backlog.'),
            'worker_saturation_pct' => $this->saturation($busy, $idle, $board),
        ], $this->rates($requests, null, self::APACHE_SOURCE, $url));
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, int>|null  $board
     */
    private function apacheWaiting(array $fields, ?array $board): Metric
    {
        // On the event MPM keep-alive connections are handed off the workers entirely, so the
        // scoreboard undercounts them and ConnsAsyncKeepAlive is the number that is actually true.
        $async = $this->field($fields, 'connsasynckeepalive');
        if ($async !== null) {
            return Metric::of($async, self::APACHE_SOURCE, 'connections', 'Idle keep-alive connections held by the event MPM, off the workers.');
        }

        if ($board === null) {
            return Metric::noData(self::APACHE_SOURCE, 'This status page carried neither a scoreboard nor async connection counts, so idle keep-alive connections cannot be counted.');
        }

        return Metric::of($board['K'], self::APACHE_SOURCE, 'connections', 'Workers held open on a keep-alive connection, counted from the scoreboard.');
    }

    /**
     * How close Apache is to running out of workers.
     *
     * The denominator has to be a ceiling, and busy + idle is not one. Apache keeps only a handful
     * of spare children alive and forks more on demand, so that ratio sits at 50% on a server using
     * 2% of its capacity and crosses 90% while two hundred slots are still free — a panic reading
     * with "raise MaxRequestWorkers" attached to it. The scoreboard is the slot table the MPM
     * allocated at startup and nothing can ever run outside it, so that is what this measures
     * against; without a scoreboard mod_status publishes no ceiling at all, and the answer is that.
     *
     * @param  array<string, int>|null  $board
     */
    private function saturation(?int $busy, ?int $idle, ?array $board): Metric
    {
        $slots = $board['slots'] ?? 0;

        if ($busy === null || $slots < 1) {
            return Metric::noData(
                self::APACHE_SOURCE,
                'mod_status publishes no MaxRequestWorkers, and this status page carried no scoreboard to count worker slots from, so there is no ceiling to measure against. Read idle workers instead: requests begin queuing once it reaches zero.',
            );
        }

        return Metric::of(round(100 * $busy / $slots, 1), self::APACHE_SOURCE, '%', $idle === 0 && ($board['open'] ?? 0) === 0
            ? 'Every worker slot Apache has is serving a request and there are none left to spawn: new connections are queuing in the kernel backlog and will be refused next. Raise MaxRequestWorkers, and ServerLimit with it, having checked the box has the RAM for the extra children.'
            : "Share of the {$slots} worker slots this MPM allocated at startup (ServerLimit, times ThreadLimit where it is threaded) that are serving a request. MaxRequestWorkers can be configured below that, so read this as a floor and watch idle workers beside it.");
    }

    private function dropped(?int $accepts, ?int $handled): Metric
    {
        if ($accepts === null || $handled === null) {
            return Metric::noData(self::NGINX_SOURCE, 'The stub_status counter line could not be read.');
        }

        $dropped = $accepts - $handled;

        // Zero is the normal reading and a real one. Anything above it is nginx accepting a
        // connection and then closing it without serving anybody.
        return Metric::of($dropped, self::NGINX_SOURCE, 'connections', $dropped > 0
            ? "nginx accepted {$dropped} connections since it started that it never handled — it is hitting a resource ceiling and refusing visitors. Raise worker_connections in the events block and worker_rlimit_nofile, then reload nginx."
            : 'Accepts and handled agree, so no connection has been refused since the server started.');
    }

    /**
     * Per-second rates, as the difference between this reading and the previous one.
     *
     * The sample carries the endpoint it was taken from, because two counters are only subtractable
     * when they belong to the same server. Pointing the status URL somewhere else — or moving from
     * Apache to nginx — leaves a previous reading that is still a plausible-looking integer, and
     * subtracting it published two million requests a second here before the check existed.
     *
     * @return array<string, Metric>
     */
    private function rates(?int $requests, ?int $accepts, string $source, string $url): array
    {
        $sample = ['at' => microtime(true), 'from' => $source . ' ' . $url, 'requests' => $requests, 'accepts' => $accepts];

        try {
            $previous = Cache::get(self::PREVIOUS_KEY);
            Cache::put(self::PREVIOUS_KEY, $sample, self::SAMPLE_TTL_SECONDS);
        } catch (\Throwable) {
            // A cache store that has fallen over costs the rates, not the page.
            return array_fill_keys(
                ['requests_per_s', 'accepted_per_s'],
                Metric::noData($source, 'The cache store that holds the previous sample could not be read, and these counters only become a rate as a difference between two readings.'),
            );
        }

        $window = is_array($previous) && isset($previous['at'])
            ? microtime(true) - (float) $previous['at']
            : null;

        if ($window === null) {
            return array_fill_keys(
                ['requests_per_s', 'accepted_per_s'],
                Metric::noData($source, 'Collecting the first sample; rates appear one minute after monitoring starts.'),
            );
        }

        if ($window < self::MIN_INTERVAL_SECONDS) {
            return array_fill_keys(
                ['requests_per_s', 'accepted_per_s'],
                Metric::noData($source, 'The previous sample is too recent to derive a rate from.'),
            );
        }

        if (($previous['from'] ?? null) !== $sample['from']) {
            return array_fill_keys(
                ['requests_per_s', 'accepted_per_s'],
                Metric::noData($source, 'The previous sample came from a different status endpoint, and counters from two servers cannot be subtracted from one another. Rates resume at the next sample.'),
            );
        }

        return [
            'requests_per_s' => $this->rate($requests, $previous['requests'] ?? null, $window, $source, 'requests/s'),
            'accepted_per_s' => $this->rate($accepts, $previous['accepts'] ?? null, $window, $source, 'connections/s'),
        ];
    }

    private function rate(?int $current, mixed $previous, float $window, string $source, string $unit): Metric
    {
        if ($current === null) {
            return Metric::noData($source, 'This status page does not publish the counter this rate is derived from.');
        }
        if (!is_int($previous)) {
            return Metric::noData($source, 'The previous sample did not carry this counter, so there is nothing to subtract.');
        }

        $delta = $current - $previous;

        // The counter only ever climbs, so a negative delta means the web server was restarted
        // between the two readings and the window it spans no longer exists.
        if ($delta < 0) {
            return Metric::noData($source, 'The counter was reset since the previous sample; the web server has been restarted.');
        }

        return Metric::of(round($delta / $window, 2), $source, $unit, 'Measured over the ' . round($window, 1) . ' s since the previous sample.');
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<string, Metric> */
    private function trafficReadings(): array
    {
        return $this->traffic ??= $this->guard(fn () => $this->readTraffic(), self::TRAFFIC_METRICS, self::BUCKET_SOURCE);
    }

    /**
     * What the shop itself served in the last five minutes.
     *
     * Every row in this table is an HTTP request the web server handed to PHP, already folded into
     * per-minute buckets, so one aggregate over the (resolution, bucket_at) index answers the
     * status mix and the rate together — no per-request scan, and no second query to derive a
     * share from a total.
     *
     * @return array<string, Metric>
     */
    private function readTraffic(): array
    {
        $source = self::BUCKET_SOURCE . ' (last ' . self::TRAFFIC_WINDOW_MINUTES . ' minutes)';

        try {
            $totals = DB::connection($this->connectionName())
                ->table('monitoring_request_buckets')
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::minutesAgo(self::TRAFFIC_WINDOW_MINUTES))
                ->selectRaw('COUNT(*) as buckets, MAX(bucket_at) as newest, SUM(hits) as hits, SUM(errors) as server_errors, SUM(client_errors) as client_errors, SUM(timeouts) as timeouts')
                ->first();
        } catch (\Throwable $exception) {
            return array_fill_keys(self::TRAFFIC_METRICS, $this->bucketFailure($exception, $source));
        }

        $hits = (int) ($totals->hits ?? 0);

        if ((int) ($totals->buckets ?? 0) === 0 || $hits === 0) {
            // Two very different things look identical from here, so neither is asserted: an idle
            // shop, and a shop whose once-a-minute flush has stopped writing buckets at all.
            return array_fill_keys(self::TRAFFIC_METRICS, Metric::noData(
                $source,
                'No request has been recorded in this window. Either nothing was served, or the once-a-minute flush is not running — check that the server cron drives php artisan schedule:run, which is what dispatches monitoring:flush.',
            ));
        }

        $serverErrors = (int) ($totals->server_errors ?? 0);
        $clientErrors = (int) ($totals->client_errors ?? 0);
        $successful = max(0, $hits - $serverErrors - $clientErrors);
        $seconds = self::TRAFFIC_WINDOW_MINUTES * 60;

        return [
            'app_requests_5m' => Metric::of($hits, $source, 'requests', 'Newest bucket ' . Clock::parse($totals->newest)->toDateTimeString() . ' UTC.'),
            // Unlike the counters above, these rows are already bounded to one minute each, so
            // dividing by the window is a real average over it rather than a lifetime one. The
            // buckets are written once a minute, so the minute in progress is not in here yet.
            'app_requests_per_s' => Metric::of(round($hits / $seconds, 2), $source, 'requests/s', 'Mean over the last ' . self::TRAFFIC_WINDOW_MINUTES . ' minutes. The minute in progress is still buffered and not counted yet.'),
            'app_status_mix' => Metric::of([
                // The bucket schema counts 4xx and 5xx; everything else it folds into hits, so
                // redirects cannot be separated from successes here without a second table.
                ['class' => '2xx/3xx', 'requests' => $successful, 'share_pct' => round(100 * $successful / $hits, 1)],
                ['class' => '4xx', 'requests' => $clientErrors, 'share_pct' => round(100 * $clientErrors / $hits, 1)],
                ['class' => '5xx', 'requests' => $serverErrors, 'share_pct' => round(100 * $serverErrors / $hits, 1)],
            ], $source, null, 'Successes and redirects share one class: the bucket table counts errors, not every status code.'),
            'app_4xx_rate_pct' => Metric::of(round(100 * $clientErrors / $hits, 2), $source, '%', 'Requests refused by the application — bad input, expired sessions, missing pages.'),
            'app_5xx_rate_pct' => Metric::of(round(100 * $serverErrors / $hits, 2), $source, '%', 'Requests the application failed to serve. These are the ones a visitor sees as a broken page.'),
            'app_timeouts' => Metric::of((int) ($totals->timeouts ?? 0), $source, 'requests', 'Requests slower than ' . (int) config('monitoring.timeout_threshold_ms', 30000) . ' ms, whether or not they eventually returned.'),
        ];
    }

    private function bucketFailure(\Throwable $exception, string $source): Metric
    {
        $connection = $this->connectionName();
        $code = $this->driverErrorCode($exception);
        $account = $this->configString("database.connections.{$connection}.username", '<MONITORING_DB_USERNAME>');
        // The grant has to name the account as the server sees it, and the server sees the host
        // this connection dials from. Not a detail: MySQL and MariaDB store 'user'@'localhost' —
        // the unix socket — and 'user'@'127.0.0.1' — TCP — as two separate accounts with separate
        // privileges, so a grant written against the wrong one changes nothing and looks like it
        // should have worked.
        $host = $this->configString("database.connections.{$connection}.host", '127.0.0.1');

        // 1146 is the table, 1049 the schema: both mean the monitoring tables were never created
        // on this connection, which is a migration away rather than a broken collector.
        if ($code === 1146 || $code === 1049) {
            return Metric::notConfigured(
                $source,
                "monitoring_request_buckets does not exist on the `{$connection}` connection. Run php artisan migrate to create the monitoring tables, and check MONITORING_DB_* in .env if that connection points at a separate database.",
                'The monitoring tables are missing, so no request has anywhere to be recorded.',
            );
        }

        // 1045, and MariaDB's 1698 for an account pinned to unix_socket auth, are the login being
        // refused outright. No GRANT reaches an account the server will not let in.
        if ($code === 1045 || $code === 1698) {
            return Metric::permissionDenied(
                $source,
                "The `{$connection}` connection could not log in: " . $exception->getMessage(),
                "Check MONITORING_DB_USERNAME and MONITORING_DB_PASSWORD in .env — they fall back to DB_USERNAME and DB_PASSWORD — then run php artisan config:clear. On MariaDB, error 1698 means the account authenticates by unix socket and ignores the password entirely; ALTER USER '{$account}'@'{$host}' IDENTIFIED VIA mysql_native_password USING PASSWORD('the password in .env'); switches it back.",
            );
        }

        if (in_array($code, [1044, 1142, 1143], true)) {
            return Metric::permissionDenied(
                $source,
                "The `{$connection}` login may not read monitoring_request_buckets: " . $exception->getMessage(),
                'Grant it against the account the error above names, which is the one that was refused: GRANT SELECT, INSERT, UPDATE, DELETE ON `'
                    . $this->configString("database.connections.{$connection}.database", '<MONITORING_DB_DATABASE>')
                    . "`.* TO '{$account}'@'{$host}';",
            );
        }

        return Metric::failed($source, $exception);
    }

    private function connectionName(): string
    {
        return $this->configString('monitoring.connection', 'monitoring') ?: 'monitoring';
    }

    /**
     * A configuration value as a string.
     *
     * Anything that is not one is treated as unset rather than cast: an array left in config or
     * .env turns into an "Array to string conversion" the moment it is cast, and that is an
     * exception out of a collector that is supposed to answer "not configured" instead.
     */
    private function configString(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    private function driverErrorCode(\Throwable $exception): ?int
    {
        for ($error = $exception; $error !== null; $error = $error->getPrevious()) {
            $info = property_exists($error, 'errorInfo') ? $error->errorInfo : null;
            if (is_array($info) && isset($info[1])) {
                return (int) $info[1];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The `Key: value` lines of an ?auto status body, keyed without spaces or case.
     *
     * @return array<string, string>
     */
    private function autoFields(string $body): array
    {
        if (preg_match_all('/^([A-Za-z][A-Za-z ]*):[ \t]*(.*)$/m', $body, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $fields = [];
        foreach ($matches as $match) {
            $fields[strtolower(str_replace(' ', '', $match[1]))] = trim($match[2]);
        }

        return $fields;
    }

    /**
     * Workers per scoreboard state.
     *
     * Absent is not zero here: a status page with no scoreboard has not told us there are no
     * workers reading, it has told us nothing, and the caller reports that instead of a zero.
     *
     * @param  array<string, string>  $fields
     * @return array<string, int>|null
     */
    private function scoreboard(array $fields): ?array
    {
        $board = $fields['scoreboard'] ?? '';
        if ($board === '') {
            return null;
        }

        $counts = count_chars($board, 1);

        return [
            'R' => $counts[ord('R')] ?? 0,
            'W' => $counts[ord('W')] ?? 0,
            'K' => $counts[ord('K')] ?? 0,
            // One character per slot in the MPM's process/thread table, so its length is the only
            // worker ceiling mod_status ever states. A '.' is a slot no child occupies yet.
            'slots' => strlen($board),
            'open' => $counts[ord('.')] ?? 0,
        ];
    }

    /**
     * A numeric field, or null when the status page did not carry it.
     *
     * @param  array<string, string>  $fields
     */
    private function field(array $fields, string $key): ?int
    {
        $value = $fields[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function matchInt(string $pattern, string $subject): ?int
    {
        return preg_match($pattern, $subject, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * A message from the HTTP client, safe to store.
     *
     * The client quotes the URL it was given back into its error text and keeps the username
     * there, so the userinfo is dropped outright before the redactor handles the rest.
     */
    private function withoutCredentials(string $text): string
    {
        return Redactor::make()->text(
            (string) preg_replace('#([a-z][a-z0-9+.\-]*://)[^/@\s]+@#i', '$1', $text),
        );
    }
}
