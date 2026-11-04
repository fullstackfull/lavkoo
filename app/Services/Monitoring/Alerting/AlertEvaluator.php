<?php

namespace App\Services\Monitoring\Alerting;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\DB;

/**
 * Decides, once a minute, whether anything is wrong enough to say so.
 *
 * Four rules govern this, and each one exists because of a specific way alerting goes bad:
 *
 * 1. NO DATA IS NOT ZERO. A metric that stopped arriving evaluates to nothing, not to zero. The
 *    alternative fires "error rate is 0%, CPU is 0%" alerts every time collection stops, and
 *    trains everyone to ignore the one that matters.
 *
 * 2. THE CONDITION MUST HOLD. `for_seconds` means every sample in the window breached, not the
 *    average of them and not the latest one. One unlucky sample is not an outage.
 *
 * 3. RECOVERY IS LOWER THAN FIRING. A metric sitting exactly on the threshold would otherwise
 *    alternate between firing and recovering every minute; the recovery threshold is deliberately
 *    inside the firing one so it has to actually come back before it is called recovered.
 *
 * 4. COOLDOWN APPLIES TO NOTIFICATION, NOT TO STATE. The dashboard always shows the truth; what
 *    the cooldown suppresses is sending the same message again.
 */
class AlertEvaluator
{
    public function __construct(
        private readonly MetricResolver $metrics,
        private readonly IncidentManager $incidents,
        private readonly AlertNotifier $notifier,
        private readonly MonitoringSettings $settings,
        private readonly EventLog $events,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>  one entry per rule evaluated
     */
    public function evaluate(): array
    {
        $outcomes = [];

        foreach ($this->rules() as $rule) {
            $outcomes[] = $this->evaluateRule($rule);
        }

        return $outcomes;
    }

    /**
     * The rules an operator has enabled.
     *
     * @return array<int, object>
     */
    public function rules(): array
    {
        try {
            return $this->connection()->table('monitoring_alert_rules')
                ->where('enabled', true)
                ->orderBy('key')
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Install the shipped rule set, once, on a system that has none.
     *
     * These are thresholds, not measurements: nothing here invents a number about this shop, it
     * only writes down when somebody should be told. An operator who deletes them all does not get
     * them back — the marker in settings makes seeding a one-time act rather than a nightly one.
     *
     * @return int  how many rules were created
     */
    public function seedDefaults(bool $force = false): int
    {
        if (!$force && $this->settings->get('alert_rules_seeded') === true) {
            return 0;
        }

        $thresholds = (array) config('monitoring.thresholds', []);
        $created = 0;
        $now = Clock::stamp();

        foreach ($this->defaultRules($thresholds) as $rule) {
            $exists = $this->connection()->table('monitoring_alert_rules')->where('key', $rule['key'])->exists();
            if ($exists) {
                continue;
            }

            $this->connection()->table('monitoring_alert_rules')->insert($rule + ['created_at' => $now, 'updated_at' => $now]);
            $created++;
        }

        $this->settings->put('alert_rules_seeded', true);

        return $created;
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function evaluateRule(object $rule): array
    {
        // An unlabelled rule covers every label the metric has (every disk, every queue). The
        // worst one decides: a rule about disk space that averages a full disk with an empty one
        // is a rule that goes off after the server has already stopped.
        $samples = $this->metrics->samples(
            $rule->metric,
            (string) $rule->label,
            max(60, (int) $rule->for_seconds),
            in_array($rule->operator, ['<', '<='], true) ? 'min' : 'max',
        );
        $state = $this->stateFor($rule);

        if ($samples === []) {
            // Rule 1. A rule cannot fire on a metric that is not arriving — that is the collector's
            // problem, reported by the self-health panel, not this rule's to shout about.
            //
            // A rule that was only BREACHING has its clock reset, because Rule 2 measures a breach
            // that held CONTINUOUSLY and a gap in the data is not evidence that it did. Leaving the
            // clock running turned `for_seconds` into wall-clock elapsed: after a collector outage,
            // one breaching sample fired a full alert immediately. A rule that was already firing is
            // left exactly as it is — silence is not recovery either.
            if (($state?->state ?? 'ok') === 'pending') {
                $this->writeState($rule, ['breached_since' => null, 'state' => 'ok']);

                return $this->outcome($rule, 'ok', null, 'no data in the window; the breach clock was reset');
            }

            return $this->outcome($rule, $state?->state ?? 'ok', null, 'no data in the window');
        }

        $latest = (float) end($samples);
        $breach = $this->breachLevel($rule, $samples);

        return $breach === null
            ? $this->resolve($rule, $state, $latest)
            : $this->raise($rule, $state, $latest, $breach, count($samples));
    }

    /**
     * Which threshold every sample in the window is past, if any.
     *
     * Critical wins over warning, and BOTH require the whole window: a spike into critical for one
     * sample inside a two-minute warning breach is still a warning until critical holds too.
     *
     * @param  array<int, float>  $samples
     */
    private function breachLevel(object $rule, array $samples): ?string
    {
        foreach (['critical', 'warning'] as $level) {
            $threshold = $rule->{$level . '_threshold'};

            if ($threshold === null) {
                continue;
            }

            $held = true;
            foreach ($samples as $sample) {
                if (!$this->compare((float) $sample, (string) $rule->operator, (float) $threshold)) {
                    $held = false;
                    break;
                }
            }

            if ($held) {
                return $level;
            }
        }

        return null;
    }

    private function compare(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '>=' => $value >= $threshold,
            '==' => abs($value - $threshold) < 1e-9,
            default => $value > $threshold,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function raise(object $rule, ?object $state, float $value, string $level, int $samples): array
    {
        $now = Clock::now();
        $breachedSince = $state?->breached_since !== null ? Clock::parse($state->breached_since) : $now;
        $heldSeconds = (int) $breachedSince->diffInSeconds($now, false);

        // Rule 2: breaching is not firing. It becomes an alert only once it has held long enough,
        // and until then it sits in `pending` where the dashboard can show it without paging.
        if ($heldSeconds < (int) $rule->for_seconds) {
            $this->writeState($rule, [
                'state' => 'pending',
                'last_value' => $value,
                'breached_since' => $breachedSince->toDateTimeString(),
            ]);

            return $this->outcome($rule, 'pending', $value, "breaching for {$heldSeconds}s of {$rule->for_seconds}s");
        }

        $alreadyFiring = in_array($state?->state, ['warning', 'critical'], true);
        $escalated = $alreadyFiring && $state->state !== $level && $level === 'critical';

        $incidentId = $state?->incident_id;
        if (!$alreadyFiring || $escalated) {
            $incidentId = $this->incidents->attach($rule->key, $rule->name, $level, [
                'metric' => $rule->metric,
                'label' => $rule->label,
                'value' => $value,
                'threshold' => $rule->{$level . '_threshold'},
                'operator' => $rule->operator,
                'samples' => $samples,
                'breached_since' => $breachedSince->toDateTimeString(),
            ]) ?? $incidentId;
        }

        // Rule 4: the cooldown gates the message, never the state.
        $lastNotified = $state?->last_notified_at !== null ? Clock::parse($state->last_notified_at) : null;
        $due = $lastNotified === null
            || $escalated
            || $lastNotified->diffInSeconds($now, false) >= (int) $rule->cooldown_seconds;

        if ($due) {
            $this->notifier->fired($rule, $level, $value, $incidentId);
        }

        // The timeline is told the first time an episode starts, not on every cooldown repeat: a
        // rule that has been firing for an hour is one event on the axis, not twelve. Until this
        // existed the alerts page had a permanent discrepancy against its own history, because the
        // rule's fire counter was the only record that a firing had ever happened.
        if (!$alreadyFiring) {
            $this->events->record(
                type: EventLog::ALERT,
                severity: $level === 'critical' ? EventLog::CRITICAL : EventLog::WARNING,
                title: $rule->name . ' — ' . $level,
                key: (string) $rule->key,
                description: $rule->metric . ' ' . $rule->operator . ' '
                    . ($level === 'critical' ? $rule->critical_threshold : $rule->warning_threshold),
                context: ['value' => $value, 'metric' => $rule->metric, 'label' => $rule->label ?: null],
                relatedId: $incidentId,
            );
        }

        $this->writeState($rule, [
            'state' => $level,
            'last_value' => $value,
            'breached_since' => $breachedSince->toDateTimeString(),
            'fired_at' => $alreadyFiring ? ($state->fired_at ?? Clock::stamp()) : Clock::stamp(),
            // Cleared deliberately: an alert that is firing again has not recovered, and leaving
            // yesterday's recovery timestamp on it would put a resolved time on an open problem.
            'recovered_at' => null,
            'last_notified_at' => $due ? Clock::stamp() : $state?->last_notified_at,
            'incident_id' => $incidentId,
            'fire_count' => (int) ($state?->fire_count ?? 0) + ($alreadyFiring ? 0 : 1),
        ]);

        return $this->outcome($rule, $level, $value, $due ? 'fired and notified' : 'firing (cooldown)');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(object $rule, ?object $state, float $value): array
    {
        if ($state === null || $state->state === 'ok') {
            $this->writeState($rule, ['state' => 'ok', 'last_value' => $value, 'breached_since' => null]);

            return $this->outcome($rule, 'ok', $value, 'within range');
        }

        // Rule 3: coming back below the firing line is not recovery if a recovery line was set —
        // the metric has to come back properly, or it will flap.
        if ($rule->recovery_threshold !== null
            && $this->compare($value, (string) $rule->operator, (float) $rule->recovery_threshold)) {
            $this->writeState($rule, ['last_value' => $value]);

            return $this->outcome($rule, (string) $state->state, $value, 'below threshold but not yet recovered');
        }

        $wasFiring = in_array($state->state, ['warning', 'critical'], true);

        if ($wasFiring) {
            $this->notifier->recovered($rule, $value);
            $this->events->record(
                type: EventLog::ALERT,
                severity: EventLog::SUCCESS,
                title: $rule->name . ' — recovered',
                key: (string) $rule->key,
                context: ['value' => $value, 'metric' => $rule->metric],
                relatedId: $state->incident_id !== null ? (int) $state->incident_id : null,
            );
        }

        $this->writeState($rule, [
            'state' => 'ok',
            'last_value' => $value,
            'breached_since' => null,
            'recovered_at' => $wasFiring ? Clock::stamp() : $state->recovered_at,
            // Rule 4 gates repeat messages about ONE episode. Carrying the timestamp past a
            // recovery gated the first message of the NEXT one: a rule that recovered and broke
            // again inside the cooldown opened an incident, flipped to firing, and told nobody.
            'last_notified_at' => $wasFiring ? null : ($state->last_notified_at ?? null),
        ]);

        if ($wasFiring && $state->incident_id !== null) {
            $this->incidents->releaseIfResolved((int) $state->incident_id);
        }

        return $this->outcome($rule, 'ok', $value, $wasFiring ? 'recovered' : 'within range');
    }

    private function stateFor(object $rule): ?object
    {
        try {
            return $this->connection()->table('monitoring_alert_states')->where('rule_key', $rule->key)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeState(object $rule, array $attributes): void
    {
        try {
            $this->connection()->table('monitoring_alert_states')->updateOrInsert(
                ['rule_key' => $rule->key],
                $attributes + ['updated_at' => Clock::stamp(), 'created_at' => Clock::stamp()],
            );
        } catch (\Throwable) {
            // The next minute will try again; an unwritable state row must not stop the evaluation
            // of the rules after this one.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(object $rule, string $state, ?float $value, string $note): array
    {
        return [
            'rule' => $rule->key,
            'metric' => $rule->metric . ($rule->label !== '' ? '@' . $rule->label : ''),
            'state' => $state,
            'value' => $value,
            'note' => $note,
        ];
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection'));
    }

    /**
     * @param  array<string, mixed>  $thresholds
     * @return array<int, array<string, mixed>>
     */
    private function defaultRules(array $thresholds): array
    {
        $rule = static fn (array $overrides) => $overrides + [
            'label' => '',
            'operator' => '>',
            'warning_threshold' => null,
            'critical_threshold' => null,
            'recovery_threshold' => null,
            'for_seconds' => 120,
            'cooldown_seconds' => 900,
            'enabled' => true,
            // On by default. Every shipped rule used to be created with this false and there was no
            // screen to turn it on, so an alert that fired went to laravel.log and nowhere else —
            // which is a log line, not an alert. A send that fails is caught and logged by the
            // notifier, so an install with no mail configured degrades rather than breaking.
            'notify_email' => true,
            'notify_channels' => null,
        ];

        return [
            $rule([
                'key' => 'http.error_rate',
                'name' => 'Server error rate is high',
                'metric' => 'http.error_rate',
                'warning_threshold' => $thresholds['error_rate_warning'] ?? 1.0,
                'critical_threshold' => $thresholds['error_rate_critical'] ?? 5.0,
                'recovery_threshold' => ($thresholds['error_rate_warning'] ?? 1.0) * 0.5,
                'description' => 'The share of requests returning 5xx, measured per minute.',
            ]),
            $rule([
                'key' => 'http.p95_ms',
                'name' => 'Pages are slow for the slowest 5% of visitors',
                'metric' => 'http.p95_ms',
                'warning_threshold' => $thresholds['p95_warning_ms'] ?? 800,
                'critical_threshold' => $thresholds['p95_critical_ms'] ?? 2000,
                'recovery_threshold' => ($thresholds['p95_warning_ms'] ?? 800) * 0.75,
                'for_seconds' => 300,
                'description' => 'The 95th percentile response time, interpolated from the latency histogram.',
            ]),
            $rule([
                'key' => 'cpu.usage',
                'name' => 'CPU is saturated',
                'metric' => 'server.cpu.usage_pct',
                'warning_threshold' => $thresholds['cpu_warning'] ?? 75,
                'critical_threshold' => $thresholds['cpu_critical'] ?? 90,
                'recovery_threshold' => ($thresholds['cpu_warning'] ?? 75) - 10,
                'for_seconds' => 300,
                'description' => 'Sustained CPU usage across all cores.',
            ]),
            $rule([
                'key' => 'memory.used',
                'name' => 'Memory is nearly exhausted',
                'metric' => 'server.memory.used_pct',
                'warning_threshold' => $thresholds['memory_warning'] ?? 80,
                'critical_threshold' => $thresholds['memory_critical'] ?? 92,
                'recovery_threshold' => ($thresholds['memory_warning'] ?? 80) - 10,
                'for_seconds' => 300,
                'description' => 'Memory in use, excluding reclaimable cache.',
            ]),
            $rule([
                'key' => 'disk.used',
                'name' => 'The disk is filling up',
                'metric' => 'server.disk.used_pct',
                'warning_threshold' => $thresholds['disk_warning'] ?? 80,
                'critical_threshold' => $thresholds['disk_critical'] ?? 90,
                'for_seconds' => 600,
                'cooldown_seconds' => 21600,
                'description' => 'A full disk takes the database down with it, and it fills slowly enough to be caught.',
            ]),
            $rule([
                'key' => 'queue.oldest_wait',
                'name' => 'The queue is not being drained',
                'metric' => 'queue.oldest_wait_seconds',
                'warning_threshold' => $thresholds['queue_lag_warning_seconds'] ?? 300,
                'critical_threshold' => $thresholds['queue_lag_critical_seconds'] ?? 900,
                'for_seconds' => 180,
                'description' => 'How long the oldest waiting job has been waiting — the honest measure of a stalled worker.',
            ]),
            $rule([
                'key' => 'db.latency',
                'name' => 'The database is responding slowly',
                'metric' => 'db.latency_ms',
                'warning_threshold' => $thresholds['db_latency_warning_ms'] ?? 50,
                'critical_threshold' => $thresholds['db_latency_critical_ms'] ?? 250,
                'for_seconds' => 180,
                'description' => 'Round trip for select 1: connection health with no query in the way.',
            ]),
            $rule([
                'key' => 'scheduler.late',
                'name' => 'Scheduled tasks are not running',
                'metric' => 'scheduler.last_run_age_minutes',
                'warning_threshold' => $thresholds['scheduler_late_minutes'] ?? 10,
                'critical_threshold' => ($thresholds['scheduler_late_minutes'] ?? 10) * 3,
                'for_seconds' => 300,
                'description' => 'Minutes since the scheduler last ran. Rollups, reminders and cleanup all stop when this climbs.',
            ]),
            $rule([
                'key' => 'check.availability',
                'name' => 'A health check is failing',
                'metric' => 'check.up',
                'operator' => '<',
                'warning_threshold' => 1,
                'critical_threshold' => null,
                'for_seconds' => 300,
                'description' => 'Any component probe that has been reporting down for five minutes.',
            ]),
        ];
    }
}
