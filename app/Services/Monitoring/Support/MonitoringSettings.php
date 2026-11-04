<?php

namespace App\Services\Monitoring\Support;

use Illuminate\Support\Facades\DB;

/**
 * Monitoring's own settings, with config/monitoring.php as the floor.
 *
 * Thresholds, retention windows and the electricity price are things an operator changes while
 * looking at a graph, not things worth a deploy — so the live value lives in the database and the
 * config file holds what a fresh install starts from. Reads are memoised for the life of the
 * process: the alert evaluator asks for a dozen thresholds per run and the panels ask for more,
 * and none of them should cost a query each.
 */
class MonitoringSettings
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = [];

        try {
            foreach (DB::connection(config('monitoring.connection'))->table('monitoring_settings')->get() as $row) {
                $stored[$row->key] = $this->cast($row->value, $row->type);
            }
        } catch (\Throwable) {
            // No table yet (migrations not run) means "defaults only", not an error page.
            $stored = [];
        }

        return $this->cache = $stored;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->all();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return config('monitoring.' . $key, $default);
    }

    /** A threshold from Settings, falling back to the shipped default. */
    public function threshold(string $name, ?float $default = null): ?float
    {
        $value = $this->get('thresholds.' . $name, config('monitoring.thresholds.' . $name, $default));

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * A retention window in days, from Settings, falling back to the shipped default.
     *
     * Retention used to be read straight from config() by the panels and the rollup, which meant a
     * stored row was saved and then ignored — and the migration that created the settings table
     * promised the opposite. Everything that prunes or explains a window asks here now, so changing
     * one on the Settings page changes what is actually kept.
     */
    public function retentionDays(string $kind, int $default): int
    {
        $value = $this->get('retention.' . $kind, config('monitoring.retention.' . $kind, $default));

        return max(1, (int) $value);
    }

    public function put(string $key, mixed $value): void
    {
        [$type, $encoded] = $this->encode($value);

        DB::connection(config('monitoring.connection'))->table('monitoring_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $encoded, 'type' => $type, 'updated_at' => Clock::stamp(), 'created_at' => Clock::stamp()],
        );

        $this->cache = null;
    }

    /**
     * Drop a stored override, so the shipped default takes over again.
     *
     * Deleted rather than stored as null: `get()` treats a present key as the answer, so a null row
     * would read back as "the setting is null" instead of "nobody has overridden this".
     */
    public function clear(string $key): void
    {
        DB::connection(config('monitoring.connection'))->table('monitoring_settings')->where('key', $key)->delete();

        $this->cache = null;
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    private function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }

    /** @return array{0: string, 1: string|null} */
    private function encode(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['bool', $value ? '1' : '0'],
            is_int($value) => ['int', (string) $value],
            is_float($value) => ['float', (string) $value],
            is_array($value) => ['json', json_encode($value)],
            default => ['string', $value === null ? null : (string) $value],
        };
    }
}
