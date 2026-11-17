<?php

namespace App\Services\PlatformAdmin;

use App\Exceptions\PlatformAdminApiException;
use App\Services\Storefront\StorefrontLegalReadiness;

/**
 * The alerts of the markets, derived at the moment they are asked for from signals that already exist: the storefront's legal gate, the stock
 * of live products and orders that have stayed pending. Nothing is stored, so an alert goes away when its cause does, and the same alert has the
 * same id every time. An alert says a market, a code and a count, never a name or anything a person wrote.
 *
 *  - `legal_not_ready` (critical): a market that is active, while storefronts are on, whose legal documents are not approved. The storefront
 *    refuses every sale until they are.
 *  - `stock_zero` (warning, critical): live products at zero stock, at the configured counts.
 *  - `orders_pending_too_long` (info, warning, critical): orders still pending after the configured age, created within the longest window.
 */
final class PlatformAlertService
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    private const SEVERITY_RANK = ['info' => 1, 'warning' => 2, 'critical' => 3];

    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly PlatformMarketFigures $figures,
        private readonly PlatformAdminSettings $settings,
    ) {
    }

    /**
     * The severity asked for, lower-cased, or null for every severity.
     *
     * @throws PlatformAdminApiException
     */
    public function severityOf(mixed $given): ?string
    {
        if ($given === null) {
            return null;
        }

        $severity = is_string($given) ? strtolower(trim($given)) : '';

        return in_array($severity, self::SEVERITIES, true) ? $severity : throw PlatformAdminApiException::invalidSeverity(self::SEVERITIES);
    }

    /**
     * The alerts of the given markets, worst first, then by market in the order of the configuration, then by code.
     *
     * @param list<string> $selected the market codes to raise alerts for
     * @param list<array{market: ?string, zero: int, low: int}>|null $stock the stock figures, when the caller already has them
     *
     * @return list<array{id: string, severity: string, market: string, code: string, message: string, deep_link: string, generated_at: string}>
     */
    public function forMarkets(array $selected, ?array $stock = null): array
    {
        $generatedAt = (string) PlatformAdminPresenter::timestamp(now());
        $zeroStock = $this->countsByMarket(rows: $stock ?? $this->figures->stock(), column: 'zero');
        $stuckOrders = $this->countsByMarket(rows: $this->stuckPendingOrders(), column: 'orders');
        $alerts = [];

        foreach ($this->markets->all() as $code => $market) {
            if (!in_array($code, $selected, true)) {
                continue;
            }

            $candidates = [
                $this->legalAlert(code: $code, active: $market['active']),
                $this->stockAlert(code: $code, zero: $zeroStock[$code] ?? 0),
                $this->pendingAlert(code: $code, stuck: $stuckOrders[$code] ?? 0),
            ];

            foreach (array_filter($candidates) as [$severity, $alertCode, $message]) {
                $alerts[] = [
                    'id' => substr(sha1("{$alertCode}|{$code}"), 0, 16),
                    'severity' => $severity,
                    'market' => $code,
                    'code' => $alertCode,
                    'message' => $message,
                    'deep_link' => "markets/{$code}",
                    'generated_at' => $generatedAt,
                ];
            }
        }

        return $this->worstFirst(alerts: $alerts, marketOrder: array_flip($this->markets->codes()));
    }

    /**
     * @param list<array<string, mixed>> $alerts
     *
     * @return array<string, array{critical: int, warning: int, info: int}> by market
     */
    public function countsBySeverity(array $alerts): array
    {
        $counts = [];

        foreach ($alerts as $alert) {
            $counts[$alert['market']] ??= ['critical' => 0, 'warning' => 0, 'info' => 0];
            $counts[$alert['market']][$alert['severity']]++;
        }

        return $counts;
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function legalAlert(string $code, bool $active): ?array
    {
        if (!$this->markets->storefrontsEnabled() || !$active || $this->legalReadiness->isReady($code)) {
            return null;
        }

        return ['critical', 'legal_not_ready', "The {$code} storefront is active but its legal documents are not approved, so it cannot take orders."];
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function stockAlert(string $code, int $zero): ?array
    {
        $severity = match (true) {
            $zero >= $this->settings->zeroStockCriticalCount() => 'critical',
            $zero >= $this->settings->zeroStockWarningCount() => 'warning',
            default => null,
        };

        return $severity === null ? null : [$severity, 'stock_zero', sprintf('%d live %s in %s %s out of stock.', $zero, $zero === 1 ? 'product' : 'products', $code, $zero === 1 ? 'is' : 'are')];
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function pendingAlert(string $code, int $stuck): ?array
    {
        $severity = match (true) {
            $stuck >= $this->settings->pendingCriticalCount() => 'critical',
            $stuck >= $this->settings->pendingWarningCount() => 'warning',
            $stuck >= 1 => 'info',
            default => null,
        };
        $hours = $this->settings->pendingAgeHours();

        return $severity === null ? null : [$severity, 'orders_pending_too_long', sprintf('%d %s in %s %s been pending for more than %d %s.', $stuck, $stuck === 1 ? 'order' : 'orders', $code, $stuck === 1 ? 'has' : 'have', $hours, $hours === 1 ? 'hour' : 'hours')];
    }

    /** @return list<array{market: ?string, status: string, orders: int, latest_at: ?string}> */
    private function stuckPendingOrders(): array
    {
        $lookback = PlatformWindow::lastDays($this->settings->windowDaysMax());

        return $this->figures->orders($lookback->startsAt(), now()->subHours($this->settings->pendingAgeHours()), ['pending']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int> the column added up for each market that has rows
     */
    private function countsByMarket(array $rows, string $column): array
    {
        $counts = [];

        foreach ($rows as $row) {
            if ($row['market'] !== null) {
                $counts[$row['market']] = ($counts[$row['market']] ?? 0) + $row[$column];
            }
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @param array<string, int> $marketOrder
     *
     * @return list<array<string, mixed>>
     */
    private function worstFirst(array $alerts, array $marketOrder): array
    {
        usort($alerts, static fn (array $left, array $right): int => [self::SEVERITY_RANK[$right['severity']], $marketOrder[$left['market']], $left['code']]
            <=> [self::SEVERITY_RANK[$left['severity']], $marketOrder[$right['market']], $right['code']]);

        return $alerts;
    }
}
