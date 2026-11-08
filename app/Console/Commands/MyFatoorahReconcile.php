<?php

namespace App\Console\Commands;

use App\Services\MyFatoorah\ReconciliationService;
use Illuminate\Console\Command;

/**
 * Finds the MyFatoorah payments that nothing else told us about, and finishes them.
 *
 * Two things normally confirm a payment: the customer's redirect, and the webhook. Both can be lost —
 * the customer closes the tab, the webhook gives up after five tries — and in either case somebody has
 * been charged and has no order. This command asks MyFatoorah about every attempt still in flight and
 * settles the ones that are paid, through exactly the same path a webhook uses, so a payment it recovers
 * cannot produce a second order for one already recovered.
 *
 * Run it every 10-15 minutes:
 *
 *     * /10 * * * * php artisan myfatoorah:reconcile
 *
 * It is safe to run concurrently with itself and with live webhooks: settlement is claimed with a
 * conditional update under a row lock, so only one caller ever applies a payment.
 */
class MyFatoorahReconcile extends Command
{
    protected $signature = 'myfatoorah:reconcile
                            {--limit= : How many in-flight attempts to check in this run}';

    protected $description = 'Verify in-flight MyFatoorah payments and settle any that turn out to be paid';

    public function handle(ReconciliationService $reconciliation): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $tally = $reconciliation->sweep($limit);

        if ($tally['skipped'] !== null) {
            $this->warn($tally['skipped']);

            // Not a failure: a deployment with the gateway switched off is a normal state, and a cron
            // that reports failure every ten minutes trains people to ignore it.
            return self::SUCCESS;
        }

        $this->table(
            ['checked', 'settled', 'pending', 'failed', 'expired', 'mismatch', 'unreachable'],
            [[
                $tally['checked'],
                $tally['settled'],
                $tally['pending'],
                $tally['failed'],
                $tally['expired'],
                $tally['mismatch'],
                $tally['unknown'],
            ]],
        );

        $attention = $reconciliation->needsAttention();

        if ($attention['mismatched'] > 0 || $attention['stuck'] > 0) {
            // Both of these mean money exists that no order was released for. Said loudly, because the
            // whole point of the sweep is that nobody has to notice these by hand.
            $this->error(sprintf(
                'Needs a person: %d payment(s) paid for the wrong amount or reference, %d paid but with no order created.',
                $attention['mismatched'],
                $attention['stuck'],
            ));
        }

        return self::SUCCESS;
    }
}
