<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Alerting\AlertEvaluator;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Alerting\MetricResolver;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * The alert engine: what it watches, what it is saying, and whether it is still awake.
 *
 * Two questions decide whether this page is worth anything, and they are asked in this order.
 *
 * First: is the engine running? Every other statement here — "nothing is firing", "everything is
 * ok" — is a claim about the last time `monitoring:evaluate` actually ran. If that was an hour
 * ago, a table of green pills is not calm, it is a stopped clock, so the freshness of the state
 * rows is established before a single rule is shown.
 *
 * Second: could each rule fire at all? A rule watching a metric that has not arrived for two days
 * is silent for the same reason a healthy shop is silent, and the two are indistinguishable from
 * the state column alone. Each row therefore carries whether its metric has been seen recently.
 *
 * A rule with no state row has NEVER been evaluated. That is not "ok", and it is not zero — it is
 * its own state, and it is drawn as one.
 */
class AlertsPanel implements Panel
{
    /**
     * A rule set is an operator's list, not a data table, so this ceiling is never reached in
     * practice — it is here because an unbounded read on a monitoring page is how the monitoring
     * page becomes the incident.
     */
    private const RULE_LIMIT = 200;

    private const EVENT_LIMIT = 50;

    private const RULES_SOURCE = 'monitoring_alert_rules';

    private const STATES_SOURCE = 'monitoring_alert_states';

    private const EVENTS_SOURCE = 'monitoring_events';

    /** States in which a rule is actively saying something is wrong. */
    private const FIRING_STATES = ['warning', 'critical'];

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly AlertEvaluator $evaluator,
        private readonly MetricResolver $metrics,
    ) {
    }

    /**
     * The window selector is deliberately ignored here.
     *
     * A rule and its state are current facts with no window to read them over, and alert history
     * is sparse by design — narrowing it to the selected five minutes would show an empty list on
     * a system that alerted twice this morning. The history is bounded by the retention window
     * instead, and says which one it used.
     */
    public function data(string $range, Request $request): array
    {
        $rules = $this->rules();
        $engine = $this->engine($rules);
        $events = $this->events($rules);

        return [
            'engine' => $engine,
            'summary' => $this->summary($rules),
            'readings' => $this->readings($engine, $rules, $events),
            'rules' => $rules,
            'firing' => $this->firing($rules, $engine),
            'events' => $events,
        ];
    }

    /**
     * Every rule, with the state row belonging to it.
     *
     * Two queries and a join in PHP rather than one query with a JOIN: monitoring may live on its
     * own database, and the habit of joining across the boundary is the habit that breaks the day
     * somebody sets MONITORING_DB_CONNECTION. It also costs nothing here — both sides are the size
     * of an operator's rule list.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        try {
            $rows = $this->reader->connection()->table(self::RULES_SOURCE)
                ->orderBy('key')
                ->limit(self::RULE_LIMIT)
                ->get();
        } catch (\Throwable $exception) {
            // PanelRegistry would catch this and blank the section. Failing this part by name
            // leaves the event history and the engine's own state readable.
            return [
                'mode' => 'unreadable',
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The alert tables are created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
                'rows' => [],
                'truncated' => false,
                'last_evaluated_at' => null,
                'last_evaluated_age_seconds' => null,
            ];
        }

        if ($rows->isEmpty()) {
            return $this->shippedDefaults();
        }

        $states = $this->states($rows->pluck('key')->all());
        $seen = $this->metricsSeenRecently();

        $presented = [];
        foreach ($rows as $row) {
            $presented[] = $this->present(
                rule: (array) $row,
                state: $states['rows'][$row->key] ?? null,
                stateAvailability: $states['state'],
                metricsSeen: $seen,
                exists: true,
            );
        }

        return [
            'mode' => 'configured',
            'state' => 'ok',
            'note' => $states['state'] === 'ok' ? null : $states['note'],
            'remedy' => null,
            'rows' => $presented,
            'truncated' => $rows->count() >= self::RULE_LIMIT,
            'last_evaluated_at' => $states['last_evaluated_at'],
            'last_evaluated_age_seconds' => $states['age_seconds'],
        ];
    }

    /**
     * The shipped rule set, shown as a proposal rather than as a reading.
     *
     * On a fresh install nothing has been created yet, and an empty table teaches an operator
     * nothing about what this system would watch for them. The preview is read out of
     * AlertEvaluator::defaultRules() — the same list `monitoring:evaluate --seed` writes — rather
     * than copied here, because a copy drifts from the engine the first time a threshold moves,
     * and a preview that misdescribes what it is about to install is worse than no preview.
     *
     * @return array<string, mixed>
     */
    private function shippedDefaults(): array
    {
        $base = [
            'mode' => 'not_yet_created',
            'state' => 'no_data',
            'note' => 'No alert rule exists on this deployment, so nothing is being watched and nothing can fire. The rules below are the shipped set — they have NOT been created.',
            'remedy' => 'Run `php artisan monitoring:evaluate --seed` to install them, then edit the thresholds in Monitoring → Settings.',
            'rows' => [],
            'truncated' => false,
            'last_evaluated_at' => null,
            'last_evaluated_age_seconds' => null,
        ];

        try {
            $reflection = new ReflectionMethod(AlertEvaluator::class, 'defaultRules');
            $reflection->setAccessible(true);
            $defaults = (array) $reflection->invoke($this->evaluator, (array) config('monitoring.thresholds', []));
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'note' => $base['note'] . ' The shipped set could not be read here: ' . $this->failureNote($exception),
            ]);
        }

        $seen = $this->metricsSeenRecently();

        return array_merge($base, [
            'rows' => array_map(
                fn (array $rule): array => $this->present(
                    rule: $rule,
                    state: null,
                    stateAvailability: 'ok',
                    metricsSeen: $seen,
                    exists: false,
                ),
                $defaults,
            ),
        ]);
    }

    /**
     * The state rows for a known set of rule keys.
     *
     * The readability of this query is carried out with the rows: when it fails, every rule would
     * otherwise look as though it had never been evaluated, which is a different — and much more
     * alarming — statement than "the state table could not be read".
     *
     * @param  array<int, string>  $keys
     * @return array{state: string, note: string|null, rows: array<string, object>, last_evaluated_at: string|null, age_seconds: int|null}
     */
    private function states(array $keys): array
    {
        if ($keys === []) {
            return ['state' => 'ok', 'note' => null, 'rows' => [], 'last_evaluated_at' => null, 'age_seconds' => null];
        }

        try {
            $rows = $this->reader->connection()->table(self::STATES_SOURCE)
                ->whereIn('rule_key', $keys)
                ->limit(count($keys))
                ->get()
                ->keyBy('rule_key');
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => 'The live state of each rule could not be read: ' . $this->failureNote($exception),
                'rows' => [],
                'last_evaluated_at' => null,
                'age_seconds' => null,
            ];
        }

        // The engine touches every state row on every pass, so the newest of them is the last time
        // the engine ran. Taken in PHP from rows already fetched rather than as a second MAX query.
        $newest = null;
        foreach ($rows as $row) {
            $stamp = $row->updated_at ?? null;
            if ($stamp !== null && ($newest === null || (string) $stamp > $newest)) {
                $newest = (string) $stamp;
            }
        }

        return [
            'state' => 'ok',
            'note' => null,
            'rows' => $rows->all(),
            'last_evaluated_at' => $newest === null ? null : Clock::display($newest)->toDateTimeString(),
            'age_seconds' => $newest === null ? null : (int) Clock::parse($newest)->diffInSeconds(Clock::now()),
        ];
    }

    /**
     * One rule as the table renders it.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<int, string>|null  $metricsSeen  null when the check itself could not be made
     * @return array<string, mixed>
     */
    private function present(array $rule, ?object $state, string $stateAvailability, ?array $metricsSeen, bool $exists): array
    {
        $enabled = (bool) ($rule['enabled'] ?? false);
        $stored = $state === null ? null : (string) $state->state;
        $metric = (string) ($rule['metric'] ?? '');

        $current = match (true) {
            !$exists => 'not_yet_created',
            $stateAvailability !== 'ok' => 'unreadable',
            !$enabled => 'disabled',
            $state === null => 'never_evaluated',
            default => $stored,
        };

        $breachedSince = $state?->breached_since;
        $checkedAt = $state?->updated_at;
        // Measured to the last evaluation rather than to now. A breach that began an hour ago on
        // an engine that stopped forty minutes ago has not been breaching for an hour — nobody has
        // looked. This is the number the evaluator itself saw when it last decided.
        $held = ($breachedSince === null || $checkedAt === null)
            ? null
            : max(0, (int) Clock::parse($breachedSince)->diffInSeconds(Clock::parse($checkedAt)));
        $forSeconds = (int) ($rule['for_seconds'] ?? 0);

        return [
            'key' => (string) ($rule['key'] ?? ''),
            'name' => (string) ($rule['name'] ?? ''),
            'description' => $rule['description'] ?? null,
            'metric' => $metric,
            'label' => (string) ($rule['label'] ?? ''),
            'section' => $this->sectionFor($metric),
            'operator' => (string) ($rule['operator'] ?? '>'),
            'warning_threshold' => $this->number($rule['warning_threshold'] ?? null),
            'critical_threshold' => $this->number($rule['critical_threshold'] ?? null),
            'recovery_threshold' => $this->number($rule['recovery_threshold'] ?? null),
            'for_seconds' => $forSeconds,
            'cooldown_seconds' => (int) ($rule['cooldown_seconds'] ?? 0),
            'enabled' => $enabled,
            'notify_email' => (bool) ($rule['notify_email'] ?? false),
            'notify_channels' => $rule['notify_channels'] ?? null,
            'exists' => $exists,
            'state' => $current,
            // Kept beside the display state so a rule switched off while it was on fire still says
            // what it was last seeing, instead of going quietly grey.
            'stored_state' => $stored,
            'evaluated' => $state !== null,
            'last_value' => $state?->last_value === null ? null : (float) $state->last_value,
            'breached_since' => $breachedSince === null ? null : Clock::display($breachedSince)->toDateTimeString(),
            'breached_for_seconds' => $held,
            'fired_at' => $state?->fired_at === null ? null : Clock::display($state->fired_at)->toDateTimeString(),
            'recovered_at' => $state?->recovered_at === null ? null : Clock::display($state->recovered_at)->toDateTimeString(),
            'last_notified_at' => $state?->last_notified_at === null ? null : Clock::display($state->last_notified_at)->toDateTimeString(),
            'fire_count' => (int) ($state?->fire_count ?? 0),
            'incident_id' => $state?->incident_id === null ? null : (int) $state->incident_id,
            'checked_at' => $checkedAt === null ? null : Clock::display($checkedAt)->toDateTimeString(),
            // A rule cannot fire on a metric that is not arriving, and that silence looks exactly
            // like a healthy shop from the state column alone.
            'metric_seen' => $metricsSeen === null ? null : in_array($metric, $metricsSeen, true),
        ];
    }

    /**
     * Which metric names have actually produced a sample recently.
     *
     * One read for the whole table rather than one per rule — the resolver answers from a single
     * bounded query, and asking it per row would turn a nine-rule page into ten queries.
     *
     * @return array<int, string>|null  null when the check could not be made at all
     */
    private function metricsSeenRecently(): ?array
    {
        try {
            // available() answers null itself when it could not look, which is the case this
            // method's own null is for; the catch stays for anything it does not handle.
            return $this->metrics->available();
        } catch (\Throwable) {
            // Silence here must not be reported as "this metric is missing": an unanswered
            // question is not a negative answer.
            return null;
        }
    }

    /**
     * Is the engine awake, and does it have anything to watch?
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function engine(array $rules): array
    {
        $rows = $rules['rows'] ?? [];
        $live = array_values(array_filter($rows, static fn (array $rule) => $rule['exists']));
        $enabled = array_values(array_filter($live, static fn (array $rule) => $rule['enabled']));
        $age = $rules['last_evaluated_age_seconds'] ?? null;
        $staleAfter = (int) config('monitoring.stale_after_seconds', 180);

        $base = [
            'last_evaluated_at' => $rules['last_evaluated_at'] ?? null,
            'age_seconds' => $age,
            'timezone' => Clock::displayTimezone(),
            'rules_enabled' => count($enabled),
            'schedule' => 'php artisan monitoring:evaluate, scheduled every minute',
        ];

        if (!config('monitoring.enabled', true)) {
            return $base + [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no rule is being evaluated and no alert can fire.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        if (($rules['state'] ?? null) === 'failed') {
            return $base + [
                'state' => 'failed',
                'note' => (string) $rules['note'],
                'remedy' => $rules['remedy'] ?? null,
            ];
        }

        if (($rules['mode'] ?? null) === 'not_yet_created') {
            return $base + [
                'state' => 'no_rules',
                'note' => 'The engine runs every minute but has no rule to evaluate, so nothing can ever fire.',
                'remedy' => (string) $rules['remedy'],
            ];
        }

        if ($enabled === []) {
            return $base + [
                'state' => 'not_configured',
                'note' => 'Every rule on this deployment is switched off, so the engine evaluates nothing.',
                'remedy' => 'Enable the rules worth waking up for in Monitoring → Settings.',
            ];
        }

        if ($age === null) {
            return $base + [
                'state' => 'never_run',
                'note' => 'No rule has ever been evaluated: not one state row has been written.',
                'remedy' => 'Run `php artisan monitoring:evaluate` once to confirm it works, then check the Laravel scheduler is running with `php artisan schedule:list`.',
            ];
        }

        if ($age > $staleAfter) {
            return $base + [
                'state' => 'stale',
                // The dangerous case, and the reason this block is drawn above everything else: a
                // stopped engine leaves a page full of green pills that were true an hour ago.
                'note' => 'The last evaluation was ' . $age . ' seconds ago, so every state below is that old. Nothing here can be read as current.',
                'remedy' => 'The evaluator runs from the scheduler every minute. Check cron is calling `php artisan schedule:run`.',
            ];
        }

        return $base + ['state' => 'ok', 'note' => null, 'remedy' => null];
    }

    /**
     * The counts, and the four settings that decide how loud this engine is allowed to be.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function summary(array $rules): array
    {
        $rows = $rules['rows'] ?? [];
        $live = array_values(array_filter($rows, static fn (array $rule) => $rule['exists']));
        $count = static fn (array $set, callable $predicate) => count(array_filter($set, $predicate));

        $holds = array_map(static fn (array $rule) => $rule['for_seconds'], $rows);
        $cooldowns = array_map(static fn (array $rule) => $rule['cooldown_seconds'], $rows);

        return [
            'mode' => $rules['mode'] ?? 'configured',
            // False whenever the rules on screen are a proposal rather than an installed set: the
            // counts below then describe what WOULD be watched, not what is.
            'watching' => ($rules['mode'] ?? null) === 'configured',
            'total' => count($rows),
            'enabled' => $count($live, static fn (array $rule) => $rule['enabled']),
            'disabled' => $count($live, static fn (array $rule) => !$rule['enabled']),
            'firing' => $count($live, static fn (array $rule) => in_array($rule['state'], self::FIRING_STATES, true)),
            'pending' => $count($live, static fn (array $rule) => $rule['state'] === 'pending'),
            'ok' => $count($live, static fn (array $rule) => $rule['state'] === 'ok'),
            'never_evaluated' => $count($live, static fn (array $rule) => $rule['state'] === 'never_evaluated'),
            'notify_email' => $count($live, static fn (array $rule) => $rule['notify_email']),
            'with_recovery_threshold' => $count($rows, static fn (array $rule) => $rule['recovery_threshold'] !== null),
            'metric_never_seen' => $count($live, static fn (array $rule) => $rule['metric_seen'] === false),
            'hold_seconds' => $holds === [] ? null : ['min' => min($holds), 'max' => max($holds)],
            'cooldown_seconds' => $cooldowns === [] ? null : ['min' => min($cooldowns), 'max' => max($cooldowns)],
        ];
    }

    /**
     * The few readings that have their own way of being unavailable, as Metrics so the view can
     * say so without reaching for a zero.
     *
     * @param  array<string, mixed>  $engine
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $events
     * @return array<string, Metric>
     */
    private function readings(array $engine, array $rules, array $events): array
    {
        $rows = array_values(array_filter($rules['rows'] ?? [], static fn (array $rule) => $rule['exists']));

        return [
            'last_evaluation' => $engine['age_seconds'] === null
                ? Metric::noData(source: self::STATES_SOURCE, note: 'No rule has been evaluated on this deployment yet.')
                : Metric::of(value: $engine['age_seconds'], source: self::STATES_SOURCE, unit: 'seconds ago', note: 'Last pass ' . $engine['last_evaluated_at'] . ' (' . $engine['timezone'] . ')'),
            'rules_being_evaluated' => ($rules['mode'] ?? null) === 'configured'
                ? Metric::of(value: $engine['rules_enabled'], source: self::RULES_SOURCE)
                : Metric::noData(source: self::RULES_SOURCE, note: 'No rule has been created yet.'),
            'where_alerts_are_sent' => $this->deliveryReading($rows),
            'alert_events_recorded' => ($events['total_in_window'] ?? null) !== null
                ? Metric::of(
                    value: $events['total_in_window'],
                    source: self::EVENTS_SOURCE,
                    note: 'In the last ' . $events['window_days'] . ' days'
                        . ($events['total_in_window'] > self::EVENT_LIMIT ? ', newest ' . self::EVENT_LIMIT . ' shown below' : ''),
                )
                : Metric::noData(source: self::EVENTS_SOURCE, note: $events['note'] ?? null),
        ];
    }

    /**
     * Who actually hears about a firing rule.
     *
     * The most important sentence on an alerts page is whether the alert reaches a person, so the
     * notifier's fallback is stated here too: a rule with email on but no recipient list is
     * delivered to the application's own from-address, and where that is unset as well the alert
     * reaches nobody but the log.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function deliveryReading(array $rows): Metric
    {
        $emailing = array_values(array_filter($rows, static fn (array $rule) => $rule['notify_email']));

        if ($emailing === []) {
            return Metric::notConfigured(
                source: self::RULES_SOURCE,
                remedy: 'Switch on notify_email for the rules worth waking up for and put the recipients in notify_channels as a comma-separated list.',
                note: 'No rule is set to send email. Firings are still written to the application log and grouped into incidents, but nobody is told.',
            );
        }

        $addresses = [];
        foreach ($emailing as $rule) {
            foreach (explode(',', (string) $rule['notify_channels']) as $address) {
                $address = trim($address);
                if ($address !== '') {
                    $addresses[$address] = true;
                }
            }
        }

        if ($addresses !== []) {
            return Metric::of(
                value: implode(', ', array_keys($addresses)),
                source: self::RULES_SOURCE,
                note: count($emailing) . ' rule(s) send email.',
            );
        }

        $fallback = config('mail.from.address');

        return is_string($fallback) && $fallback !== ''
            ? Metric::of(value: $fallback, source: 'mail.from.address', note: count($emailing) . ' rule(s) send email, none of which names a recipient, so the application from-address is used.')
            : Metric::notConfigured(
                source: self::RULES_SOURCE,
                remedy: 'Set a recipient list on the rule (notify_channels), or configure MAIL_FROM_ADDRESS.',
                note: count($emailing) . ' rule(s) are set to send email but there is no address to send it to.',
            );
    }

    /**
     * What is on fire, what is about to be, and what has never been looked at.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $engine
     * @return array<string, mixed>
     */
    private function firing(array $rules, array $engine): array
    {
        $rows = array_values(array_filter($rules['rows'] ?? [], static fn (array $rule) => $rule['exists']));
        $pick = static fn (callable $predicate) => array_values(array_filter($rows, $predicate));

        return [
            'rows' => $pick(static fn (array $rule) => in_array($rule['state'], self::FIRING_STATES, true)),
            // Breaching, but not for long enough to be an alert yet. Shown because it is the only
            // warning anyone gets before the pager, and hidden nowhere else.
            'pending' => $pick(static fn (array $rule) => $rule['state'] === 'pending'),
            'never_evaluated' => $pick(static fn (array $rule) => $rule['state'] === 'never_evaluated'),
            'blind' => $pick(static fn (array $rule) => $rule['enabled'] && $rule['metric_seen'] === false),
            // "Nothing is firing" is a claim about the last evaluation. With a stale or stopped
            // engine it is not a claim this page is entitled to make.
            'trustworthy' => ($engine['state'] ?? null) === 'ok',
            'as_of' => $engine['last_evaluated_at'] ?? null,
        ];
    }

    /**
     * The alert entries on the event timeline.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function events(array $rules): array
    {
        // Bounded by the window the rollup actually keeps: anything older has been pruned, so a
        // wider window would only promise rows that cannot exist.
        $days = max(1, app(MonitoringSettings::class)->retentionDays('incident_days', 400));

        $recorded = array_sum(array_map(
            static fn (array $rule) => $rule['fire_count'],
            array_filter($rules['rows'] ?? [], static fn (array $rule) => $rule['exists']),
        ));

        $base = [
            'window_days' => $days,
            'limit' => self::EVENT_LIMIT,
            'firings_recorded_in_state' => $recorded,
            // Whether the two independent records of a firing — the lifetime counter on the rule
            // and the event timeline — can be compared at all, and what the comparison said. The
            // page used to decide this itself and got it wrong in the one case that matters: a
            // timeline it could not READ was reported as a timeline that disagreed, which sends an
            // operator looking for a lost firing instead of a broken query.
            'discrepancy' => null,
            // How many there ARE, which is not how many are shown. The reading below used to be
            // count($rows) — a page size hard-capped at fifty, so a deployment with three thousand
            // alert events in the window reported fifty.
            'total_in_window' => null,
            'rows' => [],
        ];

        try {
            $base['total_in_window'] = (int) $this->reader->connection()->table(self::EVENTS_SOURCE)
                ->where('type', 'alert')
                ->where('occurred_at', '>=', Clock::daysAgo($days))
                ->count();

            $rows = $this->reader->connection()->table(self::EVENTS_SOURCE)
                ->where('type', 'alert')
                ->where('occurred_at', '>=', Clock::daysAgo($days))
                ->orderByDesc('occurred_at')
                ->limit(self::EVENT_LIMIT)
                ->get(['severity', 'key', 'title', 'description', 'related_id', 'occurred_at']);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => 'The event timeline could not be read: ' . $this->failureNote($exception),
                'remedy' => null,
                'discrepancy' => $recorded > 0 ? 'not_comparable' : null,
            ]);
        }

        if ($rows->isEmpty()) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => 'No alert has been written to the event timeline in the last ' . $days . ' days.',
                'remedy' => null,
                // The counter is for the life of the rule and the timeline is bounded by retention,
                // so firings older than the window are absent for a legitimate reason. Both
                // readings are given; which one it is, is stated as the question it is.
                'discrepancy' => $recorded > 0 ? 'missing_from_timeline' : null,
            ]);
        }

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $rows->map(static fn ($row) => [
                'severity' => (string) $row->severity,
                'rule' => $row->key,
                'title' => (string) $row->title,
                'description' => $row->description === null ? null : Str::limit((string) $row->description, 160),
                'incident_id' => $row->related_id === null ? null : (int) $row->related_id,
                'at' => Clock::display($row->occurred_at)->toDateTimeString(),
            ])->all(),
        ]);
    }

    /**
     * The section holding the evidence for a metric, so a firing rule is one click from its chart.
     */
    private function sectionFor(string $metric): string
    {
        return match (true) {
            str_starts_with($metric, 'http.') => 'requests',
            str_starts_with($metric, 'db.') => 'database',
            str_starts_with($metric, 'redis.'), str_starts_with($metric, 'cache.') => 'redis',
            str_starts_with($metric, 'queue.') => 'queues',
            str_starts_with($metric, 'scheduler.') => 'scheduler',
            str_starts_with($metric, 'check.') => 'synthetics',
            str_starts_with($metric, 'server.disk') => 'storage',
            str_starts_with($metric, 'server.') => 'server',
            str_starts_with($metric, 'error.'), str_starts_with($metric, 'exception.') => 'errors',
            default => 'overview',
        };
    }

    /**
     * A threshold as text, without the trailing zeros a double picks up on its way out of MySQL.
     */
    private function number(mixed $value): ?string
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    private function failureNote(\Throwable $exception): string
    {
        // Not the raw message: a QueryException carries the statement with its bound values, so
        // this used to be able to print a customer's email onto the alerts screen.
        return Metric::describeFailure($exception);
    }
}
