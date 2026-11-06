<?php

namespace App\Console\Commands\Concerns;

use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityReport;

/** The way the commands that inspect a restored database print their findings: a table, a verdict, or JSON. */
trait PrintsInspectionReports
{
    private const STATUS_LABELS = [
        CompatibilityCheck::PASS => 'PASS',
        CompatibilityCheck::WARN => 'WARN',
        CompatibilityCheck::FAIL => 'FAIL',
        CompatibilityCheck::INFO => 'INFO',
    ];

    private function printReportAsJson(CompatibilityReport $report): void
    {
        $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function printReportAsTable(CompatibilityReport $report, string $closingNote): void
    {
        if (!$report->refused) {
            $this->line('Database: ' . ($report->environment['driver'] ?? '?') . ' ' . ($report->environment['server_version'] ?? '') . ', "' . ($report->environment['database'] ?? '') . '" (APP_ENV=' . ($report->environment['app_env'] ?? '') . ')');
        }

        $this->table(['section', 'check', 'status', 'detail'], array_map(
            static fn (CompatibilityCheck $check): array => [$check->section, $check->name, self::STATUS_LABELS[$check->status] ?? $check->status, $check->detail],
            $report->checks,
        ));

        $this->line('Verdict: ' . strtoupper(str_replace('_', ' ', $report->verdict())) . ' — '
            . $report->count(CompatibilityCheck::FAIL) . ' failure(s), ' . $report->count(CompatibilityCheck::WARN) . ' warning(s), '
            . $report->count(CompatibilityCheck::PASS) . ' passed, ' . $report->count(CompatibilityCheck::INFO) . ' informational.');
        $this->line($closingNote);
    }
}
