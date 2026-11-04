<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One axis: everything that happened to this system, in the order it happened.
 *
 * The value of a timeline is not the list. It is the sentence "p95 doubled at 14:20 and the deploy
 * was at 14:19" — and that sentence is only available when every kind of event lands on the same
 * axis. So this panel reads monitoring_events across all eight types rather than one, and lays the
 * two tables that carry their own history — monitoring_deployments and monitoring_incidents —
 * beside it, so a release or an outage that never reached the axis is visible as a gap rather than
 * as an absence of trouble.
 *
 * The trap this page is shaped around: an empty axis reads as a quiet week. It is not. Six of the
 * eight types now have producers — deploy, backup, incident, alert, check and scheduler — and two,
 * config and annotation, have none anywhere in this build. A page that drew all eight as one silent
 * line would report calm where the truth is that nobody is writing. So the legend states, per type,
 * whether this build can produce it at all, when it last did, and how many are inside the window;
 * and every count on this page is measured rather than assumed, so a zero here is a real zero.
 *
 * Nothing on this page is a measurement taken now. The axis is a record, written at the moment each
 * thing happened by the producer that knew about it.
 */
class TimelinePanel implements Panel
{
    /**
     * How many entries the axis carries.
     *
     * A day of check transitions on a flapping dependency can fill a table on its own, so the read
     * is capped and the page says out loud when the window held more than it drew. An axis silently
     * cut at its own limit is an axis that has lost the oldest half of the story it is telling.
     */
    private const MAX_ENTRIES = 250;

    /** Upper bound on the two tables laid against the axis. */
    private const MAX_ROWS = 25;

    /** Upper bound on the grouped counts, which a schema this narrow bounds naturally anyway. */
    private const MAX_GROUPS = 200;

    /** How many context pairs are drawn per entry before the rest is declared rather than printed. */
    private const MAX_CONTEXT_KEYS = 8;

    /** The severity vocabulary EventLog writes. A value outside it came from somewhere else. */
    private const SEVERITIES = [EventLog::CRITICAL, EventLog::WARNING, EventLog::SUCCESS, EventLog::INFO];

    /** The severities monitoring_incidents was designed for (see the operations migration). */
    private const INCIDENT_SEVERITIES = ['critical', 'major', 'minor', 'warning'];

    /** The statuses monitoring_incidents was designed for. */
    private const INCIDENT_STATUSES = ['open', 'investigating', 'monitoring', 'resolved'];

    /**
     * Which producer writes each type, in this build, today.
     *
     * Authored here rather than inferred, because the difference between "no deploy happened" and
     * "nothing in this codebase records a deploy" is the whole reason this section can be read at
     * all — and it is a fact about the code, not a measurement, so it must not be guessed at from
     * an empty table. Every entry below was checked against its call site.
     *
     * @var array<string, array{produced: bool, producer: string|null, note: string, remedy: string|null}>
     */
    private const PRODUCERS = [
        EventLog::DEPLOY => [
            'produced' => true,
            'producer' => 'php artisan monitoring:deploy-recorded',
            'note' => 'Written once per release by the deploy recorder, which defaults the release from version.json and the commit sha from .git. A deploy that ran without calling it leaves no mark here.',
            'remedy' => null,
        ],
        EventLog::SCHEDULER => [
            'produced' => true,
            'producer' => 'MonitoringServiceProvider, on ScheduledTaskFailed',
            'note' => 'Only failures reach the axis. A scheduled task that ran perfectly is recorded in monitoring_scheduled_runs and deliberately not here, or a minute-by-minute schedule would bury every other type.',
            'remedy' => null,
        ],
        EventLog::BACKUP => [
            'produced' => true,
            'producer' => 'php artisan monitoring:backup-recorded, php artisan monitoring:restore-tested',
            'note' => 'Written by the backup recorder and the restore tester. Nothing in this application takes a backup — these record that one was taken elsewhere.',
            'remedy' => null,
        ],
        EventLog::INCIDENT => [
            'produced' => true,
            'producer' => 'IncidentManager, on open and on resolve',
            'note' => 'An incident is opened by the alert engine and closed when no rule still points at it. The intermediate statuses the schema allows — investigating, monitoring — are never written, so no event announces them.',
            'remedy' => null,
        ],
        EventLog::ALERT => [
            'produced' => true,
            'producer' => 'AlertEvaluator, on the first fire of an episode and on recovery',
            'note' => 'One entry when a rule starts firing and one when it recovers — not one per cooldown repeat, so a rule that has been firing for an hour is one line here rather than twelve.',
            'remedy' => null,
        ],
        EventLog::CONFIG => [
            'produced' => true,
            'producer' => 'php artisan monitoring:synthetic',
            'note' => 'Written when a synthetic journey is defined or removed. Monitoring has no settings screen that writes — every route in the section is a GET — so a threshold or an alert rule changed straight in the database still leaves no mark here.',
            'remedy' => null,
        ],
        EventLog::CHECK => [
            'produced' => true,
            'producer' => 'CheckRunner, on a transition between up and down',
            'note' => 'Only the crossings: ok or degraded to failing, and back. A check that has been failing for a day is one entry, not two hundred and eighty-eight.',
            'remedy' => null,
        ],
        EventLog::ANNOTATION => [
            'produced' => true,
            'producer' => 'php artisan monitoring:annotate',
            'note' => 'The one entry a machine cannot write: "we restarted the database at 14:00", "this spike is the radio advert". Takes --at, so a note typed at 09:00 lands on the axis at 02:00 where the thing actually happened.',
            'remedy' => null,
        ],
    ];

    /**
     * Where an entry's related_id points, per type.
     *
     * Named rather than linked: the sections that own these tables are not all installed in this
     * build, and a link into a page that says "not installed" is worse than the table name and the
     * row id, which are what somebody would query with anyway.
     */
    private const RELATED_TABLES = [
        EventLog::DEPLOY => 'monitoring_deployments',
        EventLog::BACKUP => 'monitoring_backups',
        EventLog::INCIDENT => 'monitoring_incidents',
        EventLog::ALERT => 'monitoring_incidents',
    ];

    private const SOURCE = 'monitoring_events';

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $filters = $this->filters($request);
        $counts = $this->counts($range);
        $history = $this->history();

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'filters' => $filters,
            'axis' => $this->axis($range, $filters, $counts, $history),
            'counts' => $counts,
            'legend' => $this->legend($counts, $history, $filters),
            'deployments' => $this->deployments($range),
            'incidents' => $this->incidents($range),
            // The pruner keeps the axis for this many days. An entry older than it was deleted,
            // which is a different fact from one that was never written.
            'retention_days' => max(1, app(MonitoringSettings::class)->retentionDays('incident_days', 400)),
            // This panel reads no collector — every column it reads is drawn. Kept so the section
            // closes on the same shape as every other one.
            'unrendered' => [],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What the operator asked for

    /**
     * The filter row, normalised against the vocabularies this axis actually holds.
     *
     * Both values reach a WHERE clause, so neither is trusted. An unrecognised type or severity is
     * dropped rather than queried: it can only ever match nothing, and an empty axis under a filter
     * nobody chose reads as "nothing happened".
     *
     * @return array<string, mixed>
     */
    /** One query value, or an empty string when it is not a single string. */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key, '');

        return is_string($value) ? trim($value) : '';
    }

    private function filters(Request $request): array
    {
        // `?type[]=x` hands the request an ARRAY, and casting one to string is a PHP warning the
        // error handler turns into a throw — which took this whole section down with an "Array to
        // string conversion" card. A filter nobody can spell is simply not applied.
        $type = $this->queryString($request, 'type');
        $severity = $this->queryString($request, 'severity');

        $type = in_array($type, EventLog::TYPES, true) ? $type : null;
        $severity = in_array($severity, self::SEVERITIES, true) ? $severity : null;

        return [
            'type' => $type,
            'severity' => $severity,
            'active' => $type !== null || $severity !== null,
            'types' => EventLog::TYPES,
            'severities' => self::SEVERITIES,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The axis

    /**
     * Every event in the window, newest first, grouped into days.
     *
     * Days rather than a flat list because the question this page is opened with is almost always
     * "what else happened around then", and a date heading is what makes that readable.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function axis(string $range, array $filters, array $counts, array $history): array
    {
        try {
            $query = $this->reader->connection()->table(self::SOURCE)
                ->where('occurred_at', '>=', $this->reader->since($range));

            if ($filters['type'] !== null) {
                $query->where('type', $filters['type']);
            }
            if ($filters['severity'] !== null) {
                $query->where('severity', $filters['severity']);
            }

            $rows = $query->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::MAX_ENTRIES + 1)
                ->get(['id', 'type', 'key', 'severity', 'title', 'description', 'context', 'related_id', 'occurred_at']);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing the axis blanks one card, while
            // letting it escape would blank the legend that explains what this page can even hold.
            return $this->emptyAxis(
                'failed',
                Metric::describeFailure($exception),
                null,
            );
        }

        $truncated = $rows->count() > self::MAX_ENTRIES;
        $today = Clock::display(Clock::now())->toDateString();
        $days = [];
        $drawn = 0;

        foreach ($rows->take(self::MAX_ENTRIES) as $row) {
            $moment = $this->moment($row->occurred_at);
            $date = $moment['date'] ?? '';

            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'is_today' => $date !== '' && $date === $today,
                    'count' => 0,
                    'entries' => [],
                ];
            }

            $days[$date]['entries'][] = $this->entry($row, $moment);
            $days[$date]['count']++;
            $drawn++;
        }

        return [
            'state' => $drawn === 0 ? 'no_data' : 'ok',
            'note' => $drawn === 0 ? $this->whyEmpty($filters, $counts, $history) : null,
            'remedy' => $drawn === 0 ? $this->emptyRemedy($filters, $counts, $history) : null,
            'source' => self::SOURCE,
            'days' => array_values($days),
            'entries' => $drawn,
            'newest_ever' => $this->newestEver($history),
            'truncated' => $truncated,
            'limit' => self::MAX_ENTRIES,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyAxis(string $state, ?string $note, ?string $remedy): array
    {
        return [
            'state' => $state,
            'note' => $note,
            'remedy' => $remedy,
            'source' => self::SOURCE,
            'days' => [],
            'entries' => 0,
            'newest_ever' => null,
            'truncated' => false,
            'limit' => self::MAX_ENTRIES,
        ];
    }

    /**
     * One entry, with everything the row carries and nothing it does not.
     *
     * type and severity are free strings at the database level, so each is flagged as recognised or
     * not: the view may only translate a value this build authored, and putting an unknown one
     * through translate() would mint a language key per stored value.
     *
     * @param  array<string, string|null>  $moment
     * @return array<string, mixed>
     */
    private function entry(object $row, array $moment): array
    {
        $type = (string) ($row->type ?? '');
        $context = $this->context($row->context);

        return [
            'id' => (int) $row->id,
            'type' => $type,
            'type_known' => in_array($type, EventLog::TYPES, true),
            'severity' => (string) ($row->severity ?? ''),
            'severity_known' => in_array((string) ($row->severity ?? ''), self::SEVERITIES, true),
            'key' => $this->text($row->key, 96),
            'title' => $this->text($row->title, 185) ?? '',
            'description' => $this->text($row->description, 400),
            'context' => $context['pairs'],
            'context_truncated' => $context['truncated'],
            'related_id' => $row->related_id === null ? null : (int) $row->related_id,
            'related_table' => $row->related_id === null ? null : (self::RELATED_TABLES[$type] ?? null),
            'at' => $moment['at'],
            'date' => $moment['date'],
            'time' => $moment['time'],
        ];
    }

    /**
     * Why the axis drew nothing, which is four different situations that share one empty card.
     *
     * "Your filter excludes everything in this window", "this window is quiet", "nothing has ever
     * been recorded" and "the count that would tell us could not be read" lead to four different
     * places, and only one of them is good news.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $history
     */
    private function whyEmpty(array $filters, array $counts, array $history): string
    {
        if (!config('monitoring.enabled', true)) {
            return 'Monitoring collection is switched off, so the producers that write this axis have recorded nothing since it was disabled — this is not a reading of a quiet window.';
        }

        if ($counts['state'] === 'failed') {
            return 'No entry matched, and the count that would say whether this window holds any events at all could not be read: ' . (string) $counts['note'];
        }

        if ($filters['active'] && (int) ($counts['total'] ?? 0) > 0) {
            return 'This window holds ' . number_format((int) $counts['total'])
                . ' events, and none of them match the current filter. The axis is not empty — this view of it is.';
        }

        if ($history['state'] === 'ok' && $history['by_type'] === []) {
            return 'No event of any type has ever been recorded on this axis, in this window or any other. Six of the eight types have a producer in this build, so this is a system that has not yet deployed, alerted, backed up or failed a check since monitoring was installed.';
        }

        $newest = $this->newestEver($history);

        return $newest !== null
            ? 'No event was recorded inside this window. The newest entry on the axis is from ' . $newest . ', so the axis is working and this window is genuinely quiet.'
            : 'No event was recorded inside this window.';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $history
     */
    private function emptyRemedy(array $filters, array $counts, array $history): ?string
    {
        if (!config('monitoring.enabled', true)) {
            return 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.';
        }

        // A filtered view of a populated axis is a choice, not a fault, and offering a fix for it
        // would invent one. The view offers to clear the filter instead.
        if ($filters['active'] && (int) ($counts['total'] ?? 0) > 0) {
            return null;
        }

        if ($history['state'] === 'ok' && $history['by_type'] === []) {
            return 'The axis fills itself as things happen. To put the first entry on it deliberately: `php artisan monitoring:deploy-recorded --by="$(whoami)"`.';
        }

        return null;
    }

    /** @param array<string, mixed> $history */
    private function newestEver(array $history): ?string
    {
        $newest = null;
        foreach ($history['by_type'] ?? [] as $stamp) {
            if (is_string($stamp) && ($newest === null || $stamp > $newest)) {
                $newest = $stamp;
            }
        }

        return $newest;
    }

    // -------------------------------------------------------------------------------------------
    // What the window holds, and what the axis has ever held

    /**
     * Every type and severity in the window, counted once.
     *
     * Counted without the filter applied on purpose: the filter row has to be able to say how many
     * entries each option would show, and an axis filtered to one type must still be able to say
     * that the other seven are not empty.
     *
     * @return array<string, mixed>
     */
    private function counts(string $range): array
    {
        try {
            $connection = $this->reader->connection();
            $rows = $connection->table(self::SOURCE)
                ->where('occurred_at', '>=', $this->reader->since($range))
                ->groupBy('type', 'severity')
                ->limit(self::MAX_GROUPS + 1)
                ->get(['type', 'severity', $connection->raw('COUNT(*) AS occurrences')]);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
                'source' => self::SOURCE,
                'by_type' => [],
                'by_severity' => [],
                // Null rather than zero: this read did not find nothing, it did not look.
                'total' => null,
                'known' => false,
                'truncated' => false,
            ];
        }

        $byType = [];
        $bySeverity = [];
        $total = 0;

        foreach ($rows->take(self::MAX_GROUPS) as $row) {
            $occurrences = (int) $row->occurrences;
            $total += $occurrences;
            $type = (string) $row->type;
            $severity = (string) $row->severity;

            $byType[$type] = ($byType[$type] ?? 0) + $occurrences;
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + $occurrences;
        }

        arsort($byType);
        arsort($bySeverity);

        return [
            'state' => $total === 0 ? 'no_data' : 'ok',
            'note' => $total === 0 ? 'No event of any type was recorded inside this window.' : null,
            'remedy' => null,
            'source' => self::SOURCE,
            'by_type' => $byType,
            'by_severity' => $bySeverity,
            'total' => $total,
            'known' => true,
            'truncated' => $rows->count() > self::MAX_GROUPS,
        ];
    }

    /**
     * The newest entry of each type, over the whole retained axis.
     *
     * A type with nothing in this window and nothing ever recorded is a producer that has never
     * run; the same type with an entry from last month is a producer that works and had nothing to
     * say. Drawn from the (type, occurred_at) index, so it stays a lookup rather than a scan.
     *
     * @return array<string, mixed>
     */
    private function history(): array
    {
        try {
            $connection = $this->reader->connection();
            $rows = $connection->table(self::SOURCE)
                ->groupBy('type')
                ->limit(self::MAX_GROUPS + 1)
                ->get(['type', $connection->raw('MAX(occurred_at) AS newest')]);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'source' => self::SOURCE,
                'by_type' => [],
            ];
        }

        $byType = [];
        foreach ($rows->take(self::MAX_GROUPS) as $row) {
            $stamp = $this->displayStamp($row->newest);
            if ($stamp !== null) {
                $byType[(string) $row->type] = $stamp;
            }
        }

        return [
            'state' => 'ok',
            'note' => null,
            'source' => self::SOURCE,
            'by_type' => $byType,
        ];
    }

    /**
     * The eight types, each stating whether this build can produce it.
     *
     * This is the part of the page that stops a silent axis from reading as a calm week. Two types
     * have no producer anywhere in the code; their absence is not evidence that nothing happened,
     * and saying so per type is the only honest way to draw them.
     *
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $history
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function legend(array $counts, array $history, array $filters): array
    {
        $types = [];

        foreach (EventLog::TYPES as $type) {
            $producer = self::PRODUCERS[$type];

            $types[] = [
                'type' => $type,
                'produced' => $producer['produced'],
                // not_supported rather than not_configured: no setting turns these on. They need
                // code that does not exist in this build, which is not the merchant's to change.
                'state' => $producer['produced'] ? 'ok' : 'not_supported',
                'producer' => $producer['producer'],
                'note' => $producer['note'],
                'remedy' => $producer['remedy'],
                'in_window' => $counts['known'] ? (int) ($counts['by_type'][$type] ?? 0) : null,
                'in_window_known' => (bool) $counts['known'],
                'last_seen_at' => $history['by_type'][$type] ?? null,
                'last_seen_known' => $history['state'] === 'ok',
                'selected' => $filters['type'] === $type,
            ];
        }

        $unproducible = array_values(array_filter(
            EventLog::TYPES,
            static fn (string $type) => !self::PRODUCERS[$type]['produced'],
        ));

        // Types the axis holds that this build does not name. A row written by hand, or by a
        // producer added after this panel — either way it is drawn rather than dropped.
        $foreign = array_values(array_diff(array_keys($counts['by_type'] ?? []), EventLog::TYPES));

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => 'App\Services\Monitoring\EventLog and its call sites',
            'types' => $types,
            'produced_count' => count(EventLog::TYPES) - count($unproducible),
            'total_count' => count(EventLog::TYPES),
            'unproducible' => $unproducible,
            'foreign_types' => $foreign,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The two tables laid against the axis

    /**
     * Releases recorded in this window, and whether each one reached the axis.
     *
     * The cross-check is the point. A deployment row whose event never landed is a hole in the
     * timeline, and without this column the timeline would simply not mention that release — which
     * is indistinguishable from a week in which nothing shipped.
     *
     * @return array<string, mixed>
     */
    private function deployments(string $range): array
    {
        $source = 'monitoring_deployments';

        try {
            $rows = $this->reader->connection()->table($source)
                ->where('deployed_at', '>=', $this->reader->since($range))
                ->orderByDesc('deployed_at')
                ->limit(self::MAX_ROWS + 1)
                ->get(['id', 'release', 'commit_sha', 'branch', 'environment', 'deployed_by', 'status', 'duration_seconds', 'migrations_run', 'notes', 'deployed_at']);
        } catch (\Throwable $exception) {
            return $this->emptyTable('failed', Metric::describeFailure($exception), null, $source);
        }

        $truncated = $rows->count() > self::MAX_ROWS;
        $kept = $rows->take(self::MAX_ROWS);
        $onAxis = $this->relatedIds(EventLog::DEPLOY, $kept->pluck('id')->all());

        $presented = [];
        foreach ($kept as $row) {
            $presented[] = [
                'id' => (int) $row->id,
                'release' => $this->text($row->release, 40),
                'commit_sha' => $this->text($row->commit_sha, 40),
                'branch' => $this->text($row->branch, 96),
                'environment' => $this->text($row->environment, 24),
                'deployed_by' => $this->text($row->deployed_by, 96),
                'status' => (string) ($row->status ?? ''),
                'status_known' => in_array((string) ($row->status ?? ''), ['success', 'failed', 'unknown'], true),
                'duration_seconds' => $row->duration_seconds === null ? null : (int) $row->duration_seconds,
                'migrations_run' => $row->migrations_run === null ? null : (int) $row->migrations_run,
                'notes' => $this->text($row->notes, 200),
                'deployed_at' => $this->displayStamp($row->deployed_at),
                // Three-valued: false means the lookup ran and found no entry, null means it could
                // not run, and those send somebody to two different places.
                'on_axis' => $onAxis === null ? null : in_array((int) $row->id, $onAxis, true),
            ];
        }

        return [
            'state' => $presented === [] ? 'no_data' : 'ok',
            'note' => $presented === []
                ? 'No release was recorded in this window. Nothing records one automatically — a deploy that nobody reported is a deploy this page cannot know about, so this is not a claim that nothing shipped.'
                : null,
            'remedy' => $presented === []
                ? 'Add `php artisan monitoring:deploy-recorded --by="$(whoami)" --duration=$SECONDS` as the last step of the deployment script.'
                : null,
            'source' => $source,
            'rows' => $presented,
            'truncated' => $truncated,
            'limit' => self::MAX_ROWS,
            'on_axis_known' => $onAxis !== null,
            'missing_from_axis' => $onAxis === null
                ? null
                : count(array_filter($presented, static fn (array $row) => $row['on_axis'] === false)),
        ];
    }

    /**
     * Incidents that started in this window, and whether each one reached the axis.
     *
     * Started rather than overlapped: an incident that opened last week and is still open is not
     * something that happened inside this window, and drawing it as though it were would put an
     * event on the axis at a time it did not occur.
     *
     * @return array<string, mixed>
     */
    private function incidents(string $range): array
    {
        $source = 'monitoring_incidents';

        try {
            $rows = $this->reader->connection()->table($source)
                ->where('started_at', '>=', $this->reader->since($range))
                ->orderByDesc('started_at')
                ->limit(self::MAX_ROWS + 1)
                ->get(['id', 'reference', 'title', 'severity', 'status', 'started_at', 'detected_at', 'resolved_at']);
        } catch (\Throwable $exception) {
            return $this->emptyTable('failed', Metric::describeFailure($exception), null, $source);
        }

        $truncated = $rows->count() > self::MAX_ROWS;
        $kept = $rows->take(self::MAX_ROWS);
        $onAxis = $this->relatedIds(EventLog::INCIDENT, $kept->pluck('id')->all());

        $presented = [];
        foreach ($kept as $row) {
            $status = (string) ($row->status ?? '');

            $presented[] = [
                'id' => (int) $row->id,
                'reference' => $this->text($row->reference, 16),
                'title' => $this->text($row->title, 191),
                'severity' => (string) ($row->severity ?? ''),
                'severity_known' => in_array((string) ($row->severity ?? ''), self::INCIDENT_SEVERITIES, true),
                'status' => $status,
                'status_known' => in_array($status, self::INCIDENT_STATUSES, true),
                'started_at' => $this->displayStamp($row->started_at),
                'detected_at' => $this->displayStamp($row->detected_at),
                'resolved_at' => $this->displayStamp($row->resolved_at),
                'resolved' => $row->resolved_at !== null,
                'on_axis' => $onAxis === null ? null : in_array((int) $row->id, $onAxis, true),
            ];
        }

        return [
            'state' => $presented === [] ? 'no_data' : 'ok',
            'note' => $presented === []
                ? 'No incident started in this window. Every incident here is opened by the alert engine correlating firing rules, so an outage that broke no alert rule never becomes one — an empty list is a statement about the rules, not only about the week.'
                : null,
            'remedy' => $presented === []
                ? 'Incidents are opened by `php artisan monitoring:evaluate`, which runs every minute. Check the rules it evaluates under Monitoring → Alerts.'
                : null,
            'source' => $source,
            'rows' => $presented,
            'truncated' => $truncated,
            'limit' => self::MAX_ROWS,
            'on_axis_known' => $onAxis !== null,
            'missing_from_axis' => $onAxis === null
                ? null
                : count(array_filter($presented, static fn (array $row) => $row['on_axis'] === false)),
        ];
    }

    /**
     * Which of these row ids an event of this type points at.
     *
     * Null when the lookup could not run, so a row can say "not checked" rather than being drawn as
     * one that never reached the axis — the second is a bug report and the first is not.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, int>|null
     */
    private function relatedIds(string $type, array $ids): ?array
    {
        $ids = array_values(array_filter(array_map(
            static fn ($id) => is_numeric($id) ? (int) $id : null,
            $ids,
        ), static fn (?int $id) => $id !== null));

        if ($ids === []) {
            return [];
        }

        try {
            return $this->reader->connection()->table(self::SOURCE)
                ->where('type', $type)
                ->whereIn('related_id', $ids)
                ->limit(self::MAX_ROWS * 4)
                ->pluck('related_id')
                ->map(static fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function emptyTable(string $state, ?string $note, ?string $remedy, string $source): array
    {
        return [
            'state' => $state,
            'note' => $note,
            'remedy' => $remedy,
            'source' => $source,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_ROWS,
            'on_axis_known' => false,
            'missing_from_axis' => null,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Turning stored columns into something a page can hold

    /**
     * The stored context, flattened into pairs a table cell can carry.
     *
     * It was redacted where it was written; it is bounded here, because a context is free JSON at
     * the database level and one oversized value would push the whole payload past what the JSON
     * refresh can carry. A null value is kept rather than dropped: "the recorder wrote no branch"
     * and "the recorder wrote nothing" are different facts.
     *
     * @return array{pairs: array<int, array{key: string, value: scalar|null, is_json: bool}>, truncated: bool}
     */
    private function context(mixed $stored): array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return ['pairs' => [], 'truncated' => false];
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return ['pairs' => [], 'truncated' => false];
        }

        $pairs = [];
        foreach ($decoded as $key => $value) {
            if (count($pairs) >= self::MAX_CONTEXT_KEYS) {
                break;
            }

            $isJson = is_array($value);
            if ($isJson) {
                $value = (string) json_encode($value);
            }

            $pairs[] = [
                'key' => (string) Str::limit((string) $key, 48, ''),
                'value' => is_scalar($value) ? $this->boundedValue($value) : null,
                'is_json' => $isJson,
            ];
        }

        return ['pairs' => $pairs, 'truncated' => count($decoded) > count($pairs)];
    }

    /** @return scalar */
    private function boundedValue(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            // Non-finite floats cannot be encoded and would take the whole payload with them.
            return is_float($value) && !is_finite($value) ? (string) $value : $value;
        }

        return $this->text($value, 160) ?? '';
    }

    /**
     * A stored string, safe to put in a JSON payload.
     *
     * Three things happen here and all three are load-bearing: invalid bytes are repaired, because
     * one of them makes json_encode return false and takes the whole section with it; secrets are
     * scrubbed a second time, since a check detail or a deploy note is a reliable place to find a
     * token; and the length is bounded so one pathological row cannot dominate the page.
     */
    private function text(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return Str::limit($this->redactor->text($value), $limit);
    }

    /**
     * A stored moment, split for a day-grouped axis.
     *
     * @return array{at: string|null, date: string, time: string|null}
     */
    private function moment(mixed $stored): array
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return ['at' => null, 'date' => '', 'time' => null];
        }

        try {
            $moment = Clock::display($stored);

            return [
                'at' => $moment->toDateTimeString(),
                'date' => $moment->toDateString(),
                'time' => $moment->format('H:i:s'),
            ];
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the event really
            // happened, and inventing a time for it would be worse than showing the raw value.
            $raw = is_scalar($stored) ? (string) $stored : '';

            return ['at' => $raw === '' ? null : $raw, 'date' => '', 'time' => null];
        }
    }

    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            return is_scalar($stored) ? (string) $stored : null;
        }
    }
}
