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
 *  - `stock_oversold` (info, warning, critical): live products whose stock has gone PAST zero. One is already information, because stock below
 *    zero is not a state a correct shop reaches -- it is a sale that should have been refused.
 *  - `orders_pending_too_long` (info, warning, critical): orders still pending after the configured age, created within the longest window.
 *  - the seven health signals of L-09 (info, warning, critical), each with a code of its own: `payments_stuck`,
 *    `payments_unreconciled`, `channels_failing`, `carriers_failing`, `books_need_a_person`, `shop_issues_open` and
 *    `approvals_pending`. The ACCOUNTING signal is the one the queue has stopped on, not the one it is still working: a queued entry is not
 *    a fault and an alert for it would be noise an operator learns to ignore.
 *
 * **The health alerts are gated by the health capability, not by this service.** They are raised only when the caller HANDS IN the health
 * figures, and the alerts endpoint hands them in only for an operator who holds `health`. That is deliberate: those figures are payment and
 * integration failures, which is exactly what the separate capability exists to keep from an operator who is not trusted with them, and an
 * alert that said "14 stuck payment attempts in KW" would hand over through this endpoint what the health endpoint refuses. One signal gets
 * ONE code and one pair of configured counts, so a ruling on any threshold changes configuration and not code.
 *
 * Each signal keeps its own code rather than being folded into a shared "integrations" one. The health service counts a failing channel
 * resource and a failing carrier capability by different tests and says so beside each figure; adding them together here would undo that in
 * the one place an operator is most likely to act on the number.
 */
final class PlatformAlertService
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    private const SEVERITY_RANK = ['info' => 1, 'warning' => 2, 'critical' => 3];

    /**
     * The health signals that raise an alert: the code, where the figure sits in a market's health, and what one row of it is called.
     *
     * The code is also the settings key of its two counts, so a signal added here needs no second table to be configurable.
     */
    private const HEALTH_SIGNALS = [
        'payments_stuck' => ['at' => ['payments', 'stuck_attempts'], 'one' => 'payment attempt', 'many' => 'payment attempts', 'doing' => 'been started and never confirmed'],
        'payments_unreconciled' => ['at' => ['payments', 'reconciliation_breaks'], 'one' => 'cost reconciliation', 'many' => 'cost reconciliations', 'doing' => 'not been settled'],
        'channels_failing' => ['at' => ['integrations', 'channels', 'failing_resources'], 'one' => 'sales-channel resource', 'many' => 'sales-channel resources', 'doing' => 'been failing'],
        'carriers_failing' => ['at' => ['integrations', 'carriers', 'failing_capabilities'], 'one' => 'carrier capability', 'many' => 'carrier capabilities', 'doing' => 'failed at its last attempt'],
        'books_need_a_person' => ['at' => ['integrations', 'books', 'postings_needing_a_person'], 'one' => 'accounting entry', 'many' => 'accounting entries', 'doing' => 'stopped and need a decision'],
        'shop_issues_open' => ['at' => ['issues', 'open_shop_issues'], 'one' => 'shop issue', 'many' => 'shop issues', 'doing' => 'been left open'],
        'approvals_pending' => ['at' => ['issues', 'pending_approvals'], 'one' => 'approval request', 'many' => 'approval requests', 'doing' => 'been left undecided'],
    ];

    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly PlatformMarketFigures $figures,
        private readonly PlatformAdminSettings $settings,
    ) {
    }

    /**
     * The health signals that raise an alert, by code and in order.
     *
     * Public so that the list can be pinned by a test without seven table worlds being built to make each figure non-zero: what is worth
     * guarding is that an eighth signal gets a code and a pair of counts of its own rather than being folded into another, and that is a
     * question about this list and not about any figure.
     *
     * @return list<string>
     */
    public function healthSignalCodes(): array
    {
        return array_keys(self::HEALTH_SIGNALS);
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

        return in_array($severity, self::SEVERITIES, true) ? $severity : throw PlatformAdminApiException::invalidSeverity(valid: self::SEVERITIES);
    }

    /**
     * The alerts of the given markets, worst first, then by market in the order of the configuration, then by code.
     *
     * @param list<string> $selected the market codes to raise alerts for
     * @param list<array{market: ?string, zero: int, low: int}>|null $stock the stock figures, when the caller already has them
     * @param list<array{market: ?string, status: string, stuck: int}>|null $backlog the backlog figures, when the caller already has them
     * @param list<array<string, mixed>>|null $health a market's health figures as `PlatformHealthService` builds them, for a caller that holds the
     *                                                `health` capability. NULL raises no health alert at all, which is what an operator without
     *                                                that capability gets: the figures behind those alerts are the ones it exists to withhold.
     *
     * @return list<array{id: string, severity: string, market: string, code: string, message: string, deep_link: string, generated_at: string}>
     */
    public function forMarkets(array $selected, ?array $stock = null, ?array $backlog = null, ?array $health = null): array
    {
        $generatedAt = (string) PlatformAdminPresenter::timestamp(moment: now());
        $stockRows = $stock ?? $this->figures->stock();
        $zeroStock = $this->countsByMarket(rows: $stockRows, column: 'zero');
        $oversold = $this->countsByMarket(rows: $stockRows, column: 'oversold');
        $pendingRows = array_filter($backlog ?? $this->figures->backlog(), static fn (array $row): bool => $row['status'] === 'pending');
        $stuckOrders = $this->countsByMarket(rows: $pendingRows, column: 'stuck');
        $healthOfMarket = $this->healthByMarket(health: $health);
        $alerts = [];

        foreach ($this->markets->all() as $code => $market) {
            if (!in_array($code, $selected, true)) {
                continue;
            }

            $candidates = [
                $this->legalAlert(code: $code, active: $market['active']),
                $this->stockAlert(code: $code, zero: $zeroStock[$code] ?? 0),
                $this->oversoldAlert(code: $code, oversold: $oversold[$code] ?? 0),
                $this->pendingAlert(code: $code, stuck: $stuckOrders[$code] ?? 0),
                ...$this->healthAlerts(code: $code, health: $healthOfMarket[$code] ?? null),
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

    /**
     * Live products of the market whose stock has gone PAST zero: units were sold that were not there, and somebody has to pick an order that
     * cannot be picked.
     *
     * **One product is a warning and not information, which is why this is not `stock_zero` with a different number.** Zero stock is an ordinary
     * state of a shop -- a line sells out, and the configured counts say when that is worth waking somebody for. Stock BELOW zero is not a state a
     * correct shop reaches at all: it is a sale that should have been refused, and the first one is already a thing to look at. So it keeps its own
     * code, its own counts and a floor of one, exactly as the seven health signals each keep their own rather than being folded into a shared one.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function oversoldAlert(string $code, int $oversold): ?array
    {
        $severity = match (true) {
            $oversold >= $this->settings->oversoldCriticalCount() => 'critical',
            $oversold >= $this->settings->oversoldWarningCount() => 'warning',
            $oversold >= 1 => 'info',
            default => null,
        };

        return $severity === null ? null : [
            $severity,
            'stock_oversold',
            sprintf('%d live %s in %s %s sold below zero.', $oversold, $oversold === 1 ? 'product' : 'products', $code, $oversold === 1 ? 'has been' : 'have been'),
        ];
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

    /**
     * One alert per health signal that has reached its first row, worst level it qualifies for.
     *
     * The market's own figures are read by the path the signal names rather than by a flattened key, so a signal added to the health answer
     * is wired here by one line and a reader can see exactly which figure each alert speaks for.
     *
     * @param array<string, mixed>|null $health one market's health, or null when health alerts are not being raised at all
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function healthAlerts(string $code, ?array $health): array
    {
        if ($health === null) {
            return [];
        }

        $alerts = [];

        foreach (self::HEALTH_SIGNALS as $signal => $about) {
            $count = (int) data_get($health, implode('.', $about['at']), 0);
            $severity = match (true) {
                $count >= $this->settings->healthAlertCriticalCount(signal: $signal) => 'critical',
                $count >= $this->settings->healthAlertWarningCount(signal: $signal) => 'warning',
                $count >= 1 => 'info',
                default => null,
            };

            if ($severity !== null) {
                $alerts[] = [$severity, $signal, sprintf(
                    '%d %s in %s %s %s.',
                    $count,
                    $count === 1 ? $about['one'] : $about['many'],
                    $code,
                    $count === 1 ? 'has' : 'have',
                    $about['doing'],
                )];
            }
        }

        return $alerts;
    }

    /**
     * The health figures keyed by the market each belongs to.
     *
     * The unattributed row needs no filter here and gets none: the loop that raises alerts walks the CONFIGURED markets, and a row belonging
     * to no market matches none of them. An alert names a market, so a row in none cannot raise one without being put in a market it is not
     * in -- which is the whole reason the health answer keeps that row apart.
     *
     * @param list<array<string, mixed>>|null $health
     *
     * @return array<string, array<string, mixed>>
     */
    private function healthByMarket(?array $health): array
    {
        return $health === null ? [] : array_column($health, null, 'market');
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
