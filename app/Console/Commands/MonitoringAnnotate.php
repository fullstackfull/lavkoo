<?php

namespace App\Console\Commands;

use App\Services\Monitoring\EventLog;
use Illuminate\Console\Command;

/**
 * Write a human note onto the timeline.
 *
 * Every other event on that axis is produced by a machine that noticed something. This one is the
 * sentence only a person can supply: "we restarted the database", "the ISP was doing maintenance",
 * "this spike is the radio advert". Without it, the reader of a graph six weeks later has the
 * shape of an incident and none of its explanation — and the timeline said so itself, in a legend
 * entry that described annotations as having no producer anywhere in this build.
 *
 *   php artisan monitoring:annotate "Restarted MariaDB after the 14:00 lock-up"
 *   php artisan monitoring:annotate "Radio advert goes out" --severity=info --at="2026-08-22 09:00"
 *
 * It is a console command rather than a screen on purpose: monitoring is GET-only in this build,
 * and a note is written by whoever is already at the terminal doing the thing worth noting.
 */
class MonitoringAnnotate extends Command
{
    protected $signature = 'monitoring:annotate
                            {title : the one-line note, as it appears on the axis}
                            {--description= : the longer version, shown when the entry is opened}
                            {--severity=info : info, success, warning or critical}
                            {--key= : a label to group related notes, e.g. a release or a supplier}
                            {--at= : when it happened, if not now}';

    protected $description = 'Write a human note onto the monitoring timeline';

    public function handle(EventLog $events): int
    {
        $title = trim((string) $this->argument('title'));

        if ($title === '') {
            $this->error('A note needs something to say.');

            return self::FAILURE;
        }

        $severity = in_array($this->option('severity'), EventLog::SEVERITIES, true)
            ? (string) $this->option('severity')
            : EventLog::INFO;

        $events->record(
            type: EventLog::ANNOTATION,
            severity: $severity,
            title: $title,
            key: $this->option('key') !== null ? (string) $this->option('key') : null,
            description: $this->option('description') !== null ? (string) $this->option('description') : null,
            occurredAt: $this->option('at') !== null ? (string) $this->option('at') : null,
        );

        $this->info('Noted on the timeline.');

        return self::SUCCESS;
    }
}
