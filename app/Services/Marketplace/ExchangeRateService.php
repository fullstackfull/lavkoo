<?php

namespace App\Services\Marketplace;

use App\Models\Currency;
use App\Models\ExchangeRateLog;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange-rate governance over the existing currencies (Phase 3, Stage E).
 *
 * This does not convert prices or redefine currencies — the platform's helpers already do that. It
 * governs the *rate*: every change goes through `updateRate()`, which writes the new rate onto the
 * existing `currencies.exchange_rate` AND records the before/after in an append-only log with who and
 * when. A no-op change (same rate) records nothing. `bulkUpdate()` is the same, applied to many at
 * once, so an FX move across every currency is one auditable action.
 */
class ExchangeRateService
{
    public function __construct(private readonly ?AuditLogger $audit = null)
    {
    }

    /**
     * Set one currency's rate, logging the change. A rate that does not actually change is a no-op.
     *
     * @return array{ok: bool, reason?: string, changed?: bool}
     */
    public function updateRate(int|string $currencyId, float $newRate, int|string|null $by = null, string $source = 'manual'): array
    {
        if (!Schema::hasTable('currencies')) {
            return ['ok' => false, 'reason' => 'currencies_unavailable'];
        }
        if ($newRate <= 0) {
            return ['ok' => false, 'reason' => 'rate_must_be_positive'];
        }

        return DB::transaction(function () use ($currencyId, $newRate, $by, $source) {
            $currency = Currency::where('id', $currencyId)->lockForUpdate()->first();
            if (!$currency) {
                return ['ok' => false, 'reason' => 'currency_not_found'];
            }

            $old = (float) $currency->exchange_rate;
            $new = round($newRate, 6);
            if (abs($old - $new) < 0.0000005) {
                return ['ok' => true, 'changed' => false];   // nothing to record
            }

            $currency->exchange_rate = $new;
            $currency->save();

            if (Schema::hasTable('exchange_rate_logs')) {
                ExchangeRateLog::create([
                    'currency_id' => $currency->id,
                    'code' => $currency->code,
                    'old_rate' => $old,
                    'new_rate' => $new,
                    'source' => $source,
                    'changed_by' => $by,
                ]);
            }

            $this->audit?->record(
                action: 'currency.rate_changed',
                subject: ['type' => 'currency', 'id' => $currency->id],
                before: ['exchange_rate' => $old],
                after: ['exchange_rate' => $new, 'code' => $currency->code],
            );

            return ['ok' => true, 'changed' => true];
        });
    }

    /**
     * Apply several rates at once, keyed by currency id. Returns how many actually changed.
     *
     * @param array<int|string, float> $rates
     */
    public function bulkUpdate(array $rates, int|string|null $by = null): int
    {
        $changed = 0;
        foreach ($rates as $currencyId => $rate) {
            if ($rate === null || $rate === '') {
                continue;
            }
            $result = $this->updateRate($currencyId, (float) $rate, $by, 'bulk');
            if (($result['ok'] ?? false) && ($result['changed'] ?? false)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Convert an amount between two currency codes using the governed `currencies.exchange_rate`
     * (each rate is relative to USD, so the ratio to/from cancels the USD base).
     *
     * Fails safe: identical codes, a missing currencies table, or a non-positive rate returns the
     * amount unchanged at rate 1 — a payout must never be mis-scaled by a bad rate.
     *
     * @return array{amount: float, rate: float, from: string, to: string}
     */
    public function convert(float $amount, string $fromCode, string $toCode): array
    {
        $from = strtoupper(trim($fromCode));
        $to = strtoupper(trim($toCode));

        if ($from === '' || $to === '' || $from === $to || !Schema::hasTable('currencies')) {
            return ['amount' => round($amount, 4), 'rate' => 1.0, 'from' => $from, 'to' => $to];
        }

        $fromRate = (float) (Currency::where('code', $from)->value('exchange_rate') ?? 0);
        $toRate = (float) (Currency::where('code', $to)->value('exchange_rate') ?? 0);
        if ($fromRate <= 0 || $toRate <= 0) {
            return ['amount' => round($amount, 4), 'rate' => 1.0, 'from' => $from, 'to' => $to];
        }

        $rate = $toRate / $fromRate;

        return ['amount' => round($amount * $rate, 4), 'rate' => round($rate, 8), 'from' => $from, 'to' => $to];
    }

    /** The change history for one currency (or all), newest first. */
    public function history(int|string|null $currencyId = null, int $limit = 50)
    {
        if (!Schema::hasTable('exchange_rate_logs')) {
            return collect();
        }

        $query = ExchangeRateLog::orderByDesc('id');
        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->limit($limit)->get();
    }
}
