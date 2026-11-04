<?php

namespace App\Console\Commands;

use App\Services\Monitoring\Ingest\BucketWriter;
use App\Services\Monitoring\Ingest\MetricSink;
use App\Services\Monitoring\Collectors\CollectorRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The heartbeat of the collection side: once a minute, turn buffered counters into rows and take a
 * fresh reading of everything that is a gauge rather than a counter.
 *
 * Two jobs, deliberately in one command run on one schedule:
 *
 *  1. Drain the request/dependency counters that web requests have been incrementing in Redis or
 *     APCu, and write them as minute buckets. Only minutes that are already OVER are drained, so
 *     nothing is counted twice.
 *  2. Sample the gauges — CPU, memory, disk, queue depth, connection counts. These have no request
 *     to hang off; somebody has to go and look, and this is that somebody.
 *
 * If this command stops running, the dashboard does not quietly show stale numbers as if they were
 * live: the self-health panel reads the age of the newest bucket and reports that monitoring
 * itself has stopped.
 */
class MonitoringFlush extends Command
{
    protected $signature = 'monitoring:flush
                            {--no-gauges : Only drain the request buffer, do not sample gauges}';

    protected $description = 'Drain buffered request counters into minute buckets and sample system gauges';

    public function handle(MetricSink $sink, BucketWriter $writer, CollectorRegistry $collectors): int
    {
        if (!config('monitoring.enabled', true)) {
            $this->warn('Monitoring is disabled (MONITORING_ENABLED=false); nothing to do.');

            return self::SUCCESS;
        }

        $drainedMinutes = 0;
        $drainedBuckets = 0;

        if ($sink->isBuffered()) {
            $drained = $sink->drainCompletedMinutes(time());
            $drainedMinutes = count($drained);
            $drainedBuckets = array_sum(array_map('count', $drained));

            if ($drained !== []) {
                $writer->apply($drained);
            }
        }

        $gauges = 0;
        if (!$this->option('no-gauges')) {
            $gauges = $this->sampleGauges($collectors, $writer);
        }

        $this->info(sprintf(
            'Flushed %d bucket(s) across %d minute(s) via %s; sampled %d gauge(s).',
            $drainedBuckets,
            $drainedMinutes,
            $sink->driver(),
            $gauges,
        ));

        return self::SUCCESS;
    }

    /**
     * Take one reading of every gauge and store it as a series point.
     *
     * A collector that cannot answer — no PSI on this kernel, no Redis configured, no permission
     * to read InnoDB status — contributes nothing rather than a zero. The dashboard reads the
     * absence and reports it as Not supported / Not configured, which is the whole point.
     */
    private function sampleGauges(CollectorRegistry $collectors, BucketWriter $writer): int
    {
        $minute = intdiv(time(), 60) * 60;
        $points = [];

        foreach ($collectors->gauges() as $metric => $result) {
            if (!$result->isOk() || !is_numeric($result->value)) {
                continue;
            }

            [$name, $label] = array_pad(explode('@', $metric, 2), 2, '');
            $key = BucketWriter::SERIES_PREFIX . $name . '|' . $label;
            $points[$key] = [
                'n' => 1,
                'sum' => (float) $result->value,
                'v:min' => (float) $result->value,
                'v:max' => (float) $result->value,
                'last' => (float) $result->value,
            ];
        }

        if ($points !== []) {
            $writer->apply([$minute => $points]);
        }

        return count($points);
    }
}
