<?php

namespace App\Services\Monitoring\Operations;

use App\Services\AuditLogger;
use App\Services\Monitoring\Checks\SyntheticCheck;
use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\MonitoringSettings;

/**
 * Changing what monitoring watches, from the panel rather than from a shell.
 *
 * The `monitoring_settings` table was created with the comment "so an operator can change them from
 * the panel without a deploy", and config/monitoring.php says the same — and then the panel that was
 * supposed to do it shipped read-only, so every threshold change was a hand-written SQL UPDATE and
 * adding a probe on your own checkout page was a command nobody had run.
 *
 * The rules that make this safe to write from a browser:
 *
 * Only keys the running code reads back through MonitoringSettings may be stored, because a row for
 * anything else is saved and then ignored — a control that silently does nothing is worse than none.
 *
 * A value is validated against what it means. A threshold expressed as a percentage cannot be 400,
 * and a retention window cannot be zero days, because either would quietly break the section that
 * reads it.
 *
 * Every change is recorded twice: in the audit trail, so it is attributable, and on the monitoring
 * timeline, so the operator reading a graph a week later can see the line move and know why.
 */
class MonitoringConfiguration
{
    /** What one run will probe. Beyond this the probe cycle outlives the schedule interval. */
    public const MAX_JOURNEYS = 10;

    /**
     * The bounds each kind of setting must sit inside, by key suffix.
     *
     * Read from the key rather than from a per-key table so a threshold added to config/monitoring.php
     * is bounded correctly the day it appears rather than the day somebody remembers to list it here.
     *
     * @var array<string, array{min: float, max: float}>
     */
    private const BOUNDS = [
        '_days' => ['min' => 1, 'max' => 3650],
        '_hours' => ['min' => 1, 'max' => 8760],
        '_minutes' => ['min' => 1, 'max' => 44640],
        '_seconds' => ['min' => 1, 'max' => 2592000],
        '_ms' => ['min' => 1, 'max' => 600000],
    ];

    /** Anything ending in `_warning`, `_critical` or `_rate` is a percentage or a share. */
    private const PERCENTAGE = ['min' => 0, 'max' => 100];

    public function __construct(
        private readonly MonitoringSettings $settings,
        private readonly EventLog $events,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Save a set of overrides.
     *
     * @param  array<string, mixed>  $values  key => raw input, as it came off the form
     * @return array{saved: array<string, array{from: mixed, to: mixed}>, refused: array<string, string>}
     */
    public function save(array $values): array
    {
        $saved = [];
        $refused = [];

        foreach ($values as $key => $raw) {
            if (!self::isWritable($key)) {
                $refused[$key] = 'not_a_setting_the_running_code_reads_back';
                continue;
            }

            $before = $this->settings->get($key);

            // An emptied field means "go back to what shipped", which is a different instruction
            // from "set this to zero" and has to stay tellable apart from it.
            if ($raw === null || $raw === '') {
                if ($this->isStored($key)) {
                    $this->settings->clear($key);
                    $saved[$key] = ['from' => $before, 'to' => null];
                }
                continue;
            }

            if (!is_numeric($raw)) {
                $refused[$key] = 'not_a_number';
                continue;
            }

            $bounds = $this->boundsFor($key);
            $value = (float) $raw;

            if ($value < $bounds['min'] || $value > $bounds['max']) {
                $refused[$key] = 'outside_' . $bounds['min'] . '_to_' . $bounds['max'];
                continue;
            }

            // Kept as an int where the shipped default is one, so a millisecond threshold does not
            // come back as 250.0 and read like a measurement.
            $shipped = config('monitoring.' . $key);
            $typed = is_int($shipped) || $value == (int) $value ? (int) $value : $value;

            if ($before == $typed) {
                continue;
            }

            $this->settings->put($key, $typed);
            $saved[$key] = ['from' => $before, 'to' => $typed];
        }

        if ($saved !== []) {
            $this->settings->forget();
            $this->audit->record(action: 'monitoring.settings_updated', after: $saved);
            $this->events->record(
                type: EventLog::CONFIG,
                severity: EventLog::INFO,
                title: 'Monitoring settings changed',
                description: implode(', ', array_map(
                    static fn (string $key, array $change): string => $key . ': ' . var_export($change['from'], true) . ' → ' . var_export($change['to'], true),
                    array_keys($saved),
                    $saved,
                )),
                context: $saved,
            );
        }

        return ['saved' => $saved, 'refused' => $refused];
    }

    /** @return array<int, array<string, mixed>> */
    public function journeys(): array
    {
        $stored = $this->settings->get('synthetics', []);

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    /**
     * Add or replace a probe.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function addJourney(string $name, string $url, int $expectStatus, ?string $expectText, ?int $maxMs, int $timeout): array
    {
        $name = trim($name);
        $url = trim($url);

        if ($name === '' || $url === '') {
            return ['ok' => false, 'error' => 'a_probe_needs_a_name_and_a_url'];
        }

        // The same rule the check applies, applied here so the refusal names the reason rather than
        // the journey silently never running.
        if (!SyntheticCheck::isProbeable($url)) {
            return ['ok' => false, 'error' => 'only_http_urls_can_be_probed_and_never_a_cloud_metadata_address'];
        }

        $journeys = array_values(array_filter(
            $this->journeys(),
            static fn (array $journey): bool => ($journey['name'] ?? null) !== $name,
        ));

        if (count($journeys) >= self::MAX_JOURNEYS) {
            return ['ok' => false, 'error' => 'there_are_already_as_many_probes_as_one_run_will_fetch_remove_one_first'];
        }

        $journeys[] = array_filter([
            'name' => $name,
            'url' => $url,
            'expect_status' => $expectStatus,
            'expect_text' => $expectText !== null && trim($expectText) !== '' ? trim($expectText) : null,
            'max_ms' => $maxMs,
            'timeout' => $timeout,
        ], static fn ($value) => $value !== null);

        $this->storeJourneys($journeys, 'Synthetic journey added', $name);

        return ['ok' => true, 'error' => null];
    }

    public function removeJourney(string $name): bool
    {
        $journeys = $this->journeys();
        $remaining = array_values(array_filter(
            $journeys,
            static fn (array $journey): bool => ($journey['name'] ?? null) !== $name,
        ));

        if (count($remaining) === count($journeys)) {
            return false;
        }

        $this->storeJourneys($remaining, 'Synthetic journey removed', $name);

        return true;
    }

    /** @param array<int, array<string, mixed>> $journeys */
    private function storeJourneys(array $journeys, string $title, string $name): void
    {
        $this->settings->put('synthetics', $journeys);
        $this->settings->forget();

        $this->audit->record(
            action: 'monitoring.synthetics_updated',
            subject: ['type' => 'synthetic', 'id' => $name],
            after: ['journeys' => array_column($journeys, 'name')],
        );

        $this->events->record(
            type: EventLog::CONFIG,
            severity: EventLog::INFO,
            title: $title,
            key: $name,
            context: ['journeys' => count($journeys)],
        );
    }

    /** Only what the running code reads back — the same rule SettingsPanel reports by. */
    public static function isWritable(string $key): bool
    {
        return str_starts_with($key, 'thresholds.')
            || str_starts_with($key, 'energy.')
            || str_starts_with($key, 'retention.');
    }

    private function isStored(string $key): bool
    {
        return array_key_exists($key, $this->settings->all());
    }

    /** @return array{min: float, max: float} */
    private function boundsFor(string $key): array
    {
        foreach (self::BOUNDS as $suffix => $bounds) {
            if (str_ends_with($key, $suffix)) {
                return $bounds;
            }
        }

        if (str_ends_with($key, '_warning') || str_ends_with($key, '_critical') || str_ends_with($key, '_rate')) {
            return self::PERCENTAGE;
        }

        // An unrecognised shape is still bounded — an unbounded numeric setting is how a chart ends
        // up asking for a hundred million rows.
        return ['min' => 0, 'max' => 1000000];
    }
}
