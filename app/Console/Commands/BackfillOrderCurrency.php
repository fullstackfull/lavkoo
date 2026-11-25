<?php

namespace App\Console\Commands;

use App\Services\Checkout\OrderCurrencyBackfill;
use Illuminate\Console\Command;

/**
 * Gives the orders placed before the snapshot columns the record of what they were sold in (D1-06).
 *
 * **A READ UNLESS `--write` IS PASSED**, which is the opposite way round from most commands here and deliberate:
 * this one writes a historical fact onto every order the platform has ever taken, and the fact it writes cannot be
 * worked out again afterwards. A run with no option prints what it would do and writes nothing.
 *
 * It never touches an amount, and proves it: the sum of every order's amount is read before and after and the two
 * must be identical. A run that does not balance says so and exits FAILURE.
 *
 * It refuses outright on a platform that converts from a base currency, naming the reason: the currency a customer
 * was shown there came from their session and no row remembers it, so there is nothing to backfill from and a
 * stamped currency would be a figure somebody could reprint an invoice from. The refusal is the answer.
 */
class BackfillOrderCurrency extends Command
{
    protected $signature = 'orders:backfill-currency
                            {--write : Write the record. Without this the command reports and writes nothing}
                            {--limit= : Stamp at most this many orders, oldest first}';

    protected $description = 'Record what already-placed orders were sold in, without touching one amount (reports unless --write)';

    public function handle(OrderCurrencyBackfill $backfill): int
    {
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        if (!$this->option('write')) {
            return $this->report(plan: $backfill->plan(limit: $limit));
        }

        $done = $backfill->apply(limit: $limit);

        if ($done['refusal'] !== null) {
            $this->error('Refused: ' . $done['refusal']);

            return self::FAILURE;
        }

        $this->table(
            ['orders stamped', 'currency', 'amount before', 'amount after', 'balances'],
            [[$done['orders'], $done['currency'], $done['amount_before'] ?? '-', $done['amount_after'] ?? '-', $done['balances'] ? 'yes' : 'NO']],
        );
        $this->table(['money rows', 'stamped'], array_map(null, array_keys($done['money_rows']), array_values($done['money_rows'])));

        if (!$done['balances']) {
            $this->error('The run does not reconcile: the total of the orders moved, or fewer orders were stamped than were found. Nothing further was attempted.');

            return self::FAILURE;
        }

        $this->info('Reconciled: every order found was stamped and the total of the orders is unchanged to the digit.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $plan */
    private function report(array $plan): int
    {
        if ($plan['refusal'] !== null) {
            $this->warn('Nothing would be written: ' . $plan['refusal']);

            return self::SUCCESS;
        }

        $this->table(
            ['orders waiting', 'would record', 'storefront', 'their total (unchanged by a run)'],
            [[$plan['orders'], $plan['currency'], $plan['country'], $plan['amount'] ?? '-']],
        );
        $this->table(['money rows waiting', 'count'], array_map(null, array_keys($plan['money_rows']), array_values($plan['money_rows'])));
        $this->info('Nothing was written. Pass --write to record it.');

        return self::SUCCESS;
    }
}
