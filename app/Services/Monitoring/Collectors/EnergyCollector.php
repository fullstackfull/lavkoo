<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Environment;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Power draw, and what it costs — only where the hardware can actually be asked.
 *
 * Energy is the metric dashboards invent most freely, because nothing on the screen distinguishes
 * a wattage read off a hardware counter from one produced by multiplying a CPU percentage by two
 * numbers somebody typed into a settings page. The cost figure derived from the second looks
 * exactly like a bill. So three rules hold here:
 *
 * 1. Measured means RAPL/powercap or a BMC and nothing else. No counters, no watts: the panel says
 *    which telemetry is missing instead of showing a plausible number.
 *
 * 2. An estimate exists, but only when an operator switches it on deliberately, and every value it
 *    produces says "Estimated" in its own note — including the money — so it can never be quoted
 *    back as a measurement.
 *
 * 3. RAPL exposes monotonic microjoule counters that wrap at a domain-specific ceiling, so watts
 *    is the difference between two readings over the time between them — never a counter divided
 *    by uptime — and the first sample after a restart reports no data instead of a number.
 *
 * What RAPL covers is also narrower than people assume: the CPU package, plus DRAM where the
 * platform meters it separately. Disks, NICs, fans and PSU losses are not in it. That makes the
 * reading a floor for the machine's wall draw rather than the wall draw, which is stated on the
 * metric instead of being quietly rounded away.
 */
class EnergyCollector implements Collector
{
    private const PREVIOUS_KEY = 'monitoring:energy:previous';

    /** The series this collector writes once a minute and reads back to accumulate Wh. */
    private const WATTS_METRIC = 'energy.watts';

    private const MEASURED = 'Measured';
    private const ESTIMATED = 'Estimated';

    private const RAPL_SOURCE = 'Linux /sys/class/powercap (Intel RAPL)';
    private const ESTIMATE_SOURCE = 'Monitoring → Settings energy estimate + Linux /proc/stat';
    private const SERIES_SOURCE = 'monitoring_series (energy.watts)';
    private const ESTIMATE_MODE_SOURCE = 'Monitoring → Settings / config/monitoring.php (energy.estimated_mode)';
    private const BAND_SOURCE = 'Monitoring → Settings / config/monitoring.php (energy.estimate_idle_watts, energy.estimate_max_watts)';
    private const PRICE_SOURCE = 'Monitoring → Settings / config/monitoring.php (energy.price_per_kwh)';
    private const CURRENCY_SOURCE = 'Monitoring → Settings / config/monitoring.php (energy.currency)';

    /**
     * A package counter wraps roughly every twenty minutes at a couple of hundred watts, and the
     * correction below can only undo one wrap. A staler previous sample is thrown away rather than
     * differenced into a figure that is silently one whole counter range short.
     */
    private const MAX_INTERVAL_SECONDS = 300;

    /** Below this, a month projected from the average draw so far is arithmetic, not a forecast. */
    private const MINIMUM_PROJECTION_MINUTES = 60;

    private const RAPL_REMEDY = 'Real power telemetry needs Intel RAPL/powercap (/sys/class/powercap, bare metal only) or a BMC reachable over IPMI. Where neither exists, an estimate from CPU load can be switched on deliberately in Monitoring → Settings → Energy, or with MONITORING_ENERGY_ESTIMATED=true in .env followed by php artisan config:clear.';

    /**
     * udev runs RUN+= programs directly, with no shell, so a rule containing intel-rapl:* would
     * chmod a path with a literal asterisk in it and change nothing. The glob has to be expanded by
     * an explicit /bin/sh -c, which is the difference between this rule working and looking like it
     * should.
     */
    private const RAPL_ACCESS_REMEDY = 'Grant read access with a udev rule in /etc/udev/rules.d/99-rapl.rules: SUBSYSTEM=="powercap", ACTION=="add", RUN+="/bin/sh -c \'chmod -R a+r /sys/class/powercap/intel-rapl*/energy_uj\'" — then run sudo udevadm control --reload and sudo udevadm trigger --subsystem-match=powercap. Running sudo chmod -R a+r /sys/class/powercap by hand fixes it now but only until the next reboot.';

    private const PRICE_REMEDY = 'Set the electricity price in Monitoring → Settings → Energy, or MONITORING_ENERGY_PRICE=0.28 with MONITORING_ENERGY_CURRENCY=EUR in .env, then run php artisan config:clear.';

    public function __construct(
        private readonly Environment $environment,
        private readonly CpuCollector $cpu,
        // The price, the currency and the estimate switch are things an operator changes while
        // looking at the panel, so they are read the way every other check reads them: the stored
        // setting first, config/monitoring.php as the floor. Reading config() directly would make
        // every "set this in Monitoring → Settings" remedy on this panel a lie.
        private readonly MonitoringSettings $settings,
    ) {
    }

    public function key(): string
    {
        return 'energy';
    }

    public function collect(): array
    {
        $basis = $this->basis();
        $today = $this->energyToday($basis);
        $month = $this->energyMonth($basis);

        return array_merge(
            ['basis' => $this->basisMetric($basis)],
            $this->power($basis),
            $today,
            $month,
            $this->cost($basis, $today, $month),
        );
    }

    public function gauges(): array
    {
        $collected = $this->collect();

        // Only the two that are readings. Cost is this series multiplied by a tariff the operator
        // typed in, so storing it would freeze today's tariff into history for good; per-domain
        // watts is detail for the panel, not a line anybody charts.
        return array_filter([
            self::WATTS_METRIC => $collected['watts'],
            'energy.wh_today' => $collected['wh_today'],
        ], fn (Metric $metric) => $metric->isOk());
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Measured, Estimated, or neither. Everything else in this collector follows from this answer.
     */
    private function basis(): ?string
    {
        if ($this->environment->has('rapl_readable')) {
            return self::MEASURED;
        }

        // Read as an explicit boolean rather than for truthiness: a leftover
        // MONITORING_ENERGY_ESTIMATED=0 or =off arrives here as a non-empty string, which PHP calls
        // true, and that must not be enough to start inventing watts. The estimate is opt-in and
        // has to be turned on in so many words.
        $enabled = filter_var($this->settings->get('energy.estimated_mode'), FILTER_VALIDATE_BOOLEAN);

        return $enabled ? self::ESTIMATED : null;
    }

    private function basisMetric(?string $basis): Metric
    {
        return match ($basis) {
            self::MEASURED => Metric::of(
                self::MEASURED,
                self::RAPL_SOURCE,
                null,
                'Watts come from this machine own energy counters.',
            ),
            self::ESTIMATED => Metric::of(
                self::ESTIMATED,
                self::ESTIMATE_MODE_SOURCE,
                null,
                'Estimated mode is on: every energy and cost figure here is modelled from CPU utilisation, not measured.',
            ),
            default => $this->noBasis(self::RAPL_SOURCE, 'There is no basis for a power figure.'),
        };
    }

    /**
     * Why this host cannot be asked for watts — and the two answers need different fixes.
     *
     * Counters that exist but cannot be read is a permission the operator can grant; no counters at
     * all is the hardware, and no amount of chmod will produce them. Telling someone whose machine
     * has RAPL that it has none sends them looking for the wrong thing entirely, so the distinction
     * is made once here and every metric on the panel inherits it.
     */
    private function noBasis(string $source, string $consequence): Metric
    {
        if ($this->environment->has('rapl')) {
            return Metric::permissionDenied(
                $source,
                'This host has RAPL energy counters but this process may not read them; energy_uj has been root-only since Linux 5.10, because at high resolution it leaks a side channel. ' . $consequence,
                self::RAPL_ACCESS_REMEDY,
            );
        }

        return Metric::notSupported(
            $source,
            'This host exposes no RAPL/powercap energy counters, which is normal on a virtual machine: the hypervisor owns the hardware and does not pass its meters through. No estimate has been switched on either. ' . $consequence,
            self::RAPL_REMEDY,
        );
    }

    /** @return array<string, Metric> */
    private function power(?string $basis): array
    {
        if ($basis === self::MEASURED) {
            return $this->measuredPower();
        }

        if ($basis === self::ESTIMATED) {
            // The estimate reads the CPU collector, which is a whole second collector's worth of
            // probes. measuredPower() guards itself; this branch has to as well, or one throw down
            // there leaves the panel by way of a collector that promised never to throw.
            try {
                return $this->estimatedPower();
            } catch (\Throwable $exception) {
                return $this->everyPowerMetric(Metric::failed(self::ESTIMATE_SOURCE, $exception));
            }
        }

        return $this->everyPowerMetric($this->basisMetric(null));
    }

    /**
     * Watts as the difference between two readings of the joule counters.
     *
     * @return array<string, Metric>
     */
    private function measuredPower(): array
    {
        try {
            $current = $this->readDomains();
            if ($current['domains'] === []) {
                return $this->everyPowerMetric(Metric::noData(self::RAPL_SOURCE, 'The powercap tree is readable but exposes no energy counter.'));
            }

            $previous = Cache::get(self::PREVIOUS_KEY);
            Cache::put(self::PREVIOUS_KEY, $current, 600);

            if (!is_array($previous) || !isset($previous['at'])) {
                return $this->everyPowerMetric(Metric::noData(
                    self::RAPL_SOURCE,
                    'Collecting the first energy counter sample; power draw appears one minute after monitoring starts.',
                ));
            }

            $elapsed = (float) $current['at'] - (float) $previous['at'];
            if ($elapsed <= 0) {
                return $this->everyPowerMetric(Metric::noData(self::RAPL_SOURCE, 'No time elapsed between energy counter samples.'));
            }
            if ($elapsed > self::MAX_INTERVAL_SECONDS) {
                return $this->everyPowerMetric(Metric::noData(
                    self::RAPL_SOURCE,
                    'The previous sample is too old to difference against; the counters may have wrapped more than once since it was taken.',
                ));
            }

            $watts = [];
            foreach ($current['domains'] as $path => $domain) {
                $before = $previous['domains'][$path] ?? null;
                $draw = $before === null ? null : $this->watts($domain, $before, $elapsed);
                if ($draw !== null) {
                    $watts[$path] = ['name' => $domain['name'], 'watts' => $draw];
                }
            }

            return $this->summarise($watts);
        } catch (\Throwable $exception) {
            return $this->everyPowerMetric(Metric::failed(self::RAPL_SOURCE, $exception));
        }
    }

    /**
     * Every energy counter this host exposes, keyed by its sysfs directory.
     *
     * The directory is the key rather than the domain name because two packages each publish a
     * "core" and a "dram" child, and keying by name would collapse socket 1 onto socket 0.
     *
     * @return array{at: float, domains: array<string, array{name: string, uj: int, max: int}>}
     */
    private function readDomains(): array
    {
        $domains = [];

        foreach ((@glob('/sys/class/powercap/intel-rapl*/energy_uj') ?: []) as $path) {
            $directory = dirname($path);
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            $name = trim((string) @file_get_contents($directory . '/name'));
            $domains[basename($directory)] = [
                'name' => $name === '' ? basename($directory) : $name,
                'uj' => (int) trim((string) $raw),
                'max' => (int) trim((string) @file_get_contents($directory . '/max_energy_range_uj')),
            ];
        }

        return ['at' => microtime(true), 'domains' => $domains];
    }

    /**
     * @param  array{name: string, uj: int, max: int}  $now
     * @param  array{name: string, uj: int, max: int}  $then
     */
    private function watts(array $now, array $then, float $elapsed): ?float
    {
        $delta = $now['uj'] - $then['uj'];

        if ($delta < 0) {
            // The counter hit its ceiling and restarted; the machine did not consume negative
            // energy. Adding one range back is right for exactly one wrap, which is why a stale
            // previous sample is refused above instead of being corrected here.
            $delta += $now['max'];
        }

        return $delta < 0 ? null : $delta / 1_000_000 / $elapsed;
    }

    /**
     * Fold the per-domain draw into the two figures that mean something, plus a total.
     *
     * @param  array<string, array{name: string, watts: float}>  $domains
     * @return array<string, Metric>
     */
    private function summarise(array $domains): array
    {
        $package = 0.0;
        $dram = 0.0;
        $hasPackage = false;
        $hasDram = false;
        $listing = [];

        foreach ($domains as $domain) {
            $listing[] = ['domain' => $domain['name'], 'watts' => round($domain['watts'], 2)];

            // Only package and dram are added. core and uncore sit inside package, and psys spans
            // the whole platform including it, so totalling everything on offer would charge the
            // same joules two or three times over.
            if (str_starts_with($domain['name'], 'package')) {
                $package += $domain['watts'];
                $hasPackage = true;
                continue;
            }
            if ($domain['name'] === 'dram') {
                $dram += $domain['watts'];
                $hasDram = true;
            }
        }

        $scope = $hasDram ? 'CPU package plus DRAM' : 'CPU package only, since this platform meters no DRAM domain';
        $floor = sprintf(
            '%s: %s. Disks, NICs, fans and PSU losses are not metered, so this is a floor for the machine wall draw, not the wall draw.',
            self::MEASURED,
            $scope,
        );

        return [
            'watts' => $hasPackage || $hasDram
                ? Metric::of(round($package + $dram, 2), self::RAPL_SOURCE, 'W', $floor)
                : Metric::noData(self::RAPL_SOURCE, 'No package or DRAM domain produced a usable delta over this interval.'),
            'package_watts' => $hasPackage
                ? Metric::of(round($package, 2), self::RAPL_SOURCE, 'W', self::MEASURED . ' at the CPU package domain.')
                : Metric::notSupported(self::RAPL_SOURCE, 'This platform exposes no RAPL package domain.'),
            'dram_watts' => $hasDram
                ? Metric::of(round($dram, 2), self::RAPL_SOURCE, 'W', self::MEASURED . ' at the DRAM domain.')
                : Metric::notSupported(self::RAPL_SOURCE, 'This platform meters no DRAM domain separately; memory draw is then part of nothing that can be read, not zero.'),
            'domains' => $listing === []
                ? Metric::noData(self::RAPL_SOURCE)
                : Metric::of($listing, self::RAPL_SOURCE, null, 'Every RAPL domain on this host. Only package and DRAM feed the total; core and uncore are inside package and psys spans both.'),
        ];
    }

    /**
     * The opt-in model: idle draw plus the configured band, scaled by how busy the CPU is.
     *
     * @return array<string, Metric>
     */
    private function estimatedPower(): array
    {
        $idle = $this->settings->get('energy.estimate_idle_watts');
        $peak = $this->settings->get('energy.estimate_max_watts');

        if (!is_numeric($idle) || !is_numeric($peak) || (float) $peak <= (float) $idle) {
            return $this->everyPowerMetric(Metric::notConfigured(
                self::BAND_SOURCE,
                'Set energy.estimate_idle_watts and energy.estimate_max_watts in Monitoring → Settings → Energy to this machine draw at idle and at full load, with the maximum above the idle figure.',
                'Estimated mode is on, but the band it interpolates across is unusable.',
            ));
        }

        // Estimated mode is off by default, so this second read of /proc/stat only ever happens on
        // hosts that asked for the estimate. Where utilisation is not available yet the estimate
        // says so: falling back to the idle figure would draw a flat, plausible line for a machine
        // nobody is measuring, which is the exact failure this whole system exists to prevent.
        $usage = $this->cpu->collect()['usage_pct'] ?? null;
        if (!$usage instanceof Metric || !$usage->isOk()) {
            return $this->everyPowerMetric(Metric::noData(
                self::ESTIMATE_SOURCE,
                'CPU utilisation is not available yet, so the estimate has nothing to interpolate with.',
            ));
        }

        $utilisation = max(0.0, min(100.0, (float) $usage->value));
        $watts = (float) $idle + ((float) $peak - (float) $idle) * $utilisation / 100;

        $perDomain = Metric::notSupported(
            self::RAPL_SOURCE,
            self::ESTIMATED . ' mode models the whole machine from CPU load; it cannot attribute draw to the CPU package or to DRAM.',
            self::RAPL_REMEDY,
        );

        return [
            'watts' => Metric::of(round($watts, 1), self::ESTIMATE_SOURCE, 'W', sprintf(
                '%s, not measured: %s%% CPU interpolated across the configured %s-%s W band. Real draw also depends on disks, memory and PSU efficiency.',
                self::ESTIMATED,
                $utilisation,
                $idle,
                $peak,
            )),
            'package_watts' => $perDomain,
            'dram_watts' => $perDomain,
            'domains' => $perDomain,
        ];
    }

    /** @return array<string, Metric> */
    private function everyPowerMetric(Metric $metric): array
    {
        return array_fill_keys(['watts', 'package_watts', 'dram_watts', 'domains'], $metric);
    }

    /** @return array<string, Metric> */
    private function energyToday(?string $basis): array
    {
        if ($basis === null) {
            return array_fill_keys(['wh_today', 'kwh_today'], $this->nothingMeasuring());
        }

        $wh = Metric::probe(self::SERIES_SOURCE, function () use ($basis) {
            $window = $this->accumulated($this->startOfLocal('day'));

            if ($window === null) {
                return Metric::noData(
                    self::SERIES_SOURCE,
                    'No watt sample has been recorded yet today. Energy is accumulated from the once-a-minute samples, so the first figure appears a minute after collection starts.',
                );
            }

            return Metric::of(round($window['wh'], 1), self::SERIES_SOURCE, 'Wh', $this->windowNote($basis, $window, 'today'));
        });

        return [
            'wh_today' => $wh,
            // Carries the same note as the Wh it divides, so the Estimated label survives the unit
            // change rather than being lost on the friendlier-looking number.
            'kwh_today' => $wh->isOk()
                ? Metric::of(round((float) $wh->value / 1000, 4), $wh->source, 'kWh', $wh->note)
                : $wh,
        ];
    }

    /** @return array<string, Metric> */
    private function energyMonth(?string $basis): array
    {
        $names = ['kwh_month_to_date', 'kwh_month_projected'];

        if ($basis === null) {
            return array_fill_keys($names, $this->nothingMeasuring());
        }

        try {
            $window = $this->accumulated($this->startOfLocal('month'));
        } catch (\Throwable $exception) {
            return array_fill_keys($names, Metric::failed(self::SERIES_SOURCE, $exception));
        }

        if ($window === null) {
            return array_fill_keys($names, Metric::noData(self::SERIES_SOURCE, 'No watt sample has been recorded yet this month.'));
        }

        return [
            'kwh_month_to_date' => Metric::of(
                round($window['wh'] / 1000, 3),
                self::SERIES_SOURCE,
                'kWh',
                $this->windowNote($basis, $window, 'so far this month'),
            ),
            'kwh_month_projected' => $this->projectedMonth($basis, $window),
        ];
    }

    /**
     * @param  array{wh: float, minutes: int, elapsed: int}  $window
     */
    private function projectedMonth(string $basis, array $window): Metric
    {
        if ($window['minutes'] < self::MINIMUM_PROJECTION_MINUTES) {
            return Metric::noData(self::SERIES_SOURCE, sprintf(
                'A month is projected from the average draw recorded so far; that needs at least %d minutes of samples and there are %d.',
                self::MINIMUM_PROJECTION_MINUTES,
                $window['minutes'],
            ));
        }

        // Projected from the average watts actually recorded rather than by scaling up the running
        // total: a total with collector downtime in it would project the downtime too, and quietly
        // forecast a cheaper month every time monitoring itself broke.
        $averageWatts = $window['wh'] * 60 / $window['minutes'];
        $hours = 24 * $this->daysInLocalMonth();

        return Metric::of(round($averageWatts * $hours / 1000, 2), self::SERIES_SOURCE, 'kWh', sprintf(
            '%s: the whole month at the %s W average recorded over %d minutes so far. Not a forecast of load, only of the same load continuing.',
            $basis,
            round($averageWatts, 1),
            $window['minutes'],
        ));
    }

    /** @return array<string, Metric> */
    private function cost(?string $basis, array $today, array $month): array
    {
        $price = $this->settings->get('energy.price_per_kwh');
        $currency = trim((string) $this->settings->get('energy.currency'));

        $currencyMetric = $currency !== ''
            ? Metric::of($currency, self::CURRENCY_SOURCE)
            : Metric::notConfigured(
                self::CURRENCY_SOURCE,
                'Set the currency the price is entered in under Monitoring → Settings → Energy, or MONITORING_ENERGY_CURRENCY=KWD in .env followed by php artisan config:clear.',
                'No currency is set, so a cost can only be shown as a bare number.',
            );

        if (!is_numeric($price) || (float) $price <= 0) {
            // A tariff is not something to default: it differs by country, contract and time of
            // day, and a wrong one produces a confident bill nobody can reconcile.
            $unpriced = Metric::notConfigured(
                self::PRICE_SOURCE,
                self::PRICE_REMEDY,
                'No electricity price is set, and one is never assumed.',
            );

            return [
                'price_per_kwh' => $unpriced,
                'currency' => $currencyMetric,
                'cost_today' => $unpriced,
                'cost_month_to_date' => $unpriced,
                'cost_month_projected' => $unpriced,
            ];
        }

        $rate = (float) $price;
        $missingCurrency = $currency === '' ? ' The currency is unset, so this is a bare number in whatever currency the price was entered in.' : '';
        $note = fn (string $period): string => sprintf(
            '%s energy %s at %s per kWh.%s',
            (string) $basis,
            $period,
            $currency === '' ? $rate : $rate . ' ' . $currency,
            $missingCurrency,
        );

        return [
            'price_per_kwh' => Metric::of($rate, self::PRICE_SOURCE, $currency === '' ? 'per kWh' : $currency . '/kWh'),
            'currency' => $currencyMetric,
            'cost_today' => $this->costOf($today['kwh_today'], $rate, $currency, $note('today')),
            'cost_month_to_date' => $this->costOf($month['kwh_month_to_date'], $rate, $currency, $note('so far this month')),
            'cost_month_projected' => $this->costOf($month['kwh_month_projected'], $rate, $currency, $note('projected for the full month')),
        ];
    }

    /**
     * Cost inherits the energy figure's state: where there is no energy reading there is no cost,
     * and the reason shown is the reason the energy was missing rather than a second one invented
     * for the money.
     */
    private function costOf(Metric $energy, float $rate, string $currency, string $note): Metric
    {
        if (!$energy->isOk()) {
            return $energy;
        }

        $cost = (float) $energy->value * $rate;

        // A few hours of a small server at a low tariff is a fraction of one unit of currency, and
        // two decimals prints that as 0.00 — the one number this panel must never show for
        // something it did measure. Amounts under a unit keep the digits that make them non-zero.
        return Metric::of(
            round($cost, abs($cost) < 1 ? 4 : 2),
            $energy->source . ' x ' . self::PRICE_SOURCE,
            $currency === '' ? null : $currency,
            $note,
        );
    }

    /**
     * Energy recorded in the series between a moment and now.
     *
     * Minute buckets are kept for a week and hour buckets for months, so a window reaching further
     * back than the minute retention is read as hour buckets up to that boundary and minute buckets
     * after it — never both across the same stretch of time, which would count the same joules
     * twice. Gaps are left as gaps: a window that was only half recorded reports the half it has
     * and says so.
     *
     * @return array{wh: float, minutes: int, elapsed: int}|null
     */
    private function accumulated(Carbon $from): ?array
    {
        $now = Clock::now();
        if ($from >= $now) {
            return null;
        }

        // The same number the pruner uses. Both read the settings service, so an operator who
        // widens the minute window on the Settings page widens it here too.
        $minuteFloor = $now->copy()->subDays(max(1, app(MonitoringSettings::class)->retentionDays('minute_days', 7)));

        // The seam between the two resolutions has to land on an hour edge. An hour bucket is only
        // ever whole, so cutting mid-hour counts the minutes between the seam and the end of that
        // hour twice — once inside the hour bucket, once as the minute buckets it was rolled up
        // from — and quietly inflates every month total by up to an hour of draw.
        $boundary = $from->greaterThan($minuteFloor) ? $from->copy() : $minuteFloor->copy()->startOfHour()->addHour();

        $coarse = $boundary->greaterThan($from) ? $this->sumSeries('hour', $from, $boundary) : ['wh' => 0.0, 'minutes' => 0];
        $fine = $this->sumSeries('minute', $boundary, $now);

        $minutes = $coarse['minutes'] + $fine['minutes'];
        if ($minutes === 0) {
            return null;
        }

        return [
            'wh' => $coarse['wh'] + $fine['wh'],
            'minutes' => $minutes,
            'elapsed' => max(1, (int) abs($from->diffInMinutes($now))),
        ];
    }

    /**
     * Energy and coverage from one resolution of the series.
     *
     * A bucket holds the sum of its samples, so the average watts in it is the only figure that may
     * be multiplied by time to get energy — and the time is how long the bucket was actually
     * sampled for, not how long it spans. One gauge sample is written per minute, which makes
     * `samples` the count of minutes the bucket really covers: an hour bucket rolled up from ten
     * minutes of collection holds ten minutes of energy, and charging it for a full hour would turn
     * every gap in collection into consumption. Capping at the bucket length stops a bucket sampled
     * more often than once a minute from claiming more time than it spans.
     *
     * A bucket with no samples therefore contributes neither energy nor coverage, which is what
     * keeps an empty or half-written window reporting no_data instead of a confident 0 Wh.
     *
     * @return array{wh: float, minutes: int}
     */
    private function sumSeries(string $resolution, Carbon $from, Carbon $until): array
    {
        $bucketMinutes = $resolution === 'hour' ? 60 : 1;
        $covered = "CASE WHEN samples > {$bucketMinutes} THEN {$bucketMinutes} ELSE samples END";

        $row = $this->monitoring()->table('monitoring_series')
            ->where('metric', self::WATTS_METRIC)
            ->where('resolution', $resolution)
            // The watts gauge is published without a dimension, so its rows all carry the empty
            // label. Summing across labels would add some future per-domain series to the machine.
            ->where('label', '')
            ->where('bucket_at', '>=', Clock::stamp($from))
            ->where('bucket_at', '<', Clock::stamp($until))
            ->selectRaw("SUM(value_sum / NULLIF(samples, 0) * ({$covered})) AS watt_minutes, SUM({$covered}) AS covered_minutes")
            ->first();

        $minutes = (int) ($row->covered_minutes ?? 0);
        if ($minutes === 0 || ($row->watt_minutes ?? null) === null) {
            return ['wh' => 0.0, 'minutes' => 0];
        }

        return ['wh' => (float) $row->watt_minutes / 60, 'minutes' => $minutes];
    }

    /**
     * @param  array{wh: float, minutes: int, elapsed: int}  $window
     */
    private function windowNote(string $basis, array $window, string $period): string
    {
        $note = sprintf('%s draw accumulated from the per-minute samples recorded %s.', $basis, $period);

        if ($window['minutes'] < $window['elapsed']) {
            $note .= sprintf(
                ' %d of %d minutes have samples; the rest is left out rather than filled in.',
                $window['minutes'],
                $window['elapsed'],
            );
        }

        return $note;
    }

    /**
     * The start of the operator's local day or month, expressed in UTC.
     *
     * "What did today cost" is a calendar question about the merchant's day, while every bucket in
     * the series is stored in UTC. The conversion happens once, here, in that direction only.
     */
    private function startOfLocal(string $unit): Carbon
    {
        $local = Clock::now()->setTimezone(Clock::displayTimezone());

        return ($unit === 'month' ? $local->startOfMonth() : $local->startOfDay())->setTimezone(Clock::TIMEZONE);
    }

    /** Counted in the local month, since the UTC instant it starts at can fall in the previous one. */
    private function daysInLocalMonth(): int
    {
        return Clock::now()->setTimezone(Clock::displayTimezone())->daysInMonth;
    }

    private function nothingMeasuring(): Metric
    {
        return $this->noBasis(self::SERIES_SOURCE, 'Nothing is measuring power here, so no energy is accumulating to report.');
    }

    private function monitoring(): Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }
}
