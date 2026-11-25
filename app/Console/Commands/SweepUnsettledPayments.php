<?php

namespace App\Console\Commands;

use App\Services\Payments\UnsettledPaymentSweep;
use Illuminate\Console\Command;

/**
 * Finds Paymob payments the gateway took and this shop never heard about, and settles them (D2-12, D2-16).
 *
 * Built to be hard to fire by accident, for the same reason the vendor settlement command is: this one
 * creates orders and credits wallets on the strength of what a gateway says.
 *
 *  - `--dry-run` asks Paymob and writes nothing. It is the intended first run.
 *  - It does nothing at all while `gcc.payments.recovery_sweep.enabled` is off, which is how it ships,
 *    so a schedule written before an operator has decided to run it moves no money.
 *  - It never fails a payment. A payment Paymob says it did not take is reported and left alone.
 *
 * Schedule every few minutes once it is switched on; a payment is left alone for its first quarter of an
 * hour, because a customer still at the gateway is not an abandoned payment.
 */
class SweepUnsettledPayments extends Command
{
    protected $signature = 'payments:sweep-unsettled
                            {--dry-run : Ask the gateway and write nothing}
                            {--limit= : Consider at most this many payments}';

    protected $description = 'Settle payments the gateway took and no browser ever came back from (never fails a payment)';

    public function handle(UnsettledPaymentSweep $sweep): int
    {
        if (!$sweep->isEnabled()) {
            $this->warn('The recovery sweep is switched off (gcc.payments.recovery_sweep.enabled). Nothing was asked and nothing was settled.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        $found = $sweep->sweep(dryRun: $dryRun, limit: $limit);

        $this->table(
            ['considered', 'settled', 'refused', 'unknown', 'unaskable'],
            [[$found['considered'], $found['settled'], $found['refused'], $found['unknown'], $found['unaskable']]],
        );

        $this->info($dryRun
            ? sprintf('Dry run: %d payment(s) would have been settled. Nothing was written.', $found['settled'])
            : sprintf('%d payment(s) settled.', $found['settled']));

        return self::SUCCESS;
    }
}
