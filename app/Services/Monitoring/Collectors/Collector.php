<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;

/**
 * Something that can be asked for readings.
 *
 * The contract is deliberately narrow: a collector answers with named Metrics and never decides
 * what any of them mean. Thresholds, colours and health scores are applied above this layer, so a
 * collector can be tested by reading its numbers against the machine it ran on, with no opinion in
 * the way.
 *
 * A collector NEVER throws and NEVER invents. Anything it cannot read comes back as a Metric in
 * the state that explains why — not_supported, not_configured, permission_denied — carrying the
 * remedy where there is one.
 */
interface Collector
{
    /** Stable prefix for this collector's metric names, e.g. "server" or "db". */
    public function key(): string;

    /**
     * Everything this collector can currently answer.
     *
     * @return array<string, Metric>  metric name (without the collector prefix) => reading
     */
    public function collect(): array;

    /**
     * The subset worth storing as a time series once a minute.
     *
     * Not everything belongs in history: a list of the top ten tables by size is a snapshot, while
     * CPU usage is a line on a chart. Keys are dotted metric names, optionally with an `@label`
     * suffix for a single low-cardinality dimension (`disk.used_pct@/dev/vda`).
     *
     * @return array<string, Metric>
     */
    public function gauges(): array;
}
