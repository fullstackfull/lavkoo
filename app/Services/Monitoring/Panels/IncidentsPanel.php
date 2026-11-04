<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Alerting\IncidentManager;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * Incidents: the outages this system was able to notice, and how long each one took.
 *
 * The word does not mean here what it means in a status page, and the difference decides whether
 * this page is read correctly. An incident in this build is a CORRELATION BUCKET FOR FIRING ALERT
 * RULES: when a rule holds past its `for_seconds`, IncidentManager attaches it to an incident that
 * started in the last thirty minutes, or opens a new one. Nothing else on this deployment can open
 * an incident — not a support ticket, not a person, not a check that went red without a rule behind
 * it. So an empty list is a statement about the alert rules, not about the shop, and a page that
 * let "no incidents" read as "nothing went wrong" would be the most reassuring lie in the whole
 * dashboard. That is why the detector block is assembled first and drawn above everything: with no
 * enabled rule, or with the evaluator stopped, the count below cannot rise however bad things get.
 *
 * Six columns of the schema are written by nothing in this build — probable_cause, cause_evidence,
 * notes, deployment_id, acknowledged_at, resolved_by. They are published as not_configured readings
 * with the reason, never as empty table cells: a blank cause column reads as "no cause was found",
 * which is a finding, and no finding was ever attempted.
 *
 * MTTD is real but degenerate and says so. detected_at is stamped when the rule fired and
 * started_at is when the metric first breached, so the gap between them is the rule's hold time
 * rather than how long anybody took to notice. MTTR is genuine.
 */
class IncidentsPanel implements Panel
{
    /** The statuses an incident can hold while it is still someone's problem. */
    private const OPEN_STATUSES = ['open', 'investigating', 'monitoring'];

    /** The status vocabulary the schema allows — the allowlist that makes translate() safe. */
    private const STATUSES = ['open', 'investigating', 'monitoring', 'resolved'];

    /** The only two IncidentManager can ever write: an incident goes open, then resolved. */
    private const STATUSES_WRITTEN = ['open', 'resolved'];

    /** The severity vocabulary the schema allows. */
    private const SEVERITIES = ['critical', 'major', 'minor', 'warning'];

    /**
     * The only two the writer can emit.
     *
     * IncidentManager::SEVERITY maps the firing level onto critical|minor, so 'major' and 'warning'
     * exist in the column and can never appear in it. Offering them as filters that can only ever
     * return nothing would read as "no major incidents", which is a claim rather than a gap.
     */
    private const SEVERITIES_WRITTEN = ['critical', 'minor'];

    /** EventLog's severity vocabulary, for the incident rows on the timeline. */
    private const EVENT_SEVERITIES = ['info', 'success', 'warning', 'critical'];

    /** The comparison operators an alert rule can carry, so a stored one is echoed only if it is ours. */
    private const OPERATORS = ['>', '<', '>=', '<=', '=='];


    /** The one class that writes this table. Named on the page so the list can be reasoned about. */
    private const WRITER = 'app/Services/Monitoring/Alerting/IncidentManager.php';

    /** Open incidents listed in full. More than this and the page says it was cut. */
    private const MAX_OPEN = 25;

    /** Rows in the history table. */
    private const MAX_HISTORY = 50;

    /**
     * Rows folded into the counts and the mean times.
     *
     * Read rather than aggregated in SQL because the durations are differences between timestamp
     * columns, and every portable way to subtract two of those is a vendor function.
     */
    private const MAX_TIMING_ROWS = 1000;

    /** Incident rows on the timeline. */
    private const MAX_EVENTS = 50;

    /** IncidentManager keeps the last 25 signals per incident; this reads no further. */
    private const MAX_SIGNALS = 25;

    /** One row per alert rule, so this is the rule list's own ceiling. */
    private const MAX_ALERT_STATES = 200;

    private const SOURCE = 'monitoring_incidents';

    private const STATES_SOURCE = 'monitoring_alert_states';

    private const EVENTS_SOURCE = 'monitoring_events (type=incident)';

    private const RULES_SOURCE = 'monitoring_alert_rules';

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $filters = $this->filters($request);
        $states = $this->alertStates();
        $open = $this->open($states);
        $sample = $this->windowSample($range);
        $detector = $this->detector();

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'definition' => $this->definition(),
            'detector' => $detector,
            'headline' => $this->headline($open, $sample, $detector),
            'filters' => $filters,
            'options' => $this->options($sample),
            'open' => $open,
            'history' => $this->history($range, $filters, $states),
            'resolution' => $this->resolution($sample),
            'events' => $this->events($range),
            'unwritten' => $this->unwritten(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What an incident is here

    /**
     * The grouping rule, published rather than described in the view.
     *
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'correlation_window_minutes' => IncidentManager::CORRELATION_WINDOW_MINUTES,
            'writer' => self::WRITER,
            'severities' => self::SEVERITIES,
            'severities_written' => self::SEVERITIES_WRITTEN,
            'statuses' => self::STATUSES,
            'statuses_written' => self::STATUSES_WRITTEN,
            'source' => self::SOURCE,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Can anything open an incident at all

    /**
     * Whether the machine that opens incidents is armed.
     *
     * This is the block the rest of the page depends on. Every count below is a real measurement of
     * a table, and every one of them is meaningless as a statement about the shop when no rule is
     * enabled: zero incidents is then a fact about the rule list. Three-valued on purpose —
     * "nothing can fire" and "I could not read whether anything can fire" send different people to
     * different places.
     *
     * @return array<string, mixed>
     */
    private function detector(): array
    {
        $base = [
            'source' => self::RULES_SOURCE . ', ' . self::STATES_SOURCE,
            'schedule' => 'php artisan monitoring:evaluate, scheduled every minute',
            'correlation_window_minutes' => IncidentManager::CORRELATION_WINDOW_MINUTES,
            'rules_total' => null,
            'rules_enabled' => null,
            'last_evaluated_at' => null,
            'age_seconds' => null,
            'can_open_incidents' => null,
        ];

        if (!config('monitoring.enabled', true)) {
            return array_merge($base, [
                'state' => 'not_configured',
                'can_open_incidents' => false,
                'note' => 'Monitoring collection is switched off, so no rule is evaluated and no incident can be opened. An empty list below is a consequence of that setting, not a quiet week.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ]);
        }

        try {
            $connection = $this->reader->connection();
            $total = (int) $connection->table(self::RULES_SOURCE)->count();
            $enabled = (int) $connection->table(self::RULES_SOURCE)->where('enabled', true)->count();
            $lastEvaluated = $connection->table(self::STATES_SOURCE)->max('updated_at');
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing this read costs the banner,
            // while letting it escape would blank an incident list that reads perfectly well.
            return array_merge($base, [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The alert tables are created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
            ]);
        }

        $age = $this->secondsSince($lastEvaluated);
        $staleAfter = (int) config('monitoring.stale_after_seconds', 180);

        $base = array_merge($base, [
            'rules_total' => $total,
            'rules_enabled' => $enabled,
            'last_evaluated_at' => $this->displayStamp($lastEvaluated),
            'age_seconds' => $age,
        ]);

        if ($total === 0) {
            return array_merge($base, [
                'state' => 'not_configured',
                'can_open_incidents' => false,
                'note' => 'No alert rule exists on this deployment. Rules are the only thing that opens an incident, so this list cannot grow no matter what happens to the shop.',
                'remedy' => 'Install the shipped rule set with `php artisan monitoring:evaluate --seed`, then review it in Monitoring → Alerts.',
            ]);
        }

        if ($enabled === 0) {
            return array_merge($base, [
                'state' => 'not_configured',
                'can_open_incidents' => false,
                'note' => 'Every alert rule on this deployment is switched off, so nothing can fire and nothing can open an incident.',
                'remedy' => 'Enable the rules worth waking up for in Monitoring → Alerts.',
            ]);
        }

        if ($age === null) {
            return array_merge($base, [
                'state' => 'no_data',
                'can_open_incidents' => null,
                'note' => 'Rules exist but not one alert state row has ever been written, so the evaluator has either never run or never finished a run.',
                'remedy' => 'Run `php artisan monitoring:evaluate` once to confirm it works, then check the scheduler with `php artisan schedule:list`.',
            ]);
        }

        if ($age > $staleAfter) {
            return array_merge($base, [
                'state' => 'collector_offline',
                'can_open_incidents' => false,
                'note' => 'The alert engine last evaluated ' . $age . ' seconds ago. While it is stopped nothing fires, so no incident can be opened and an empty list says nothing about the shop.',
                'remedy' => 'The evaluator runs from the scheduler every minute. Check cron is calling `php artisan schedule:run`.',
            ]);
        }

        return array_merge($base, [
            'state' => 'ok',
            'can_open_incidents' => true,
            'note' => null,
            'remedy' => null,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // The numbers above the tables

    /**
     * The readings drawn as cards above the tables.
     *
     * A name whose read failed is WITHHELD rather than given a state here: the card underneath it
     * carries the failure with the real reason, and a second copy of the same fault above it turns
     * one broken query into a page that looks broken in four places.
     *
     * @param  array<string, mixed>  $open
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>  $detector
     * @return array<string, Metric>
     */
    private function headline(array $open, array $sample, array $detector): array
    {
        // A measured zero is a reading and is printed as one — but on a deployment where nothing can
        // fire it is a reading of the rule list rather than of the shop, and the number carries that
        // sentence with it rather than leaving the banner to be scrolled past.
        $blind = $detector['can_open_incidents'] === false
            ? 'Nothing on this deployment can open an incident right now, so this figure cannot rise: read it as a reading of the alert engine, not of the shop.'
            : null;

        $headline = [];

        if ($open['state'] !== 'failed') {
            $headline['open_incidents'] = Metric::of(
                value: $open['total'],
                source: self::SOURCE,
                unit: null,
                note: $blind,
            );
            $headline['critical_open'] = Metric::of(
                value: $open['critical'],
                source: self::SOURCE,
                unit: null,
                note: $open['truncated']
                    ? 'Counted over the ' . self::MAX_OPEN . ' incidents listed below, which is fewer than are open.'
                    : $blind,
            );
            $headline['oldest_open_incident'] = $open['oldest_age_minutes'] === null
                ? Metric::noData(
                    source: self::SOURCE,
                    note: $open['total'] === 0
                        ? 'Nothing is open, so nothing has an age.'
                        : 'The oldest open incident has no readable start time.',
                )
                : Metric::of(value: $open['oldest_age_minutes'], source: self::SOURCE, unit: 'min');
        }

        if ($sample['state'] === 'failed') {
            return $headline;
        }

        $resolution = $this->resolution($sample);

        $headline['started_in_window'] = Metric::of(
            value: $resolution['started'],
            source: self::SOURCE,
            unit: null,
            note: $sample['truncated'] ? 'The window holds more incidents than this page folds.' : $blind,
        );
        $headline['resolved_in_window'] = Metric::of(
            value: $resolution['resolved'],
            source: self::SOURCE,
            unit: null,
            note: 'Counted over incidents that started in this window and have since closed.',
        );

        // Null, never zero. A mean over nothing is not a fast response.
        $headline['mean_time_to_detect'] = $resolution['mttd_seconds'] === null
            ? Metric::noData(
                source: self::SOURCE,
                note: $resolution['started'] > 0
                    ? 'No incident in this window carries both a start and a detection time.'
                    : 'No incident started in this window.',
            )
            : Metric::of(
                value: $resolution['mttd_seconds'],
                source: self::SOURCE,
                unit: 's',
                note: 'Mean over ' . $this->incidentCount($resolution['mttd_samples'])
                    . '. This measures the rule hold time, not human reaction.',
            );

        $headline['mean_time_to_resolve'] = $resolution['mttr_seconds'] === null
            ? Metric::noData(
                source: self::SOURCE,
                note: $resolution['started'] > 0
                    ? 'No incident that started in this window has been resolved yet.'
                    : 'No incident started in this window.',
            )
            : Metric::of(
                value: $resolution['mttr_seconds'],
                source: self::SOURCE,
                unit: 's',
                note: 'Mean over ' . $this->incidentCount($resolution['mttr_samples'], 'resolved ')
                    . ($resolution['still_open'] > 0 ? '; ' . $resolution['still_open'] . ' from this window are still open and are not in it.' : '.'),
            );

        return $headline;
    }

    /** "1 incident" rather than "1 incidents": the count is a sentence, not a column. */
    private function incidentCount(int $count, string $qualifier = ''): string
    {
        return $count . ' ' . $qualifier . ($count === 1 ? 'incident' : 'incidents');
    }

    // -------------------------------------------------------------------------------------------
    // The filter row

    /**
     * Severity and status, clamped.
     *
     * Both reach a WHERE clause, so neither is trusted: each is matched against the schema's own
     * vocabulary and falls back to 'all' rather than being bound as typed.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $severity = $this->queryString($request, 'severity');
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'all';
        }

        $status = $this->queryString($request, 'status');
        if (!in_array($status, array_merge(self::STATUSES, ['open_only']), true)) {
            $status = 'all';
        }

        return [
            'severity' => $severity,
            'status' => $status,
            'narrowed' => $severity !== 'all' || $status !== 'all',
        ];
    }

    /**
     * One query value, or 'all' when it is not a single string.
     *
     * `?severity[]=a` hands the request an array, and casting one to string is a PHP warning that
     * the error handler turns into a throw — which would take the whole section down with an
     * "Array to string conversion" card. A filter nobody can spell is simply not applied.
     */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key, 'all');

        return is_string($value) ? trim($value) : 'all';
    }

    /**
     * What the window actually holds, so the filter row offers counts rather than guesses.
     *
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function options(array $sample): array
    {
        if ($sample['state'] === 'failed') {
            return [
                'state' => 'failed',
                'note' => $sample['note'],
                'source' => self::SOURCE,
                'severities' => [],
                'statuses' => [],
                'truncated' => false,
            ];
        }

        $severities = [];
        $statuses = [];
        foreach ($sample['rows'] as $row) {
            $severity = (string) $row['severity'];
            $status = (string) $row['status'];
            $severities[$severity] = ($severities[$severity] ?? 0) + 1;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }

        arsort($severities);
        arsort($statuses);

        return [
            'state' => $sample['rows'] === [] ? 'no_data' : 'ok',
            'note' => $sample['rows'] === [] ? 'No incident started inside this window.' : null,
            'source' => self::SOURCE,
            'severities' => $severities,
            'statuses' => $statuses,
            'truncated' => $sample['truncated'],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Open incidents

    /**
     * Everything still open, whatever the selected range.
     *
     * Deliberately not filtered by the window. An incident that opened three days ago and is still
     * open is the most important thing on this page while somebody is looking at the last hour, and
     * hiding it behind a range selector would make the range control able to conceal an outage.
     *
     * @param  array<string, mixed>  $states
     * @return array<string, mixed>
     */
    private function open(array $states): array
    {
        try {
            $connection = $this->reader->connection();
            $total = (int) $connection->table(self::SOURCE)
                ->whereIn('status', self::OPEN_STATUSES)
                ->count();

            $rows = $connection->table(self::SOURCE)
                ->whereIn('status', self::OPEN_STATUSES)
                ->orderByDesc('started_at')
                ->limit(self::MAX_OPEN + 1)
                ->get($this->incidentColumns());
        } catch (\Throwable $exception) {
            $failure = $this->emptyIncidents(
                state: 'failed',
                note: $this->failureNote($exception),
                remedy: 'The incident table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
            );

            return array_merge($failure, [
                'limit' => self::MAX_OPEN,
                'total' => null,
                'critical' => null,
                'oldest_age_minutes' => null,
            ]);
        }

        $presented = [];
        foreach ($rows->take(self::MAX_OPEN) as $row) {
            $presented[] = $this->present($row, $states);
        }

        $ages = array_values(array_filter(
            array_column($presented, 'age_minutes'),
            static fn ($age) => $age !== null,
        ));

        return [
            'state' => $presented === [] ? 'no_data' : 'ok',
            'note' => $presented === []
                ? 'No incident is open. Every incident here was opened by an alert rule, so this says nothing about an outage that broke no rule.'
                : null,
            'remedy' => null,
            'source' => self::SOURCE,
            'rows' => $presented,
            'truncated' => $rows->count() > self::MAX_OPEN,
            'limit' => self::MAX_OPEN,
            'total' => $total,
            'critical' => count(array_filter($presented, static fn (array $row) => $row['severity'] === 'critical')),
            'oldest_age_minutes' => $ages === [] ? null : max($ages),
        ];
    }

    /**
     * The incidents that started inside the selected window, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $states
     * @return array<string, mixed>
     */
    private function history(string $range, array $filters, array $states): array
    {
        try {
            $query = $this->reader->connection()->table(self::SOURCE)
                ->where('started_at', '>=', $this->reader->since($range));

            if ($filters['severity'] !== 'all') {
                $query->where('severity', $filters['severity']);
            }
            if ($filters['status'] === 'open_only') {
                $query->whereIn('status', self::OPEN_STATUSES);
            } elseif ($filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }

            $rows = $query->orderByDesc('started_at')
                ->limit(self::MAX_HISTORY + 1)
                ->get($this->incidentColumns());
        } catch (\Throwable $exception) {
            return $this->emptyIncidents(
                state: 'failed',
                note: $this->failureNote($exception),
                remedy: 'The incident table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
            );
        }

        $presented = [];
        foreach ($rows->take(self::MAX_HISTORY) as $row) {
            $presented[] = $this->present($row, $states);
        }

        return [
            'state' => $presented === [] ? 'no_data' : 'ok',
            'note' => $presented === []
                ? ($filters['narrowed']
                    ? 'No incident in this window matches the selected severity and status.'
                    : 'No incident started inside this window.')
                : null,
            'remedy' => null,
            'source' => self::SOURCE,
            'rows' => $presented,
            'truncated' => $rows->count() > self::MAX_HISTORY,
            'limit' => self::MAX_HISTORY,
        ];
    }

    /** @return array<int, string> */
    private function incidentColumns(): array
    {
        return [
            'id', 'reference', 'title', 'severity', 'status', 'affected_services', 'signals',
            'probable_cause', 'cause_evidence', 'notes', 'deployment_id',
            'started_at', 'detected_at', 'acknowledged_at', 'resolved_at', 'updated_at',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyIncidents(string $state, string $note, ?string $remedy = null): array
    {
        return [
            'state' => $state,
            'note' => $note,
            'remedy' => $remedy,
            'source' => self::SOURCE,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_HISTORY,
        ];
    }

    /**
     * One incident, with its signals decoded.
     *
     * @param  array<string, mixed>  $states
     * @return array<string, mixed>
     */
    private function present(object $row, array $states): array
    {
        $id = (int) $row->id;
        $severity = (string) $row->severity;
        $status = (string) $row->status;

        return [
            'id' => $id,
            'reference' => mb_substr((string) $row->reference, 0, 16),
            // Rule names are operator-edited free text and travel into the incident title, so they
            // go through the redactor like any other string this system did not author.
            'title' => $this->redactor->text(mb_substr((string) $row->title, 0, 191)),
            'severity' => $severity,
            // Whether the stored value is one of ours decides whether the view may translate it: a
            // value from a column that reached translate() mints a language key per distinct value.
            'severity_known' => in_array($severity, self::SEVERITIES, true),
            'status' => $status,
            'status_known' => in_array($status, self::STATUSES, true),
            'is_open' => in_array($status, self::OPEN_STATUSES, true),
            'started_at' => $this->displayStamp($row->started_at),
            'age_minutes' => $this->minutesSince($row->started_at),
            'detected_at' => $this->displayStamp($row->detected_at),
            'detect_seconds' => $this->elapsedSeconds($row->started_at, $row->detected_at),
            'resolved_at' => $this->displayStamp($row->resolved_at),
            'resolve_seconds' => $this->elapsedSeconds($row->started_at, $row->resolved_at),
            'updated_at' => $this->displayStamp($row->updated_at),
            // Read rather than assumed empty. Nothing in this build writes either column — that is
            // what the unconfigured readings at the foot of the page say — but "nothing writes it"
            // is a statement about the code, and a value put there by hand or by a later writer
            // must appear on the incident rather than be hidden by a claim made about the code.
            'probable_cause' => $this->shortText($row->probable_cause, 191),
            'cause_evidence' => $this->shortText($row->cause_evidence ?? null, 2000),
            'deployment_id' => $row->deployment_id !== null ? (int) $row->deployment_id : null,
            'notes' => $this->shortText($row->notes ?? null, 4000),
            'acknowledged_at' => $this->displayStamp($row->acknowledged_at),
            'affected_services' => $this->services($row->affected_services),
            'signals' => $this->signals($row->signals, $states, $id),
            'holding_open' => $this->holdingOpen($states, $id),
        ];
    }

    /**
     * The subsystems the incident's signals belong to.
     *
     * A block rather than a bare list: "the column is empty" and "the column is not readable JSON"
     * both draw no service names and mean opposite things.
     *
     * @return array<string, mixed>
     */
    private function services(mixed $stored): array
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return ['state' => 'no_data', 'rows' => []];
        }

        $decoded = json_decode((string) $stored, true);
        if (!is_array($decoded)) {
            return ['state' => 'failed', 'rows' => []];
        }

        $services = [];
        foreach ($decoded as $service) {
            if (is_string($service) && trim($service) !== '') {
                $services[] = mb_substr(trim($service), 0, 40);
            }
        }

        $services = array_values(array_unique($services));

        return ['state' => $services === [] ? 'no_data' : 'ok', 'rows' => $services];
    }

    /**
     * The signals JSON, decoded into one row per rule with the value that broke it.
     *
     * This is the content of an incident. Without it the list is a row of references and titles
     * that says something happened and never what — and the values are already stored, so drawing
     * them is a matter of decoding rather than of measuring.
     *
     * @param  array<string, mixed>  $states
     * @return array<string, mixed>
     */
    private function signals(mixed $stored, array $states, int $incidentId): array
    {
        $empty = [
            'state' => 'no_data',
            'note' => 'This incident carries no signal payload, so the rules behind it cannot be named.',
            'source' => self::SOURCE,
            'rows' => [],
            'truncated' => false,
        ];

        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return $empty;
        }

        $decoded = json_decode((string) $stored, true);
        if (!is_array($decoded)) {
            return [
                'state' => 'failed',
                'note' => 'The signals column on this incident is not readable JSON, so what fired cannot be listed. The incident itself is real.',
                'source' => self::SOURCE,
                'rows' => [],
                'truncated' => false,
            ];
        }

        if ($decoded === []) {
            return $empty;
        }

        $live = $states['by_incident'][$incidentId] ?? [];
        $known = $states['state'] === 'ok';

        $rows = [];
        foreach (array_slice($decoded, 0, self::MAX_SIGNALS) as $signal) {
            if (!is_array($signal)) {
                continue;
            }

            $rule = trim((string) ($signal['rule'] ?? ''));
            $operator = (string) ($signal['operator'] ?? '');
            $current = $rule === '' ? null : ($live[$rule] ?? null);

            $rows[] = [
                'rule' => $rule === '' ? null : mb_substr($rule, 0, 96),
                'metric' => $this->shortText($signal['metric'] ?? null, 96),
                'label' => $this->shortText($signal['label'] ?? null, 96),
                'operator' => in_array($operator, self::OPERATORS, true) ? $operator : null,
                'value' => $this->floatOrNull($signal['value'] ?? null),
                'threshold' => $this->floatOrNull($signal['threshold'] ?? null),
                'samples' => $this->integerOrNull($signal['samples'] ?? null),
                'breached_since' => $this->displayStamp($signal['breached_since'] ?? null),
                'recorded_at' => $this->displayStamp($signal['at'] ?? null),
                'rule_state' => $current['rule_state'] ?? null,
                // Three-valued. False is "this rule has recovered"; null is "the alert states could
                // not be read, or the rule no longer exists", and those are not the same news.
                'still_firing' => $known && $current !== null ? $current['firing'] : null,
            ];
        }

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === [] ? 'The signals payload held no readable entry.' : null,
            'source' => self::SOURCE,
            'rows' => $rows,
            'truncated' => count($decoded) > self::MAX_SIGNALS,
        ];
    }

    /**
     * The rules still firing against this incident, which is why it is still open.
     *
     * @param  array<string, mixed>  $states
     * @return array<string, mixed>
     */
    private function holdingOpen(array $states, int $incidentId): array
    {
        if ($states['state'] !== 'ok') {
            return [
                'state' => $states['state'],
                'note' => $states['note'],
                'source' => self::STATES_SOURCE,
                'rows' => [],
                'attached' => null,
            ];
        }

        $attached = array_values($states['by_incident'][$incidentId] ?? []);
        $firing = array_values(array_filter($attached, static fn (array $rule) => $rule['firing']));

        return [
            'state' => $firing === [] ? 'no_data' : 'ok',
            'note' => $firing === []
                ? ($attached === []
                    ? 'No alert rule state points at this incident. Its rules were deleted, or its state rows were.'
                    : 'Every rule attached to this incident has recovered.')
                : null,
            'source' => self::STATES_SOURCE,
            'rows' => $firing,
            'attached' => count($attached),
        ];
    }

    /**
     * The live state of every rule that has ever been attached to an incident.
     *
     * One read for the whole page. The table holds one row per alert rule, so it is the rule list's
     * own size — a query per incident would make this page the slowest thing on the server.
     *
     * @return array<string, mixed>
     */
    private function alertStates(): array
    {
        try {
            $rows = $this->reader->connection()->table(self::STATES_SOURCE)
                ->whereNotNull('incident_id')
                ->limit(self::MAX_ALERT_STATES + 1)
                ->get(['rule_key', 'state', 'last_value', 'fired_at', 'recovered_at', 'fire_count', 'incident_id']);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'source' => self::STATES_SOURCE,
                'by_incident' => [],
                'truncated' => false,
            ];
        }

        $byIncident = [];
        foreach ($rows->take(self::MAX_ALERT_STATES) as $row) {
            $state = (string) $row->state;

            $byIncident[(int) $row->incident_id][(string) $row->rule_key] = [
                'rule' => mb_substr((string) $row->rule_key, 0, 96),
                'rule_state' => $state,
                'firing' => in_array($state, ['warning', 'critical'], true),
                'last_value' => $this->floatOrNull($row->last_value),
                'fired_at' => $this->displayStamp($row->fired_at),
                'recovered_at' => $this->displayStamp($row->recovered_at),
                'fire_count' => (int) $row->fire_count,
            ];
        }

        return [
            'state' => 'ok',
            'note' => null,
            'source' => self::STATES_SOURCE,
            'by_incident' => $byIncident,
            'truncated' => $rows->count() > self::MAX_ALERT_STATES,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // How long they took

    /**
     * The window's incidents, read once and folded twice — into the counts and into the mean times.
     *
     * @return array<string, mixed>
     */
    private function windowSample(string $range): array
    {
        try {
            $rows = $this->reader->connection()->table(self::SOURCE)
                ->where('started_at', '>=', $this->reader->since($range))
                ->orderByDesc('started_at')
                ->limit(self::MAX_TIMING_ROWS + 1)
                ->get(['severity', 'status', 'started_at', 'detected_at', 'resolved_at']);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'source' => self::SOURCE,
                'rows' => [],
                'truncated' => false,
            ];
        }

        $sample = [];
        foreach ($rows->take(self::MAX_TIMING_ROWS) as $row) {
            $sample[] = [
                'severity' => (string) $row->severity,
                'status' => (string) $row->status,
                'open' => in_array((string) $row->status, self::OPEN_STATUSES, true),
                'detect_seconds' => $this->elapsedSeconds($row->started_at, $row->detected_at),
                'resolve_seconds' => $this->elapsedSeconds($row->started_at, $row->resolved_at),
                'resolved' => $row->resolved_at !== null,
            ];
        }

        return [
            'state' => $sample === [] ? 'no_data' : 'ok',
            'note' => $sample === [] ? 'No incident started inside this window.' : null,
            'source' => self::SOURCE,
            'rows' => $sample,
            'truncated' => $rows->count() > self::MAX_TIMING_ROWS,
        ];
    }

    /**
     * MTTD and MTTR over the window, with the count each mean was taken over.
     *
     * A mean with no n beside it is not a statistic, and a mean over zero incidents is not a fast
     * response — it is the absence of one, so it is null rather than 0.
     *
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function resolution(array $sample): array
    {
        $base = [
            'source' => self::SOURCE,
            'truncated' => (bool) ($sample['truncated'] ?? false),
            'started' => 0,
            'resolved' => 0,
            'still_open' => 0,
            'undetected' => 0,
            'out_of_order' => 0,
            'mttd_seconds' => null,
            'mttd_samples' => 0,
            'mttr_seconds' => null,
            'mttr_samples' => 0,
            // The caveat travels with the number, not in a footnote under it.
            'mttd_caveat' => 'detected_at is stamped when the alert rule fired and started_at is when the metric first breached, so this gap is the rule hold time (for_seconds) rather than how long anybody took to notice.',
            'mttr_definition' => 'Measured from started_at to resolved_at over incidents that started inside this window and have since closed.',
        ];

        if ($sample['state'] === 'failed') {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $sample['note'],
                'remedy' => null,
            ]);
        }

        $detect = [];
        $resolve = [];
        $started = 0;
        $resolved = 0;
        $stillOpen = 0;
        $undetected = 0;
        $outOfOrder = 0;

        foreach ($sample['rows'] as $row) {
            $started++;

            if ($row['open']) {
                $stillOpen++;
            }

            if ($row['detect_seconds'] === null) {
                $undetected++;
            } elseif ($row['detect_seconds'] < 0) {
                // Detected before it started. Real rows, impossible durations — folding them into a
                // mean would drag it below zero, so they are excluded and counted out loud.
                $outOfOrder++;
            } else {
                $detect[] = $row['detect_seconds'];
            }

            if ($row['resolved']) {
                $resolved++;
                if ($row['resolve_seconds'] !== null && $row['resolve_seconds'] >= 0) {
                    $resolve[] = $row['resolve_seconds'];
                } elseif ($row['resolve_seconds'] !== null) {
                    $outOfOrder++;
                }
            }
        }

        return array_merge($base, [
            'state' => $sample['rows'] === [] ? 'no_data' : 'ok',
            'note' => $sample['rows'] === [] ? $sample['note'] : null,
            'remedy' => null,
            'started' => $started,
            'resolved' => $resolved,
            'still_open' => $stillOpen,
            'undetected' => $undetected,
            'out_of_order' => $outOfOrder,
            'mttd_seconds' => $detect === [] ? null : round(array_sum($detect) / count($detect), 1),
            'mttd_samples' => count($detect),
            'mttr_seconds' => $resolve === [] ? null : round(array_sum($resolve) / count($resolve), 1),
            'mttr_samples' => count($resolve),
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // The incident rows on the timeline

    /**
     * Incident lifecycle events, as EventLog wrote them.
     *
     * A second, independent record of the same incidents: IncidentManager records one event when an
     * incident opens and one when it closes. Where the two disagree the disagreement is the finding
     * — an event with no incident behind it means a row was pruned, and an incident with no events
     * means it predates the axis.
     *
     * @return array<string, mixed>
     */
    private function events(string $range): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_events')
                ->where('type', 'incident')
                ->where('occurred_at', '>=', $this->reader->since($range))
                ->orderByDesc('occurred_at')
                ->limit(self::MAX_EVENTS + 1)
                ->get(['severity', 'title', 'description', 'key', 'related_id', 'occurred_at']);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The event table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
                'source' => self::EVENTS_SOURCE,
                'rows' => [],
                'truncated' => false,
                'limit' => self::MAX_EVENTS,
            ];
        }

        $presented = [];
        foreach ($rows->take(self::MAX_EVENTS) as $row) {
            $severity = (string) $row->severity;

            $presented[] = [
                'severity' => $severity,
                'severity_known' => in_array($severity, self::EVENT_SEVERITIES, true),
                // EventLog composes these from the rule name and the incident reference, so they are
                // free strings by the time they are stored and are echoed, never translated.
                'title' => $this->redactor->text(mb_substr((string) $row->title, 0, 191)),
                'description' => $this->shortText($row->description, 191),
                'reference' => $this->shortText($row->key, 16),
                'incident_id' => $row->related_id === null ? null : (int) $row->related_id,
                'occurred_at' => $this->displayStamp($row->occurred_at),
            ];
        }

        return [
            'state' => $presented === [] ? 'no_data' : 'ok',
            'note' => $presented === []
                ? 'No incident opened or closed inside this window, so the timeline has no incident row for it.'
                : null,
            'remedy' => null,
            'source' => self::EVENTS_SOURCE,
            'rows' => $presented,
            'truncated' => $rows->count() > self::MAX_EVENTS,
            'limit' => self::MAX_EVENTS,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // How much of an incident has been filled in

    /**
     * Six columns that used to have no writer anywhere, and now have one.
     *
     * They are still published as readings rather than left as empty cells, because the distinction
     * that mattered before still matters: a blank "probable cause" reads as a cause that was looked
     * for and not found. What changed is the reason a cell is empty — it is now that nobody has
     * filled it in on THIS incident, not that the product cannot record it — so the remedy names the
     * action rather than the missing feature.
     *
     * @return array<string, mixed>
     */
    private function unwritten(): array
    {
        $action = 'Recorded from the incident itself, in Monitoring → Incidents.';

        return [
            'state' => 'not_configured',
            'source' => self::SOURCE,
            'note' => 'These columns are filled in by whoever handles the incident. They are shown as unconfigured readings rather than as empty cells, because a blank cause column reads as a cause that was looked for and not found. Every one of them is read back onto the incident above the moment it holds a value.',
            'fields' => [
                'probable_cause' => Metric::notConfigured(
                    source: self::SOURCE . '.probable_cause',
                    remedy: $action . ' The form offers the deploys that ran within two hours either side, so a release can be attached without guessing.',
                    note: 'No cause has been recorded on the incidents in this window. The cause is stated by a person; it is never inferred from a timestamp.',
                ),
                'cause_evidence' => Metric::notConfigured(
                    source: self::SOURCE . '.cause_evidence',
                    remedy: $action . ' It is the long-form companion to probable_cause and is written with it.',
                    note: 'No evidence has been written, because no cause has been recorded to evidence.',
                ),
                'deployment_id' => Metric::notConfigured(
                    source: self::SOURCE . '.deployment_id',
                    remedy: $action . ' Deploys are recorded in the Deployments section — from that page or from `php artisan monitoring:deploy-recorded` — and the incident form lists the ones near this incident.',
                    note: 'No incident in this window is tied to a release.',
                ),
                'acknowledged_at' => Metric::notConfigured(
                    source: self::SOURCE . '.acknowledged_at',
                    remedy: $action,
                    note: 'No incident in this window has been acknowledged, so time-to-acknowledge is not measured for it. That is different from every incident being acknowledged instantly.',
                ),
                'resolved_by' => Metric::notConfigured(
                    source: self::SOURCE . '.resolved_by',
                    remedy: $action . ' Incidents also close on their own, in ' . self::WRITER . '::releaseIfResolved(), and an automatic closure has no person to record.',
                    note: 'Every closure in this window was automatic, so none has a person behind it.',
                ),
                'notes' => Metric::notConfigured(
                    source: self::SOURCE . '.notes',
                    remedy: $action . ' Notes are appended and stamped, never replaced — the attempt that did not work is usually the one worth reading.',
                    note: 'No notes have been written on the incidents in this window.',
                ),
            ],
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A failed read, said in one line that is safe to print.
     *
     * A QueryException carries the statement and its bindings, and an exception message is one of
     * the most reliable places in an application to find a token or a customer's address — so it
     * goes through the redactor and is bounded before it reaches a page an operator can screenshot.
     */
    private function failureNote(\Throwable $exception): string
    {
        return class_basename($exception) . ': '
            . $this->redactor->text(mb_substr($exception->getMessage(), 0, 400));
    }

    /**
     * A stored UTC stamp, in the timezone the dashboard renders in.
     *
     * Every timestamp on this page passes through here. Printing a stored value directly would put
     * an incident hours away from the deploy it followed on any deployment whose display timezone
     * is not UTC, which is the class of bug the Clock exists to prevent.
     */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the incident really
            // happened, and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    private function minutesSince(mixed $stored): ?int
    {
        $seconds = $this->secondsSince($stored);

        return $seconds === null ? null : intdiv($seconds, 60);
    }

    private function secondsSince(mixed $stored): ?int
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return (int) Clock::parse($stored)->diffInSeconds(Clock::now(), false);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The signed gap between two stored stamps, or null when either cannot be read. */
    private function elapsedSeconds(mixed $from, mixed $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        try {
            return (int) Clock::parse($from)->diffInSeconds(Clock::parse($to), false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A count, or null when the payload had none to give.
     *
     * Null is preserved rather than cast: (int) null is 0, and a zero in a samples column would say
     * the rule fired on no measurements at all.
     */
    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function shortText(mixed $value, int $length): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->redactor->text(mb_substr(trim($value), 0, $length));
    }
}
