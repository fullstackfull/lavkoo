<?php

namespace App\Services\Monitoring;

use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one axis: everything that HAPPENED to this system, in the order it happened.
 *
 * monitoring_events was designed for eight kinds of event — deploy, scheduler, backup, incident,
 * alert, config, check, annotation — and exactly one of them was ever written: a scheduled task
 * that failed. So the timeline could only ever draw a line of scheduler failures, and the alerts
 * page had a permanent, tested discrepancy against it (AlertHistoryDiscrepancyTest): the rule state
 * counted firings that the timeline had never been told about.
 *
 * The fix is not a bigger reader. It is this: one place that writes an event, called from each of
 * the seams where something actually happens. Writing it in eight places would have meant eight
 * spellings of `type`, and a timeline whose filters silently miss half the rows.
 *
 * Nothing here may throw. An event that cannot be recorded is a gap in a chart; an exception on
 * this path would be an outage caused by the thing that exists to notice outages.
 */
class EventLog
{
    /** A release started running. */
    public const DEPLOY = 'deploy';
    /** A scheduled task did something worth remembering. */
    public const SCHEDULER = 'scheduler';
    /** A backup ran, or was restore-tested. */
    public const BACKUP = 'backup';
    /** An incident opened, changed or resolved. */
    public const INCIDENT = 'incident';
    /** An alert rule fired or recovered. */
    public const ALERT = 'alert';
    /** Monitoring's own configuration changed. */
    public const CONFIG = 'config';
    /** A health or synthetic check crossed between up and down. */
    public const CHECK = 'check';
    /** A human wrote a note on the axis. */
    public const ANNOTATION = 'annotation';

    /** Every type this axis can carry, so a reader can offer them all as filters. */
    public const TYPES = [
        self::DEPLOY, self::SCHEDULER, self::BACKUP, self::INCIDENT,
        self::ALERT, self::CONFIG, self::CHECK, self::ANNOTATION,
    ];

    public const INFO = 'info';
    public const SUCCESS = 'success';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    /** @var array<int, string> */
    public const SEVERITIES = [self::INFO, self::SUCCESS, self::WARNING, self::CRITICAL];

    /**
     * @param  array<string, mixed>  $context  redacted before it is stored
     * @param  int|null  $relatedId  the row this event is about (an incident, a deployment, a backup)
     */
    public function record(
        string $type,
        string $severity,
        string $title,
        ?string $key = null,
        ?string $description = null,
        array $context = [],
        ?int $relatedId = null,
        ?string $occurredAt = null,
    ): void {
        try {
            $this->connection()->table('monitoring_events')->insert([
                'type' => Str::limit($type, 24, ''),
                'key' => $key !== null ? Str::limit($key, 96, '') : null,
                'severity' => Str::limit($severity, 12, ''),
                'title' => Str::limit($title, 185, ''),
                'description' => $description,
                'context' => $context === [] ? null : json_encode(app(Redactor::class)->array($context)),
                'related_id' => $relatedId,
                // A note may be written after the fact — "the ISP maintenance was at 02:00" is
                // typed at 09:00 — so an explicit time lands on the axis where it belongs, while
                // an unparseable one falls back to now rather than losing the entry.
                'occurred_at' => $occurredAt !== null ? $this->stampFor($occurredAt) : Clock::stamp(),
                'created_at' => Clock::stamp(),
            ]);
        } catch (\Throwable) {
            // A timeline that cannot be written is a gap in a chart. An exception here would be an
            // outage caused by the system that exists to notice outages.
        }
    }

    private function stampFor(string $occurredAt): string
    {
        try {
            return Clock::parse($occurredAt)->toDateTimeString();
        } catch (\Throwable) {
            return Clock::stamp();
        }
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }
}
