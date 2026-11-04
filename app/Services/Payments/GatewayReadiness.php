<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;

/**
 * Whether a gateway that is switched on can actually take a payment.
 *
 * Credentials live in `addon_settings` as two separate blobs — `live_values` and `test_values` — and
 * every controller reads only the one matching the row's `mode`. So a shop can show a green, fully
 * filled-in gateway on the admin screen and refuse every payment at checkout, because the keys were
 * typed into the mode that is switched off. Nothing about that is visible from the form.
 *
 * The check existed as `php artisan payment:check` and nowhere a merchant would look. This is the
 * same judgement, in a service both the command and the payment-methods screen read, so the shell
 * and the page can never disagree about which gateway is broken.
 */
class GatewayReadiness
{
    /** Fields that are structure, not credentials — blank ones here are not a fault. */
    private const NON_CREDENTIAL_FIELDS = ['gateway', 'mode', 'status'];

    public const READY = 'ready';

    /**
     * Every configured gateway with its verdict.
     *
     * @return array<int, array{gateway: string, active: bool, mode: ?string, ready: bool, verdict: string, rehearsing: bool}>
     */
    public function all(?string $gateway = null): array
    {
        try {
            $rows = DB::table('addon_settings')
                ->where('settings_type', 'payment_config')
                ->when($gateway !== null, fn ($query) => $query->where('key_name', $gateway))
                ->orderBy('key_name')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(function (object $row): array {
            $verdict = $this->verdict($row);
            $active = (int) ($row->is_active ?? 0) === 1;

            return [
                'gateway' => (string) $row->key_name,
                'active' => $active,
                'mode' => $row->mode,
                'ready' => $verdict === self::READY,
                'verdict' => $verdict,
                // Configured correctly and pointed at the wrong world. Not a fault — a shop is
                // legitimately in test mode while being set up — but it is the difference between
                // taking money and rehearsing, and nothing on the checkout says which is happening.
                'rehearsing' => $active && $row->mode === 'test' && $verdict === self::READY,
            ];
        })->all();
    }

    /**
     * Gateways a customer can reach that cannot take their money.
     *
     * @return array<int, array<string, mixed>>
     */
    public function broken(): array
    {
        return array_values(array_filter($this->all(), static fn (array $row): bool => $row['active'] && !$row['ready']));
    }

    /** @return array<int, string> */
    public function rehearsing(): array
    {
        return array_column(array_filter($this->all(), static fn (array $row): bool => $row['rehearsing']), 'gateway');
    }

    public function verdict(object $row): string
    {
        $column = $this->columnInForce($row->mode);

        if ($column === null) {
            return 'mode is ' . var_export($row->mode, true) . ' — expected "live" or "test", so no column is read';
        }

        $values = json_decode((string) ($row->$column ?? ''), true);

        if (!is_array($values) || $values === []) {
            return $column . ' is empty or not valid JSON';
        }

        $blank = [];
        foreach ($values as $field => $value) {
            if (in_array($field, self::NON_CREDENTIAL_FIELDS, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                $blank[] = $field;
            }
        }

        if ($blank === []) {
            return self::READY;
        }

        $other = $column === 'live_values' ? 'test_values' : 'live_values';

        // The whole reason this check exists: it turns "the fields look filled in on my screen" into
        // "they are, in the column your gateway is not reading".
        return $column . ' has no ' . implode(', ', $blank)
            . ($this->hasValues($row->$other ?? null, $blank)
                ? " — but {$other} does. The credentials are saved under the mode that is switched off."
                : '');
    }

    public function columnInForce(?string $mode): ?string
    {
        return match ($mode) {
            'live' => 'live_values',
            'test' => 'test_values',
            default => null,
        };
    }

    /** @param array<int, string> $fields */
    private function hasValues(mixed $json, array $fields): bool
    {
        $values = json_decode((string) $json, true);

        if (!is_array($values)) {
            return false;
        }

        foreach ($fields as $field) {
            if (!empty($values[$field])) {
                return true;
            }
        }

        return false;
    }
}
