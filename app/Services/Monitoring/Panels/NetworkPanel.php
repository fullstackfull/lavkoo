<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Environment;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * Network: what the interfaces are actually moving, what TCP makes of it, and whether the
 * application's own name still resolves.
 *
 * Three decisions shape the section, and each of them is a way a network page usually misleads the
 * person reading it.
 *
 * An interface is not a host. Throughput belongs to a NIC, so a machine with a public interface, a
 * private one and a pair of container bridges has four different answers to "how busy is the
 * network". Every interface therefore gets its own card, split apart by the label the collector
 * publishes after the "@" in each metric name, and nothing here is summed into a single figure
 * that would be wrong on all four.
 *
 * A rate is a difference, not a total. Everything in /proc/net/dev and the Tcp line of
 * /proc/net/snmp is a counter that only climbs, so bytes per second exist only between two
 * readings. The collector reports that delta and says "no window yet" on its first sample, and
 * this page never converts a since-boot total into a rate: a machine's lifetime average wearing
 * the costume of a current reading is the most convincing wrong number a network page can show.
 *
 * A quiet interface is not a healthy one. A NIC that lost carrier reads as zero bytes and zero
 * errors, which is exactly what a genuinely idle link reads as, so the kernel's own link state is
 * carried on every card beside the numbers it explains.
 *
 * Byte rates leave here as raw bytes per second. Choosing KB/s or MB/s is a rendering decision the
 * view makes from the number; a pre-formatted string in the payload would be a figure the JSON
 * refresh could never re-scale and no threshold could ever compare.
 */
class NetworkPanel implements Panel
{
    /** The one collector this section is made of. Read exactly once per request. */
    private const COLLECTOR = 'network';

    /** The throughput pair. They lead each card, because "how much" is the finding. */
    private const INTERFACE_RATES = ['rx_bytes_per_s', 'tx_bytes_per_s'];

    /** The rest of an interface's readings, in the order the card reads them. */
    private const INTERFACE_COUNTERS = [
        'rx_packets_per_s', 'tx_packets_per_s', 'rx_errors', 'tx_errors', 'rx_dropped', 'tx_dropped',
    ];

    private const LINK_STATE = 'link_state';

    /** Presence of any one of these under a label IS an interface. */
    private const INTERFACE_METRICS = [...self::INTERFACE_RATES, ...self::INTERFACE_COUNTERS, self::LINK_STATE];

    /**
     * A ceiling on the interface cards one render may draw.
     *
     * /proc/net/dev lists every interface the host has, and a container runtime mints a fresh pair
     * per workload — a busy Kubernetes node carries hundreds. The cap bounds the page rather than
     * the query, and what it cuts is stated rather than quietly dropped.
     */
    private const MAX_INTERFACES = 24;

    /**
     * A hard ceiling on the rows one render may pull out of monitoring_series.
     *
     * The window, the resolution and the metric list already bound the query; this bounds the
     * other axis, which is the host's. When the cap bites the oldest points are the ones lost,
     * and the charts say so rather than quietly starting late.
     */
    private const MAX_SERIES_ROWS = 4000;

    /**
     * TCP, grouped by the question each group answers.
     *
     * @var array<string, array{why: string, metrics: list<string>}>
     */
    private const TCP_GROUPS = [
        'connections' => [
            'why' => 'how_many_connections_this_host_is_holding_right_now_and_in_which_state',
            'metrics' => ['tcp_established', 'tcp_inuse', 'tcp_time_wait', 'tcp_orphan', 'tcp_alloc', 'sockets_used', 'udp_inuse'],
        ],
        'retransmissions' => [
            'why' => 'segments_the_kernel_had_to_send_a_second_time_which_is_the_wire_reporting_that_it_lost_the_first_one',
            'metrics' => ['tcp_retrans_per_s', 'tcp_retrans_pct', 'tcp_out_segments_per_s', 'tcp_in_segments_per_s'],
        ],
        'connection_churn' => [
            'why' => 'active_opens_are_connections_this_host_dialled_out_and_passive_opens_are_the_ones_it_accepted',
            'metrics' => ['tcp_active_opens_per_s', 'tcp_passive_opens_per_s'],
        ],
    ];

    /**
     * The TCP failure counters, and why this build does not have them.
     *
     * A "connection errors" row is the one thing a TCP card is expected to carry that nothing here
     * measures, and leaving it off the page would be indistinguishable from measuring it and
     * finding none. The Tcp line already being parsed holds all four fields, so the remedy is
     * exact rather than aspirational.
     *
     * @var array<string, string>
     */
    private const CONNECTION_ERRORS = [
        'state' => 'not_supported',
        'source' => 'Linux /proc/net/snmp',
        'note' => 'The network collector reads RetransSegs, ActiveOpens, PassiveOpens, InSegs and OutSegs from the Tcp line and stops there, so refused connection attempts, resets and checksum errors are not measured anywhere on this dashboard.',
        'remedy' => 'Add AttemptFails, EstabResets, InErrs and OutRsts to the field list in NetworkCollector::tcpRates(); they sit on the same Tcp line of /proc/net/snmp that is already being parsed and differenced.',
    ];

    /**
     * The stored gauge behind each per-interface line.
     *
     * @var array<string, array{metric: string, unit: string, title: string, source: string}>
     */
    private const INTERFACE_CHARTS = [
        'rx_bytes_per_s' => ['metric' => 'server.network.rx_bytes_per_s', 'unit' => 'bytes/s', 'title' => 'received_over_time', 'source' => 'rx_bytes_per_s'],
        'tx_bytes_per_s' => ['metric' => 'server.network.tx_bytes_per_s', 'unit' => 'bytes/s', 'title' => 'transmitted_over_time', 'source' => 'tx_bytes_per_s'],
    ];

    /**
     * The gauges that describe the host rather than one interface, and are stored without a label.
     *
     * @var array<string, array{metric: string, unit: string, title: string, source: string}>
     */
    private const HOST_CHARTS = [
        'tcp_established' => ['metric' => 'server.network.tcp_established', 'unit' => 'connections', 'title' => 'established_connections_over_time', 'source' => 'tcp_established'],
        'tcp_retrans_per_s' => ['metric' => 'server.network.tcp_retrans_per_s', 'unit' => 'segments/s', 'title' => 'retransmissions_over_time', 'source' => 'tcp_retrans_per_s'],
        'dns_ms' => ['metric' => 'server.network.dns_ms', 'unit' => 'ms', 'title' => 'name_resolution_over_time', 'source' => 'dns_ms'],
    ];

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
        private readonly Environment $environment,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);

        // Collected once, and reused everywhere below. Every rate the collector publishes is a
        // delta against a cached previous sample, so a second collection inside the same request
        // would find its own first call's reading to subtract from and report a silent network.
        $readings = $this->collectors->collect(self::COLLECTOR);
        $labelled = $this->byLabel($readings);
        $host = $labelled[''] ?? [];
        $faults = $this->collectorFaults($readings);

        $published = $this->publishedGauges();
        $stored = $this->storedSeries($range, $window['resolution']);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'host' => $this->hostDescription(),
            'collectors' => $faults,
            'series' => ['state' => $stored['state'], 'note' => $stored['note'], 'truncated' => $stored['truncated']],
            'interfaces' => $this->interfaces($labelled, $published, $stored, $window['resolution'], $faults),
            'tcp' => $this->tcp($host, $published, $stored, $window['resolution']),
            'dns' => $this->dns($host, $published, $stored, $window['resolution']),
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The host

    /**
     * Which machine every interface on this page belongs to.
     */
    private function hostDescription(): ?string
    {
        try {
            return $this->environment->hostDescription();
        } catch (\Throwable) {
            // A name for the machine is context, not evidence. Losing it must not cost the page.
            return null;
        }
    }

    /**
     * The collector failing to answer at all, said once at the top.
     *
     * Normally empty. One fault reproduced across every card underneath reads as a dozen faults.
     *
     * @param  array<string, Metric>  $readings
     * @return array<int, array<string, mixed>>
     */
    private function collectorFaults(array $readings): array
    {
        if ($readings === []) {
            return [[
                'collector' => self::COLLECTOR,
                'state' => 'not_supported',
                'note' => 'The network collector is not installed in this build, so nothing on this page can be read from it.',
            ]];
        }

        $failure = $readings['__collector'] ?? null;

        return $failure instanceof Metric
            ? [['collector' => self::COLLECTOR, 'state' => 'failed', 'note' => $failure->note]]
            : [];
    }

    /**
     * Split the collector's readings by the label after the "@" in each metric name.
     *
     * `rx_bytes_per_s@eth0` and `tcp_established` arrive as one flat list and are two different
     * kinds of thing on the page. The empty-string key holds everything published without a label:
     * the TCP and socket counters, the DNS reading, and the fallback the collector emits when no
     * interface could be read at all.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, array<string, Metric>>
     */
    private function byLabel(array $readings): array
    {
        $grouped = [];

        foreach ($readings as $key => $metric) {
            if ($key === '__collector' || !$metric instanceof Metric) {
                continue;
            }

            [$name, $label] = array_pad(explode('@', $key, 2), 2, '');
            $grouped[$label][$name] = $metric;
        }

        return $grouped;
    }

    // -------------------------------------------------------------------------------------------
    // Interfaces

    /**
     * One card per interface, or the reason there are none.
     *
     * @param  array<string, array<string, Metric>>  $labelled
     * @param  array<int, string>|null  $published
     * @param  array<string, mixed>  $stored
     * @param  array<int, array<string, mixed>>  $faults  the collector failing to answer at all
     * @return array<string, mixed>
     */
    private function interfaces(array $labelled, ?array $published, array $stored, string $resolution, array $faults): array
    {
        $cards = [];
        foreach ($labelled as $label => $metrics) {
            if ($label === '' || array_intersect(self::INTERFACE_METRICS, array_keys($metrics)) === []) {
                continue;
            }

            $cards[] = $this->interfaceCard($label, $metrics, $published, $stored, $resolution);
        }

        // Interfaces the sampler keeps history for come first, then alphabetically. That order is
        // stable between refreshes, and it puts the host's real NICs above the container-created
        // ones the sampler deliberately skips rather than letting the alphabet decide.
        usort($cards, static fn (array $first, array $second) => [!$first['stored'], $first['key']] <=> [!$second['stored'], $second['key']]);

        $fallback = ($labelled['']['interfaces'] ?? null) instanceof Metric ? $labelled['']['interfaces'] : null;

        // A collector that could not answer at all did not measure zero interfaces — it did not
        // measure. Without this the card said "returned no interface readings on this host" over a
        // collector that threw, which is the reassuring version of a fault.
        $fault = $cards === [] && $fallback === null ? ($faults[0] ?? null) : null;

        return [
            'state' => match (true) {
                $cards !== [] => 'ok',
                $fallback instanceof Metric && !$fallback->isOk() => $fallback->state,
                $fault !== null => $fault['state'],
                default => 'no_data',
            },
            'note' => match (true) {
                $cards !== [] => null,
                $fault !== null => $fault['note'],
                default => $fallback?->note ?? 'The network collector returned no interface readings on this host.',
            },
            'remedy' => $cards !== [] ? null : $fallback?->remedy,
            'source' => $fallback?->source ?? 'Linux /proc/net/dev',
            'total' => count($cards),
            'shown' => min(count($cards), self::MAX_INTERFACES),
            'cards' => array_slice($cards, 0, self::MAX_INTERFACES),
        ];
    }

    /**
     * @param  array<string, Metric>  $metrics
     * @param  array<int, string>|null  $published
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function interfaceCard(string $label, array $metrics, ?array $published, array $stored, string $resolution): array
    {
        return [
            'key' => $label,
            'label' => $label,
            'link' => $metrics[self::LINK_STATE] ?? null,
            'rates' => $this->ordered($metrics, self::INTERFACE_RATES),
            'metrics' => $this->ordered($metrics, self::INTERFACE_COUNTERS),
            'charts' => $this->charts(self::INTERFACE_CHARTS, $metrics, $label, $published, $stored, $resolution),
            // Whether this interface is sampled into monitoring_series at all, which is what
            // separates "no history yet" from "history is deliberately not kept for this one".
            'stored' => $this->isPublished(self::INTERFACE_CHARTS['rx_bytes_per_s']['metric'], $label, $published),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // TCP and DNS

    /**
     * @param  array<string, Metric>  $host
     * @param  array<int, string>|null  $published
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function tcp(array $host, ?array $published, array $stored, string $resolution): array
    {
        $groups = [];
        foreach (self::TCP_GROUPS as $key => $definition) {
            $metrics = $this->ordered($host, $definition['metrics']);
            if ($metrics === []) {
                continue;
            }

            $groups[] = ['key' => $key, 'why' => $definition['why'], 'metrics' => $metrics];
        }

        return [
            'groups' => $groups,
            'connection_errors' => self::CONNECTION_ERRORS,
            'charts' => $this->charts(
                array_intersect_key(self::HOST_CHARTS, array_flip(['tcp_established', 'tcp_retrans_per_s'])),
                $host,
                '',
                $published,
                $stored,
                $resolution,
            ),
        ];
    }

    /**
     * @param  array<string, Metric>  $host
     * @param  array<int, string>|null  $published
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function dns(array $host, ?array $published, array $stored, string $resolution): array
    {
        return [
            'metric' => $host['dns_ms'] ?? null,
            'chart' => $this->chart(
                'dns_ms',
                self::HOST_CHARTS['dns_ms'],
                '',
                $host['dns_ms'] ?? null,
                $published,
                $stored,
                $resolution,
            ),
        ];
    }

    /**
     * The readings a card shows, in a fixed order.
     *
     * A reading that is OK but not scalar has no honest one-line rendering, and handing an array
     * to the metric partial prints a PHP warning where a value belongs. An unavailable reading
     * goes in whole: its state and its remedy are the content.
     *
     * @param  array<string, Metric>  $metrics
     * @param  list<string>  $order
     * @return array<string, Metric>
     */
    private function ordered(array $metrics, array $order): array
    {
        $card = [];

        foreach ($order as $name) {
            $metric = $metrics[$name] ?? null;
            if (!$metric instanceof Metric || ($metric->isOk() && !is_scalar($metric->value))) {
                continue;
            }

            $card[$name] = $metric;
        }

        return $card;
    }

    // -------------------------------------------------------------------------------------------
    // Stored gauges

    /**
     * The gauge names this host is publishing this minute.
     *
     * The collector deliberately keeps container-created interfaces out of the series — a
     * Kubernetes node would otherwise write the cluster's whole scheduling history into
     * monitoring_series, one dead veth name at a time. Asking the collector which gauges it
     * publishes is how a chart can say that, without this panel keeping its own copy of the rule.
     * gauges() reads the same memoised sample collect() already took, so it costs nothing.
     *
     * @return array<int, string>|null  null when it could not be established at all
     */
    private function publishedGauges(): ?array
    {
        try {
            $collector = $this->collectors->get(self::COLLECTOR);

            return $collector === null ? null : array_keys($collector->gauges());
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int, string>|null $published */
    private function isPublished(string $metric, string $label, ?array $published): bool
    {
        return $published !== null && in_array($metric . ($label === '' ? '' : '@' . $label), $published, true);
    }

    /**
     * Every gauge this section draws, for every interface, in one read.
     *
     * SeriesReader::series() reads one metric and one label at a time, which is right for a page
     * with a fixed handful of lines and wrong for this one: a host with six interfaces would cost
     * a dozen round trips to draw one screen. The window, the resolution and the UTC boundary
     * still come from the reader — only the grouping happens here, on the same
     * (metric, resolution, bucket_at) index every other series read uses.
     *
     * @return array{state: string, note: string|null, truncated: bool, points: array<string, array<string, list<array{t: string, v: float|null}>>>}
     */
    private function storedSeries(string $range, string $resolution): array
    {
        $metrics = array_values(array_unique(array_merge(
            array_column(self::INTERFACE_CHARTS, 'metric'),
            array_column(self::HOST_CHARTS, 'metric'),
        )));

        try {
            $rows = $this->reader->connection()->table('monitoring_series')
                ->whereIn('metric', $metrics)
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $this->reader->since($range))
                // Newest first, so a window wider than the cap loses its oldest points rather than
                // its most recent ones — a line that stops an hour ago is a lie about right now.
                ->orderByDesc('bucket_at')
                ->limit(self::MAX_SERIES_ROWS)
                ->get(['metric', 'label', 'bucket_at', 'samples', 'value_sum', 'value_last']);
        } catch (\Throwable $exception) {
            // PanelRegistry would catch this as well, but it can only blank the whole section.
            // Failing the charts alone leaves every live reading on the page readable.
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'truncated' => false,
                'points' => [],
            ];
        }

        $points = [];
        foreach ($rows as $row) {
            // Mirrors SeriesReader::series(): a gauge's honest value for a bucket is its last
            // reading, a counter's is its sum, and value_last being null is how the two are told
            // apart. Kept identical deliberately — two readings of the same stored row must not
            // disagree between this page and any other.
            $value = $row->value_last !== null
                ? (float) $row->value_last
                : ((int) $row->samples > 0 ? (float) $row->value_sum : null);

            $points[$row->metric][(string) $row->label][] = [
                't' => Clock::parse($row->bucket_at)->toIso8601String(),
                'v' => $value,
            ];
        }

        foreach ($points as $metric => $labels) {
            foreach ($labels as $label => $series) {
                $points[$metric][$label] = array_reverse($series);
            }
        }

        return [
            'state' => 'ok',
            'note' => null,
            'truncated' => count($rows) >= self::MAX_SERIES_ROWS,
            'points' => $points,
        ];
    }

    /**
     * @param  array<string, array{metric: string, unit: string, title: string, source: string}>  $definitions
     * @param  array<string, Metric>  $metrics
     * @param  array<int, string>|null  $published
     * @param  array<string, mixed>  $stored
     * @return array<string, array<string, mixed>>
     */
    private function charts(array $definitions, array $metrics, string $label, ?array $published, array $stored, string $resolution): array
    {
        $charts = [];

        foreach ($definitions as $key => $definition) {
            $charts[$key] = $this->chart($key, $definition, $label, $metrics[$definition['source']] ?? null, $published, $stored, $resolution);
        }

        return $charts;
    }

    /**
     * One stored gauge over the window, carrying why it has no line when it has none.
     *
     * @param  array{metric: string, unit: string, title: string, source: string}  $definition
     * @param  array<int, string>|null  $published
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function chart(string $key, array $definition, string $label, ?Metric $live, ?array $published, array $stored, string $resolution): array
    {
        $points = array_values(array_filter(
            $stored['points'][$definition['metric']][$label] ?? [],
            static fn (array $point) => $point['v'] !== null,
        ));

        $read = $stored['state'] === 'ok';

        $chart = [
            'key' => $key,
            'metric' => $definition['metric'],
            'label' => $label,
            'unit' => $definition['unit'],
            'title' => $definition['title'],
            'latest' => $points === [] ? null : end($points)['v'],
            // Points, not samples: at hour or day resolution one stored row is a rollup of sixty
            // or of fourteen hundred readings, so calling this a sample count would understate a
            // week's collection by two orders of magnitude. Null rather than zero when the read
            // failed — it did not find nothing, it did not look, and the JSON refresh reads this
            // key without the note beside it that would have said so.
            'stored_points' => $read ? count($points) : null,
            'points' => $points,
        ];

        if (!$read) {
            return array_merge($chart, ['state' => 'failed', 'note' => $stored['note'], 'remedy' => null]);
        }

        // One point is a reading; a line needs two. Saying which of those it is stops a single
        // sample being read as a flat trend.
        if (count($points) < 2) {
            return array_merge($chart, $this->gaugeGap($definition['metric'], $label, $resolution, count($points), $live, $published));
        }

        return array_merge($chart, [
            'state' => 'ok',
            'note' => $stored['truncated']
                ? 'This render hit its ' . self::MAX_SERIES_ROWS . '-row read cap, so the line may start later than the window does.'
                : null,
            'remedy' => null,
        ]);
    }

    /**
     * Why a gauge has no line.
     *
     * Five different silences with five different answers, and the empty chart they all draw looks
     * identical.
     *
     * @param  array<int, string>|null  $published
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function gaugeGap(string $metric, string $label, string $resolution, int $points, ?Metric $live, ?array $published): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no gauge has been sampled since it was disabled.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        // The sampler only stores a reading that is OK, so a metric this host cannot produce has
        // never been written. The gap is the host, not the scheduler, and the reading says which.
        //
        // Only a reading that is structurally unavailable earns that answer. NO_DATA means the
        // opposite — the probe works here and has simply not recorded yet — and every rate in this
        // section is NO_DATA on the first sample of a process, because bytes per second is a delta
        // against a cached previous reading. Blaming "not on this host" for that printed "it is not
        // on this host" over eth0 with rx_bytes_per_s history sitting in monitoring_series
        // underneath it.
        $unavailable = [Metric::NOT_SUPPORTED, Metric::NOT_CONFIGURED, Metric::PERMISSION_DENIED, Metric::COLLECTOR_OFFLINE, Metric::FAILED];
        if ($live instanceof Metric && in_array($live->state, $unavailable, true)) {
            return [
                'state' => $live->state,
                'note' => 'This gauge is only stored while the reading behind it is available, and it is not on this host. '
                    . ($live->note ?? 'The collector returned no value for it.'),
                'remedy' => $live->remedy,
            ];
        }

        // "Available live but never stored" is a claim only a readable reading can carry. Without
        // isOk() this branch caught the first-sample NO_DATA above and told the operator their
        // primary NIC was a container's throwaway veth.
        if ($live instanceof Metric && $live->isOk() && $published !== null && !$this->isPublished($metric, $label, $published)) {
            // Readable right now, and deliberately never stored: container runtimes mint an
            // interface per workload, and each one is a series name that never comes back.
            return [
                'state' => 'not_supported',
                'note' => 'This reading is available live but is deliberately kept out of the stored series, because interfaces a container runtime creates per workload would fill the history with names that never appear again.',
                'remedy' => null,
            ];
        }

        if ($resolution !== 'minute') {
            // Gauges are written once a minute. Longer ranges read rolled-up rows, which the rollup
            // produces — so this window can be empty while the minute rows under it are full.
            return [
                'state' => 'no_data',
                'note' => 'This range reads ' . $resolution . ' rows, which the monitoring rollup builds from the minute samples rather than the sampler writing directly.',
                'remedy' => 'Choose a shorter range to read the minute samples, or check the hourly rollup is running: `php artisan schedule:list`.',
            ];
        }

        return [
            'state' => 'no_data',
            'note' => $points === 1
                ? 'Only one sample has been stored in this window, and one point is not a line.'
                : 'No sample of this gauge has been stored in this window.',
            'remedy' => 'Gauges are sampled by `php artisan monitoring:flush`, scheduled every minute. Check the Laravel scheduler is running: `php artisan schedule:list`.',
        ];
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Readings the collector produced that this page draws nowhere.
     *
     * Normally empty. It exists so that a collector which grows a reading cannot have it silently
     * disappear: an undrawn measurement is indistinguishable from an unmeasured one, and that is
     * the confusion this whole system is built to avoid.
     *
     * @param  array<string, Metric>  $readings
     * @return array<int, array{metric: string, label: string, state: string}>
     */
    private function unrendered(array $readings): array
    {
        $claimed = array_merge(
            self::INTERFACE_METRICS,
            ['interfaces', 'dns_ms'],
            ...array_column(self::TCP_GROUPS, 'metrics'),
        );

        $unrendered = [];
        foreach ($readings as $key => $metric) {
            // __collector is the registry's own failure marker, reported at the top of the page
            // rather than as a reading the collector produced.
            if ($key === '__collector' || !$metric instanceof Metric) {
                continue;
            }

            [$name, $label] = array_pad(explode('@', $key, 2), 2, '');
            if (in_array($name, $claimed, true)) {
                continue;
            }

            $unrendered[] = ['metric' => $name, 'label' => $label, 'state' => $metric->state];
        }

        return $unrendered;
    }
}
