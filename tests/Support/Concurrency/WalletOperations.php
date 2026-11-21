<?php

namespace Tests\Support\Concurrency;

use App\Services\Customer\CustomerWalletWriter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Customer wallet writes, each run in its own process against the same wallet row.
 *
 * Everything goes through App\Services\Customer\CustomerWalletWriter, because it is the only thing that writes a customer's
 * `wallet_balance`. There used to be one of these operations per copy of the write, and there were four copies.
 */
final class WalletOperations
{
    /** The holder has the customer's row and is not letting go. */
    private const THE_ROW_IS_LOCKED = 'the customer row is locked';

    /** Long enough for two processes to boot and reach the row, short enough to fail inside the runner's own timeout. */
    private const WAIT_SECONDS = 30.0;

    /** @param array{customer_id: int|string, amount: float, transaction_type: string, reference: string} $args */
    public static function credit(array $args): bool
    {
        return CustomerWalletWriter::record(
            customerId: $args['customer_id'],
            amount: (float) $args['amount'],
            transactionType: (string) $args['transaction_type'],
            reference: (string) $args['reference'],
        ) !== false;
    }

    /**
     * Holds the customer's row until BOTH crediting processes are stopped at it, then lets go.
     *
     * This is the arrangement that makes the lost update a property rather than a coin flip. Released from the common start
     * barrier alone, two credits meet wherever the scheduler puts them, and "both read the same balance" happens on some runs
     * and not others. Here neither can get past the row until this process says so, and this process does not say so until the
     * engine itself shows both of them stopped at a statement that cannot pass a locked row.
     *
     * The same arrangement measures the defect and the cure, because the two differ in WHERE a credit is stopped and not in
     * whether it is. An unlocked writer reads the balance with a plain non-locking SELECT, which InnoDB answers under an
     * exclusive lock, and is stopped at its `update users set wallet_balance` holding a balance that is about to go stale. A
     * writer that locks is stopped at the read itself, `... for update`, and so reads whatever the other credit committed.
     * Either way the credit has gone as far as it can, so waiting for it is waiting for a fact rather than for a while.
     *
     * @param array{customer_id: int|string, writers: int} $args
     * @return array{waited_for: float, credits_held_at_the_row: int}
     */
    public static function holdTheRowUntilTheCreditsAreStoppedAtIt(array $args): array
    {
        DB::beginTransaction();
        DB::table('users')->where('id', $args['customer_id'])->lockForUpdate()->first();
        Rendezvous::mark(self::THE_ROW_IS_LOCKED);

        $writers = (int) $args['writers'];
        $began = microtime(true);
        while (true) {
            $held = self::creditsHeldAtTheRow();
            if ($held >= $writers) {
                break;
            }
            if (microtime(true) - $began > self::WAIT_SECONDS) {
                DB::rollBack();

                throw new RuntimeException("Only {$held} of {$writers} credits ever reached the row, so nothing was arranged.");
            }
            usleep(500);
        }

        DB::commit();

        return ['waited_for' => round(microtime(true) - $began, 6), 'credits_held_at_the_row' => $writers];
    }

    /**
     * One credit, which does not start until the row is held, so it is certain to queue behind the holder.
     *
     * @param array{customer_id: int|string, amount: float, transaction_type: string, reference: string} $args
     * @return array{credited: bool, held_for: float}
     */
    public static function creditOnceTheRowIsLocked(array $args): array
    {
        $heldFor = Rendezvous::waitFor(self::THE_ROW_IS_LOCKED, self::WAIT_SECONDS);

        return ['credited' => self::credit($args), 'held_for' => $heldFor];
    }

    /**
     * How many other connections of THIS database are stopped on a statement that cannot get past a locked customer row: the
     * writer's locking read, or the balance update an unlocked writer reaches instead.
     *
     * The process list rather than `information_schema.INNODB_TRX`, because MariaDB 10.11 does not list these waiters there at
     * all. Measured on 10.11.14: both credits sit in process-list state `Statistics` with the locking read as their current
     * statement while `INNODB_TRX` holds only the holder's own transaction — to an outside observer as well as to the holder.
     *
     * Scoped to this database because the engine is shared with other test lanes.
     */
    private static function creditsHeldAtTheRow(): int
    {
        $rows = DB::select(
            "SELECT COUNT(*) AS held
               FROM information_schema.PROCESSLIST
              WHERE DB = ?
                AND ID <> CONNECTION_ID()
                AND INFO IS NOT NULL
                AND INFO LIKE '%users%'
                AND (INFO LIKE '%for update%' OR INFO LIKE '%wallet\\_balance%')",
            [DB::connection()->getDatabaseName()],
        );

        return (int) ($rows[0]->held ?? 0);
    }
}
