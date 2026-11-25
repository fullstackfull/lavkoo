<?php

namespace App\Services\PlatformAdmin;

/**
 * One figure out of several currencies, and the refusal to make one without the rates that would justify it (L-08).
 *
 * SAR, AED, KWD, QAR, BHD and OMR are never added together by this code. A combined figure exists only when the owner has written down what to
 * convert into, the rate of every currency the window holds, where the rates came from and the day they are from; then the figure is sent WITH
 * that rate, that source and that date, so a reader can check it. Anything missing and there is no combined figure at all, only a reason naming
 * what is missing -- an unlabelled total of six currencies is worse than no total, because it looks like money.
 *
 * It ships with nothing configured (`gcc.platform_admin.reports.conversion`), so today every answer is a refusal. Writing rates into that
 * configuration is a finance decision and not a code change.
 */
final class PlatformSalesCombination
{
    public const NO_RULE = 'no_conversion_rule_recorded';

    public const NO_RATE = 'no_conversion_rate_for_every_currency';

    /**
     * @param list<array{currency: ?string, orders: int, gross: ?string}> $rows the priced rows of one market
     *
     * @return array{figure: array<string, mixed>|null, reason: ?string}
     */
    public function combine(array $rows): array
    {
        $rule = $this->rule();

        if ($rule === null) {
            return ['figure' => null, 'reason' => self::NO_RULE];
        }

        $currencies = [];

        foreach ($rows as $row) {
            if (is_string($row['currency']) && $row['currency'] !== '') {
                $currencies[$row['currency']] = ($currencies[$row['currency']] ?? 0.0) + (float) $row['gross'];
            }
        }

        // By currency, so the answer reads the same whatever order the engine grouped the rows in.
        ksort($currencies);
        $applied = [];
        $total = 0.0;

        foreach ($currencies as $currency => $gross) {
            $rate = $rule['rates'][$currency] ?? null;

            if (!is_numeric($rate) || (float) $rate <= 0.0) {
                return ['figure' => null, 'reason' => self::NO_RATE];
            }

            $applied[] = ['currency' => $currency, 'rate' => (string) $rate];
            $total += $gross * (float) $rate;
        }

        return [
            'figure' => [
                'currency' => $rule['currency'],
                'gross' => PlatformAdminPresenter::amount(sum: $total),
                'rate_source' => $rule['source'],
                'rate_date' => $rule['as_of'],
                'rates' => $applied,
            ],
            'reason' => null,
        ];
    }

    /**
     * What the owner wrote down: the currency to convert into, a rate for each currency, where the rates came from and the day they are from. A
     * rule missing any of the four is no rule.
     *
     * @return array{currency: string, source: string, as_of: string, rates: array<string, mixed>}|null
     */
    private function rule(): ?array
    {
        $configured = (array) config('gcc.platform_admin.reports.conversion', []);
        $currency = strtoupper(trim((string) ($configured['currency'] ?? '')));
        $source = trim((string) ($configured['source'] ?? ''));
        $asOf = trim((string) ($configured['as_of'] ?? ''));
        $rates = is_array($configured['rates'] ?? null) ? $configured['rates'] : [];

        if ($currency === '' || $source === '' || $asOf === '' || $rates === []) {
            return null;
        }

        $byCurrency = [];

        foreach ($rates as $code => $rate) {
            $byCurrency[strtoupper(trim((string) $code))] = $rate;
        }

        return ['currency' => $currency, 'source' => $source, 'as_of' => $asOf, 'rates' => $byCurrency];
    }
}
