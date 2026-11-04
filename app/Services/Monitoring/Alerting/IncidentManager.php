<?php

namespace App\Services\Monitoring\Alerting;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Many signals, one problem.
 *
 * When a database stalls, CPU climbs, p95 explodes, the queue backs up and half a dozen rules fire
 * inside the same minute. Six alerts describing one outage is six times the noise and none of the
 * insight, so signals that overlap in time are attached to a single incident, and the incident
 * carries the timeline: when it started, when it was detected, when somebody acknowledged it, when
 * it recovered. Those four timestamps are what make MTTD and MTTR measurable instead of asserted.
 */
class IncidentManager
{
    /** Severity of the incident a rule at this state opens. */
    private const SEVERITY = ['critical' => 'critical', 'warning' => 'minor'];

    /**
     * How long after the last signal an incident stays open for new signals to join.
     *
     * Long enough that a stall which flaps for a few minutes stays one incident; short enough that
     * tomorrow's unrelated failure does not get filed under today's.
     */
    public const CORRELATION_WINDOW_MINUTES = 30;

    public function __construct(private readonly EventLog $events)
    {
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection'));
    }

    /**
     * Attach a firing rule to an incident, opening one if nothing open matches.
     *
     * @param  array<string, mixed>  $signal
     * @return int|null  the incident id, or null when incidents cannot be written
     */
    public function attach(string $ruleKey, string $ruleName, string $state, array $signal): ?int
    {
        try {
            $open = $this->connection()->table('monitoring_incidents')
                ->whereIn('status', ['open', 'investigating', 'monitoring'])
                ->where('started_at', '>=', Clock::minutesAgo(self::CORRELATION_WINDOW_MINUTES))
                ->orderByDesc('started_at')
                ->first();

            return $open === null
                ? $this->open($ruleKey, $ruleName, $state, $signal)
                : $this->addSignal($open, $ruleKey, $ruleName, $state, $signal);
        } catch (\Throwable) {
            // An incident that cannot be filed must not stop the alert that would have filed it.
            return null;
        }
    }

    /**
     * A rule recovered: close the incident once nothing it holds is still firing.
     */
    public function releaseIfResolved(int $incidentId): void
    {
        try {
            $stillFiring = $this->connection()->table('monitoring_alert_states')
                ->where('incident_id', $incidentId)
                ->whereIn('state', ['warning', 'critical'])
                ->exists();

            if ($stillFiring) {
                return;
            }

            $closed = $this->connection()->table('monitoring_incidents')
                ->where('id', $incidentId)
                ->whereIn('status', ['open', 'investigating', 'monitoring'])
                ->update([
                    'status' => 'resolved',
                    'resolved_at' => Clock::stamp(),
                    'updated_at' => Clock::stamp(),
                ]);

            // Only when this call is the one that closed it. releaseIfResolved is called by every
            // recovering rule attached to the incident, and an axis that records the same closure
            // four times is an axis nobody trusts.
            if ($closed > 0) {
                $incident = $this->connection()->table('monitoring_incidents')->where('id', $incidentId)->first(['reference', 'title', 'started_at']);

                $this->events->record(
                    type: EventLog::INCIDENT,
                    severity: EventLog::SUCCESS,
                    title: ($incident->reference ?? 'INC') . ' resolved — ' . ($incident->title ?? ''),
                    key: $incident->reference ?? null,
                    context: ['started_at' => $incident->started_at ?? null],
                    relatedId: $incidentId,
                );
            }
        } catch (\Throwable) {
            // As above: bookkeeping failure, not an operational one.
        }
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function open(string $ruleKey, string $ruleName, string $state, array $signal): int
    {
        $now = Clock::stamp();

        $reference = $this->nextReference();

        $id = $this->connection()->table('monitoring_incidents')->insertGetId([
            'reference' => $reference,
            'title' => mb_substr($ruleName, 0, 191),
            'severity' => self::SEVERITY[$state] ?? 'minor',
            'status' => 'open',
            'affected_services' => json_encode([$this->serviceOf($signal['metric'] ?? '')]),
            'signals' => json_encode([$signal + ['rule' => $ruleKey, 'at' => $now]]),
            'started_at' => $signal['breached_since'] ?? $now,
            // Detected when the rule fired, not when it started: the gap between the two IS the
            // time to detect, and collapsing them would make every incident look instantly caught.
            'detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->events->record(
            type: EventLog::INCIDENT,
            severity: EventLog::CRITICAL,
            title: $reference . ' opened — ' . mb_substr($ruleName, 0, 140),
            key: $reference,
            description: 'First signal: ' . $ruleKey,
            context: ['severity' => self::SEVERITY[$state] ?? 'minor', 'metric' => $signal['metric'] ?? null],
            relatedId: (int) $id,
        );

        return (int) $id;
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function addSignal(object $incident, string $ruleKey, string $ruleName, string $state, array $signal): int
    {
        $signals = json_decode((string) $incident->signals, true) ?: [];
        $signals = array_values(array_filter($signals, static fn ($existing) => ($existing['rule'] ?? null) !== $ruleKey));
        $signals[] = $signal + ['rule' => $ruleKey, 'at' => Clock::stamp()];

        $services = json_decode((string) $incident->affected_services, true) ?: [];
        $service = $this->serviceOf($signal['metric'] ?? '');
        if (!in_array($service, $services, true)) {
            $services[] = $service;
        }

        $update = [
            'signals' => json_encode(array_slice($signals, -25)),
            'affected_services' => json_encode($services),
            'updated_at' => Clock::stamp(),
        ];

        // One critical signal makes the whole incident critical; an incident does not get quieter
        // because a second, milder rule joined it.
        if ($state === 'critical' && $incident->severity !== 'critical') {
            $update['severity'] = 'critical';
        }

        if (count($signals) > 1) {
            $update['title'] = mb_substr(count($signals) . ' signals: ' . $incident->title, 0, 191);
        }

        $this->connection()->table('monitoring_incidents')->where('id', $incident->id)->update($update);

        return (int) $incident->id;
    }

    /** The subsystem a metric belongs to, from its dotted prefix. */
    private function serviceOf(string $metric): string
    {
        $prefix = strtok($metric, '.');

        return match ($prefix) {
            'http', 'php' => 'application',
            'db' => 'database',
            'redis' => 'cache',
            'queue' => 'queue',
            'scheduler' => 'scheduler',
            'cpu', 'memory', 'disk', 'network', 'server' => 'server',
            'check' => 'availability',
            false, '' => 'unknown',
            default => $prefix,
        };
    }

    private function nextReference(): string
    {
        $last = $this->connection()->table('monitoring_incidents')->max('id');

        return sprintf('INC-%05d', ((int) $last) + 1);
    }
}
