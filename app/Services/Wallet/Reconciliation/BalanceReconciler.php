<?php

namespace App\Services\Wallet\Reconciliation;

use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;

/**
 * Proves, on a restored copy and without writing, that the balance each customer carries equals the sum of the ledger behind it, and puts every
 * difference in a class (the discovery migration plan, sections 3.1 and 3.2):
 *
 *   A  ledger rows with no owner                 B  ledger rows of a user that no longer exists
 *   C  a NULL balance with no movement           D  float residue: a difference no larger than the tolerance
 *   E  a difference an operator explained with evidence (a file of user ids)
 *   F  unexplained: the one class that blocks a migration
 *
 * The comparison is made by the database over decimal columns, up to the highest ledger row that existed when the run began, and only counts and
 * sums are reported: no name, no contact detail, no row. A and B are rows that cannot be compared with any balance; they are counted, not judged.
 */
final class BalanceReconciler
{
    /** A tolerance reaches the database as text; SQLite ranks any number below any text, so it is made a number there before it is compared. */
    private const TOLERANCE = 'CAST(? AS DECIMAL(40, 20))';

    public function __construct(private readonly WalletPaymentMatcher $matcher)
    {
    }

    /** @return list<CompatibilityCheck> */
    public function checks(ReadOnlyProbe $probe, ReconciliationOptions $options): array
    {
        $checks = [];
        foreach ($options->ledgers as $ledger) {
            $missing = $this->missingStructure(probe: $probe, ledger: $ledger);
            if ($missing !== null) {
                $checks[] = new CompatibilityCheck($ledger->label, 'the ledger can be compared', CompatibilityCheck::FAIL, $missing);

                continue;
            }

            $snapshotId = (int) $probe->select("SELECT COALESCE(MAX(id), 0) AS snapshot FROM {$ledger->table}")[0]['snapshot'];

            array_push($checks, ...$this->ledgerChecks(probe: $probe, ledger: $ledger, options: $options, snapshotId: $snapshotId));

            if ($ledger->label === 'wallet') {
                array_push($checks, ...$this->walletOnlyChecks(probe: $probe, ledger: $ledger, options: $options, snapshotId: $snapshotId));
            }
        }

        return $checks;
    }

    private function missingStructure(ReadOnlyProbe $probe, LedgerSpec $ledger): ?string
    {
        $users = $probe->columns('users');
        $rows = $probe->columns($ledger->table);

        $absent = array_values(array_filter([...$ledger->netColumns, $ledger->ownerColumn, 'id'], static fn (string $column): bool => !isset($rows[$column])));

        return match (true) {
            $users === [] => 'the table users does not exist',
            !isset($users[$ledger->balanceColumn]) => "users has no column {$ledger->balanceColumn}",
            $rows === [] => "the table {$ledger->table} does not exist",
            $absent !== [] => "{$ledger->table} has no column " . implode(', ', $absent),
            default => null,
        };
    }

    /** @return list<CompatibilityCheck> */
    private function ledgerChecks(ReadOnlyProbe $probe, LedgerSpec $ledger, ReconciliationOptions $options, int $snapshotId): array
    {
        $label = $ledger->label;
        $table = $ledger->table;
        $owner = $ledger->ownerColumn;
        $net = $ledger->netExpression;
        $perUser = $this->perUserDifferences(ledger: $ledger);
        $tolerance = self::TOLERANCE;

        $ledgerRows = (int) $probe->select("SELECT COUNT(*) AS total FROM {$table} WHERE id <= ?", [$snapshotId])[0]['total'];

        $overall = $probe->select(
            "SELECT COUNT(*) AS users_compared,
                SUM(CASE WHEN d.diff = 0 THEN 1 ELSE 0 END) AS equal_count,
                SUM(CASE WHEN d.diff <> 0 AND ABS(d.diff) <= {$tolerance} THEN 1 ELSE 0 END) AS residue_count,
                SUM(CASE WHEN d.diff <> 0 AND ABS(d.diff) <= {$tolerance} THEN d.diff ELSE 0 END) AS residue_sum,
                SUM(CASE WHEN ABS(d.diff) > {$tolerance} THEN 1 ELSE 0 END) AS differing_count,
                SUM(CASE WHEN ABS(d.diff) > {$tolerance} THEN d.diff ELSE 0 END) AS differing_sum,
                SUM(CASE WHEN d.balance_is_null = 1 AND d.movements = 0 THEN 1 ELSE 0 END) AS null_without_movement,
                SUM(CASE WHEN d.balance_is_null = 1 AND d.movements > 0 THEN 1 ELSE 0 END) AS null_with_movement
             FROM ({$perUser}) d",
            [$options->tolerance, $options->tolerance, $options->tolerance, $options->tolerance, $snapshotId],
        )[0];

        $withoutOwner = $probe->select("SELECT COUNT(*) AS c, COALESCE(SUM({$net}), 0) AS s FROM {$table} WHERE {$owner} IS NULL AND id <= ?", [$snapshotId])[0];
        $ofMissingUser = $probe->select(
            "SELECT COUNT(*) AS c, COUNT(DISTINCT t.{$owner}) AS users, COALESCE(SUM({$net}), 0) AS s FROM {$table} t
             WHERE t.{$owner} IS NOT NULL AND t.id <= ? AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = t.{$owner})",
            [$snapshotId],
        )[0];

        $differing = (int) $overall['differing_count'];
        [$explainedCount, $unexplainedCount, $unexplainedSum] = $this->splitByEvidence(probe: $probe, perUser: $perUser, snapshotId: $snapshotId, differing: $differing, differingSum: $overall['differing_sum'], options: $options);

        $facts = fn (array $values): array => $values + ['snapshot_ledger_id' => $snapshotId];

        $checks = [
            new CompatibilityCheck($label, 'ledger and balances compared', CompatibilityCheck::INFO, sprintf('%d ledger rows up to row %d, %d users compared', $ledgerRows, $snapshotId, (int) $overall['users_compared']), $facts([
                'ledger_rows' => $ledgerRows,
                'users_compared' => (int) $overall['users_compared'],
                'equal' => (int) $overall['equal_count'],
            ])),
            new CompatibilityCheck($label, 'class A: ledger rows with no owner', CompatibilityCheck::INFO, sprintf('%d rows, net %s', (int) $withoutOwner['c'], $this->plain($withoutOwner['s'])), [
                'rows' => (int) $withoutOwner['c'],
                'net' => $this->plain($withoutOwner['s']),
            ]),
            new CompatibilityCheck($label, 'class B: ledger rows of a user that no longer exists', CompatibilityCheck::INFO, sprintf('%d rows of %d users, net %s', (int) $ofMissingUser['c'], (int) $ofMissingUser['users'], $this->plain($ofMissingUser['s'])), [
                'rows' => (int) $ofMissingUser['c'],
                'users' => (int) $ofMissingUser['users'],
                'net' => $this->plain($ofMissingUser['s']),
            ]),
            new CompatibilityCheck($label, 'class C: a NULL balance', CompatibilityCheck::INFO, sprintf('%d users with no movement; %d with movements (compared as zero, so they show below if they differ)', (int) $overall['null_without_movement'], (int) $overall['null_with_movement']), [
                'without_movement' => (int) $overall['null_without_movement'],
                'with_movement' => (int) $overall['null_with_movement'],
            ]),
            new CompatibilityCheck($label, 'class D: float residue', CompatibilityCheck::INFO, sprintf('%d users differ by no more than %s, together %s', (int) $overall['residue_count'], $options->tolerance, $this->plain($overall['residue_sum'])), [
                'users' => (int) $overall['residue_count'],
                'sum' => $this->plain($overall['residue_sum']),
                'tolerance' => $options->tolerance,
            ]),
            new CompatibilityCheck($label, 'class E: differences explained with evidence', CompatibilityCheck::INFO, sprintf('%d users with a difference are named in the evidence file', $explainedCount), ['users' => $explainedCount]),
            new CompatibilityCheck(
                $label,
                'class F: unexplained differences',
                $unexplainedCount === 0 ? CompatibilityCheck::PASS : CompatibilityCheck::FAIL,
                $unexplainedCount === 0
                    ? 'none: every balance equals its ledger, or its difference is explained'
                    : sprintf('%d users differ from their ledger by more than %s and are not explained (net %s): no migration may start', $unexplainedCount, $options->tolerance, $this->plain($unexplainedSum)),
                ['users' => $unexplainedCount, 'net' => $this->plain($unexplainedSum)] + $this->listed(probe: $probe, perUser: $perUser, snapshotId: $snapshotId, unexplained: $unexplainedCount, options: $options),
            ),
        ];

        return $checks;
    }

    /**
     * Splits the users that differ by more than the tolerance into the ones an operator explained and the ones nobody did.
     *
     * @return array{0: int, 1: int, 2: mixed} explained count, unexplained count, unexplained net difference
     */
    private function splitByEvidence(ReadOnlyProbe $probe, string $perUser, int $snapshotId, int $differing, mixed $differingSum, ReconciliationOptions $options): array
    {
        if ($options->explainedUserIds === [] || $differing === 0) {
            return [0, $differing, $differingSum];
        }

        $tolerance = self::TOLERANCE;
        $marks = implode(', ', array_fill(0, count($options->explainedUserIds), '?'));
        $unexplained = $probe->select(
            "SELECT COUNT(*) AS c, COALESCE(SUM(d.diff), 0) AS s FROM ({$perUser}) d WHERE ABS(d.diff) > {$tolerance} AND d.id NOT IN ({$marks})",
            [$snapshotId, $options->tolerance, ...$options->explainedUserIds],
        )[0];

        return [$differing - (int) $unexplained['c'], (int) $unexplained['c'], $unexplained['s']];
    }

    /**
     * The ids (never names) of the users with an unexplained difference, when the operator asked for them.
     *
     * @return array<string, mixed>
     */
    private function listed(ReadOnlyProbe $probe, string $perUser, int $snapshotId, int $unexplained, ReconciliationOptions $options): array
    {
        $limit = $options->listLimit();
        if ($limit === 0 || $unexplained === 0) {
            return [];
        }

        $tolerance = self::TOLERANCE;
        $exclusion = $options->explainedUserIds === [] ? '' : ' AND d.id NOT IN (' . implode(', ', array_fill(0, count($options->explainedUserIds), '?')) . ')';
        $rows = $probe->select(
            "SELECT d.id AS id FROM ({$perUser}) d WHERE ABS(d.diff) > {$tolerance}{$exclusion} ORDER BY ABS(d.diff) DESC, d.id LIMIT {$limit}",
            [$snapshotId, $options->tolerance, ...$options->explainedUserIds],
        );

        return ['listed_user_ids' => array_map(static fn (array $row): int => (int) $row['id'], $rows), 'listed_limit' => $limit];
    }

    /** One row per user: what the user carries minus what the ledger says, in the database's own arithmetic. A user without a row counts as zero. */
    private function perUserDifferences(LedgerSpec $ledger): string
    {
        return "SELECT u.id AS id,
                    (COALESCE(u.{$ledger->balanceColumn}, 0) - COALESCE(l.net, 0)) AS diff,
                    (CASE WHEN u.{$ledger->balanceColumn} IS NULL THEN 1 ELSE 0 END) AS balance_is_null,
                    COALESCE(l.movements, 0) AS movements
                FROM users u
                LEFT JOIN (SELECT {$ledger->ownerColumn} AS ledger_owner, SUM({$ledger->netExpression}) AS net, COUNT(*) AS movements
                           FROM {$ledger->table} WHERE id <= ? AND {$ledger->ownerColumn} IS NOT NULL GROUP BY {$ledger->ownerColumn}) l ON l.ledger_owner = u.id";
    }

    /** @return list<CompatibilityCheck> */
    private function walletOnlyChecks(ReadOnlyProbe $probe, LedgerSpec $ledger, ReconciliationOptions $options, int $snapshotId): array
    {
        $checks = [$this->duplicatePosRefunds(probe: $probe, ledger: $ledger, snapshotId: $snapshotId)];

        if ($options->matchOrders) {
            $checks[] = $this->matcher->check(probe: $probe, snapshotId: $snapshotId, options: $options);
        }

        $checks[] = $this->legacyTablesCheck(probe: $probe);

        return $checks;
    }

    /** A cancelled POS order is refunded once. Two refund rows with the same reference mean the customer was paid twice. */
    private function duplicatePosRefunds(ReadOnlyProbe $probe, LedgerSpec $ledger, int $snapshotId): CompatibilityCheck
    {
        $columns = $probe->columns($ledger->table);
        if (!isset($columns['transaction_type'], $columns['reference'])) {
            return new CompatibilityCheck($ledger->label, 'POS cancellations refunded more than once', CompatibilityCheck::WARN, "{$ledger->table} has no transaction_type or no reference column: the refunds cannot be compared", []);
        }

        $duplicates = $probe->select(
            "SELECT COUNT(*) AS references_repeated, COALESCE(SUM(c - 1), 0) AS extra_rows FROM (
                SELECT reference, COUNT(*) AS c FROM {$ledger->table}
                WHERE transaction_type = 'order_refund' AND reference LIKE 'pos_order_cancel_%' AND id <= ? GROUP BY reference HAVING COUNT(*) > 1) x",
            [$snapshotId],
        )[0];

        return new CompatibilityCheck(
            $ledger->label,
            'POS cancellations refunded more than once',
            (int) $duplicates['references_repeated'] === 0 ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            sprintf('%d cancellations have more than one refund row (%d extra rows)', (int) $duplicates['references_repeated'], (int) $duplicates['extra_rows']),
            ['cancellations' => (int) $duplicates['references_repeated'], 'extra_rows' => (int) $duplicates['extra_rows']],
        );
    }

    /** The legacy reward-point tables hold a second balance outside the ledger; the report says whether they hold anything. */
    private function legacyTablesCheck(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $wallets = $probe->hasTable('customer_wallets');
        $histories = $probe->hasTable('customer_wallet_histories');
        if (!$wallets && !$histories) {
            return new CompatibilityCheck('wallet', 'the legacy customer wallet tables', CompatibilityCheck::PASS, 'neither customer_wallets nor customer_wallet_histories exists', []);
        }

        $facts = ['customer_wallets_exists' => $wallets, 'customer_wallet_histories_exists' => $histories];
        if ($wallets) {
            $columns = $probe->columns('customer_wallets');
            $balance = isset($columns['balance']) ? 'COALESCE(SUM(balance), 0)' : '0';
            $points = isset($columns['royality_points']) ? 'COALESCE(SUM(royality_points), 0)' : '0';
            $row = $probe->select("SELECT COUNT(*) AS c, {$balance} AS balance, {$points} AS points FROM customer_wallets")[0];
            $facts += ['customer_wallets_rows' => (int) $row['c'], 'customer_wallets_balance' => $this->plain($row['balance']), 'customer_wallets_points' => $this->plain($row['points'])];
        }
        if ($histories) {
            $amount = isset($probe->columns('customer_wallet_histories')['transaction_amount']) ? 'COALESCE(SUM(transaction_amount), 0)' : '0';
            $row = $probe->select("SELECT COUNT(*) AS c, {$amount} AS amount FROM customer_wallet_histories")[0];
            $facts += ['customer_wallet_histories_rows' => (int) $row['c'], 'customer_wallet_histories_amount' => $this->plain($row['amount'])];
        }

        $holdsRows = ($facts['customer_wallets_rows'] ?? 0) > 0 || ($facts['customer_wallet_histories_rows'] ?? 0) > 0;

        return new CompatibilityCheck(
            'wallet',
            'the legacy customer wallet tables',
            $holdsRows ? CompatibilityCheck::WARN : CompatibilityCheck::PASS,
            $holdsRows
                ? 'a second balance sits outside the ledger: these tables are not part of the comparison above and must be migrated or declared frozen'
                : 'the tables exist and are empty',
            $facts,
        );
    }

    /** A number as the database gave it, written plainly (a float from SQLite has no exponent and no trailing noise). */
    private function plain(mixed $value): string
    {
        $text = is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : number_format((float) $value, 8, '.', '');
        $text = str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;

        return in_array($text, ['', '-0'], true) ? '0' : $text;
    }
}
