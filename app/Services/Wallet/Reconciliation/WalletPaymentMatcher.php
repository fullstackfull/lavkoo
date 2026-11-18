<?php

namespace App\Services\Wallet\Reconciliation;

use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;

/**
 * Ties each wallet debit that paid for an order to the orders it paid for, by customer, amount and time, so that a debit nobody can explain (or an
 * order paid from the wallet that no debit paid) is counted. One checkout debits the total of every order of its group at once, so the orders of a group
 * are added up before they are compared. Reads in pages of customers, never lists a debit or an order, and reports counts and sums only.
 */
final class WalletPaymentMatcher
{
    private const PAGE = 400;

    private const WALLET_METHODS = "'pay_by_wallet', 'wallet'";

    /** Added to a tolerance so that a gap of exactly the tolerance is not lost to the last bit of a float. */
    private const EPSILON = 0.000001;

    private const SECTION = 'wallet';

    private const NAME = 'wallet payments matched to the orders they paid';

    public function check(ReadOnlyProbe $probe, int $snapshotId, ReconciliationOptions $options): CompatibilityCheck
    {
        $orders = $probe->columns('orders');
        $ledger = $probe->columns('wallet_transactions');
        foreach (['orders' => [$orders, ['id', 'customer_id', 'payment_method', 'order_amount', 'created_at']], 'wallet_transactions' => [$ledger, ['id', 'user_id', 'debit', 'transaction_type', 'created_at']]] as $table => [$columns, $required]) {
            $absent = array_values(array_filter($required, static fn (string $column): bool => !isset($columns[$column])));
            if ($absent !== []) {
                return new CompatibilityCheck(self::SECTION, self::NAME, CompatibilityCheck::WARN, "the table {$table} has no column " . implode(', ', $absent) . ': the debits cannot be matched', []);
            }
        }

        $group = isset($orders['order_group_id']) ? 'COALESCE(order_group_id, id)' : 'id';
        $methods = self::WALLET_METHODS;

        $debits = $probe->select("SELECT COUNT(*) AS c FROM wallet_transactions WHERE transaction_type = 'order_place' AND debit > 0 AND user_id IS NOT NULL AND id <= ?", [$snapshotId])[0];
        $groups = $probe->select("SELECT COUNT(*) AS c FROM (SELECT customer_id, {$group} AS grp FROM orders WHERE payment_method IN ({$methods}) AND customer_id IS NOT NULL GROUP BY customer_id, {$group}) g")[0];

        $matchedDebits = 0;
        $matchedAmount = 0.0;
        $lastUser = 0;

        while (true) {
            $users = array_map(static fn (array $row): int => (int) $row['user_id'], $probe->select(
                "SELECT DISTINCT user_id FROM wallet_transactions WHERE transaction_type = 'order_place' AND debit > 0 AND id <= ? AND user_id > ? ORDER BY user_id LIMIT " . self::PAGE,
                [$snapshotId, $lastUser],
            ));
            if ($users === []) {
                break;
            }
            $lastUser = max($users);
            $marks = implode(', ', array_fill(0, count($users), '?'));

            $debitRows = $probe->select(
                "SELECT user_id, debit, created_at FROM wallet_transactions WHERE transaction_type = 'order_place' AND debit > 0 AND id <= ? AND user_id IN ({$marks}) ORDER BY user_id, created_at, id",
                [$snapshotId, ...$users],
            );
            $groupRows = $probe->select(
                "SELECT customer_id, SUM(order_amount) AS total, MIN(created_at) AS first_at FROM orders
                 WHERE payment_method IN ({$methods}) AND customer_id IN ({$marks}) GROUP BY customer_id, {$group}",
                $users,
            );

            [$pageMatched, $pageAmount] = $this->match(debits: $debitRows, groups: $groupRows, options: $options);
            $matchedDebits += $pageMatched;
            $matchedAmount += $pageAmount;
        }

        $unmatchedDebits = (int) $debits['c'] - $matchedDebits;
        $unmatchedGroups = (int) $groups['c'] - $matchedDebits;

        return new CompatibilityCheck(
            self::SECTION,
            self::NAME,
            $unmatchedDebits === 0 && $unmatchedGroups === 0 ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            sprintf(
                '%d of %d order debits match an order group (customer, amount within %s, time within %d s); %d debits and %d wallet-paid order groups have no counterpart',
                $matchedDebits,
                (int) $debits['c'],
                $options->amountTolerance,
                $options->windowSeconds,
                $unmatchedDebits,
                $unmatchedGroups,
            ),
            [
                'debits' => (int) $debits['c'],
                'matched_debits' => $matchedDebits,
                'unmatched_debits' => $unmatchedDebits,
                'wallet_order_groups' => (int) $groups['c'],
                'unmatched_groups' => $unmatchedGroups,
                'matched_amount' => $this->plain($matchedAmount),
            ],
        );
    }

    /**
     * Greedy matching inside one page of customers: each debit takes the unused order group of its customer that is closest in time and within both tolerances.
     *
     * @param list<array<string, mixed>> $debits
     * @param list<array<string, mixed>> $groups
     * @return array{0: int, 1: float} debits matched (each took one order group), the amount they cover
     */
    private function match(array $debits, array $groups, ReconciliationOptions $options): array
    {
        $tolerance = (float) $options->amountTolerance + self::EPSILON;

        $unused = [];
        foreach ($groups as $index => $group) {
            $unused[(int) $group['customer_id']][$index] = ['total' => (float) $group['total'], 'at' => $this->seconds($group['first_at'])];
        }

        $matched = 0;
        $amount = 0.0;
        foreach ($debits as $debit) {
            $owner = (int) $debit['user_id'];
            $paid = (float) $debit['debit'];
            $at = $this->seconds($debit['created_at']);
            $closest = null;
            $closestGap = null;

            foreach ($unused[$owner] ?? [] as $index => $candidate) {
                $gap = $at === null || $candidate['at'] === null ? 0 : abs($at - $candidate['at']);
                if (abs($candidate['total'] - $paid) > $tolerance || $gap > $options->windowSeconds) {
                    continue;
                }
                if ($closestGap === null || $gap < $closestGap) {
                    $closest = $index;
                    $closestGap = $gap;
                }
            }

            if ($closest !== null) {
                unset($unused[$owner][$closest]);
                $matched++;
                $amount += $paid;
            }
        }

        return [$matched, $amount];
    }

    private function plain(float $amount): string
    {
        $text = rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');

        return in_array($text, ['', '-0'], true) ? '0' : $text;
    }

    private function seconds(mixed $time): ?int
    {
        $seconds = is_string($time) && $time !== '' ? strtotime($time) : false;

        return $seconds === false ? null : $seconds;
    }
}
