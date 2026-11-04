<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\MonitoringSettings;

/**
 * Did cron fire, and is every scheduled task keeping up?
 *
 * The distinction that matters: a server with no cron entry at all is a setup problem the operator
 * must fix once, while a cron that fires but whose tasks are failing is an incident. Those are
 * reported as different states so they cannot be confused for one another — the first is what this
 * project shipped with, and the reason the Analytics rollups were empty for months.
 */
class SchedulerCheck implements Check
{
    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly MonitoringSettings $settings,
    ) {
    }

    public function key(): string
    {
        return 'scheduler';
    }

    public function kind(): string
    {
        return 'health';
    }

    public function run(): CheckResult
    {
        $readings = $this->collectors->collect('scheduler');
        $installed = $readings['cron_installed'] ?? null;
        $age = $readings['last_run_age_minutes'] ?? null;

        if ($installed instanceof Metric && $installed->isOk() && $installed->value === false) {
            return CheckResult::notConfigured(
                $this->key(),
                'No scheduled task has ever recorded a run. Add the Laravel scheduler to the server crontab: * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
            );
        }

        $context = [
            'defined_tasks' => $this->number($readings['defined_tasks'] ?? null),
            'late_tasks' => $this->number($readings['late_tasks'] ?? null),
            'missed_tasks' => $this->number($readings['missed_tasks'] ?? null),
            'failed_tasks' => $this->number($readings['failed_tasks'] ?? null),
        ];

        if ($context['failed_tasks']) {
            return CheckResult::failing(
                $this->key(),
                "{$context['failed_tasks']} scheduled task(s) failed on their last run.",
                context: $context,
            );
        }

        if ($context['missed_tasks']) {
            return CheckResult::failing(
                $this->key(),
                "{$context['missed_tasks']} scheduled task(s) missed their due time.",
                context: $context,
            );
        }

        if (!$age instanceof Metric || !$age->isOk()) {
            return CheckResult::unknown($this->key(), 'The time of the last scheduler run could not be read.', $context);
        }

        $minutes = (int) $age->value;
        $late = (int) ($this->settings->threshold('scheduler_late_minutes') ?? 10);

        if ($minutes > $late) {
            return CheckResult::failing($this->key(), "The scheduler last ran {$minutes} minute(s) ago.", context: $context);
        }

        if ($context['late_tasks']) {
            return CheckResult::degraded($this->key(), "{$context['late_tasks']} scheduled task(s) are running late.", context: $context);
        }

        return CheckResult::ok($this->key(), "The scheduler ran {$minutes} minute(s) ago.", context: $context);
    }

    private function number(mixed $metric): ?int
    {
        return $metric instanceof Metric && $metric->isOk() && is_numeric($metric->value) ? (int) $metric->value : null;
    }
}
