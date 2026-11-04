<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Checks\CheckResult;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Synthetic journeys: whether a page a customer actually opens still comes back, and correct.
 *
 * Every other section on this dashboard measures the server answering itself. CPU, queue depth,
 * cache hit rate and error counts can all be green while the storefront renders a white screen —
 * a template that throws after the response has started, a CDN serving a stale error page, a
 * maintenance file nobody removed. Only fetching the page the way a customer would finds that,
 * which is what SyntheticCheck does every five minutes and what this section reads back.
 *
 * The default state of this section is the one it has to get right. NOTHING is probed until an
 * operator defines a journey — inventing a target would mean this server quietly making outbound
 * requests nobody asked for — so on a fresh install there is no journey, no probe and therefore no
 * availability. That is neither an outage nor an all-clear, and the two wrong renderings of it are
 * equally bad: a red panel reports a failure that has not happened, and a green "100%" claims a
 * page was checked when nothing fetched it. A journey that has never run is excluded from every
 * figure here rather than counted as either, exactly as CheckRunner excludes it from the
 * availability series, and the page says where a target is stored and what one looks like instead
 * of leaving an empty table to be read as good news.
 *
 * Three sources, kept apart because a gap in one says nothing about the others. The TARGETS come
 * from the `synthetics` key in monitoring_settings — what somebody asked to be watched. The
 * RESULTS in monitoring_check_results (kind = synthetic) are what each probe actually found,
 * one row per journey per run. The SERIES rows check.up|<key> and check.duration_ms|<key> are the
 * same outcomes folded for charting, and they are read as a chart rather than as the arithmetic:
 * they are bucketed, so a long window reads the rollup's rows, while the result history is
 * event-shaped and needs no fold to be complete.
 *
 * One row in the result history is not a journey at all. With no target defined the check records
 * itself as not_configured under the bare key `synthetic`, which is the probe reporting that it
 * ran and had nothing to fetch. Listing that as a journey with a 0% pass rate would manufacture a
 * failing page out of an empty setting, so it is separated out and reported as what it is: proof
 * the runner is alive.
 */
class SyntheticsPanel implements Panel
{
    /** The `kind` discriminator SyntheticCheck stamps on every row it records. */
    private const KIND = 'synthetic';

    /**
     * The key the check writes under when it has nothing to probe.
     *
     * SyntheticCheck::run() returns CheckResult::notConfigured($this->key(), …) with no target
     * defined, and key() is the bare word. Journeys are keyed `synthetic:<slug>`, so this one row
     * is the runner talking about itself and never a journey.
     */
    private const RUNNER_KEY = 'synthetic';

    /** Prefix SyntheticCheck::probe() builds every journey key from. */
    private const KEY_PREFIX = 'synthetic:';

    /** Width of monitoring_check_results.check_key — CheckRunner truncates to it before insert. */
    private const KEY_WIDTH = 64;

    /**
     * How many journeys one run of the check will probe.
     *
     * Mirrors SyntheticCheck::MAX_TARGETS, which is private there. A target past this line is
     * stored and never fetched, and the difference between "configured" and "probed" is the kind
     * of thing an operator only discovers from a page that says it.
     */
    private const PROBED_TARGETS = 10;

    /** Upper bound on how many stored target entries are read, so a bloated setting cannot stall this page. */
    private const MAX_TARGETS_LISTED = 25;

    /**
     * Upper bound on the grouped result read.
     *
     * The probe itself never produces more than PROBED_TARGETS keys per run, but renamed and
     * removed journeys leave their history behind under the old key, so the distinct-key count in
     * a 90-day window is not bounded by the current setting.
     */
    private const MAX_JOURNEYS = 50;

    /** Recent failing and degraded probes carried with their context. */
    private const MAX_FAILURES = 25;

    /** Backstop on the chart read, counted in buckets. */
    private const MAX_TIMELINE_BUCKETS = 1500;

    /**
     * How often monitoring:check runs, from bootstrap/app.php (`->everyFiveMinutes()`).
     *
     * Used only to say how many probes a window should have held. A window shorter than the
     * cadence can legitimately contain none, and without this the page would report a healthy
     * five-minute gap as a stopped runner.
     */
    private const CHECK_CADENCE_MINUTES = 5;

    /** Ceiling on a failure message drawn on the page, in characters. */
    private const NOTE_LIMIT = 300;

    /** Readings drawn as single values above the tables. */
    private const HEADLINE = [
        'journeys_defined', 'journeys_probed', 'probes_in_window', 'pass_rate',
        'average_latency_ms', 'last_probe_age_minutes', 'synthetic_check_runs',
    ];

    /**
     * Headline readings that exist only once a journey is defined.
     *
     * With no target they are all missing for one reason, and that reason is stated once at the
     * top of the section. Repeating it five times turns one configuration gap into five faults.
     */
    private const PROBE_DERIVED = [
        'journeys_probed', 'probes_in_window', 'pass_rate', 'average_latency_ms', 'last_probe_age_minutes',
    ];

    /** The availability and latency series CheckRunner::publishSeries() writes per check key. */
    private const UP_METRIC = 'check.up';

    private const DURATION_METRIC = 'check.duration_ms';

    /**
     * The fields SyntheticCheck reads off a stored target.
     *
     * Published so the page can name what a target looks like, and so a key the probe ignores can
     * be listed as ignored rather than silently doing nothing.
     */
    private const TARGET_FIELDS = ['name', 'url', 'expect_status', 'expect_text', 'max_ms', 'timeout'];

    /**
     * Hosts SyntheticCheck refuses to fetch, mirrored so this page can say why a target is skipped.
     *
     * Cloud instance metadata endpoints hand out credentials to anything that asks; a monitoring
     * target list is not allowed to become the thing that asks.
     */
    private const BLOCKED_HOSTS = ['169.254.169.254', 'metadata.google.internal', '[fd00:ec2::254]'];

    /**
     * The CheckResult vocabulary.
     *
     * check_key and status are free strings at the database level. This allowlist is how the view
     * can translate a status it authored without ever handing a stored value to translate().
     */
    private const STATUSES = [
        CheckResult::OK, CheckResult::DEGRADED, CheckResult::FAILING,
        CheckResult::UNKNOWN, CheckResult::NOT_CONFIGURED, CheckResult::NOT_SUPPORTED,
    ];

    /** Context keys SyntheticCheck attaches to a result, in the order they are worth reading. */
    private const CONTEXT_FIELDS = ['name', 'url', 'status', 'expected_status', 'expect_text', 'bytes'];

    private const SETTINGS_SOURCE = 'monitoring_settings (key: synthetics)';

    private const RESULTS_SOURCE = 'monitoring_check_results (kind = synthetic)';

    private const SERIES_SOURCE = 'monitoring_series (check.up, check.duration_ms)';

    /**
     * The only way to define a journey in this build.
     *
     * Monitoring's admin routes are all GET (routes/admin/routes.php), so there is no form that
     * writes this key — the check's own remedy names a Settings screen that does not exist. A
     * remedy that cannot be followed is worse than none, so this one is the command that works.
     */
    private const CONFIGURE_REMEDY = 'php artisan tinker --execute="app(App\Services\Monitoring\Support\MonitoringSettings::class)->put(\'synthetics\', [[\'name\' => \'Storefront home\', \'url\' => \'https://your-shop.example/\', \'expect_status\' => 200, \'expect_text\' => \'Add to cart\', \'max_ms\' => 2500]]);"';

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly MonitoringSettings $settings,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $history = $this->history($range, $window);
        $targets = $this->qualifyTargets($this->targets(), $history);
        $runner = $this->runner($history, $window, $targets);
        $keys = $this->journeyKeys($history);
        $unreadable = $history['state'] === 'failed';
        $series = $this->series($range, $window, $keys, $unreadable);
        $journeys = $this->journeys($targets, $history, $series);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'targets' => $targets,
            'runner' => $runner,
            'headline' => $this->headline($targets, $history, $runner),
            'journeys' => $journeys,
            'series' => $series,
            'timeline' => $this->timeline($range, $window, $keys, $unreadable),
            'failures' => $this->failures($range),
            // The status vocabulary, published rather than restated in the view: check_key and
            // status are free strings at the database level, and translate() persists any key it
            // has not seen, so a stored value is only translated when it is one of these six.
            'statuses' => self::STATUSES,
            // What a target looks like, so the section can describe one without a form to draw.
            'shape' => $this->shape(),
            // This panel reads stored tables rather than a collector, so there is no reading it
            // could take and fail to draw. The key is kept so the section footer is the same
            // shape as every other section's.
            'unrendered' => [],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What somebody asked to be watched

    /**
     * The journeys defined in monitoring_settings, and which of them the probe will actually fetch.
     *
     * The acceptance rules are mirrored from SyntheticCheck rather than shared, because they are
     * private there. That is a duplication worth naming: a target this page calls probeable and
     * the check then skips would be the worst kind of wrong, so every rule here is a copy of a
     * named rule there and the page says out loud that it is a mirror.
     *
     * @return array<string, mixed>
     */
    private function targets(): array
    {
        try {
            $configured = $this->settings->get('synthetics', []);
        } catch (\Throwable $exception) {
            return $this->emptyTargets(
                state: 'failed',
                note: $this->failureNote($exception),
                failure: Metric::failed(self::SETTINGS_SOURCE, $exception),
            );
        }

        // Nothing stored at all. The one state this section exists to render honestly.
        if ($configured === null || $configured === [] || $configured === '') {
            return $this->emptyTargets(
                state: 'not_configured',
                note: 'No synthetic journey is defined, so no journey is being probed. This is not an outage and not an all-clear: a journey that has never run has no availability to report, and it is excluded from every figure on this page rather than counted as 100% up.',
                remedy: self::CONFIGURE_REMEDY,
            );
        }

        // MonitoringSettings casts a `json` row for us, but a row stored under any other type comes
        // back as the raw string. SyntheticCheck decodes it the same way; so does this.
        if (is_string($configured)) {
            $configured = json_decode($configured, true);
        }

        // Something IS stored and it is not a list of targets. The check reads exactly the same
        // value and falls back to probing nothing, so this is a broken setting rather than an
        // absent one — and reporting it as "not configured" would hide the difference between
        // somebody who has not set this up and somebody whose setup stopped working.
        if (!is_array($configured)) {
            return $this->emptyTargets(
                state: 'failed',
                note: 'A synthetics setting is stored but it is not a list of targets, so the check reads it as no targets and probes nothing.',
                remedy: self::CONFIGURE_REMEDY,
            );
        }

        $rows = [];
        $probeable = 0;
        $entries = 0;

        foreach ($configured as $entry) {
            $entries++;

            if ($entries > self::MAX_TARGETS_LISTED) {
                break;
            }

            $row = $this->target($entry, $probeable);

            if ($row['probeable']) {
                $probeable++;
            }

            $rows[] = $row;
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => self::SETTINGS_SOURCE,
            'failure' => null,
            'rows' => $rows,
            'defined' => count($rows),
            'probeable' => $probeable,
            'skipped' => count($rows) - $probeable,
            // A target past the tenth probeable one is stored and never fetched.
            'probed' => min($probeable, self::PROBED_TARGETS),
            'probe_limit' => self::PROBED_TARGETS,
            'truncated' => is_countable($configured) && count($configured) > self::MAX_TARGETS_LISTED,
            'limit' => self::MAX_TARGETS_LISTED,
        ];
    }

    /**
     * "No journey is defined" is only sayable when the store could be read.
     *
     * MonitoringSettings swallows its own read failure and returns defaults — deliberately, so a
     * missing table cannot take the dashboard down — which means an unreadable settings table and
     * an empty one arrive here as the same empty array. On its own that is unrecoverable, but the
     * result history is read from the same connection on the same request: when THAT failed, the
     * store was not readable and "nothing is defined" is a claim this page has not earned. It is
     * downgraded to an unread reading rather than asserted, because asserting it would tell an
     * operator their configuration is missing when their database is.
     *
     * @param  array<string, mixed>  $targets
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function qualifyTargets(array $targets, array $history): array
    {
        if ($targets['state'] !== 'not_configured' || $history['state'] !== 'failed') {
            return $targets;
        }

        return array_merge($targets, [
            'state' => 'no_data',
            'note' => 'Nothing could be read from the monitoring store on this request, so whether a journey is defined is unknown rather than answered. The settings reader reports an unreadable table as "no setting stored", which is indistinguishable here from an empty one. The read that failed said: ' . $history['note'],
            'remedy' => 'Fix the monitoring connection first — confirm the database named by config(\'monitoring.connection\') is reachable and migrated: `php artisan migrate --database=' . (string) config('monitoring.connection', 'monitoring') . '`.',
            'defined' => null,
            'probeable' => null,
            'skipped' => null,
            'probed' => null,
        ]);
    }

    /**
     * One stored entry, read exactly as SyntheticCheck reads it.
     *
     * @param  int  $probeableSoFar  how many probeable targets precede this one, which is what
     *                               decides whether the run reaches this one at all
     * @return array<string, mixed>
     */
    private function target(mixed $entry, int $probeableSoFar): array
    {
        if (!is_array($entry) || !isset($entry['url']) || !is_scalar($entry['url'])) {
            return [
                'name' => null,
                'url' => null,
                'key' => null,
                'expect_status' => null,
                'expect_text' => null,
                'max_ms' => null,
                'timeout' => null,
                'probeable' => false,
                'probed' => false,
                'skip_reason' => 'This entry has no url, so the check skips it. A target is an object with at least a url.',
                'key_collision' => false,
                'ignored_fields' => is_array($entry) ? $this->ignoredFields($entry) : [],
            ];
        }

        $url = (string) $entry['url'];
        $probeable = $this->isProbeable($url);
        $name = (string) ($entry['name'] ?? parse_url($url, PHP_URL_PATH) ?: 'journey');
        $key = mb_substr(self::KEY_PREFIX . Str::slug($name), 0, self::KEY_WIDTH);

        return [
            'name' => $this->redactor->text($name),
            'url' => $this->redactor->url($url),
            'key' => $key,
            'expect_status' => isset($entry['expect_status']) ? (int) $entry['expect_status'] : 200,
            'expect_text' => isset($entry['expect_text']) && is_scalar($entry['expect_text'])
                ? $this->redactor->text((string) $entry['expect_text'])
                : null,
            'max_ms' => isset($entry['max_ms']) && is_numeric($entry['max_ms']) ? (int) $entry['max_ms'] : null,
            'timeout' => isset($entry['timeout']) && is_numeric($entry['timeout']) ? (int) $entry['timeout'] : null,
            'probeable' => $probeable,
            'probed' => $probeable && $probeableSoFar < self::PROBED_TARGETS,
            'skip_reason' => $probeable ? null : $this->whyNotProbeable($url),
            // Str::slug() drops everything it cannot transliterate, so a name written entirely in a
            // non-Latin script slugs to an empty string and the key becomes the bare word the
            // runner uses for itself. This journey's results would then be indistinguishable from
            // the runner's own rows, which is worth saying before it happens rather than after.
            'key_collision' => $key === self::RUNNER_KEY . ':' || $key === self::RUNNER_KEY,
            'ignored_fields' => $this->ignoredFields($entry),
        ];
    }

    /** @param array<mixed> $entry @return array<int, string> */
    private function ignoredFields(array $entry): array
    {
        $ignored = [];

        foreach (array_keys($entry) as $field) {
            if (!is_string($field) || in_array($field, self::TARGET_FIELDS, true)) {
                continue;
            }

            $ignored[] = mb_substr($field, 0, 40);
        }

        return $ignored;
    }

    /** Mirrors SyntheticCheck::isProbeable(). */
    private function isProbeable(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            return false;
        }

        return !in_array($host, self::BLOCKED_HOSTS, true);
    }

    private function whyNotProbeable(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'This host is a cloud instance metadata endpoint. Those hand out credentials to anything that asks, so the check refuses to fetch one and this target is never probed.';
        }

        return 'Only http and https addresses are fetched. This target is skipped by the check, so nothing about it is measured.';
    }

    /** @return array<string, mixed> */
    private function emptyTargets(string $state, string $note, ?string $remedy = null, ?Metric $failure = null): array
    {
        return [
            'state' => $state,
            'note' => $note,
            'remedy' => $remedy,
            'source' => self::SETTINGS_SOURCE,
            'failure' => $failure,
            'rows' => [],
            // Null rather than zero on a failed read: nothing was counted, so there is no count.
            'defined' => $state === 'not_configured' ? 0 : null,
            'probeable' => $state === 'not_configured' ? 0 : null,
            'skipped' => $state === 'not_configured' ? 0 : null,
            'probed' => $state === 'not_configured' ? 0 : null,
            'probe_limit' => self::PROBED_TARGETS,
            'truncated' => false,
            'limit' => self::MAX_TARGETS_LISTED,
        ];
    }

    /**
     * What a target looks like, field by field.
     *
     * There is no form in this build that writes one, so the shape has to be documented on the
     * page that would otherwise just say "none defined". Keys are fixed English identifiers and
     * the descriptions are prose authored here, both rendered as written.
     *
     * @return array<string, mixed>
     */
    private function shape(): array
    {
        return [
            'settings_key' => 'synthetics',
            'settings_table' => 'monitoring_settings',
            'writable_here' => false,
            'note' => 'Targets live under the `synthetics` key in monitoring_settings, stored as a JSON list of objects. Monitoring\'s admin routes are read-only in this build, so no screen writes that key; the command below does.',
            'remedy' => self::CONFIGURE_REMEDY,
            'fields' => [
                ['field' => 'name', 'required' => false, 'example' => 'Storefront home', 'note' => 'Names the journey and becomes its key: synthetic:<slug of the name>. Defaults to the URL path.'],
                ['field' => 'url', 'required' => true, 'example' => 'https://your-shop.example/', 'note' => 'The page to fetch. http and https only, and never a cloud metadata address. Fetched with GET and nothing else, so a journey can never write to the shop.'],
                ['field' => 'expect_status', 'required' => false, 'example' => '200', 'note' => 'The status code the page must return. Anything else fails the journey. Defaults to 200.'],
                ['field' => 'expect_text', 'required' => false, 'example' => 'Add to cart', 'note' => 'A phrase the body must contain. This is what catches the silent failure: a 200 where the shell renders and the products do not.'],
                ['field' => 'max_ms', 'required' => false, 'example' => '2500', 'note' => 'The time budget. Over it the journey is degraded rather than failing — it answered, just too slowly.'],
                ['field' => 'timeout', 'required' => false, 'example' => '15', 'note' => 'Seconds before the request is abandoned. Defaults to 15.'],
            ],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What the probes found

    /**
     * Every synthetic result in the window, folded by key, plus the newest row of each.
     *
     * Two bounded reads rather than one per journey: the aggregate carries MAX(id) so the newest
     * row of each key is fetched by primary key in a single follow-up, which is what lets the
     * table show a last status and a last detail without a query per row.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function history(string $range, array $window): array
    {
        try {
            $connection = $this->reader->connection();
            $rows = $connection->table('monitoring_check_results')
                ->where('kind', self::KIND)
                ->where('checked_at', '>=', $this->reader->since($range))
                ->groupBy('check_key')
                ->limit(self::MAX_JOURNEYS + 1)
                ->get([
                    'check_key',
                    $connection->raw('COUNT(*) AS runs'),
                    $connection->raw("SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS passed"),
                    $connection->raw("SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END) AS degraded"),
                    $connection->raw("SUM(CASE WHEN status = 'failing' THEN 1 ELSE 0 END) AS failing"),
                    $connection->raw("SUM(CASE WHEN status = 'unknown' THEN 1 ELSE 0 END) AS unknown_runs"),
                    $connection->raw("SUM(CASE WHEN status = 'not_configured' THEN 1 ELSE 0 END) AS not_configured"),
                    $connection->raw("SUM(CASE WHEN status = 'not_supported' THEN 1 ELSE 0 END) AS not_supported"),
                    $connection->raw('SUM(duration_ms) AS total_ms'),
                    $connection->raw('MIN(duration_ms) AS min_ms'),
                    $connection->raw('MAX(duration_ms) AS max_ms'),
                    $connection->raw('COUNT(duration_ms) AS timed'),
                    $connection->raw('MIN(checked_at) AS first_checked'),
                    $connection->raw('MAX(checked_at) AS last_checked'),
                    $connection->raw('MAX(id) AS latest_id'),
                ]);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing this read blanks the journey
            // table, while letting it escape would blank the target list that was read perfectly
            // well — and the target list is the half that says what should be happening.
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'source' => self::RESULTS_SOURCE,
                'failure' => Metric::failed(self::RESULTS_SOURCE, $exception),
                'by_key' => [],
                'truncated' => false,
                'window_minutes' => $window['minutes'],
            ];
        }

        $kept = $rows->take(self::MAX_JOURNEYS);
        $latest = $this->latestResults($kept->pluck('latest_id')->all());

        $byKey = [];
        foreach ($kept as $row) {
            $key = (string) $row->check_key;
            $passed = (int) $row->passed;
            $degraded = (int) $row->degraded;
            $failing = (int) $row->failing;
            $graded = $passed + $degraded + $failing;
            $timed = (int) $row->timed;
            $newest = $latest[(int) $row->latest_id] ?? null;

            $byKey[$key] = [
                'runs' => (int) $row->runs,
                'passed' => $passed,
                'degraded' => $degraded,
                'failing' => $failing,
                'unknown' => (int) $row->unknown_runs,
                'not_configured' => (int) $row->not_configured,
                'not_supported' => (int) $row->not_supported,
                'graded' => $graded,
                // Only probes that actually fetched the page can carry a rate. A window holding
                // nothing but not_configured rows has no pass rate at all, and printing 0% there
                // would report a broken page where nothing was ever fetched.
                'pass_rate' => $graded > 0 ? round(100 * $passed / $graded, 1) : null,
                'avg_ms' => $timed > 0 ? round((float) $row->total_ms / $timed, 1) : null,
                'min_ms' => $row->min_ms === null ? null : (int) $row->min_ms,
                'max_ms' => $row->max_ms === null ? null : (int) $row->max_ms,
                'timed' => $timed,
                'first_checked_at' => $this->displayStamp($row->first_checked),
                'last_checked_at' => $this->displayStamp($row->last_checked),
                'last_checked_minutes_ago' => $this->minutesSince($row->last_checked),
                'last_status' => $newest['status'] ?? null,
                'last_detail' => $newest['detail'] ?? null,
                'last_duration_ms' => $newest['duration_ms'] ?? null,
                'last_context' => $newest['context'] ?? [],
            ];
        }

        return [
            'state' => $byKey === [] ? 'no_data' : 'ok',
            'note' => $byKey === []
                ? 'No synthetic probe of any kind has been recorded in this window.'
                : null,
            'remedy' => $byKey === []
                ? 'The checks run on the schedule: confirm cron is firing with `php artisan schedule:list`, or run them now with `php artisan monitoring:check`.'
                : null,
            'source' => self::RESULTS_SOURCE,
            'failure' => null,
            'by_key' => $byKey,
            'truncated' => $rows->count() > self::MAX_JOURNEYS,
            'window_minutes' => $window['minutes'],
        ];
    }

    /**
     * The newest recorded row of each key, fetched by primary key.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function latestResults(array $ids): array
    {
        $ids = array_values(array_filter(array_map(
            static fn ($id) => is_numeric($id) ? (int) $id : null,
            $ids,
        )));

        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->reader->connection()->table('monitoring_check_results')
                ->whereIn('id', array_slice($ids, 0, self::MAX_JOURNEYS))
                ->get(['id', 'status', 'detail', 'duration_ms', 'context']);
        } catch (\Throwable) {
            // The aggregate above is the reading that matters; losing the newest row of each key
            // costs a status column, and an empty column that says "not read" is better than a
            // whole table that says nothing.
            return [];
        }

        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row->id] = [
                'status' => (string) $row->status,
                'detail' => $row->detail === null ? null : $this->redactor->text((string) $row->detail),
                'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
                'context' => $this->context($row->context),
            ];
        }

        return $latest;
    }

    /**
     * A stored context blob as a flat map of scalars.
     *
     * The context is written by the check, but it holds a URL and whatever a failing response put
     * in front of it, so it is bounded and redacted rather than passed through. Nested values are
     * dropped rather than flattened: this is a table cell, not a payload viewer.
     *
     * @return array<string, string|int|bool>
     */
    private function context(mixed $stored): array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return [];
        }

        $decoded = json_decode($stored, true);

        if (!is_array($decoded)) {
            return [];
        }

        $context = [];
        foreach ($decoded as $field => $value) {
            if (!is_string($field) || !is_scalar($value)) {
                continue;
            }

            $field = mb_substr($field, 0, 40);
            $context[$field] = match (true) {
                is_bool($value) => $value,
                is_int($value) => $value,
                $field === 'url' => $this->redactor->url((string) $value),
                default => mb_substr($this->redactor->text((string) $value), 0, 160),
            };
        }

        // The fields the check writes come first and in a fixed order, so two failures of the same
        // journey read the same way down the column. Anything else follows rather than being lost.
        $ordered = [];
        foreach (self::CONTEXT_FIELDS as $field) {
            if (array_key_exists($field, $context)) {
                $ordered[$field] = $context[$field];
                unset($context[$field]);
            }
        }

        return $ordered + $context;
    }

    /**
     * The check reporting on itself.
     *
     * With no target defined this is the only synthetic row that exists, and it is the difference
     * between "nothing is configured" and "nothing is running". A page that showed neither would
     * leave an operator unable to tell an empty setting from a stopped cron.
     *
     * @param  array<string, mixed>  $history
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @param  array<string, mixed>  $targets
     * @return array<string, mixed>
     */
    private function runner(array $history, array $window, array $targets): array
    {
        $expected = intdiv($window['minutes'], self::CHECK_CADENCE_MINUTES);
        $shape = [
            'source' => self::RESULTS_SOURCE,
            'cadence_minutes' => self::CHECK_CADENCE_MINUTES,
            'expected_runs' => $expected,
            'window_shorter_than_cadence' => $window['minutes'] < self::CHECK_CADENCE_MINUTES * 3,
            'disagrees_with_targets' => false,
        ];

        if ($history['state'] === 'failed') {
            return $shape + [
                'state' => 'failed',
                'note' => $history['note'],
                'remedy' => null,
                'runs' => null,
                'last_checked_at' => null,
                'last_checked_minutes_ago' => null,
                'last_status' => null,
                'last_detail' => null,
                'statuses' => [],
            ];
        }

        $row = $history['by_key'][self::RUNNER_KEY] ?? null;

        if ($row === null) {
            // A window narrower than a few cadences can hold no run without anything being wrong,
            // so the absence is only called a stopped runner where the window is wide enough for
            // the absence to mean something.
            $stopped = !$shape['window_shorter_than_cadence'];

            return $shape + [
                'state' => $stopped ? 'collector_offline' : 'no_data',
                'note' => $stopped
                    ? 'The synthetic check has not recorded a single run in this window, so nothing here is being probed and nothing would report it if a journey broke.'
                    : 'This window is shorter than a few runs of a check that fires every ' . self::CHECK_CADENCE_MINUTES . ' minutes, so holding no run proves nothing either way.',
                'remedy' => $stopped
                    ? 'Confirm the scheduler is firing: `php artisan schedule:list`. To run the checks now: `php artisan monitoring:check`.'
                    : 'Choose a wider range to see whether the check is running.',
                'runs' => $stopped ? 0 : null,
                'last_checked_at' => null,
                'last_checked_minutes_ago' => null,
                'last_status' => null,
                'last_detail' => null,
                'statuses' => [],
            ];
        }

        // Two independent records of the same fact, and they can disagree: this page decides
        // which targets are probeable by mirroring rules that are private to SyntheticCheck, so a
        // stored target this page calls probeable and the check then refuses is exactly the drift
        // a mirrored rule invites. Named rather than resolved — an empty journey table under a
        // full target list is otherwise unexplainable from the screen.
        $disagrees = $row['last_status'] === CheckResult::NOT_CONFIGURED
            && $targets['state'] === 'ok'
            && (int) ($targets['probed'] ?? 0) > 0;

        return array_merge($shape, [
            'disagrees_with_targets' => $disagrees,
            'state' => 'ok',
            'note' => $disagrees
                ? 'The most recent run of the check reported having nothing to probe, while ' . $targets['probed'] . ' of the stored targets are listed here as ones it would fetch. Either the setting changed after that run, or the check rejects a target this page accepts.'
                : null,
            'remedy' => $disagrees
                ? 'Run the checks now and compare: `php artisan monitoring:check`.'
                : null,
            'runs' => $row['runs'],
            'last_checked_at' => $row['last_checked_at'],
            'last_checked_minutes_ago' => $row['last_checked_minutes_ago'],
            'last_status' => $row['last_status'],
            'last_detail' => $row['last_detail'],
            'statuses' => array_filter([
                CheckResult::OK => $row['passed'],
                CheckResult::DEGRADED => $row['degraded'],
                CheckResult::FAILING => $row['failing'],
                CheckResult::UNKNOWN => $row['unknown'],
                CheckResult::NOT_CONFIGURED => $row['not_configured'],
                CheckResult::NOT_SUPPORTED => $row['not_supported'],
            ], static fn (int $count) => $count > 0),
        ]);
    }

    /**
     * Journey keys the window recorded, the runner's own key excluded.
     *
     * @param  array<string, mixed>  $history
     * @return array<int, string>
     */
    private function journeyKeys(array $history): array
    {
        return array_values(array_filter(
            array_keys($history['by_key'] ?? []),
            static fn (string $key) => $key !== self::RUNNER_KEY,
        ));
    }

    // -------------------------------------------------------------------------------------------
    // The journey table

    /**
     * One row per journey: what was asked for, what was measured, and which of the two is missing.
     *
     * A defined target with no results and a result history under a key nobody defines any more
     * are both real and both invisible on a table built from either source alone, so the two are
     * merged here and each row says which side it came from.
     *
     * @param  array<string, mixed>  $targets
     * @param  array<string, mixed>  $history
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>
     */
    private function journeys(array $targets, array $history, array $series): array
    {
        if ($history['state'] === 'failed') {
            return [
                'state' => 'failed',
                'note' => $history['note'],
                'remedy' => null,
                'source' => self::RESULTS_SOURCE,
                'rows' => [],
                'retired' => [],
                'never_probed' => [],
                'truncated' => false,
            ];
        }

        $defined = [];
        foreach ($targets['rows'] as $target) {
            if ($target['key'] !== null) {
                $defined[$target['key']] = $target;
            }
        }

        $rows = [];
        $neverProbed = [];
        $retired = [];

        foreach ($defined as $key => $target) {
            $measured = $history['by_key'][$key] ?? null;

            if ($measured === null) {
                // Only the ones the check would actually have fetched. A target it skips is
                // missing from this table for a reason the target list already states beside it,
                // and repeating it here would turn one configuration fault into two.
                if ($target['probed']) {
                    $neverProbed[] = ['key' => $key, 'name' => $target['name']];
                }

                continue;
            }

            $rows[] = $this->journeyRow($key, $target, $measured, $series['by_journey'][$key] ?? null);
        }

        foreach ($this->journeyKeys($history) as $key) {
            if (isset($defined[$key])) {
                continue;
            }

            $retired[] = $key;
            $rows[] = $this->journeyRow($key, null, $history['by_key'][$key], $series['by_journey'][$key] ?? null);
        }

        // Worst first: an operator opens this page to find what is broken, and a table ordered by
        // name puts the six healthy journeys above the one that has been failing since Tuesday.
        usort($rows, static fn (array $a, array $b) => [$a['pass_rate'] ?? 101, $a['key']]
            <=> [$b['pass_rate'] ?? 101, $b['key']]);

        return [
            'state' => $rows === [] ? ($targets['state'] === 'not_configured' ? 'not_configured' : 'no_data') : 'ok',
            'note' => $rows === [] ? $this->whyNoJourneys($targets, $history) : null,
            'remedy' => $rows === [] ? ($targets['state'] === 'not_configured' ? self::CONFIGURE_REMEDY : $history['remedy']) : null,
            'source' => self::RESULTS_SOURCE,
            'rows' => $rows,
            // Results recorded under a key the settings no longer define: a renamed or removed
            // journey. Its history is real and worth keeping visible, but nothing probes it now.
            'retired' => $retired,
            // Defined and never measured. The opposite gap, and the one that reads as an
            // all-clear if it is left off the page.
            'never_probed' => $neverProbed,
            'truncated' => (bool) $history['truncated'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $target
     * @param  array<string, mixed>  $measured
     * @param  array<string, mixed>|null  $folded
     * @return array<string, mixed>
     */
    private function journeyRow(string $key, ?array $target, array $measured, ?array $folded): array
    {
        $context = $measured['last_context'];

        return [
            'key' => $key,
            // The configured name is preferred because it is what somebody wrote; the recorded one
            // is what was actually fetched, and for a retired journey it is all there is.
            'name' => $target['name'] ?? (isset($context['name']) ? (string) $context['name'] : null),
            'url' => $target['url'] ?? (isset($context['url']) ? (string) $context['url'] : null),
            'defined' => $target !== null,
            'probeable' => $target['probeable'] ?? null,
            'probed' => $target['probed'] ?? null,
            'expect_status' => $target['expect_status'] ?? (isset($context['expected_status']) ? (int) $context['expected_status'] : null),
            'expect_text' => $target['expect_text'] ?? null,
            'max_ms' => $target['max_ms'] ?? null,
            'runs' => $measured['runs'],
            'passed' => $measured['passed'],
            'degraded' => $measured['degraded'],
            'failing' => $measured['failing'],
            'ungraded' => $measured['runs'] - $measured['graded'],
            'graded' => $measured['graded'],
            'pass_rate' => $measured['pass_rate'],
            'avg_ms' => $measured['avg_ms'],
            'min_ms' => $measured['min_ms'],
            'max_ms_observed' => $measured['max_ms'],
            'timed' => $measured['timed'],
            'first_checked_at' => $measured['first_checked_at'],
            'last_checked_at' => $measured['last_checked_at'],
            'last_checked_minutes_ago' => $measured['last_checked_minutes_ago'],
            'last_status' => $measured['last_status'],
            'last_detail' => $measured['last_detail'],
            'last_duration_ms' => $measured['last_duration_ms'],
            // The same outcomes as the rollup folded them, kept beside the history rather than
            // instead of it: the two are read from different tables and either can be the one
            // that is missing.
            'series_probes' => $folded['probes'] ?? null,
            'series_up' => $folded['up'] ?? null,
            'series_availability' => $folded['availability'] ?? null,
            'series_avg_ms' => $folded['avg_ms'] ?? null,
            'series_max_ms' => $folded['max_ms'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $targets
     * @param  array<string, mixed>  $history
     */
    private function whyNoJourneys(array $targets, array $history): string
    {
        if ($targets['state'] === 'not_configured') {
            return 'No journey is defined, so none is being probed and there is nothing to rate. A journey that has never run is excluded from this page rather than counted as up or down.';
        }

        if ($targets['state'] === 'failed') {
            return 'The target list could not be read, so this page cannot say which journeys should have results here.';
        }

        if (($targets['probeable'] ?? 0) === 0) {
            return 'Every stored target is skipped by the check, so nothing was fetched and no journey has results.';
        }

        return (string) ($history['note'] ?? 'No journey recorded a probe in this window.');
    }

    // -------------------------------------------------------------------------------------------
    // The folded series

    /**
     * check.up and check.duration_ms per journey, as the rollup holds them.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @param  array<int, string>  $keys
     * @param  bool  $unreadable  the journey list could not be read, so an empty key set is not a
     *                            measurement of no journeys — it is the absence of one
     * @return array<string, mixed>
     */
    private function series(string $range, array $window, array $keys, bool $unreadable): array
    {
        $folded = $window['resolution'] !== 'minute';
        $shape = [
            'source' => self::SERIES_SOURCE,
            'resolution' => $window['resolution'],
            'folded' => $folded,
            // The rollup folds the parent that is still in progress, so a coarse window can be
            // short of its newest minutes. The journey table above is read from the event-shaped
            // history and has no such seam, which is why that one carries the arithmetic and this
            // one is only ever a second opinion.
            'seam_note' => $folded
                ? 'These figures read the ' . $window['resolution'] . ' rows the rollup builds, so the newest minutes may not be folded into them yet. The journey history above is read row by row and has no such gap.'
                : null,
        ];

        if ($unreadable) {
            return $shape + [
                'state' => 'failed',
                'note' => 'The journey list could not be read, so this page does not know which series to ask for. This is not a reading of no availability.',
                'remedy' => null,
                'by_journey' => [],
                'truncated' => false,
            ];
        }

        if ($keys === []) {
            return $shape + [
                'state' => 'no_data',
                'note' => 'No journey recorded a probe in this window, so nothing was written to the availability series.',
                'remedy' => null,
                'by_journey' => [],
                'truncated' => false,
            ];
        }

        try {
            $connection = $this->reader->connection();
            $rows = $connection->table('monitoring_series')
                ->whereIn('metric', [self::UP_METRIC, self::DURATION_METRIC])
                ->whereIn('label', $keys)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->groupBy('label', 'metric')
                ->limit(self::MAX_JOURNEYS * 2 + 1)
                ->get([
                    'label',
                    'metric',
                    $connection->raw('SUM(samples) AS samples'),
                    $connection->raw('SUM(value_sum) AS total'),
                    $connection->raw('MAX(value_max) AS peak'),
                ]);
        } catch (\Throwable $exception) {
            return $shape + [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'by_journey' => [],
                'truncated' => false,
            ];
        }

        $byJourney = [];
        foreach ($rows->take(self::MAX_JOURNEYS * 2) as $row) {
            $label = (string) $row->label;
            $samples = (int) $row->samples;
            $byJourney[$label] ??= [
                'probes' => null, 'up' => null, 'down' => null,
                'availability' => null, 'avg_ms' => null, 'max_ms' => null,
            ];

            if ((string) $row->metric === self::UP_METRIC) {
                $up = (int) round((float) $row->total);
                $byJourney[$label]['probes'] = $samples;
                $byJourney[$label]['up'] = $up;
                $byJourney[$label]['down'] = $samples - $up;
                $byJourney[$label]['availability'] = $samples > 0 ? round(100 * $up / $samples, 2) : null;

                continue;
            }

            $byJourney[$label]['avg_ms'] = $samples > 0 ? round((float) $row->total / $samples, 1) : null;
            $byJourney[$label]['max_ms'] = $row->peak === null ? null : round((float) $row->peak, 1);
        }

        return $shape + [
            'state' => $byJourney === [] ? 'no_data' : 'ok',
            'note' => $byJourney === []
                ? 'The journeys recorded results but nothing reached the availability series for them in this window.'
                : null,
            'remedy' => $byJourney === []
                ? 'The series is written by the same run that records the result: check the rollup is running with `php artisan schedule:list`, or choose a shorter range to read the minute rows directly.'
                : null,
            'by_journey' => $byJourney,
            'truncated' => $rows->count() > self::MAX_JOURNEYS * 2,
        ];
    }

    /**
     * Probes and failures per bucket, every journey folded together.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @param  array<int, string>  $keys
     * @param  bool  $unreadable  the journey list could not be read; an empty key set is then the
     *                            absence of a reading rather than a measurement of no probes
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $window, array $keys, bool $unreadable): array
    {
        if ($unreadable) {
            return [
                'state' => 'failed',
                'note' => 'The journey list could not be read, so this page does not know which series to draw. An empty chart here would read as an outage that has not been measured.',
                'points' => [],
                'truncated' => false,
            ];
        }

        if ($keys === []) {
            return [
                'state' => 'no_data',
                'note' => 'No journey recorded a probe in this window, so there is no availability to draw.',
                'points' => [],
                'truncated' => false,
            ];
        }

        $limit = min(self::MAX_TIMELINE_BUCKETS, $this->bucketsInWindow($window) + 1);

        try {
            $connection = $this->reader->connection();
            $rows = $connection->table('monitoring_series')
                ->where('metric', self::UP_METRIC)
                ->whereIn('label', $keys)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->groupBy('bucket_at')
                ->orderBy('bucket_at')
                ->limit($limit)
                ->get([
                    'bucket_at',
                    $connection->raw('SUM(samples) AS probes'),
                    $connection->raw('SUM(value_sum) AS up'),
                ]);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'points' => [],
                'truncated' => false,
            ];
        }

        $points = [];
        foreach ($rows as $row) {
            $probes = (int) $row->probes;
            $up = (int) round((float) $row->up);
            $points[] = [
                't' => Clock::parse($row->bucket_at)->toIso8601String(),
                'hits' => $probes,
                'errors' => max(0, $probes - $up),
            ];
        }

        // One bucket is a reading; a line needs two. Saying which of the two this is stops a single
        // probe being read as a flat line across the whole window.
        return [
            'state' => count($points) >= 2 ? 'ok' : 'no_data',
            'note' => count($points) >= 2
                ? null
                : (count($points) === 1
                    ? 'Only one bucket in this window recorded a probe, and one point is not a line.'
                    : 'No probe reached the availability series in this window.'),
            'points' => $points,
            'truncated' => count($points) >= $limit,
        ];
    }

    /** @param array{minutes: int, resolution: string, points: int} $window */
    private function bucketsInWindow(array $window): int
    {
        $minutesPerBucket = match ($window['resolution']) {
            'day' => 1440,
            'hour' => 60,
            default => 1,
        };

        return (int) ceil($window['minutes'] / $minutesPerBucket);
    }

    // -------------------------------------------------------------------------------------------
    // What went wrong, with what it saw

    /**
     * Recent failing and degraded probes, newest first, each with the context the check recorded.
     *
     * The context is the whole value of this table. "The home page failed" is not actionable;
     * "returned 503 where 200 was expected, 1.2 kB of body, 14 seconds" is a fault somebody can go
     * and find.
     *
     * @return array<string, mixed>
     */
    private function failures(string $range): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_check_results')
                ->where('kind', self::KIND)
                ->whereIn('status', [CheckResult::FAILING, CheckResult::DEGRADED])
                ->where('checked_at', '>=', $this->reader->since($range))
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->limit(self::MAX_FAILURES + 1)
                ->get(['check_key', 'status', 'duration_ms', 'detail', 'context', 'checked_at']);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'source' => self::RESULTS_SOURCE,
                'rows' => [],
                'truncated' => false,
                'limit' => self::MAX_FAILURES,
            ];
        }

        $failures = [];
        foreach ($rows->take(self::MAX_FAILURES) as $row) {
            $failures[] = [
                'key' => (string) $row->check_key,
                'status' => (string) $row->status,
                'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
                'detail' => $row->detail === null ? null : $this->redactor->text((string) $row->detail),
                'context' => $this->context($row->context),
                'checked_at' => $this->displayStamp($row->checked_at),
            ];
        }

        return [
            'state' => $failures === [] ? 'no_data' : 'ok',
            'note' => $failures === []
                ? 'No journey failed or ran over its budget in this window. On a page with no journey defined that is the absence of a probe, not a clean run.'
                : null,
            'remedy' => null,
            'source' => self::RESULTS_SOURCE,
            'rows' => $failures,
            'truncated' => $rows->count() > self::MAX_FAILURES,
            'limit' => self::MAX_FAILURES,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The cards

    /**
     * @param  array<string, mixed>  $targets
     * @param  array<string, mixed>  $history
     * @param  array<string, mixed>  $runner
     * @return array<string, Metric>
     */
    private function headline(array $targets, array $history, array $runner): array
    {
        $undefined = $targets['state'] === 'not_configured'
            || ($targets['state'] === 'ok' && (int) ($targets['probeable'] ?? 0) === 0);
        // On a failed read every one of these is missing for the same single reason, which the
        // runner card and the banner above it already state. Repeating it five more times turns
        // one broken query into six faults.
        $suppressed = $undefined || $history['state'] === 'failed';

        $totals = $this->totals($history);
        $headline = [];

        foreach (self::HEADLINE as $name) {
            if ($suppressed && in_array($name, self::PROBE_DERIVED, true)) {
                continue;
            }

            $metric = $this->headlineMetric($name, $targets, $history, $runner, $totals);

            if ($metric instanceof Metric) {
                $headline[$name] = $metric;
            }
        }

        return $headline;
    }

    /**
     * Every journey folded into one set of totals.
     *
     * The runner's own rows are excluded: they say the check ran, not that a page was fetched, and
     * folding them into a pass rate would rate a probe against a page it never opened.
     *
     * @param  array<string, mixed>  $history
     * @return array<string, int|float|null>
     */
    private function totals(array $history): array
    {
        $totals = ['journeys' => 0, 'runs' => 0, 'passed' => 0, 'graded' => 0, 'total_ms' => 0.0, 'timed' => 0, 'newest' => null];

        foreach ($history['by_key'] ?? [] as $key => $row) {
            if ($key === self::RUNNER_KEY) {
                continue;
            }

            $totals['journeys']++;
            $totals['runs'] += $row['runs'];
            $totals['passed'] += $row['passed'];
            $totals['graded'] += $row['graded'];
            $totals['timed'] += $row['timed'];
            $totals['total_ms'] += (float) ($row['avg_ms'] ?? 0) * $row['timed'];

            if ($row['last_checked_minutes_ago'] !== null
                && ($totals['newest'] === null || $row['last_checked_minutes_ago'] < $totals['newest'])) {
                $totals['newest'] = $row['last_checked_minutes_ago'];
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $targets
     * @param  array<string, mixed>  $history
     * @param  array<string, mixed>  $runner
     * @param  array<string, int|float|null>  $totals
     */
    private function headlineMetric(string $name, array $targets, array $history, array $runner, array $totals): ?Metric
    {
        if ($name === 'journeys_defined') {
            return match ($targets['state']) {
                'ok' => Metric::of(
                    $targets['probeable'],
                    self::SETTINGS_SOURCE,
                    null,
                    $targets['skipped'] > 0
                        ? $targets['skipped'] . ' of the ' . $targets['defined'] . ' stored entries are skipped by the check.'
                        : null,
                ),
                'not_configured' => Metric::notConfigured(self::SETTINGS_SOURCE, self::CONFIGURE_REMEDY, $targets['note']),
                default => $targets['failure'] instanceof Metric
                    ? $targets['failure']
                    : Metric::noData(self::SETTINGS_SOURCE, $targets['note']),
            };
        }

        if ($name === 'synthetic_check_runs') {
            return match ($runner['state']) {
                'ok' => Metric::of($runner['runs'], self::RESULTS_SOURCE, null, 'Every ' . self::CHECK_CADENCE_MINUTES . ' minutes this window should hold one, so about ' . $runner['expected_runs'] . '.'),
                'collector_offline' => Metric::collectorOffline(self::RESULTS_SOURCE, $runner['note'], $runner['remedy']),
                'failed' => $history['failure'] instanceof Metric ? $history['failure'] : Metric::noData(self::RESULTS_SOURCE, $runner['note']),
                default => Metric::noData(self::RESULTS_SOURCE, $runner['note']),
            };
        }

        if ($history['state'] === 'failed') {
            return $history['failure'] instanceof Metric ? $history['failure'] : null;
        }

        return match ($name) {
            'journeys_probed' => $totals['journeys'] > 0
                ? Metric::of($totals['journeys'], self::RESULTS_SOURCE)
                : Metric::noData(self::RESULTS_SOURCE, 'No defined journey recorded a probe in this window.'),
            'probes_in_window' => $totals['runs'] > 0
                ? Metric::of($totals['runs'], self::RESULTS_SOURCE)
                : Metric::noData(self::RESULTS_SOURCE, 'No journey was fetched in this window.'),
            // Zero graded probes is not a zero per cent pass rate: it is the absence of anything
            // to rate, and the two read as opposites on a card this size.
            'pass_rate' => $totals['graded'] > 0
                ? Metric::of(round(100 * $totals['passed'] / $totals['graded'], 1), self::RESULTS_SOURCE, '%', $totals['passed'] . ' of ' . $totals['graded'] . ' probes returned exactly what was expected.')
                : Metric::noData(self::RESULTS_SOURCE, 'No probe in this window fetched a page, so there is nothing to rate.'),
            'average_latency_ms' => $totals['timed'] > 0
                ? Metric::of(round($totals['total_ms'] / $totals['timed'], 1), self::RESULTS_SOURCE, 'ms')
                : Metric::noData(self::RESULTS_SOURCE, 'No probe in this window recorded how long it took.'),
            'last_probe_age_minutes' => $totals['newest'] !== null
                ? Metric::of($totals['newest'], self::RESULTS_SOURCE, 'min')
                : Metric::noData(self::RESULTS_SOURCE, 'No journey has been probed in this window.'),
            default => null,
        };
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A caught failure, safe and short enough to put on the page.
     *
     * A driver exception carries the whole statement and its bound values, which is one of the
     * most reliable places in an application to find a token or a customer's address — and an
     * eight-hundred-character SQL dump repeated under three cards is unreadable besides. Redacted
     * and bounded once here rather than at each catch.
     */
    private function failureNote(\Throwable $exception): string
    {
        return Str::limit(
            $this->redactor->text(Metric::describeFailure($exception)),
            self::NOTE_LIMIT,
        );
    }

    /**
     * A stored UTC stamp, in the timezone the dashboard renders in.
     *
     * Every timestamp on this page passes through here. Printing a stored value directly would put
     * a probe hours away from the deploy it followed on a deployment whose display timezone is not
     * UTC, which is the exact class of bug the Clock exists to prevent.
     */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the probe really ran,
            // and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    private function minutesSince(mixed $stored): ?int
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return (int) Clock::parse($stored)->diffInMinutes(Clock::now());
        } catch (\Throwable) {
            return null;
        }
    }
}
