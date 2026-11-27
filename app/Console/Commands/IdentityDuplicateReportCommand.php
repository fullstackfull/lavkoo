<?php

namespace App\Console\Commands;

use App\Services\Identity\DuplicateIdentityReport;
use Illuminate\Console\Command;

/**
 * The report A2-01 asks for: whether a duplicate e-mail or phone is already stored (A2-01, A2-06, M2-04).
 *
 * `users.phone` and `users.email` carry no unique key in the installation dump, and the dump is not in this repository, so no test can
 * prove the key exists. This proves the other half of the criterion instead -- whether a duplicate exists at all -- on the live table, and
 * three decisions are waiting on its figures: whether `users` gets a unique key, and whether `GCC_CANONICAL_PHONE` and
 * `GCC_CANONICAL_EMAIL` may be turned on. Each of those three refuses a duplicate from now on and does nothing about the duplicates that
 * are already there, which is why the count has to come first.
 *
 * It reads and writes nothing, and it prints no identity: a duplicate is reported as a count and the row ids holding it, never as the
 * number or the address. The ids are what a merge is done by, and a terminal, a ticket and a log file are none of them places to leave a
 * list of customers' telephone numbers.
 *
 * Exit 0 means no duplicate of either kind, which is the evidence the owner needs; exit 1 means at least one, with the figure that says
 * how big the job is.
 */
class IdentityDuplicateReportCommand extends Command
{
    protected $signature = 'gcc:identity:duplicate-report {--market= : the storefront a number with no country code is read as belonging to (default: the configured default)}';

    protected $description = 'Report the customer accounts that share one phone number or one e-mail address, by the exact string and across its spellings. Read only, prints no identity. Exits 1 when a duplicate exists';

    public function handle(DuplicateIdentityReport $duplicates): int
    {
        $report = $duplicates->report(servedMarket: $this->option('market') ? (string) $this->option('market') : null);

        if (!$report['migrated']) {
            $this->error('There is no users table on this connection: nothing to report.');

            return self::FAILURE;
        }

        $this->line($report['accounts'] . ' customer accounts.');

        foreach (['phone', 'email'] as $kind) {
            $this->printSide($kind, $report[$kind]);
        }

        $this->line($report['clean']
            ? 'No duplicate of either kind. A unique key could be added, and the canonical switches could be turned on, without an existing account being refused.'
            : 'Duplicates exist. A unique key would abort an upgrade and a canonical switch would refuse an account that is already there: the groups above have to be merged first.');

        return $report['clean'] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $side */
    private function printSide(string $kind, array $side): void
    {
        $this->newLine();
        $this->line(strtoupper($kind) . ': ' . $side['filled'] . ' accounts hold one.');
        $this->line('  unique key on the column: ' . ($side['unique_key'] ?? 'none'));

        $this->printQuestion(
            'the exact string held by more than one account (what a unique key on the column would refuse)',
            $side['exact'],
        );
        $this->printQuestion(
            'one identity across its spellings (what the canonical switch would refuse)',
            $side['canonical'],
        );

        if (($side['canonical']['unjudged'] ?? 0) > 0) {
            $this->line('  ' . $side['canonical']['unjudged'] . ' accounts hold a value the canonical rule cannot judge (no numbering plan for its country, or not one address). They are neither counted as duplicates nor proved unique.');
        }
    }

    /** @param array<string, mixed> $question */
    private function printQuestion(string $title, array $question): void
    {
        $this->line('  ' . $title . ': ' . $question['groups'] . ' groups, ' . $question['accounts'] . ' accounts.');

        foreach ($question['listed'] as $ids) {
            $this->line('    accounts ' . implode(', ', $ids));
        }

        if ($question['groups'] > count($question['listed'])) {
            $this->line('    ... and ' . ($question['groups'] - count($question['listed'])) . ' more groups, not listed.');
        }
    }
}
