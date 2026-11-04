<?php

namespace App\Services\Monitoring\Ingest;

use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The slow-query ledger, kept independently of tracing.
 *
 * MySQL's own slow query log is off on most shared deployments, and enabling it needs server
 * access the merchant often does not have — so the application records its own. Every query over
 * the threshold is counted here whatever the trace sample rate, which is what makes the answer to
 * "which query is eating the database" trustworthy rather than a 2% guess.
 *
 * Queries are stored by FINGERPRINT — the statement with its literals replaced — so a query run
 * forty thousand times with different ids is one row with a count, and no customer's data is ever
 * written to the monitoring store.
 */
class SlowQueryRecorder
{
    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }

    public function persist(RequestContext $context, string $route): void
    {
        try {
            if ($context->slowQueries === []) {
                return;
            }

            $hour = Clock::now()->startOfHour();
            $grouped = [];

            foreach ($context->slowQueries as $query) {
                $fingerprint = sha1($query['sql']);
                if (!isset($grouped[$fingerprint])) {
                    $grouped[$fingerprint] = [
                        'sql' => $query['sql'],
                        'executions' => 0,
                        'total_ms' => 0.0,
                        'max_ms' => 0.0,
                    ];
                }
                $grouped[$fingerprint]['executions']++;
                $grouped[$fingerprint]['total_ms'] += $query['ms'];
                $grouped[$fingerprint]['max_ms'] = max($grouped[$fingerprint]['max_ms'], $query['ms']);
            }

            $rows = [];
            foreach ($grouped as $fingerprint => $data) {
                $rows[] = [
                    'fingerprint' => $fingerprint,
                    'resolution' => 'hour',
                    'bucket_at' => $hour->toDateTimeString(),
                    'sql_normalised' => Str::limit($data['sql'], 4000, ''),
                    'primary_table' => $this->primaryTable($data['sql']),
                    'route' => Str::limit($route, 185, ''),
                    'executions' => $data['executions'],
                    'total_ms' => (int) round($data['total_ms']),
                    'max_ms' => (int) round($data['max_ms']),
                    'rows_examined_sum' => 0,
                ];
            }

            $grammar = $this->connection()->getQueryGrammar();
            $this->connection()->table('monitoring_slow_queries')->upsert(
                $rows,
                ['fingerprint', 'resolution', 'bucket_at'],
                [
                    'executions' => DB::raw($grammar->wrap('executions') . ' + VALUES(' . $grammar->wrap('executions') . ')'),
                    'total_ms' => DB::raw($grammar->wrap('total_ms') . ' + VALUES(' . $grammar->wrap('total_ms') . ')'),
                    'max_ms' => DB::raw('GREATEST(' . $grammar->wrap('max_ms') . ', VALUES(' . $grammar->wrap('max_ms') . '))'),
                    // The route that most recently provoked it — a starting point, not a claim
                    // that it is the only caller.
                    'route',
                ],
            );
        } catch (\Throwable) {
            // Recording a slow query must never itself become a failure.
        }
    }

    private function primaryTable(string $sql): ?string
    {
        if (preg_match('/\b(?:from|into|update|join)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $match) === 1) {
            return Str::limit($match[1], 90, '');
        }

        return null;
    }
}
