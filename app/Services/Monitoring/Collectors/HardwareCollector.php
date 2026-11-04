<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Environment;
use Illuminate\Support\Facades\Cache;

/**
 * The physical machine: temperatures, fans, voltages, drive health, ECC errors.
 *
 * Hardware telemetry is where a dashboard is most likely to invent good news, because every one of
 * these numbers has an innocent-looking default. A missing SMART reading is not a healthy disk, an
 * absent thermal zone is not a cool CPU, a fan input that reads nothing is not a fan that is
 * spinning, and an ECC counter that cannot be found is not zero errors — zero is what a working
 * EDAC controller reports, which is precisely why the absent case must never borrow it.
 *
 * Most deployments of this application run on a virtual machine, where none of this exists: sensors
 * belong to the metal and no hypervisor forwards them to a guest. So the unavailable path is the
 * main path here, and each one names what a bare-metal host would need instead of shrugging. The
 * `available` summary exists so the UI can draw one honest empty state rather than twenty grey rows
 * that each look like a fault.
 */
class HardwareCollector implements Collector
{
    private const SENSOR_SOURCE = 'Linux /sys/class/thermal and /sys/class/hwmon';
    private const THERMAL_SOURCE = 'Linux /sys/class/thermal';
    private const HWMON_SOURCE = 'Linux /sys/class/hwmon';
    private const SMART_SOURCE = 'smartctl (smartmontools)';
    private const NVME_SOURCE = 'nvme-cli smart-log';
    private const EDAC_SOURCE = 'Linux /sys/devices/system/edac';
    private const IPMI_SOURCE = 'ipmitool (IPMI/BMC)';

    private const ABSENT = 'Hardware telemetry is not available in this environment';

    private const SENSOR_REMEDY = 'On bare metal these appear once the platform driver is loaded: modprobe coretemp (Intel), k10temp (AMD) or drivetemp (SATA), then install lm-sensors and run sensors-detect. No hypervisor forwards the host sensors to a guest, so a virtual machine cannot be made to answer.';
    private const FAN_REMEDY = 'Fan tachometers come from the board Super-I/O chip: on bare metal, modprobe the driver sensors-detect names (nct6775, it87, w83627ehf). A virtual machine has no fan to measure.';
    private const VOLTAGE_REMEDY = 'Voltage rails come from the same Super-I/O or PMBus chip as the fans: on bare metal, modprobe the driver sensors-detect names (nct6775, it87). A virtual machine has no rails to measure.';
    private const SENSOR_ACCESS_REMEDY = 'Give the web user read access to /sys/class/hwmon and /sys/class/thermal. Those files are world-readable by default, so something is hiding them rather than the permissions being wrong: a systemd sandbox on the PHP-FPM unit (InaccessiblePaths= or TemporaryFileSystem= covering /sys), an AppArmor or SELinux profile, or a container that masks /sys. ProtectKernelTunables= only makes /sys read-only and is never the cause.';
    private const SMART_REMEDY = 'Install smartmontools (apt install smartmontools) or nvme-cli, then give the web user the privilege the ioctl needs: SMART rides an ATA/NVMe passthrough command, so read access to the device node alone never grants it. Either setcap cap_sys_rawio,cap_sys_admin+ep /usr/sbin/smartctl (and /usr/sbin/nvme), or add AmbientCapabilities=CAP_SYS_RAWIO CAP_SYS_ADMIN to the PHP-FPM unit with systemctl edit php-fpm and reload it.';
    private const EDAC_REMEDY = 'ECC error counting needs ECC DIMMs, CONFIG_EDAC in the kernel and the memory controller driver for the CPU (modprobe amd64_edac, skx_edac or i10nm_edac). A virtual machine is not shown the host memory controller.';
    private const IPMI_REMEDY = 'Install ipmitool (apt install ipmitool), load the BMC drivers (modprobe ipmi_si ipmi_devintf) and give the web user access to /dev/ipmi0, or query the BMC over the network with ipmitool -H. Only a server with a baseboard management controller has one.';

    /** Long enough for a spun-down disk to answer, short enough that nobody watches it. */
    private const PROBE_TIMEOUT_SECONDS = 5;

    /** A ceiling on the whole drive sweep: eight wedged disks must not add up to eight timeouts. */
    private const DRIVE_SCAN_BUDGET_SECONDS = 15;

    private const DRIVE_CACHE_KEY = 'monitoring:hardware:drives';
    private const DRIVE_CACHE_SECONDS = 300;
    private const IPMI_CACHE_KEY = 'monitoring:hardware:ipmi';
    private const IPMI_CACHE_SECONDS = 120;

    /** Enough to cover a real server's disks without turning a page render into a shell storm. */
    private const MAX_DRIVES = 8;

    private ?bool $timeoutAvailable = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'hardware';
    }

    public function collect(): array
    {
        $scan = $this->scanSensors();
        $drives = $this->drives();

        $metrics = [
            'cpu_temp_c' => $this->cpuTemperature($scan),
            'board_temp_c' => $this->boardTemperature($scan),
            'temperatures' => $this->sensorList($scan, 'temperatures', self::SENSOR_SOURCE, 'this host exposes no thermal zones and no hwmon temperature inputs', self::SENSOR_REMEDY),
            'max_disk_temp_c' => $this->diskTemperature($scan, $drives),
            'fan_rpm_max' => $this->fanSpeed($scan),
            'fans' => $this->sensorList($scan, 'fans', self::HWMON_SOURCE, 'this host exposes no fan tachometers', self::FAN_REMEDY),
            'voltages' => $this->sensorList($scan, 'voltages', self::HWMON_SOURCE, 'this host exposes no voltage rails', self::VOLTAGE_REMEDY),
            'disk_health' => $drives['health'],
            'ecc_correctable_errors' => $this->eccErrors('ce_count', 'correctable'),
            'ecc_uncorrectable_errors' => $this->eccErrors('ue_count', 'uncorrectable'),
            'ipmi_sensors' => $this->ipmiSensors(),
        ];

        return ['available' => $this->availability($metrics)] + $metrics;
    }

    public function gauges(): array
    {
        $collected = $this->collect();

        return array_filter([
            'hardware.cpu_temp_c' => $collected['cpu_temp_c'],
            'hardware.max_disk_temp_c' => $collected['max_disk_temp_c'],
            'hardware.fan_rpm_max' => $collected['fan_rpm_max'],
        ], fn (Metric $metric) => $metric->isOk());
    }

    // -------------------------------------------------------------------------------------------

    /**
     * One reading of every sensor the kernel exposes, taken once per collection.
     *
     * `unreadable` counts only the inputs this process is not allowed to open, which is what
     * separates "this machine has no sensors" from "this process may not look at them" further
     * down.
     *
     * @return array{temperatures: list<array<string, mixed>>, fans: list<array<string, mixed>>, voltages: list<array<string, mixed>>, unreadable: int}
     */
    private function scanSensors(): array
    {
        $scan = ['temperatures' => [], 'fans' => [], 'voltages' => [], 'unreadable' => 0];

        if ($this->environment->has('thermal_zones')) {
            foreach (@glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $path) {
                $zone = dirname($path);
                $label = $this->readText($zone . '/type') ?? basename($zone);
                $celsius = $this->celsius($this->readNumber($path, $scan));
                if ($celsius === null) {
                    continue;
                }

                $scan['temperatures'][] = [
                    'label' => $label,
                    'chip' => basename($zone),
                    'celsius' => $celsius,
                    'kind' => $this->classify($label, 'thermal_zone'),
                    'source' => self::THERMAL_SOURCE . ' ' . basename($zone),
                ];
            }
        }

        if ($this->environment->has('hwmon')) {
            foreach (@glob('/sys/class/hwmon/hwmon*') ?: [] as $chipPath) {
                $chip = $this->readText($chipPath . '/name') ?? basename($chipPath);
                $source = self::HWMON_SOURCE . ' ' . basename($chipPath) . ' (' . $chip . ')';

                foreach (@glob($chipPath . '/temp*_input') ?: [] as $path) {
                    $celsius = $this->celsius($this->readNumber($path, $scan));
                    if ($celsius === null) {
                        continue;
                    }
                    $label = $this->inputLabel($path, $chip);
                    $scan['temperatures'][] = [
                        'label' => $label,
                        'chip' => $chip,
                        'celsius' => $celsius,
                        'kind' => $this->classify($label, $chip),
                        'source' => $source,
                    ];
                }

                foreach (@glob($chipPath . '/fan*_input') ?: [] as $path) {
                    $rpm = $this->readNumber($path, $scan);
                    if ($rpm === null) {
                        continue;
                    }
                    $scan['fans'][] = [
                        'label' => $this->inputLabel($path, $chip),
                        'chip' => $chip,
                        'rpm' => (int) $rpm,
                        'source' => $source,
                    ];
                }

                foreach (@glob($chipPath . '/in*_input') ?: [] as $path) {
                    $millivolts = $this->readNumber($path, $scan);
                    if ($millivolts === null) {
                        continue;
                    }
                    $scan['voltages'][] = [
                        'label' => $this->inputLabel($path, $chip),
                        'chip' => $chip,
                        'volts' => round($millivolts / 1000, 3),
                        'source' => $source,
                    ];
                }
            }
        }

        return $scan;
    }

    /** @param array{unreadable: int} $scan */
    private function readNumber(string $path, array &$scan): ?float
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            // A file this process may not open is the operator's to fix; one that opens and then
            // errors is the driver saying no — a disabled thermal zone answers -EINVAL — and
            // counting that as a permission fault would send them to change permissions that were
            // never wrong.
            if (!is_readable($path)) {
                $scan['unreadable']++;
            }

            return null;
        }

        $trimmed = trim($contents);

        return is_numeric($trimmed) ? (float) $trimmed : null;
    }

    private function readText(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
    }

    /**
     * hwmon and thermal zones both report thousandths of a degree.
     *
     * A disabled input answers with a sentinel rather than an error — -274 °C, or a raw trip point
     * in the thousands. Those are not temperatures, and charting one is worse than charting none.
     */
    private function celsius(?float $millidegrees): ?float
    {
        if ($millidegrees === null) {
            return null;
        }

        $celsius = round($millidegrees / 1000, 1);

        return $celsius > -50 && $celsius < 200 ? $celsius : null;
    }

    private function inputLabel(string $path, string $chip): string
    {
        return $this->readText(preg_replace('/_input$/', '_label', $path) ?? '')
            ?? $chip . ' ' . basename($path, '_input');
    }

    /** Which part of the machine a sensor belongs to, so "CPU temperature" means the CPU. */
    private function classify(string $label, string $chip): string
    {
        $subject = strtolower($chip . ' ' . $label);

        return match (true) {
            // Disks first: an NVMe "Composite" input sits on a chip whose name would otherwise
            // read as a CPU sensor.
            preg_match('/nvme|drivetemp|composite|\bssd\b|\bhdd\b/', $subject) === 1 => 'disk',
            preg_match('/coretemp|k10temp|zenpower|x86_pkg_temp|\bcpu\b|core \d|package|tdie|tctl|soc/', $subject) === 1 => 'cpu',
            preg_match('/acpitz|systin|ambient|board|motherboard|\bmb\b/', $subject) === 1 => 'board',
            default => 'other',
        };
    }

    /** @param array<string, mixed> $scan */
    private function cpuTemperature(array $scan): Metric
    {
        $cpu = $this->ofKind($scan['temperatures'], 'cpu');
        $package = array_values(array_filter($cpu, fn (array $entry) => preg_match('/package|tdie|tctl|x86_pkg/i', $entry['label']) === 1));

        // The package sensor is the die-wide reading, so prefer it. Failing that, the hottest core
        // rather than the mean: one core at 95 °C is the event, and averaging eight cores hides it.
        $entry = $this->peak($package !== [] ? $package : $cpu, 'celsius');

        if ($entry === null) {
            return $this->unavailableSensor($scan, 'this host exposes no CPU temperature sensor', self::SENSOR_REMEDY);
        }

        return Metric::of($entry['celsius'], $entry['source'], '°C', 'Sensor: ' . $entry['label']);
    }

    /** @param array<string, mixed> $scan */
    private function boardTemperature(array $scan): Metric
    {
        $entry = $this->peak($this->ofKind($scan['temperatures'], 'board'), 'celsius');

        if ($entry === null) {
            return $this->unavailableSensor($scan, 'this host exposes no board or ambient temperature sensor', self::SENSOR_REMEDY);
        }

        return Metric::of($entry['celsius'], $entry['source'], '°C', 'Sensor: ' . $entry['label']);
    }

    /**
     * @param array<string, mixed> $scan
     * @param array{health: Metric, temps: list<array<string, mixed>>} $drives
     */
    private function diskTemperature(array $scan, array $drives): Metric
    {
        $entry = $this->peak(array_merge($this->ofKind($scan['temperatures'], 'disk'), $drives['temps']), 'celsius');

        if ($entry !== null) {
            return Metric::of($entry['celsius'], $entry['source'], '°C', 'Drive: ' . $entry['label']);
        }

        // Drive temperature has two possible sources and both came up empty. When the drive probe
        // failed for a reason the operator can fix, that reason is the more useful answer than a
        // flat "no sensor".
        if ($drives['health']->isActionable()) {
            return $drives['health'];
        }

        return $this->unavailableSensor($scan, 'no hwmon drive sensor exists and no SMART tool can be asked for a drive temperature', self::SMART_REMEDY);
    }

    /** @param array<string, mixed> $scan */
    private function fanSpeed(array $scan): Metric
    {
        $entry = $this->peak($scan['fans'], 'rpm');

        if ($entry === null) {
            return $this->unavailableSensor($scan, 'this host exposes no fan tachometers', self::FAN_REMEDY);
        }

        return Metric::of($entry['rpm'], $entry['source'], 'RPM', 'Sensor: ' . $entry['label']);
    }

    /** @param array<string, mixed> $scan */
    private function sensorList(array $scan, string $kind, string $source, string $absent, string $remedy): Metric
    {
        if ($scan[$kind] === []) {
            return $this->unavailableSensor($scan, $absent, $remedy);
        }

        return Metric::of($scan[$kind], $source);
    }

    /**
     * The one answer every sensor metric falls back to.
     *
     * Files that exist but will not open are a permission answer with a different fix, so they are
     * never folded into "this machine has no sensors".
     *
     * @param array<string, mixed> $scan
     */
    private function unavailableSensor(array $scan, string $absent, string $remedy): Metric
    {
        if ($scan['unreadable'] > 0) {
            return Metric::permissionDenied(
                self::SENSOR_SOURCE,
                'Sensor inputs exist under /sys but this process may not read them.',
                self::SENSOR_ACCESS_REMEDY,
            );
        }

        return Metric::notSupported(self::SENSOR_SOURCE, self::ABSENT . ': ' . $absent . '.', $remedy);
    }

    /**
     * Drive health, only ever from a tool that actually asked the drive.
     *
     * @return array{health: Metric, temps: list<array<string, mixed>>}
     */
    private function drives(): array
    {
        if (!$this->environment->has('shell')) {
            return $this->noDrives(Metric::permissionDenied(
                self::SMART_SOURCE,
                'This PHP may not run smartctl or nvme, and no PHP function reports drive health.',
                'Remove exec and shell_exec from disable_functions in php.ini and reload PHP-FPM. ' . self::SMART_REMEDY,
            ));
        }

        $tool = match (true) {
            $this->environment->has('smartctl_bin') => 'smartctl',
            $this->environment->has('nvme_bin') => 'nvme',
            default => null,
        };

        if ($tool === null) {
            return $this->noDrives(Metric::notSupported(
                self::SMART_SOURCE,
                self::ABSENT . ': neither smartctl nor nvme is installed, and drive health has no other source. An absent SMART reading is not a healthy disk.',
                self::SMART_REMEDY,
            ));
        }

        $source = $tool === 'smartctl' ? self::SMART_SOURCE : self::NVME_SOURCE;

        try {
            $report = $this->remember(self::DRIVE_CACHE_KEY, self::DRIVE_CACHE_SECONDS, fn () => $this->readDrives($tool));
        } catch (\Throwable $exception) {
            return $this->noDrives(Metric::failed($source, $exception));
        }

        if ($report['status'] === 'denied') {
            return $this->noDrives(Metric::permissionDenied(
                $source,
                $tool . ' is installed but cannot open the device as the web user: ' . $report['detail'],
                self::SMART_REMEDY,
            ));
        }

        if ($report['devices'] === []) {
            return $this->noDrives(Metric::noData($source, $report['detail']));
        }

        $temps = [];
        foreach ($report['devices'] as $device) {
            if (isset($device['temp_c'])) {
                $temps[] = ['label' => $device['device'], 'celsius' => (float) $device['temp_c'], 'source' => $source];
            }
        }

        return [
            'health' => Metric::of($report['devices'], $source, null, $report['detail'] !== '' ? $report['detail'] : null),
            'temps' => $temps,
        ];
    }

    /** @return array{health: Metric, temps: list<array<string, mixed>>} */
    private function noDrives(Metric $health): array
    {
        return ['health' => $health, 'temps' => []];
    }

    /**
     * @return array{status: string, detail: string, devices: list<array<string, mixed>>}
     */
    private function readDrives(string $tool): array
    {
        $devices = $this->blockDevices();
        if ($devices === []) {
            return ['status' => 'no_devices', 'detail' => 'No physical block device was found under /sys/block.', 'devices' => []];
        }

        $rows = [];
        $denied = null;
        $deadline = microtime(true) + self::DRIVE_SCAN_BUDGET_SECONDS;
        $unscanned = [];

        foreach ($devices as $device) {
            if (microtime(true) >= $deadline) {
                $unscanned[] = $device;
                continue;
            }

            $output = $this->run($tool === 'smartctl'
                ? 'smartctl --json=c -H -A ' . escapeshellarg($device)
                : 'nvme smart-log -o json ' . escapeshellarg($device));

            if ($output === null) {
                continue;
            }

            if (preg_match('/permission denied|operation not permitted|requires root|must be run as root|failed to open|open device.*failed/i', $output, $refusal) === 1) {
                $denied = $this->reasonLine($output, $refusal[0]);
                continue;
            }

            $row = $tool === 'smartctl' ? $this->parseSmart($device, $output) : $this->parseNvme($device, $output);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === [] && $denied !== null) {
            return ['status' => 'denied', 'detail' => $denied, 'devices' => []];
        }

        if ($unscanned !== []) {
            $detail = $tool . ' ran out of the ' . self::DRIVE_SCAN_BUDGET_SECONDS . 's scan budget; '
                . implode(', ', $unscanned) . ' were not asked. A disk that never answers is usually spun down or wedged.';

            return $rows === []
                ? ['status' => 'no_data', 'detail' => $detail, 'devices' => []]
                : ['status' => 'partial', 'detail' => $detail, 'devices' => $rows];
        }

        if ($rows === []) {
            return [
                'status' => 'no_data',
                'detail' => $tool . ' returned no readable health data for ' . implode(', ', $devices) . ' (virtual disks have no SMART data behind them).',
                'devices' => [],
            ];
        }

        return ['status' => 'ok', 'detail' => '', 'devices' => $rows];
    }

    /** @return list<string> */
    private function blockDevices(): array
    {
        $devices = [];

        foreach (@glob('/sys/block/*') ?: [] as $path) {
            $name = basename($path);
            // loop, ram, zram, dm, md and optical nodes are kernel constructs with no drive behind
            // them to interrogate.
            if (preg_match('/^(loop|ram|zram|dm-|md\d|sr\d|fd\d)/', $name) === 1) {
                continue;
            }
            $devices[] = '/dev/' . $name;
        }

        return array_slice($devices, 0, self::MAX_DRIVES);
    }

    /** @return array<string, mixed>|null */
    private function parseSmart(string $device, string $output): ?array
    {
        $json = json_decode($output, true);

        if (is_array($json)) {
            $attributes = $this->smartAttributes($json);
            $row = array_filter([
                'device' => $device,
                'model' => $json['model_name'] ?? null,
                'passed' => $json['smart_status']['passed'] ?? null,
                'temp_c' => isset($json['temperature']['current']) && is_numeric($json['temperature']['current'])
                    ? $this->driveCelsius((float) $json['temperature']['current'])
                    : null,
                'power_on_hours' => $json['power_on_time']['hours'] ?? null,
                'reallocated_sectors' => $attributes[5] ?? null,
                'pending_sectors' => $attributes[197] ?? null,
            ], fn ($value) => $value !== null);

            return isset($row['passed']) || isset($row['temp_c']) ? $row : null;
        }

        // smartmontools before 7.0 has no --json at all, so the two lines that matter are read out
        // of the text report rather than reporting nothing on an older server.
        $row = ['device' => $device];
        if (preg_match('/self-assessment test result:\s*(\w+)/i', $output, $match) === 1) {
            $row['passed'] = strtoupper($match[1]) === 'PASSED';
        }
        if (preg_match('/Temperature_Celsius.*?-\s+(\d+)/i', $output, $match) === 1) {
            $celsius = $this->driveCelsius((float) $match[1]);
            if ($celsius !== null) {
                $row['temp_c'] = $celsius;
            }
        }

        return isset($row['passed']) || isset($row['temp_c']) ? $row : null;
    }

    /**
     * A drive temperature, but only when the drive actually supplied one.
     *
     * Both tools have a "nothing here" value that reads like a measurement. nvme-cli prints the raw
     * register in JSON, which is kelvin — and 0 there is an unpopulated sensor, not absolute zero —
     * while its human output prints celsius, so anything above 200 is the kelvin form. smartctl
     * passes a 0 straight through from a USB bridge that never asked the disk. A powered drive sits
     * above room temperature, so 0 °C is the absence of a sensor, and charting it as an ice-cold
     * disk is precisely the good news this collector exists not to invent.
     */
    private function driveCelsius(float $reading): ?float
    {
        $celsius = $reading > 200 ? $reading - 273.15 : $reading;

        return $celsius > 0 && $celsius < 150 ? round($celsius, 1) : null;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<int, int>  SMART attribute id => raw value
     */
    private function smartAttributes(array $json): array
    {
        $attributes = [];
        $table = $json['ata_smart_attributes']['table'] ?? [];

        foreach (is_array($table) ? $table : [] as $attribute) {
            if (isset($attribute['id'], $attribute['raw']['value'])) {
                $attributes[(int) $attribute['id']] = (int) $attribute['raw']['value'];
            }
        }

        return $attributes;
    }

    /** @return array<string, mixed>|null */
    private function parseNvme(string $device, string $output): ?array
    {
        $json = json_decode($output, true);
        if (!is_array($json)) {
            return null;
        }

        $row = ['device' => $device];

        if (isset($json['temperature']) && is_numeric($json['temperature'])) {
            $celsius = $this->driveCelsius((float) $json['temperature']);
            if ($celsius !== null) {
                $row['temp_c'] = $celsius;
            }
        }
        if (isset($json['critical_warning']) && is_numeric($json['critical_warning'])) {
            $row['passed'] = (int) $json['critical_warning'] === 0;
        }
        foreach (['percentage_used', 'media_errors', 'power_on_hours'] as $field) {
            if (isset($json[$field]) && is_numeric($json[$field])) {
                $row[$field] = (int) $json[$field];
            }
        }

        return isset($row['passed']) || isset($row['temp_c']) ? $row : null;
    }

    /**
     * Correctable and uncorrectable ECC errors, straight off the memory controller.
     *
     * Zero here is the whole point of the metric — "no ECC errors since boot" — which is exactly
     * why a host with no EDAC controller must report not_supported instead of borrowing that zero.
     */
    private function eccErrors(string $counter, string $kind): Metric
    {
        return Metric::probe(self::EDAC_SOURCE, function () use ($counter, $kind) {
            $paths = @glob('/sys/devices/system/edac/mc/mc*/' . $counter) ?: [];
            if ($paths === []) {
                return Metric::notSupported(
                    self::EDAC_SOURCE,
                    self::ABSENT . ': this host exposes no EDAC memory controller, so ' . $kind . ' ECC errors cannot be counted.',
                    self::EDAC_REMEDY,
                );
            }

            $total = 0;
            foreach ($paths as $path) {
                $contents = @file_get_contents($path);
                if ($contents === false) {
                    return Metric::permissionDenied(
                        self::EDAC_SOURCE,
                        'The EDAC counters exist but this process may not read ' . $path . '.',
                        'Give the web user read access to /sys/devices/system/edac. It is world-readable by default, so a hardened /sys mount or an AppArmor profile is the usual cause.',
                    );
                }
                $total += (int) trim($contents);
            }

            return $total;
        }, 'errors');
    }

    /** Chassis sensors from the BMC, for the machines that have one. */
    private function ipmiSensors(): Metric
    {
        return Metric::probe(self::IPMI_SOURCE, function () {
            if (!$this->environment->has('shell')) {
                return Metric::permissionDenied(
                    self::IPMI_SOURCE,
                    'This PHP may not run ipmitool, and the BMC has no other interface here.',
                    'Remove exec and shell_exec from disable_functions in php.ini and reload PHP-FPM. ' . self::IPMI_REMEDY,
                );
            }

            if (!$this->environment->has('ipmitool_bin')) {
                return Metric::notSupported(
                    self::IPMI_SOURCE,
                    self::ABSENT . ': ipmitool is not installed and no baseboard management controller was found.',
                    self::IPMI_REMEDY,
                );
            }

            $output = $this->remember(self::IPMI_CACHE_KEY, self::IPMI_CACHE_SECONDS, fn () => $this->run('ipmitool sdr type temperature') ?? '');

            if (trim($output) === '') {
                return Metric::noData(self::IPMI_SOURCE, 'ipmitool returned nothing for the temperature sensor list.');
            }

            if (preg_match('/could not open device|permission denied|operation not permitted|driver.*not (?:loaded|present)/i', $output, $refusal) === 1) {
                return Metric::permissionDenied(
                    self::IPMI_SOURCE,
                    'ipmitool cannot reach the BMC: ' . $this->reasonLine($output, $refusal[0]),
                    self::IPMI_REMEDY,
                );
            }

            $rows = [];
            foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
                // ipmitool prints "CPU1 Temp | 01h | ok | 3.1 | 45 degrees C"; a sensor with no
                // reading prints "no reading" in the last column and is skipped rather than zeroed.
                $columns = array_map('trim', explode('|', $line));
                if (count($columns) < 5 || preg_match('/^(-?[\d.]+)/', $columns[4], $match) !== 1) {
                    continue;
                }
                $rows[] = ['sensor' => $columns[0], 'status' => $columns[2], 'celsius' => (float) $match[1]];
            }

            return $rows === [] ? Metric::noData(self::IPMI_SOURCE, 'The BMC listed no readable temperature sensors.') : $rows;
        });
    }

    /**
     * One honest answer for the whole panel.
     *
     * Twenty unavailable rows read as twenty faults; one "no hardware telemetry here" reads as the
     * truth about the machine.
     *
     * @param array<string, Metric> $metrics
     */
    private function availability(array $metrics): Metric
    {
        $source = 'Hardware sensor discovery on ' . $this->environment->hostDescription();
        $readable = array_keys(array_filter($metrics, fn (Metric $metric) => $metric->isOk()));

        if ($readable !== []) {
            return Metric::of(true, $source, null, 'Readable here: ' . implode(', ', $readable) . '.');
        }

        return Metric::of(false, $source, null, self::ABSENT . ': no thermal zone, hwmon chip, SMART tool, EDAC controller or BMC answered on this host. Physical sensors belong to the machine, and a virtual guest is never shown the ones underneath it.');
    }

    /**
     * Run a hardware probe, bounded.
     *
     * smartctl against a spun-down disk and ipmitool against a wedged BMC both block for a long
     * time, and monitoring must never be the thing that hangs the page. Where coreutils' timeout
     * exists every command goes through it and is never retried bare afterwards: a killed probe
     * returns nothing, so retrying on empty output would hand back the unbounded wait the wrapper
     * was there to prevent. stderr is folded in deliberately — on these tools "Permission denied"
     * is the answer, and it only ever arrives there.
     */
    private function run(string $command): ?string
    {
        if ($this->timeoutAvailable()) {
            $command = 'timeout ' . self::PROBE_TIMEOUT_SECONDS . ' ' . $command;
        }

        $output = @shell_exec($command . ' 2>&1');

        return is_string($output) && trim($output) !== '' ? $output : null;
    }

    /** Asked once per request: whether a probe can be bounded decides how every probe is run. */
    private function timeoutAvailable(): bool
    {
        if ($this->timeoutAvailable === null) {
            $path = @shell_exec('command -v timeout 2>/dev/null');
            $this->timeoutAvailable = is_string($path) && trim($path) !== '';
        }

        return $this->timeoutAvailable;
    }

    /**
     * Shelling out to every disk on every dashboard refresh would cost more than the readings are
     * worth, and SMART attributes move over days. A broken cache store must not blind the probe.
     */
    private function remember(string $key, int $seconds, callable $probe): mixed
    {
        try {
            return Cache::remember($key, $seconds, $probe);
        } catch (\Throwable) {
            return $probe();
        }
    }

    /**
     * The line that actually refused, since a tool's version banner is not a reason.
     *
     * These notes are what an operator reads to decide what to change, and smartctl prints its
     * build header before saying why it could not open the device.
     */
    private function reasonLine(string $output, string $refusal): string
    {
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (stripos($line, $refusal) !== false) {
                return mb_substr(trim($line), 0, 200);
            }
        }

        return mb_substr(trim($output), 0, 200);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function ofKind(array $entries, string $kind): array
    {
        return array_values(array_filter($entries, fn (array $entry) => $entry['kind'] === $kind));
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private function peak(array $entries, string $key): ?array
    {
        $peak = null;

        foreach ($entries as $entry) {
            if ($peak === null || $entry[$key] > $peak[$key]) {
                $peak = $entry;
            }
        }

        return $peak;
    }
}
