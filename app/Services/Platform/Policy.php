<?php

namespace App\Services\Platform;

use App\Models\BusinessSetting;
use App\Services\AuditLogger;

/**
 * Reads and writes the rules declared in PolicyRegistry.
 *
 * Three behaviours matter, and all three exist because these values drive decisions rather than
 * decorate a page.
 *
 * A value is clamped to its declared bounds rather than obeyed, because the bounds are what keep it
 * usable: a zero-hour deadline marks every order late the instant it arrives, and a zero-attempt
 * webhook policy loses every event silently.
 *
 * An unusable value falls back to the shipped default rather than to zero or null, so a half-written
 * settings row degrades to today's behaviour instead of to a new and undeclared one.
 *
 * And a write is audited with its before and after, because "who lowered the password minimum" is a
 * question a marketplace eventually has to answer.
 */
class Policy
{
    /** Values already read this request. These are read inside loops over products and orders. */
    private array $resolved = [];

    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $definition = PolicyRegistry::definition($key);

        if ($definition === null) {
            throw new \InvalidArgumentException('Unknown policy: ' . $key);
        }

        return $this->resolved[$key] = $this->cast($this->stored($key), $definition);
    }

    /**
     * Whether an operator has actually set this, as opposed to it falling back to the default.
     *
     * The distinction matters wherever a setting has a second source. The developer-portal switches
     * are configurable by environment as well as here, and precedence has to be stored-then-env-
     * then-default — without this, an install that turns the console off in .env would have it
     * turned back on by a default nobody chose.
     */
    public function isSet(string $key): bool
    {
        $stored = $this->stored($key);

        return $stored !== null && $stored !== '';
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    /** @return array<string, mixed> every policy, or every policy in one group */
    public function all(?string $group = null): array
    {
        $keys = $group === null ? array_keys(PolicyRegistry::definitions()) : PolicyRegistry::keysIn($group);

        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Save a set of policies, recording what changed.
     *
     * Only declared keys are written and only changed ones are recorded, so an operator who opens the
     * page and saves without editing leaves no audit noise behind.
     *
     * @param array<string, mixed> $values
     * @return array<string, array{from: mixed, to: mixed}> what actually changed
     */
    public function save(array $values): array
    {
        $changes = [];

        foreach ($values as $key => $value) {
            $definition = PolicyRegistry::definition($key);

            if ($definition === null) {
                continue;
            }

            $before = $this->get($key);
            $after = $this->cast($value, $definition);

            if ($before === $after) {
                continue;
            }

            BusinessSetting::updateOrCreate(['type' => $key], ['value' => $this->store($after, $definition)]);
            unset($this->resolved[$key]);
            $changes[$key] = ['from' => $before, 'to' => $after];
        }

        if ($changes !== []) {
            cache()->flush();
            $this->resolved = [];
            $this->audit?->record(
                action: 'platform.policy_updated',
                before: array_map(static fn (array $change) => $change['from'], $changes),
                after: array_map(static fn (array $change) => $change['to'], $changes),
            );
        }

        return $changes;
    }

    /**
     * Laravel validation rules for a group, built from the declarations.
     *
     * Generated rather than written beside the form: a form that accepted what the reader clamps
     * would save one number and apply another, which is the failure this whole registry exists to
     * remove.
     *
     * @return array<string, string>
     */
    public function rules(?string $group = null): array
    {
        $keys = $group === null ? array_keys(PolicyRegistry::definitions()) : PolicyRegistry::keysIn($group);
        $rules = [];

        foreach ($keys as $key) {
            $definition = PolicyRegistry::definitions()[$key];

            $rules[$key] = match ($definition['type']) {
                'int' => "required|integer|min:{$definition['min']}|max:{$definition['max']}",
                'decimal', 'ratio' => "required|numeric|min:{$definition['min']}|max:{$definition['max']}",
                'toggle' => 'required|boolean',
                'time' => 'required|date_format:H:i',
                'choice' => 'required|in:' . implode(',', $definition['options'] ?? []),
                'multi_choice' => 'array',
                default => 'required',
            };

            if ($definition['type'] === 'multi_choice') {
                $rules[$key . '.*'] = 'in:' . implode(',', $definition['options'] ?? []);
            }
        }

        return $rules;
    }

    private function stored(string $key): mixed
    {
        try {
            return getWebConfig(name: $key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cast(mixed $value, array $definition): mixed
    {
        $default = $definition['default'];

        return match ($definition['type']) {
            'int' => (int) $this->bounded($value, $definition, static fn ($raw) => (int) $raw),
            'decimal', 'ratio' => (float) $this->bounded($value, $definition, static fn ($raw) => (float) $raw),
            'toggle' => $value === null || $value === '' ? (bool) $default : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'multi_choice' => $this->choices($value, $definition),
            'choice' => in_array($value, $definition['options'] ?? [], true) ? $value : $default,
            'time' => is_string($value) && preg_match('/^\d{1,2}:\d{2}$/', $value) ? $value : $default,
            default => $value === null || $value === '' ? $default : $value,
        };
    }

    private function bounded(mixed $value, array $definition, callable $to): int|float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $definition['default'];
        }

        return max($definition['min'], min($definition['max'], $to($value)));
    }

    /** @return array<int, string> */
    private function choices(mixed $value, array $definition): array
    {
        $options = $definition['options'] ?? [];

        if (is_string($value)) {
            $value = json_decode($value, true) ?? explode(',', $value);
        }

        if (!is_array($value)) {
            return $definition['default'];
        }

        $chosen = array_values(array_intersect(array_map('strval', $value), $options));

        // An empty selection is a policy that can never be satisfied — every order uneditable, every
        // status non-cancellable — so it falls back rather than locking the marketplace out of itself.
        return $chosen === [] ? $definition['default'] : $chosen;
    }

    private function store(mixed $value, array $definition): string
    {
        return match ($definition['type']) {
            'multi_choice' => json_encode(array_values($value)),
            'toggle' => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
