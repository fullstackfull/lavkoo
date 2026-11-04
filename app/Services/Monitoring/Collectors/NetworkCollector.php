<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Environment;
use Illuminate\Support\Facades\Cache;

/**
 * Network: what the interfaces are actually moving, and what TCP makes of it.
 *
 * Four things this deliberately does NOT do, because each one is a standard way a network page
 * misleads the person reading it:
 *
 * 1. It does not divide since-boot counters by uptime. Everything in /proc/net/dev and the Tcp
 *    line of /proc/net/snmp is a monotonic counter, so bytes per second and retransmits per
 *    second only exist as the difference between two readings. The previous reading is cached and
 *    the delta is what gets reported — on the first sample the answer is "no data yet", never the
 *    machine's lifetime average presented as the last minute.
 *
 * 2. It does not count the loopback as traffic. Every query to a local database and every Redis
 *    round trip crosses lo, so including it paints a gigabyte an hour onto a host whose NIC is
 *    idle, and hides the moment the real interface stops moving.
 *
 * 3. It does not report retransmissions as a bare rate. Fifty retransmitted segments a second is
 *    a dying link on a quiet server and background noise on a saturated one, so the share of
 *    outbound segments that had to be sent twice is reported beside the rate.
 *
 * 4. It does not time a lookup that is not one. Where APP_URL holds an IP address or localhost
 *    there is no name to resolve, and publishing the microseconds /etc/hosts takes as "DNS
 *    latency" would put a reassuring green number where a resolver was never measured at all.
 */
class NetworkCollector implements Collector
{
    private const PREVIOUS_KEY = 'monitoring:network:previous';
    private const DNS_INFLIGHT_KEY = 'monitoring:network:dns_in_flight';

    private const NETDEV_SOURCE = 'Linux /proc/net/dev';
    private const SNMP_SOURCE = 'Linux /proc/net/snmp';
    private const SOCKSTAT_SOURCE = 'Linux /proc/net/sockstat';
    private const OPERSTATE_SOURCE = 'Linux /sys/class/net/<iface>/operstate';
    private const DNS_SOURCE = 'PHP dns_get_record() on the APP_URL host';
    private const RESOLVER_SOURCE = 'PHP gethostbyname() on the APP_URL host';

    private const SAMPLE_TTL_SECONDS = 600;

    /**
     * Below this the window is short enough that sampling jitter, not the wire, decides the
     * answer — one 1500-byte frame across 20 ms would be published as 75 kB/s.
     */
    private const MIN_INTERVAL_SECONDS = 0.2;

    /**
     * How long a lookup that never returned blocks further lookups. Neither dns_get_record() nor
     * gethostbyname() takes a timeout, so this is the only bound available from PHP.
     */
    private const DNS_INFLIGHT_SECONDS = 300;

    /** @var list<string> */
    private const INTERFACE_METRICS = [
        'rx_bytes_per_s', 'tx_bytes_per_s', 'rx_packets_per_s', 'tx_packets_per_s',
        'rx_errors', 'tx_errors', 'rx_dropped', 'tx_dropped',
    ];

    /** @var list<string> */
    private const TCP_RATE_METRICS = [
        'tcp_retrans_per_s', 'tcp_retrans_pct', 'tcp_active_opens_per_s',
        'tcp_passive_opens_per_s', 'tcp_in_segments_per_s', 'tcp_out_segments_per_s',
    ];

    /** @var list<string> */
    private const TCP_METRICS = ['tcp_established', ...self::TCP_RATE_METRICS];

    /** @var list<string> */
    private const SOCKET_METRICS = [
        'sockets_used', 'tcp_inuse', 'tcp_orphan', 'tcp_time_wait', 'tcp_alloc', 'udp_inuse',
    ];

    /** The chartable subset, matched against the metric name in front of its "@label". */
    private const GAUGE_METRICS = [
        'rx_bytes_per_s', 'tx_bytes_per_s', 'tcp_established', 'tcp_retrans_per_s', 'dns_ms',
    ];

    /**
     * Interfaces a container runtime mints and tears down per workload, named after an id it
     * assigned. Their traffic is real and stays in collect(), but as a chart dimension each one
     * is a series name that never comes back: a Kubernetes node would open a fresh pair of them
     * for every pod that has ever started on it, and the time series table would carry the
     * cluster's whole scheduling history a minute at a time.
     */
    private const EPHEMERAL_INTERFACE = '/^(?:veth|cali|cni|flannel|tap|dummy)|^br-[0-9a-f]{12}$/';

    /** @var array<string, Metric>|null */
    private ?array $readings = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'network';
    }

    public function collect(): array
    {
        // Sampled once per instance. Every rate here is a delta against a cached previous
        // reading, so collecting twice in one request — once for the table, once for gauges() —
        // would leave the second pass with a few microseconds of window and a resolver hit it
        // has no reason to make.
        return $this->readings ??= $this->read();
    }

    public function gauges(): array
    {
        $gauges = [];

        foreach ($this->collect() as $name => $metric) {
            [$base, $label] = array_pad(explode('@', $name, 2), 2, null);
            if (!in_array($base, self::GAUGE_METRICS, true) || !$metric->isOk() || !is_numeric($metric->value)) {
                continue;
            }
            if ($label !== null && preg_match(self::EPHEMERAL_INTERFACE, $label) === 1) {
                continue;
            }

            $gauges['server.network.' . $name] = $metric;
        }

        return $gauges;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @return array<string, Metric>
     */
    private function read(): array
    {
        try {
            $sample = [
                'at' => microtime(true),
                'interfaces' => $this->environment->has('proc_netdev') ? $this->readInterfaces() : [],
                'tcp' => $this->environment->has('proc_snmp') ? $this->readTcpCounters() : [],
            ];

            $previous = $this->rotate($sample);
            $window = $this->window($sample, $previous);
        } catch (\Throwable $exception) {
            // Sockets and DNS do not depend on the sample, so they are still worth asking for.
            return array_merge(
                ['interfaces' => Metric::failed(self::NETDEV_SOURCE, $exception)],
                array_fill_keys(self::TCP_METRICS, Metric::failed(self::SNMP_SOURCE, $exception)),
                $this->socketReadings(),
                ['dns_ms' => $this->dnsLatency()],
            );
        }

        return array_merge(
            $this->interfaceReadings($sample['interfaces'], $previous['interfaces'] ?? [], $window),
            $this->tcpReadings($sample['tcp'], $previous['tcp'] ?? [], $window),
            $this->socketReadings(),
            ['dns_ms' => $this->dnsLatency()],
        );
    }

    /**
     * Store this sample and hand back the one it replaces.
     *
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>|null
     */
    private function rotate(array $sample): ?array
    {
        try {
            $previous = Cache::get(self::PREVIOUS_KEY);
            Cache::put(self::PREVIOUS_KEY, $sample, self::SAMPLE_TTL_SECONDS);
        } catch (\Throwable) {
            // A cache store that has fallen over costs the rates, not the page.
            return null;
        }

        return is_array($previous) && isset($previous['at']) ? $previous : null;
    }

    /**
     * The measurable interval, or the reason there is not one.
     *
     * The reason comes back as text rather than as a Metric so each group can attach it to its
     * own source: "no window yet" on a TCP metric must still say it came from /proc/net/snmp.
     *
     * @param  array<string, mixed>  $sample
     * @param  array<string, mixed>|null  $previous
     */
    private function window(array $sample, ?array $previous): float|string
    {
        if ($previous === null) {
            return 'Collecting the first sample; rates appear one minute after monitoring starts.';
        }

        $elapsed = $sample['at'] - (float) $previous['at'];

        return $elapsed >= self::MIN_INTERVAL_SECONDS
            ? $elapsed
            : 'The previous sample is too recent to derive a rate from.';
    }

    /**
     * @param  array<string, array<string, int>>  $current
     * @param  array<string, array<string, int>>  $previous
     * @return array<string, Metric>
     */
    private function interfaceReadings(array $current, array $previous, float|string $window): array
    {
        if (!$this->environment->has('proc_netdev')) {
            return ['interfaces' => Metric::notSupported(
                self::NETDEV_SOURCE,
                'This host does not expose /proc/net/dev, so interface throughput cannot be measured.',
            )];
        }

        if ($current === []) {
            return ['interfaces' => Metric::noData(
                self::NETDEV_SOURCE,
                'No interface other than the loopback appears in /proc/net/dev.',
            )];
        }

        try {
            $readings = [];
            foreach ($current as $interface => $counters) {
                $readings += $this->labelled($this->interfaceRates($counters, $previous[$interface] ?? null, $window), $interface);
                $readings['link_state@' . $interface] = $this->linkState($interface);
            }

            return $readings;
        } catch (\Throwable $exception) {
            return ['interfaces' => Metric::failed(self::NETDEV_SOURCE, $exception)];
        }
    }

    /**
     * @param  array<string, int>  $counters
     * @param  array<string, int>|null  $previous
     * @return array<string, Metric>
     */
    private function interfaceRates(array $counters, ?array $previous, float|string $window): array
    {
        if (is_string($window)) {
            return array_fill_keys(self::INTERFACE_METRICS, Metric::noData(self::NETDEV_SOURCE, $window));
        }

        if ($previous === null) {
            return array_fill_keys(self::INTERFACE_METRICS, Metric::noData(
                self::NETDEV_SOURCE,
                'This interface was not present at the previous sample.',
            ));
        }

        $delta = [];
        foreach ($counters as $field => $value) {
            // A previous sample written before this list of counters changed — an upgrade lands
            // while the cached sample is still warm — has nothing to subtract for the new field.
            // Standing in a zero there would publish the interface's entire since-boot total
            // divided by the window: a lifetime average wearing the costume of a current rate,
            // and a spectacular one, since that total is measured in gigabytes.
            if (!array_key_exists($field, $previous)) {
                return array_fill_keys(self::INTERFACE_METRICS, Metric::noData(
                    self::NETDEV_SOURCE,
                    "The previous sample carries no {$field} counter for this interface to subtract.",
                ));
            }

            $delta[$field] = $value - $previous[$field];
        }

        // These counters only ever climb, so a negative delta means they were reset underneath us
        // — a reboot, or the interface being torn down and re-created. There is no window left.
        if (min($delta) < 0) {
            return array_fill_keys(self::INTERFACE_METRICS, Metric::noData(
                self::NETDEV_SOURCE,
                'The kernel counters were reset since the previous sample (reboot, or the interface was re-created).',
            ));
        }

        // Errors and drops stay counts, not rates: one dropped frame a minute is a fact an
        // operator can act on, and 0.016 errors/s is the same fact made unreadable.
        $since = 'New since the previous sample, ' . round($window, 1) . ' s earlier.';

        return [
            'rx_bytes_per_s' => Metric::of(round($delta['rx_bytes'] / $window, 1), self::NETDEV_SOURCE, 'bytes/s'),
            'tx_bytes_per_s' => Metric::of(round($delta['tx_bytes'] / $window, 1), self::NETDEV_SOURCE, 'bytes/s'),
            'rx_packets_per_s' => Metric::of(round($delta['rx_packets'] / $window, 1), self::NETDEV_SOURCE, 'packets/s'),
            'tx_packets_per_s' => Metric::of(round($delta['tx_packets'] / $window, 1), self::NETDEV_SOURCE, 'packets/s'),
            'rx_errors' => Metric::of($delta['rx_errors'], self::NETDEV_SOURCE, 'errors', $since),
            'tx_errors' => Metric::of($delta['tx_errors'], self::NETDEV_SOURCE, 'errors', $since),
            // Drops on receive are usually a full socket queue rather than a bad cable, which is
            // why they climb on a busy box while errors stay at zero.
            'rx_dropped' => Metric::of($delta['rx_dropped'], self::NETDEV_SOURCE, 'packets', $since),
            'tx_dropped' => Metric::of($delta['tx_dropped'], self::NETDEV_SOURCE, 'packets', $since),
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function readInterfaces(): array
    {
        $lines = @file('/proc/net/dev', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $interfaces = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([^\s:]+):\s*(.+)$/', $line, $match) !== 1) {
                continue;
            }

            $name = $match[1];
            if ($name === 'lo') {
                continue;
            }

            $fields = array_map('intval', preg_split('/\s+/', trim($match[2])) ?: []);
            if (count($fields) < 16) {
                continue;
            }

            $interfaces[$name] = [
                'rx_bytes' => $fields[0],
                'rx_packets' => $fields[1],
                'rx_errors' => $fields[2],
                'rx_dropped' => $fields[3],
                'tx_bytes' => $fields[8],
                'tx_packets' => $fields[9],
                'tx_errors' => $fields[10],
                'tx_dropped' => $fields[11],
            ];
        }

        return $interfaces;
    }

    /**
     * Whether the kernel still considers the link up.
     *
     * A NIC that lost carrier reads as a perfectly calm interface — zero bytes, zero errors — so
     * without this the difference between "nothing to do" and "unplugged" never reaches the page.
     */
    private function linkState(string $interface): Metric
    {
        return Metric::probe(self::OPERSTATE_SOURCE, function () use ($interface) {
            $path = '/sys/class/net/' . $interface . '/operstate';
            // Suppressed like every other probe here: an open_basedir that stops short of /sys
            // makes the stat itself raise, and the answer to that is "no operstate to read", not
            // a stack trace where the link state belongs.
            if (!@is_readable($path)) {
                return Metric::notSupported(
                    self::OPERSTATE_SOURCE,
                    "This host does not expose an operstate file for {$interface}.",
                );
            }

            $state = trim((string) @file_get_contents($path));
            if ($state === '') {
                return Metric::noData(self::OPERSTATE_SOURCE, "{$interface} reports an empty operstate.");
            }

            // "unknown" is not a failure to read: drivers with no carrier detection (tun, ifb,
            // most virtio setups) report exactly that while carrying traffic normally.
            return Metric::of($state, self::OPERSTATE_SOURCE, null, $state === 'unknown'
                ? 'This driver does not report a carrier state, which is normal for a virtual interface.'
                : null);
        });
    }

    /**
     * @param  array<string, int>  $current
     * @param  array<string, int>  $previous
     * @return array<string, Metric>
     */
    private function tcpReadings(array $current, array $previous, float|string $window): array
    {
        if (!$this->environment->has('proc_snmp')) {
            return array_fill_keys(self::TCP_METRICS, Metric::notSupported(
                self::SNMP_SOURCE,
                'This host does not expose /proc/net/snmp, so TCP counters cannot be read.',
            ));
        }

        if ($current === []) {
            return array_fill_keys(self::TCP_METRICS, Metric::noData(
                self::SNMP_SOURCE,
                '/proc/net/snmp carries no Tcp section on this kernel.',
            ));
        }

        try {
            return array_merge(
                [
                    // CurrEstab is the one Tcp field that is a gauge rather than a counter: it is
                    // how many connections are ESTABLISHED right now, so it is read as it stands
                    // and never differenced.
                    'tcp_established' => array_key_exists('CurrEstab', $current)
                        ? Metric::of((int) $current['CurrEstab'], self::SNMP_SOURCE, 'connections')
                        : Metric::noData(self::SNMP_SOURCE, 'This kernel does not report Tcp: CurrEstab.'),
                ],
                $this->tcpRates($current, $previous, $window),
            );
        } catch (\Throwable $exception) {
            return array_fill_keys(self::TCP_METRICS, Metric::failed(self::SNMP_SOURCE, $exception));
        }
    }

    /**
     * @param  array<string, int>  $current
     * @param  array<string, int>  $previous
     * @return array<string, Metric>
     */
    private function tcpRates(array $current, array $previous, float|string $window): array
    {
        if (is_string($window)) {
            return array_fill_keys(self::TCP_RATE_METRICS, Metric::noData(self::SNMP_SOURCE, $window));
        }

        if ($previous === []) {
            return array_fill_keys(self::TCP_RATE_METRICS, Metric::noData(
                self::SNMP_SOURCE,
                'The previous sample holds no TCP counters to compare against.',
            ));
        }

        $delta = [];
        foreach (['RetransSegs', 'ActiveOpens', 'PassiveOpens', 'InSegs', 'OutSegs'] as $field) {
            if (!array_key_exists($field, $current) || !array_key_exists($field, $previous)) {
                return array_fill_keys(self::TCP_RATE_METRICS, Metric::noData(
                    self::SNMP_SOURCE,
                    "This kernel does not report Tcp: {$field}.",
                ));
            }

            $delta[$field] = (int) $current[$field] - (int) $previous[$field];
        }

        // The Tcp counters are 32 bit on plenty of builds and wrap without announcing it, so a
        // negative delta means the window spans a wrap or a reboot, not negative traffic.
        if (min($delta) < 0) {
            return array_fill_keys(self::TCP_RATE_METRICS, Metric::noData(
                self::SNMP_SOURCE,
                'The TCP counters wrapped or were reset since the previous sample.',
            ));
        }

        return [
            'tcp_retrans_per_s' => Metric::of(round($delta['RetransSegs'] / $window, 2), self::SNMP_SOURCE, 'segments/s'),
            'tcp_retrans_pct' => $delta['OutSegs'] > 0
                ? Metric::of(round(100 * $delta['RetransSegs'] / $delta['OutSegs'], 2), self::SNMP_SOURCE, '%')
                : Metric::noData(self::SNMP_SOURCE, 'No segments were sent during the sample window.'),
            // Active opens are connections this host dialled out (database, cache, payment APIs);
            // passive opens are the ones it accepted. Watching them apart is what separates "our
            // traffic doubled" from "we are hammering someone else's API".
            'tcp_active_opens_per_s' => Metric::of(round($delta['ActiveOpens'] / $window, 2), self::SNMP_SOURCE, 'connections/s'),
            'tcp_passive_opens_per_s' => Metric::of(round($delta['PassiveOpens'] / $window, 2), self::SNMP_SOURCE, 'connections/s'),
            'tcp_in_segments_per_s' => Metric::of(round($delta['InSegs'] / $window, 1), self::SNMP_SOURCE, 'segments/s'),
            'tcp_out_segments_per_s' => Metric::of(round($delta['OutSegs'] / $window, 1), self::SNMP_SOURCE, 'segments/s'),
        ];
    }

    /**
     * The Tcp block of /proc/net/snmp as field => value.
     *
     * @return array<string, int>
     */
    private function readTcpCounters(): array
    {
        $lines = @file('/proc/net/snmp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        // The section is written as a pair of lines: names, then the values in the same order.
        $names = null;
        foreach ($lines as $line) {
            if (!str_starts_with($line, 'Tcp:')) {
                continue;
            }

            $fields = preg_split('/\s+/', trim(substr($line, 4))) ?: [];
            if ($names === null) {
                $names = $fields;
                continue;
            }

            $width = min(count($names), count($fields));

            return array_map('intval', array_combine(
                array_slice($names, 0, $width),
                array_slice($fields, 0, $width),
            ));
        }

        return [];
    }

    /**
     * Socket table occupancy, the resource a busy host exhausts before it runs out of anything
     * else. TIME_WAIT is broken out because a wall of half-closed sockets holds ephemeral ports
     * for a minute each, and the machine starts refusing outbound connections while every other
     * number on the page still looks idle.
     *
     * @return array<string, Metric>
     */
    private function socketReadings(): array
    {
        try {
            return $this->sockets();
        } catch (\Throwable $exception) {
            return array_fill_keys(self::SOCKET_METRICS, Metric::failed(self::SOCKSTAT_SOURCE, $exception));
        }
    }

    /**
     * @return array<string, Metric>
     */
    private function sockets(): array
    {
        // open_basedir is settled before the filesystem is touched at all, because it refuses the
        // stat as loudly as the read and Laravel promotes that warning to an exception. Asking
        // second would report the shared host's php.ini as a kernel missing /proc/net/sockstat,
        // and send the operator hunting somewhere the problem has never been.
        if ($this->outsideOpenBasedir('/proc/net/sockstat')) {
            return array_fill_keys(self::SOCKET_METRICS, Metric::permissionDenied(
                self::SOCKSTAT_SOURCE,
                'open_basedir does not cover /proc, so PHP is not allowed to look at /proc/net/sockstat.',
                'Add /proc to open_basedir in php.ini: open_basedir = "' . ini_get('open_basedir') . ':/proc" — or clear the setting entirely.',
            ));
        }

        // Environment does not probe this file, and it is genuinely absent on kernels built
        // without CONFIG_PROC_FS niceties and inside some restricted container runtimes.
        if (!@is_readable('/proc/net/sockstat')) {
            return array_fill_keys(self::SOCKET_METRICS, Metric::notSupported(
                self::SOCKSTAT_SOURCE,
                'This host does not expose /proc/net/sockstat, so socket counts cannot be read.',
            ));
        }

        $contents = @file_get_contents('/proc/net/sockstat');
        if (!is_string($contents)) {
            return array_fill_keys(self::SOCKET_METRICS, Metric::permissionDenied(
                self::SOCKSTAT_SOURCE,
                'The PHP user may not read /proc/net/sockstat.',
                'Let the PHP worker read /proc/net: drop the deny rule from its AppArmor profile in /etc/apparmor.d/ (or the equivalent SELinux policy), then reload it with apparmor_parser -r.',
            ));
        }

        $values = [];
        foreach (explode("\n", $contents) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $section = rtrim((string) array_shift($fields), ':');
            for ($index = 0; $index + 1 < count($fields); $index += 2) {
                $values[$section . '.' . $fields[$index]] = (int) $fields[$index + 1];
            }
        }

        $read = fn (string $field, string $unit): Metric => array_key_exists($field, $values)
            ? Metric::of($values[$field], self::SOCKSTAT_SOURCE, $unit)
            : Metric::noData(self::SOCKSTAT_SOURCE, "This kernel does not report \"{$field}\" in /proc/net/sockstat.");

        return [
            'sockets_used' => $read('sockets.used', 'sockets'),
            'tcp_inuse' => $read('TCP.inuse', 'sockets'),
            // Orphans have no file descriptor behind them any more; past tcp_max_orphans the
            // kernel resets them outright, so a rising count is lost connections in waiting.
            'tcp_orphan' => $read('TCP.orphan', 'sockets'),
            'tcp_time_wait' => $read('TCP.tw', 'sockets'),
            'tcp_alloc' => $read('TCP.alloc', 'sockets'),
            'udp_inuse' => $read('UDP.inuse', 'sockets'),
        ];
    }

    /** Whether open_basedir is in force and leaves $path outside every allowed tree. */
    private function outsideOpenBasedir(string $path): bool
    {
        $setting = trim((string) ini_get('open_basedir'));
        if ($setting === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $setting) as $allowed) {
            $tree = rtrim(trim($allowed), '/');
            if ($tree !== '' && ($path === $tree || str_starts_with($path, $tree . '/'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * How long the app's own hostname takes to resolve.
     *
     * Neither dns_get_record() nor gethostbyname() accepts a timeout, so a resolver that has
     * stopped answering would hold the dashboard for as long as resolv.conf allows — commonly
     * 20 seconds, and PHP offers no way to cut that short from inside the call. The guard is
     * therefore a breaker: a marker is written before the lookup and cleared after it, so a
     * request that died inside the resolver leaves the marker behind and the next collection
     * skips the lookup and says why instead of walking into the same stall.
     */
    private function dnsLatency(): Metric
    {
        return Metric::probe(self::DNS_SOURCE, function () {
            $host = $this->appHost();
            if ($host instanceof Metric) {
                return $host;
            }

            if (Cache::get(self::DNS_INFLIGHT_KEY) !== null) {
                return Metric::notConfigured(
                    self::DNS_SOURCE,
                    'Give the resolver a deadline — add "options timeout:1 attempts:2" to /etc/resolv.conf — or point the host at a resolver that answers.',
                    "A previous lookup of {$host} never returned, so this one is skipped rather than stall the dashboard behind it.",
                );
            }

            Cache::put(self::DNS_INFLIGHT_KEY, $host, self::DNS_INFLIGHT_SECONDS);
            try {
                $started = microtime(true);
                $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : false;
                $elapsedMs = round((microtime(true) - $started) * 1000, 1);

                if (is_array($records) && $records !== []) {
                    return Metric::of($elapsedMs, self::DNS_SOURCE, 'ms', 'Resolved ' . $host . ' to ' . count($records) . ' record' . (count($records) === 1 ? '' : 's') . '.');
                }

                // The fallback stays inside the marker: gethostbyname() blocks on the same dead
                // resolver for the same untimeoutable stretch, so releasing the breaker before it
                // would leave the one lookup most likely to hang with nothing guarding it.
                return $this->resolverLatency($host);
            } finally {
                Cache::forget(self::DNS_INFLIGHT_KEY);
            }
        });
    }

    /**
     * dns_get_record() talks to the nameservers directly, so a host that only exists in
     * /etc/hosts or in nscd comes back empty from it while the application resolves it fine.
     * gethostbyname() goes through the same NSS path the app itself uses, which makes it the
     * honest second question: is this name resolvable at all, and how long does that take?
     */
    private function resolverLatency(string $host): Metric
    {
        $started = microtime(true);
        $address = gethostbyname($host);
        $elapsedMs = round((microtime(true) - $started) * 1000, 1);

        if ($address === $host) {
            return Metric::notConfigured(
                self::RESOLVER_SOURCE,
                "Publish an A or AAAA record for {$host}, or correct APP_URL to the name this store is actually served under.",
                "{$host} does not resolve from this server, so there is no resolution time to measure.",
            );
        }

        return Metric::of($elapsedMs, self::RESOLVER_SOURCE, 'ms', "Resolved {$host} to {$address} through the system resolver; the nameservers returned no record, so this may be /etc/hosts or a local cache rather than DNS.");
    }

    /**
     * The hostname from APP_URL, or the reason timing a lookup of it would mean nothing.
     */
    private function appHost(): string|Metric
    {
        $url = trim((string) config('app.url'));
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        if ($host === '') {
            // An APP_URL without a scheme parses as a path, which is a common .env mistake worth
            // recovering from rather than reporting as an unresolvable name.
            $host = strtolower(strtok(preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url) ?: '', '/:') ?: '');
        }

        if ($host === '') {
            return Metric::notConfigured(
                self::DNS_SOURCE,
                'Set APP_URL in .env to the address the store is served on, e.g. APP_URL=https://shop.example.com.',
                'APP_URL holds no hostname, so there is nothing to resolve.',
            );
        }

        // parse_url() hands back an IPv6 literal still wearing its brackets, and "[::1]" is
        // neither an address filter_var() will accept nor a name any resolver will answer for.
        // A colon left after the port has been stripped can only be IPv6: hostnames cannot hold
        // one, so anything with a colon is an address rather than a name that failed to resolve.
        $address = trim($host, '[]');
        if (filter_var($address, FILTER_VALIDATE_IP) !== false || str_contains($address, ':')) {
            return Metric::notConfigured(
                self::DNS_SOURCE,
                "Set APP_URL to the hostname customers use instead of the address {$address}; resolution time is only measurable for a name.",
                'APP_URL points straight at an IP address, so no name is ever resolved to reach this store.',
            );
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'localhost.localdomain') {
            return Metric::notConfigured(
                self::DNS_SOURCE,
                'Set APP_URL to the store\'s public hostname to measure what customers\' resolvers actually do.',
                "{$host} is answered from /etc/hosts without touching a nameserver, so timing it would report a number that has nothing to do with DNS.",
            );
        }

        return $host;
    }

    /**
     * @param  array<string, Metric>  $metrics
     * @return array<string, Metric>
     */
    private function labelled(array $metrics, string $label): array
    {
        $keyed = [];
        foreach ($metrics as $name => $metric) {
            $keyed[$name . '@' . $label] = $metric;
        }

        return $keyed;
    }
}
