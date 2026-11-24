<?php

namespace App\Console\Commands\Concerns;

use App\Services\Wallet\Reconciliation\ReconciliationOptions;

/**
 * Reads the `--explained` file of the balance commands: the users whose difference against the ledger an operator has looked at and
 * written down with evidence (class E of docs/gcc/WALLET-RECONCILE.md), one id per line.
 *
 * Shared because the reconciliation and the backfill have to read the same file the same way: a backfill that accepted a difference the
 * reconciliation would not, or refused one it would, would be deciding for itself which money is explained.
 */
trait ReadsExplainedUserIds
{
    /** @return list<int>|string the user ids the evidence file names, or what is wrong with the file */
    private function explainedUserIds(): array|string
    {
        $path = (string) $this->option('explained');
        if ($path === '') {
            return [];
        }
        if (!is_file($path) || !is_readable($path)) {
            return '--explained must name a file that can be read.';
        }

        $ids = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            $line = trim(preg_replace('/\s*#.*$/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\d{1,18}$/', $line) !== 1) {
                return sprintf('Line %d of the evidence file is not a user id: one whole number per line, a # starts a comment.', $number + 1);
            }
            $ids[(int) $line] = (int) $line;
        }

        return count($ids) > ReconciliationOptions::MAX_EXPLAINED
            ? sprintf('The evidence file names %d users; the most it may name is %d.', count($ids), ReconciliationOptions::MAX_EXPLAINED)
            : array_values($ids);
    }
}
