<?php

namespace App\Services\Monitoring\Operations;

use App\Services\AuditLogger;
use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Taking an incident, saying what caused it, and closing it.
 *
 * Six columns of `monitoring_incidents` had no writer anywhere — acknowledged_at, notes,
 * probable_cause, cause_evidence, deployment_id, resolved_by — so the console could tell an operator
 * that something was on fire and could record nothing about what happened next. There was no
 * time-to-acknowledge, no record of who took it, and no cause attribution even though the deploy
 * table sat beside it.
 *
 * The interesting one is `deployment_id`. Deploys were already recorded; nothing joined the two, so
 * the single most useful sentence a monitoring tool can produce — "p95 doubled at 14:20 and the
 * deploy was at 14:19" — could not be written. Attribution offers the deploys that ran near the
 * incident and lets a person choose, rather than guessing on their behalf: a correlation the tool
 * asserts is a cause nobody checked.
 */
class MonitoringIncidents
{
    /** How far either side of an incident a deploy is worth offering as a candidate cause. */
    private const CAUSE_WINDOW_MINUTES = 120;

    public function __construct(
        private readonly EventLog $events,
        private readonly AuditLogger $audit,
    ) {
    }

    public function acknowledge(int $incidentId, string $actor): bool
    {
        $incident = $this->find($incidentId);

        if ($incident === null || $incident->acknowledged_at !== null) {
            return false;
        }

        $this->connection()->table('monitoring_incidents')->where('id', $incidentId)->update([
            'acknowledged_at' => Clock::stamp(),
            'updated_at' => Clock::stamp(),
        ]);

        $this->record($incidentId, 'acknowledged', $actor, EventLog::INFO);

        return true;
    }

    public function note(int $incidentId, string $note, string $actor): bool
    {
        $incident = $this->find($incidentId);
        $note = trim($note);

        if ($incident === null || $note === '') {
            return false;
        }

        // Appended, never replaced. An incident's notes are a running account of what was tried,
        // and overwriting them would lose the attempt that did not work — which is usually the one
        // worth reading.
        $stamped = '[' . Clock::display(Clock::now())->toDateTimeString() . ' ' . $actor . '] ' . $note;
        $existing = trim((string) ($incident->notes ?? ''));

        $this->connection()->table('monitoring_incidents')->where('id', $incidentId)->update([
            'notes' => $existing === '' ? $stamped : $existing . "\n" . $stamped,
            'updated_at' => Clock::stamp(),
        ]);

        $this->record($incidentId, 'noted', $actor, EventLog::INFO, $note);

        return true;
    }

    public function attribute(int $incidentId, string $cause, ?string $evidence, ?int $deploymentId, string $actor): bool
    {
        $incident = $this->find($incidentId);
        $cause = trim($cause);

        if ($incident === null || $cause === '') {
            return false;
        }

        $this->connection()->table('monitoring_incidents')->where('id', $incidentId)->update([
            'probable_cause' => mb_substr($cause, 0, 191),
            'cause_evidence' => $evidence !== null && trim($evidence) !== '' ? trim($evidence) : null,
            'deployment_id' => $deploymentId,
            'updated_at' => Clock::stamp(),
        ]);

        $this->record($incidentId, 'cause recorded', $actor, EventLog::INFO, $cause);

        return true;
    }

    public function resolve(int $incidentId, string $actor, ?int $actorId): bool
    {
        $incident = $this->find($incidentId);

        if ($incident === null || ($incident->status ?? null) === 'resolved') {
            return false;
        }

        $this->connection()->table('monitoring_incidents')->where('id', $incidentId)->update([
            'status' => 'resolved',
            'resolved_at' => Clock::stamp(),
            'resolved_by' => $actorId,
            'updated_at' => Clock::stamp(),
        ]);

        $this->record($incidentId, 'resolved', $actor, EventLog::SUCCESS);

        return true;
    }

    /**
     * Deploys that ran close enough to an incident to be worth considering.
     *
     * Offered, not asserted: the operator picks. A tool that names a cause on a timestamp alone
     * will eventually blame the deploy that happened to be nearby, and be believed.
     *
     * @return array<int, array{id: int, release: string, deployed_at: string, minutes_before: int}>
     */
    public function candidateDeployments(int $incidentId): array
    {
        $incident = $this->find($incidentId);

        if ($incident === null) {
            return [];
        }

        $started = Clock::parse((string) ($incident->started_at ?? $incident->detected_at ?? Clock::stamp()));

        try {
            $rows = $this->connection()->table('monitoring_deployments')
                ->whereBetween('deployed_at', [
                    $started->copy()->subMinutes(self::CAUSE_WINDOW_MINUTES)->toDateTimeString(),
                    $started->copy()->addMinutes(self::CAUSE_WINDOW_MINUTES)->toDateTimeString(),
                ])
                ->orderByDesc('deployed_at')
                ->limit(20)
                ->get(['id', 'release', 'deployed_at']);
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'release' => (string) $row->release,
            'deployed_at' => (string) $row->deployed_at,
            'minutes_before' => (int) round(Clock::parse((string) $row->deployed_at)->diffInMinutes($started, false)),
        ])->all();
    }

    private function record(int $incidentId, string $what, string $actor, string $severity, ?string $detail = null): void
    {
        $this->events->record(
            type: EventLog::INCIDENT,
            severity: $severity,
            title: 'Incident #' . $incidentId . ' ' . $what,
            key: (string) $incidentId,
            description: $detail,
            context: ['by' => $actor],
            relatedId: $incidentId,
        );

        $this->audit->record(
            action: 'monitoring.incident_' . str_replace(' ', '_', $what),
            subject: ['type' => 'monitoring_incident', 'id' => $incidentId],
            after: array_filter(['detail' => $detail]),
        );
    }

    private function find(int $incidentId): ?object
    {
        try {
            return $this->connection()->table('monitoring_incidents')->where('id', $incidentId)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection'));
    }
}
