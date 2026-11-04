<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What this machine draws, what that costs, and what its sensors say about the metal.
 *
 * Energy is the section a dashboard is most tempted to invent, because nothing on a screen
 * separates a wattage read off a hardware counter from one produced by multiplying a CPU
 * percentage by two numbers somebody typed into a settings page — and the money derived from the
 * second looks exactly like a bill. So the BASIS leads this page: Measured, Estimated, or neither,
 * stated before any number, and stamped onto every figure that descends from it. A reader who sees
 * a kilowatt hour here can always say which of those three produced it.
 *
 * The absent case is the normal case and is written as an answer rather than a gap. A virtual
 * machine or a container has no RAPL — the hypervisor owns the meters and does not pass them
 * through — and no hypervisor forwards the host's thermal zones, fan tachometers or SMART data to
 * a guest either. On such a host almost every block here is legitimately not_supported, so each
 * one names what a bare-metal host would need instead of drawing a grey row that reads as a fault,
 * and the twenty sensor readings that are all missing for the one same reason are collapsed into
 * that reason, stated once.
 *
 * Cost is the one figure that is never allowed a fallback. A tariff differs by country, contract
 * and time of day, so an unset price is not zero and is not a default: it is not_configured with
 * the line to paste, because a zero here puts free electricity on the page of somebody who is
 * paying for it.
 *
 * Nothing on this page is measured here. Power and sensors are read from the two collectors, and
 * the energy accumulated over the selected window is summed out of the once-a-minute samples the
 * flush already stored.
 */
class EnergyPanel implements Panel
{
    /** The two collectors this section is made of. Each is read exactly once per request. */
    private const COLLECTORS = ['energy', 'hardware'];

    /**
     * Why a collector that answered nothing at all answered nothing.
     *
     * "Not installed in this build" and "installed and unable to read" are different sentences for
     * different people, and an empty card cannot tell them apart on its own.
     */
    private const COLLECTOR_ABSENT = [
        'energy' => 'The energy collector is not installed in this build, so power draw, energy and cost cannot be read here.',
        'hardware' => 'The hardware collector is not installed in this build, so temperatures, fans, voltages, drive health and ECC counters cannot be read here.',
    ];

    /**
     * The two bases the energy collector publishes, as an allowlist.
     *
     * The value decides how every other number on this page must be read, so it is checked against
     * the vocabulary rather than trusted: an unrecognised string would otherwise become a label
     * this page repeats as if it meant something.
     */
    private const MEASURED = 'Measured';

    private const ESTIMATED = 'Estimated';

    /** The gauge the window accumulation is summed from, and where it is stored. */
    private const WATTS_METRIC = 'energy.watts';

    private const SERIES_SOURCE = 'monitoring_series (energy.watts)';

    /** One gauge sample is written per minute, which is what makes `samples` a count of minutes. */
    private const BUCKET_MINUTES = ['minute' => 1, 'hour' => 60, 'day' => 1440];

    /** Ceilings on lists that come out of a collector, so one strange host cannot bloat the page. */
    private const MAX_DOMAIN_ROWS = 32;

    private const MAX_SENSOR_ROWS = 64;

    private const MAX_DRIVE_ROWS = 16;

    /** Draw readings, in the order they are drawn. `label => metric name`. */
    private const POWER_METRICS = [
        'power_draw' => 'watts',
        'cpu_package_draw' => 'package_watts',
        'memory_draw' => 'dram_watts',
    ];

    /** Energy accumulated by the collector against the operator's own calendar. */
    private const ENERGY_METRICS = [
        'energy_today' => 'kwh_today',
        'energy_today_in_watt_hours' => 'wh_today',
        'energy_so_far_this_month' => 'kwh_month_to_date',
        'projected_for_the_full_month' => 'kwh_month_projected',
    ];

    /** What a kilowatt hour costs here — two settings, not two measurements. */
    private const TARIFF_METRICS = [
        'electricity_price' => 'price_per_kwh',
        'currency' => 'currency',
    ];

    /** The money. Every one of these is unavailable for the same reason when no price is set. */
    private const COST_METRICS = [
        'cost_today' => 'cost_today',
        'cost_so_far_this_month' => 'cost_month_to_date',
        'projected_cost_for_the_full_month' => 'cost_month_projected',
    ];

    /** The single-value sensor readings, drawn as cards above the sensor tables. */
    private const SENSOR_METRICS = [
        'cpu_temperature' => 'cpu_temp_c',
        'board_temperature' => 'board_temp_c',
        'hottest_drive' => 'max_disk_temp_c',
        'fastest_fan' => 'fan_rpm_max',
    ];

    /** Zero here is the metric's whole point, which is why an absent controller must not borrow it. */
    private const ECC_METRICS = [
        'correctable_ecc_errors' => 'ecc_correctable_errors',
        'uncorrectable_ecc_errors' => 'ecc_uncorrectable_errors',
    ];

    /** Every hardware reading, in the order the "nothing is readable here" summary lists them. */
    private const HARDWARE_READINGS = [
        'cpu_temp_c', 'board_temp_c', 'temperatures', 'max_disk_temp_c', 'fan_rpm_max', 'fans',
        'voltages', 'disk_health', 'ecc_correctable_errors', 'ecc_uncorrectable_errors', 'ipmi_sensors',
    ];

    /**
     * The stored gauges this section charts.
     *
     * `collector` and `source` name the live reading each one is written from, which is what lets
     * an empty chart say WHY it is empty: the sampler stores a reading only while it is OK, so a
     * flat gauge on a host with no counters is a missing reading rather than a stopped scheduler.
     *
     * @var array<string, array{metric: string, unit: string, title: string, collector: string, source: string}>
     */
    private const GAUGES = [
        'power_draw' => [
            'metric' => self::WATTS_METRIC,
            'unit' => 'W',
            'title' => 'power_draw_over_time',
            'collector' => 'energy',
            'source' => 'watts',
        ],
        'energy_today_in_watt_hours' => [
            'metric' => 'energy.wh_today',
            'unit' => 'Wh',
            'title' => 'energy_accumulated_today_over_time',
            'collector' => 'energy',
            'source' => 'wh_today',
        ],
        'cpu_temperature' => [
            'metric' => 'hardware.cpu_temp_c',
            'unit' => '°C',
            'title' => 'cpu_temperature_over_time',
            'collector' => 'hardware',
            'source' => 'cpu_temp_c',
        ],
        'hottest_drive' => [
            'metric' => 'hardware.max_disk_temp_c',
            'unit' => '°C',
            'title' => 'drive_temperature_over_time',
            'collector' => 'hardware',
            'source' => 'max_disk_temp_c',
        ],
        'fastest_fan' => [
            'metric' => 'hardware.fan_rpm_max',
            'unit' => 'RPM',
            'title' => 'fan_speed_over_time',
            'collector' => 'hardware',
            'source' => 'fan_rpm_max',
        ],
    ];

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
        // The estimate band is what an Estimated watt is interpolated across, and it is edited on
        // the Settings page while somebody is looking at this one. Read the way every other check
        // reads it — the stored setting first, config/monitoring.php as the floor — or the
        // collector's own "set this in Monitoring → Settings" remedy would point at a number this
        // page then contradicts.
        private readonly MonitoringSettings $settings,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);

        // Collected once each and passed down. The energy collector's watts is the difference
        // between two readings of a monotonic counter, so a second call inside the same request
        // would difference its own first call and report a machine drawing nothing at all.
        $readings = [];
        foreach (self::COLLECTORS as $collector) {
            $readings[$collector] = $this->collectors->collect($collector);
        }

        $basis = $this->basis($readings['energy']);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'collectors' => $this->collectorFaults($readings),
            'basis' => $basis,
            'power' => $this->power($readings['energy'], $basis),
            'domains' => $this->domains($readings['energy']),
            'window_energy' => $this->windowEnergy($range, $window, $basis, $readings['energy']),
            'energy' => array_merge(
                $this->grouped($readings['energy'], self::ENERGY_METRICS, self::COLLECTOR_ABSENT['energy']),
                ['estimated' => $basis['estimated'], 'basis' => $basis['value']],
            ),
            'tariff' => $this->grouped($readings['energy'], self::TARIFF_METRICS, self::COLLECTOR_ABSENT['energy']),
            'cost' => array_merge(
                $this->grouped($readings['energy'], self::COST_METRICS, self::COLLECTOR_ABSENT['energy']),
                ['estimated' => $basis['estimated'], 'basis' => $basis['value']],
            ),
            'gauges' => $this->gauges($range, $window['resolution'], $readings),
            'hardware' => $this->hardware($readings['hardware']),
            'sensors' => $this->grouped($readings['hardware'], self::SENSOR_METRICS, self::COLLECTOR_ABSENT['hardware']),
            'temperatures' => $this->sensorRows($readings['hardware'], 'temperatures', ['label', 'chip', 'kind'], ['celsius']),
            'fans' => $this->sensorRows($readings['hardware'], 'fans', ['label', 'chip'], ['rpm']),
            'voltages' => $this->sensorRows($readings['hardware'], 'voltages', ['label', 'chip'], ['volts']),
            'drives' => $this->drives($readings['hardware']),
            'ecc' => $this->grouped($readings['hardware'], self::ECC_METRICS, self::COLLECTOR_ABSENT['hardware']),
            'ipmi' => $this->sensorRows($readings['hardware'], 'ipmi_sensors', ['sensor', 'status'], ['celsius']),
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // A collector that could not answer at all, said once

    /**
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<int, array{collector: string, state: string, note: string|null}>
     */
    private function collectorFaults(array $readings): array
    {
        $faults = [];

        foreach ($readings as $collector => $collected) {
            if ($collected === []) {
                $faults[] = [
                    'collector' => $collector,
                    'state' => 'not_supported',
                    'note' => self::COLLECTOR_ABSENT[$collector] ?? null,
                ];

                continue;
            }

            // The registry's own marker for a collector that threw. Reported here rather than as a
            // reading, because it is not one.
            $failure = $collected['__collector'] ?? null;
            if ($failure instanceof Metric) {
                $faults[] = ['collector' => $collector, 'state' => 'failed', 'note' => $failure->note];
            }
        }

        return $faults;
    }

    // -------------------------------------------------------------------------------------------
    // The fact every other number on this page descends from

    /**
     * Measured, Estimated, or neither — and the band an estimate is interpolated across.
     *
     * Three-valued, and the third value is not a failure: a host with no energy counters and no
     * estimate switched on has given a complete answer about itself. Published as booleans beside
     * the collector's own string so the page can state which it is in the reader's language without
     * ever putting a stored value through translate().
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function basis(array $readings): array
    {
        $metric = $readings['basis'] ?? null;

        if (!$metric instanceof Metric) {
            return [
                'state' => 'not_supported',
                'value' => null,
                'measured' => false,
                'estimated' => false,
                'note' => self::COLLECTOR_ABSENT['energy'],
                'remedy' => null,
                'source' => null,
                'metric' => null,
                'band' => $this->band(false),
            ];
        }

        $value = $metric->isOk() && is_string($metric->value) && in_array($metric->value, [self::MEASURED, self::ESTIMATED], true)
            ? $metric->value
            : null;

        return [
            'state' => $metric->state,
            'value' => $value,
            'measured' => $value === self::MEASURED,
            'estimated' => $value === self::ESTIMATED,
            'note' => $metric->note,
            'remedy' => $metric->remedy,
            'source' => $metric->source,
            'metric' => $metric,
            'band' => $this->band($value === self::ESTIMATED),
        ];
    }

    /**
     * The idle-to-full-load band an Estimated watt is interpolated across.
     *
     * Drawn only in estimated mode, and drawn at all because it is the whole derivation: an
     * estimate whose inputs are not on the page is indistinguishable from a measurement.
     *
     * @return array<string, mixed>
     */
    private function band(bool $estimated): array
    {
        $idle = $this->numberOrNull($this->settings->get('energy.estimate_idle_watts'));
        $peak = $this->numberOrNull($this->settings->get('energy.estimate_max_watts'));
        $usable = $idle !== null && $peak !== null && $peak > $idle;

        return [
            'state' => $usable ? 'ok' : 'not_configured',
            // The band is only part of an answer in estimated mode. On a machine with real counters
            // it is two settings nobody is using, and drawing it there would suggest the measured
            // watts came out of it.
            'relevant' => $estimated,
            'idle_watts' => $idle,
            'max_watts' => $peak,
            'source' => 'Monitoring → Settings / config/monitoring.php (energy.estimate_idle_watts, energy.estimate_max_watts)',
            'note' => $usable
                ? null
                : 'The idle and full-load watt figures are missing or the maximum is not above the idle figure, so there is no band to interpolate across.',
            'remedy' => $usable
                ? null
                : 'Set energy.estimate_idle_watts and energy.estimate_max_watts in Monitoring → Settings → Energy to this machine draw at idle and at full load, with the maximum above the idle figure.',
        ];
    }

    /**
     * Draw right now, with the basis it was produced under travelling beside it.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<string, mixed>  $basis
     * @return array<string, mixed>
     */
    private function power(array $readings, array $basis): array
    {
        return array_merge(
            $this->grouped($readings, self::POWER_METRICS, self::COLLECTOR_ABSENT['energy']),
            ['estimated' => $basis['estimated'], 'basis' => $basis['value']],
        );
    }

    /**
     * Every RAPL domain this host exposes, as a table.
     *
     * Kept out of the cards because the list overlaps itself: core and uncore sit inside package
     * and psys spans the whole platform, so the rows add up to more than the machine draws and the
     * page has to say which of them the total is made of.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function domains(array $readings): array
    {
        $metric = $readings['domains'] ?? null;

        if (!$metric instanceof Metric) {
            return [
                'state' => 'not_supported',
                'note' => self::COLLECTOR_ABSENT['energy'],
                'remedy' => null,
                'source' => null,
                'rows' => [],
                'truncated' => false,
            ];
        }

        if (!$metric->isOk() || !is_array($metric->value)) {
            return [
                'state' => $metric->isOk() ? 'no_data' : $metric->state,
                'note' => $metric->note,
                'remedy' => $metric->remedy,
                'source' => $metric->source,
                'rows' => [],
                'truncated' => false,
            ];
        }

        $rows = [];
        foreach ($metric->value as $entry) {
            $entry = (array) $entry;
            $name = $this->text($entry['domain'] ?? null);
            if ($name === null) {
                continue;
            }

            $rows[] = ['domain' => $name, 'watts' => $this->numberOrNull($entry['watts'] ?? null)];
        }

        $truncated = count($rows) > self::MAX_DOMAIN_ROWS;

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === []
                ? 'The domain listing carried no readable domain rows.'
                : $metric->note,
            'remedy' => $metric->remedy,
            'source' => $metric->source,
            'rows' => array_slice($rows, 0, self::MAX_DOMAIN_ROWS),
            'truncated' => $truncated,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Energy over the selected window

    /**
     * Kilowatt hours across the range this page is set to, and what they cost.
     *
     * The collector accumulates against the operator's calendar — today, this month — which is the
     * right frame for a bill and the wrong one for a dashboard whose range control says six hours.
     * This is the same arithmetic over the selected window instead: the stored per-minute samples,
     * each bucket credited only with the time it was actually sampled for, so a gap in collection
     * stays a gap rather than becoming consumption.
     *
     * @param  array<string, mixed>  $window
     * @param  array<string, mixed>  $basis
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function windowEnergy(string $range, array $window, array $basis, array $readings): array
    {
        $price = $readings['price_per_kwh'] ?? null;
        $currency = $readings['currency'] ?? null;

        $block = [
            'state' => 'no_data',
            'note' => null,
            'remedy' => null,
            'source' => self::SERIES_SOURCE,
            'basis' => $basis['value'],
            'estimated' => $basis['estimated'],
            'kwh' => null,
            'wh' => null,
            'average_watts' => null,
            // Null rather than zero: nothing has been counted until the read below succeeds, and a
            // zero in a coverage column is the difference between "the window is empty" and "the
            // window was never read".
            'covered_minutes' => null,
            'window_minutes' => (int) $window['minutes'],
            'resolution' => $window['resolution'],
            'cost' => $this->windowCost(null, $price, $currency),
        ];

        if ($basis['value'] === null) {
            // Nothing is measuring, so nothing has been stored. The reason is the basis reason
            // rather than a second one invented for the series.
            return array_merge($block, [
                'state' => $basis['state'] === 'ok' ? 'no_data' : $basis['state'],
                'note' => $basis['note'],
                'remedy' => $basis['remedy'],
            ]);
        }

        try {
            $accumulated = $this->accumulate($range, (string) $window['resolution']);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing this one sum blanks a card,
            // while letting it escape would blank the live power draw that was read perfectly well.
            return array_merge($block, [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
            ]);
        }

        if ($accumulated['minutes'] <= 0) {
            return array_merge($block, [
                'state' => 'no_data',
                'note' => 'No watt sample is stored in this window. Energy is accumulated from the once-a-minute gauge, so the first figure appears a minute after collection starts.',
                'remedy' => 'The sample is written by `php artisan monitoring:flush`, scheduled every minute: check the scheduler is running with `php artisan schedule:list`.',
                'covered_minutes' => 0,
            ]);
        }

        $wh = $accumulated['wh'];
        $kwh = $wh / 1000;

        return array_merge($block, [
            'state' => 'ok',
            'note' => $accumulated['minutes'] < (int) $window['minutes']
                ? 'Only part of this window has samples; the minutes without one are left out rather than filled in.'
                : null,
            'kwh' => round($kwh, 4),
            'wh' => round($wh, 2),
            'average_watts' => round($wh * 60 / max(1, $accumulated['minutes']), 2),
            'covered_minutes' => $accumulated['minutes'],
            'cost' => $this->windowCost($kwh, $price, $currency),
        ]);
    }

    /**
     * What this window's energy costs, or why there is no such figure.
     *
     * A tariff is never defaulted and never assumed. An unset price makes this not_configured with
     * the collector's own remedy — the alternative is a confident zero, which is a claim that the
     * electricity was free.
     *
     * @return array<string, mixed>
     */
    private function windowCost(?float $kwh, mixed $price, mixed $currency): array
    {
        $currencyCode = $currency instanceof Metric && $currency->isOk() && is_scalar($currency->value)
            ? $this->text($currency->value, 12)
            : null;

        if (!$price instanceof Metric) {
            return [
                'state' => 'not_supported',
                'note' => self::COLLECTOR_ABSENT['energy'],
                'remedy' => null,
                'source' => null,
                'amount' => null,
                'currency' => $currencyCode,
                'price_per_kwh' => null,
            ];
        }

        if (!$price->isOk()) {
            // The price's own state and remedy ARE the answer: it is the operator's to set, and
            // saying so is worth more than any number this panel could put in its place.
            return [
                'state' => $price->state,
                'note' => $price->note,
                'remedy' => $price->remedy,
                'source' => $price->source,
                'amount' => null,
                'currency' => $currencyCode,
                'price_per_kwh' => null,
            ];
        }

        $rate = $this->numberOrNull($price->value);

        if ($rate === null || $kwh === null) {
            return [
                'state' => 'no_data',
                'note' => $kwh === null
                    ? 'There is no energy figure for this window to price.'
                    : 'The stored electricity price is not a number.',
                'remedy' => null,
                'source' => $price->source,
                'amount' => null,
                'currency' => $currencyCode,
                'price_per_kwh' => $rate,
            ];
        }

        $cost = $kwh * $rate;

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => self::SERIES_SOURCE . ' x ' . $price->source,
            // A few hours of a small server at a low tariff is a fraction of one unit of currency,
            // and two decimals prints that as 0.00 — the one number this panel must never show for
            // something it did measure.
            'amount' => round($cost, abs($cost) < 1 ? 4 : 2),
            'currency' => $currencyCode,
            'price_per_kwh' => $rate,
        ];
    }

    /**
     * Watt hours stored across the window, and how many minutes of it were actually sampled.
     *
     * Coarse buckets up to the newest folded parent, minute buckets from it onward. The rollup
     * folds the parent that is still in progress, so treating that bucket as complete would lose
     * every minute since the last fold — up to an hour of draw missing from a day-long window,
     * with the headline and the chart under it disagreeing about the same machine.
     *
     * @return array{wh: float, minutes: int, buckets: int}
     */
    private function accumulate(string $range, string $resolution): array
    {
        $from = $this->reader->since($range);
        $until = Clock::now();

        if ($resolution === 'minute') {
            return $this->sumSeries('minute', $from, $until);
        }

        $seam = $this->foldSeam($resolution, $from);
        $coarse = $seam->greaterThan($from)
            ? $this->sumSeries($resolution, $from, $seam)
            : ['wh' => 0.0, 'minutes' => 0, 'buckets' => 0];
        $fine = $this->sumSeries('minute', $seam->greaterThan($from) ? $seam : $from, $until);

        return [
            'wh' => $coarse['wh'] + $fine['wh'],
            'minutes' => $coarse['minutes'] + $fine['minutes'],
            'buckets' => $coarse['buckets'] + $fine['buckets'],
        ];
    }

    /**
     * Where the folded buckets stop and the raw minutes take over: the newest folded parent's own
     * start. It may be partial, so it is read from minutes rather than trusted.
     */
    private function foldSeam(string $resolution, Carbon $from): Carbon
    {
        $newest = $this->reader->connection()->table('monitoring_series')
            ->where('metric', self::WATTS_METRIC)
            ->where('resolution', $resolution)
            ->where('bucket_at', '>=', Clock::stamp($from))
            ->max('bucket_at');

        return $newest !== null ? Clock::parse($newest) : $from;
    }

    /**
     * Energy and coverage from one resolution of the series.
     *
     * A bucket holds the sum of its samples, so the average watts in it is the only figure that may
     * be multiplied by time — and the time is how long the bucket was actually sampled for, not how
     * long it spans. One sample is written per minute, which makes `samples` the count of minutes
     * the bucket really covers; charging an hour bucket built from ten minutes of collection for a
     * full hour would turn every gap in collection into consumption. Capping at the bucket length
     * stops a bucket sampled more often than once a minute claiming more time than it spans.
     *
     * @return array{wh: float, minutes: int, buckets: int}
     */
    private function sumSeries(string $resolution, Carbon $from, Carbon $until): array
    {
        $empty = ['wh' => 0.0, 'minutes' => 0, 'buckets' => 0];

        if (!$from->lessThan($until)) {
            return $empty;
        }

        // From this class's own constant, never from input: it is interpolated into SQL.
        $bucketMinutes = (int) (self::BUCKET_MINUTES[$resolution] ?? 1);
        $covered = "CASE WHEN samples > {$bucketMinutes} THEN {$bucketMinutes} ELSE samples END";

        $connection = $this->reader->connection();
        $row = $connection->table('monitoring_series')
            ->where('metric', self::WATTS_METRIC)
            ->where('resolution', $resolution)
            // The watts gauge is published without a dimension, so its rows all carry the empty
            // label. Summing across labels would add some future per-domain series to the machine.
            ->where('label', '')
            ->where('bucket_at', '>=', Clock::stamp($from))
            ->where('bucket_at', '<', Clock::stamp($until))
            ->selectRaw("SUM(value_sum / NULLIF(samples, 0) * ({$covered})) AS watt_minutes, SUM({$covered}) AS covered_minutes, COUNT(*) AS buckets")
            ->first();

        $minutes = (int) ($row->covered_minutes ?? 0);

        if ($minutes <= 0 || ($row->watt_minutes ?? null) === null) {
            return $empty;
        }

        return [
            'wh' => (float) $row->watt_minutes / 60,
            'minutes' => $minutes,
            'buckets' => (int) ($row->buckets ?? 0),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The same readings over the window

    /**
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<string, array<string, mixed>>
     */
    private function gauges(string $range, string $resolution, array $readings): array
    {
        $gauges = [];

        foreach (self::GAUGES as $key => $definition) {
            $live = $readings[$definition['collector']][$definition['source']] ?? null;

            $series = $this->reader->series($definition['metric'], $range, label: '');

            if ($series['state'] !== 'ok') {
                // Answered here rather than in gaugeGap, which explains only the silences of a read
                // that happened: a store nothing could reach would otherwise be blamed on the host,
                // the range or an empty window. PanelRegistry sees the same failure, but it can only
                // blank the whole section — failing one gauge by name leaves every card above it
                // readable.
                $gauges[$key] = array_merge($definition, [
                    'key' => $key,
                    'state' => $series['state'],
                    'note' => $series['note'],
                    'remedy' => null,
                    // Null, not zero: a read that failed did not find nothing, it did not look.
                    'latest' => null,
                    'samples' => null,
                    'points' => [],
                ]);

                continue;
            }

            $points = array_values(array_filter(
                $series['points'],
                static fn (array $point) => ($point['v'] ?? null) !== null,
            ));

            $gauge = array_merge($definition, [
                'key' => $key,
                'latest' => $series['latest'],
                // The window's own sample count, not the number of points it drew: one bucket holds
                // every sample taken inside it, so counting points understates a rolled-up range.
                'samples' => $series['samples'],
                'points' => $points,
            ]);

            // One point is a reading; a line needs two. Saying which of those it is stops a single
            // sample being read as a flat trend.
            $gauges[$key] = count($points) < 2
                ? array_merge($gauge, $this->gaugeGap($resolution, count($points), $live instanceof Metric ? $live : null))
                : array_merge($gauge, ['state' => 'ok', 'note' => null, 'remedy' => null]);
        }

        return $gauges;
    }

    /**
     * Why a gauge has no line.
     *
     * Four different silences with four different answers, and the empty chart they all draw looks
     * identical: collection switched off, the reading unavailable on this host, a range that reads
     * rolled-up rows, and a window nothing was written into.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function gaugeGap(string $resolution, int $points, ?Metric $live): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no gauge has been sampled since it was disabled. This is not a reading of zero.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        if ($live instanceof Metric && !$live->isOk()) {
            // The sampler stores a reading only while it is OK, so an unavailable counter has never
            // been written at all. The gap belongs to this host, and the reading says why.
            return [
                'state' => $live->state,
                'note' => 'This gauge is stored only while the reading behind it is available, and it is not on this host. '
                    . ($live->note ?? 'The collector returned no value for it.'),
                'remedy' => $live->remedy,
            ];
        }

        if ($resolution !== 'minute') {
            // Gauges are written once a minute. A longer range reads the rolled-up rows, which the
            // rollup builds — so this window can be empty while the minute rows under it are full.
            return [
                'state' => 'no_data',
                'note' => 'This range reads ' . $resolution . ' rows, which the monitoring rollup builds from the minute samples rather than the sampler writing them directly.',
                'remedy' => 'Choose a shorter range to read the minute samples, or check the rollup is running: `php artisan schedule:list`.',
            ];
        }

        return [
            'state' => 'no_data',
            'note' => ($points === 1
                ? 'Only one sample has been stored in this window, and one point is not a line.'
                : 'No sample of this gauge has been stored in this window.')
                . ' The sample is taken by a command-line process, which reads the same counters as a different user — a file readable by the web user is not necessarily readable there.',
            'remedy' => 'Gauges are sampled by `php artisan monitoring:flush`, scheduled every minute: check the scheduler is running with `php artisan schedule:list`.',
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The metal

    /**
     * One honest answer for the whole hardware half, and the reasons behind it, grouped.
     *
     * A virtual machine answers nothing here, and twenty grey cards each look like a separate
     * fault. The collector already folds the question into one `available` reading; this collapses
     * the twenty reasons behind it into the handful of distinct ones, so the page states each cause
     * once and names the readings it accounts for.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function hardware(array $readings): array
    {
        $available = $readings['available'] ?? null;

        if (!$available instanceof Metric) {
            return [
                'state' => 'not_supported',
                'available' => null,
                'note' => self::COLLECTOR_ABSENT['hardware'],
                'remedy' => null,
                'source' => null,
                'unavailable' => [],
            ];
        }

        $groups = [];
        foreach (self::HARDWARE_READINGS as $name) {
            $metric = $readings[$name] ?? null;
            if (!$metric instanceof Metric || $metric->isOk()) {
                continue;
            }

            // Grouped on the reason rather than on the instance: the collector builds a fresh
            // Metric per reading, so twelve identical "this host exposes no sensors" answers are
            // twelve objects saying one thing.
            $key = $metric->state . '|' . (string) $metric->note . '|' . (string) $metric->remedy;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'metrics' => [],
                    'state' => $metric->state,
                    'note' => $metric->note,
                    'remedy' => $metric->remedy,
                    'source' => $metric->source,
                ];
            }

            $groups[$key]['metrics'][] = $name;
        }

        return [
            'state' => $available->state,
            // Three-valued: false is the collector's own verdict that nothing here can be read,
            // while null is this page failing to obtain even that.
            'available' => $available->isOk() && is_bool($available->value) ? $available->value : null,
            'note' => $available->note,
            'remedy' => $available->remedy,
            'source' => $available->source,
            'unavailable' => array_values($groups),
        ];
    }

    /**
     * A sensor list as a table: temperatures, fans, voltages, or the BMC's own sensors.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<int, string>  $strings  columns copied as text
     * @param  array<int, string>  $numbers  columns copied as numbers, null when the sensor gave none
     * @return array<string, mixed>
     */
    private function sensorRows(array $readings, string $name, array $strings, array $numbers): array
    {
        $metric = $readings[$name] ?? null;

        if (!$metric instanceof Metric) {
            return [
                'state' => 'not_supported',
                'note' => self::COLLECTOR_ABSENT['hardware'],
                'remedy' => null,
                'source' => null,
                'rows' => [],
                'truncated' => false,
            ];
        }

        if (!$metric->isOk() || !is_array($metric->value)) {
            return [
                'state' => $metric->isOk() ? 'no_data' : $metric->state,
                'note' => $metric->note,
                'remedy' => $metric->remedy,
                'source' => $metric->source,
                'rows' => [],
                'truncated' => false,
            ];
        }

        $rows = [];
        foreach ($metric->value as $entry) {
            $entry = (array) $entry;
            $row = [];
            foreach ($strings as $column) {
                // Sensor labels come out of sysfs and a chip's own name field; they are bounded and
                // scrubbed of invalid bytes because this payload is json_encode()d on the way to
                // the page's refresh, where one bad byte returns an empty body instead of a page.
                $row[$column] = $this->text($entry[$column] ?? null);
            }
            foreach ($numbers as $column) {
                $row[$column] = $this->numberOrNull($entry[$column] ?? null);
            }

            $rows[] = $row;
        }

        $truncated = count($rows) > self::MAX_SENSOR_ROWS;

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === [] ? 'The reading carried no sensor rows.' : $metric->note,
            'remedy' => $metric->remedy,
            'source' => $metric->source,
            'rows' => array_slice($rows, 0, self::MAX_SENSOR_ROWS),
            'truncated' => $truncated,
        ];
    }

    /**
     * Drive health, exactly as the tool that asked the drive reported it.
     *
     * `passed` is three-valued. A drive that did not answer the health question has not passed it
     * and has not failed it, and a page that renders the missing answer as either one is making a
     * claim about a disk nobody interrogated.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function drives(array $readings): array
    {
        $metric = $readings['disk_health'] ?? null;

        if (!$metric instanceof Metric) {
            return [
                'state' => 'not_supported',
                'note' => self::COLLECTOR_ABSENT['hardware'],
                'remedy' => null,
                'source' => null,
                'rows' => [],
                'truncated' => false,
            ];
        }

        if (!$metric->isOk() || !is_array($metric->value)) {
            return [
                'state' => $metric->isOk() ? 'no_data' : $metric->state,
                'note' => $metric->note,
                'remedy' => $metric->remedy,
                'source' => $metric->source,
                'rows' => [],
                'truncated' => false,
            ];
        }

        $rows = [];
        foreach ($metric->value as $entry) {
            $entry = (array) $entry;
            $device = $this->text($entry['device'] ?? null, 64);
            if ($device === null) {
                continue;
            }

            $rows[] = [
                'device' => $device,
                'model' => $this->text($entry['model'] ?? null, 64),
                'passed' => isset($entry['passed']) && is_bool($entry['passed']) ? $entry['passed'] : null,
                'temp_c' => $this->numberOrNull($entry['temp_c'] ?? null),
                'power_on_hours' => $this->numberOrNull($entry['power_on_hours'] ?? null),
                'reallocated_sectors' => $this->numberOrNull($entry['reallocated_sectors'] ?? null),
                'pending_sectors' => $this->numberOrNull($entry['pending_sectors'] ?? null),
                'percentage_used' => $this->numberOrNull($entry['percentage_used'] ?? null),
                'media_errors' => $this->numberOrNull($entry['media_errors'] ?? null),
            ];
        }

        $truncated = count($rows) > self::MAX_DRIVE_ROWS;

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === [] ? 'The drive report carried no readable devices.' : $metric->note,
            'remedy' => $metric->remedy,
            'source' => $metric->source,
            'rows' => array_slice($rows, 0, self::MAX_DRIVE_ROWS),
            'truncated' => $truncated,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Shared shapes

    /**
     * A card's worth of readings, or the one fault that emptied it.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<string, string>  $labels  label => metric name
     * @return array<string, mixed>
     */
    private function grouped(array $readings, array $labels, string $absentNote): array
    {
        $shared = $this->sharedFault($readings, array_values($labels));

        if ($shared instanceof Metric) {
            return [
                'state' => $shared->state,
                'note' => $shared->note,
                'remedy' => $shared->remedy,
                'source' => $shared->source,
                'metrics' => [],
            ];
        }

        $metrics = $this->cards($readings, $labels);

        if ($metrics === []) {
            return [
                'state' => 'not_supported',
                'note' => $absentNote,
                'remedy' => null,
                'source' => null,
                'metrics' => [],
            ];
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => $this->firstSource($metrics),
            'metrics' => $metrics,
        ];
    }

    /**
     * The one reason a whole group is unavailable, when there is exactly one.
     *
     * The energy collector marks a group unavailable by filling every key in it with one shared
     * reason — a missing tariff becomes three identical cost cards, and three copies of one fault
     * read as three faults. The reason is compared rather than the object, because the collector
     * builds today's pair and this month's pair in two different methods: the same sentence
     * arrives as two instances, and comparing identity would leave four cards saying one thing.
     *
     * A group where each reading is unavailable for its OWN reason keeps its own cards — a platform
     * that meters no DRAM domain is not the same statement as a host with no counters at all.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<int, string>  $names
     */
    private function sharedFault(array $readings, array $names): ?Metric
    {
        $shared = null;
        $reason = null;

        foreach ($names as $name) {
            $metric = $readings[$name] ?? null;

            if (!$metric instanceof Metric || $metric->isOk()) {
                return null;
            }

            $theirs = $metric->state . '|' . (string) $metric->note . '|' . (string) $metric->remedy;

            if ($shared === null) {
                $shared = $metric;
                $reason = $theirs;

                continue;
            }

            if ($reason !== $theirs) {
                return null;
            }
        }

        return $shared;
    }

    /**
     * The readings a one-line card can honestly draw.
     *
     * An unavailable reading goes in whole — its state and its remedy ARE the content. A reading
     * that is OK but not scalar has no honest single-value rendering, and handing an array to the
     * metric partial prints a PHP warning where a value belongs.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<string, string>  $labels
     * @return array<string, Metric>
     */
    private function cards(array $readings, array $labels): array
    {
        $cards = [];

        foreach ($labels as $label => $name) {
            $metric = $readings[$name] ?? null;

            if (!$metric instanceof Metric) {
                continue;
            }

            if ($metric->isOk() && !is_scalar($metric->value)) {
                continue;
            }

            $cards[$label] = $metric;
        }

        return $cards;
    }

    /** @param array<string, Metric> $metrics */
    private function firstSource(array $metrics): ?string
    {
        foreach ($metrics as $metric) {
            return $metric->source;
        }

        return null;
    }

    /**
     * A number, or null when there was none.
     *
     * (float) null is 0.0, and a zero in a watt, a degree or an error column is the single most
     * misleading value this page could print.
     */
    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * A label from a sysfs file or a shell tool, bounded and safe to encode.
     *
     * The controller serves this payload through response()->json(), which has no
     * JSON_PARTIAL_OUTPUT_ON_ERROR: one invalid byte out of a drive's model string would return an
     * empty body rather than a page.
     */
    private function text(mixed $value, int $limit = 120): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return mb_substr(mb_convert_encoding($string, 'UTF-8', 'UTF-8'), 0, $limit);
    }

    /**
     * Readings this page draws nowhere.
     *
     * Normally empty. It exists so a collector that grows a reading cannot have it silently
     * disappear: an undrawn measurement is indistinguishable from an unmeasured one, and that is
     * the confusion this whole system is built to avoid.
     *
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<int, array{collector: string, metric: string, state: string}>
     */
    private function unrendered(array $readings): array
    {
        $claimed = [
            'energy' => array_merge(
                ['basis', 'domains'],
                array_values(self::POWER_METRICS),
                array_values(self::ENERGY_METRICS),
                array_values(self::TARIFF_METRICS),
                array_values(self::COST_METRICS),
            ),
            'hardware' => array_merge(['available'], self::HARDWARE_READINGS),
        ];

        $unrendered = [];

        foreach ($readings as $collector => $collected) {
            foreach ($collected as $name => $metric) {
                // The registry's own failure marker, reported at the top of the page as a fault
                // rather than here as a reading the collector produced.
                if ($name === '__collector' || !$metric instanceof Metric) {
                    continue;
                }

                if (in_array($name, $claimed[$collector] ?? [], true)) {
                    continue;
                }

                $unrendered[] = ['collector' => $collector, 'metric' => $name, 'state' => $metric->state];
            }
        }

        return $unrendered;
    }
}
