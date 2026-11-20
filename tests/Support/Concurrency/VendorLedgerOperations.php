<?php

namespace Tests\Support\Concurrency;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Support\Facades\DB;

/**
 * What a worker process does in the seller-ledger concurrency test. Everything goes through the application's own writer, because what is being tested is whether
 * `VendorLedger::record()` keeps a chain's running balance right when two processes append to it at the same instant.
 */
final class VendorLedgerOperations
{
    /** The second delivery has fixed the snapshot its idempotency lookup will be answered from. */
    private const SECOND_DELIVERY_HAS_READ = 'the second delivery has read the ledger';

    /** The first delivery's entry is committed, and so visible to anything that reads the ledger after this. */
    private const FIRST_DELIVERY_IS_ON_THE_LEDGER = 'the first delivery is on the ledger';

    /**
     * Appends one entry to a seller's ledger and reports the running balance it wrote.
     *
     * The switch is set here rather than in the test, because a worker boots the application itself and nothing the test process put in `config()` reaches it.
     *
     * @param array{seller_id: int, credit: float, reference_id: string, currency: string, legal_entity_id?: int|null, chains_apart?: bool} $args
     * @return array{id: int|null, balance_after: float|null, currency: string|null}
     */
    public static function append(array $args): array
    {
        config(['gcc.ledgers.keyed_by_entity_and_currency' => (bool) ($args['chains_apart'] ?? false)]);

        $entry = app(VendorLedger::class)->record(
            sellerId: (int) $args['seller_id'],
            entryType: VendorLedgerEntry::TYPE_ORDER_EARNING,
            credit: (float) $args['credit'],
            status: VendorLedgerEntry::STATUS_AVAILABLE,
            referenceType: 'order_details',
            referenceId: (string) $args['reference_id'],
            currency: (string) $args['currency'],
            legalEntityId: $args['legal_entity_id'] ?? null,
        );

        return [
            'id' => $entry?->id,
            'balance_after' => $entry === null ? null : (float) $entry->balance_after,
            'currency' => $entry?->currency,
        ];
    }

    /**
     * The FIRST of two deliveries of one order line: it waits until the other process has taken its snapshot, then appends and commits, then says it has.
     *
     * Waiting first is what makes the pair an arrangement instead of a race: committed before the other process had read, this delivery would simply be found by the other's
     * idempotency lookup, which is the ledger behaving correctly and not the interleaving under test.
     *
     * @param array{seller_id: int, credit: float, reference_id: string, currency: string, legal_entity_id?: int|null, chains_apart?: bool} $args
     * @return array{id: int|null, balance_after: float|null, currency: string|null, held_for: float}
     */
    public static function appendOnceTheOtherHasRead(array $args): array
    {
        $heldFor = Rendezvous::waitFor(self::SECOND_DELIVERY_HAS_READ);
        $written = self::append($args);
        Rendezvous::mark(self::FIRST_DELIVERY_IS_ON_THE_LEDGER);

        return $written + ['held_for' => $heldFor];
    }

    /**
     * The SECOND of two deliveries of one order line, reading the ledger before the first one has committed.
     *
     * InnoDB under REPEATABLE READ fixes a transaction's read view at its FIRST consistent read, not at `START TRANSACTION`, and answers every later consistent read of that
     * transaction from it. So this opens a transaction, reads once — fixing the view while the ledger still has no entry for the line — and only then lets the other process
     * commit. `record()` joins this transaction as a savepoint, its idempotency lookup is answered from the view taken before that commit, it finds nothing, and it appends a
     * second entry for an order line that is already on the ledger. `lockForUpdate()` is a locking read and does see the committed row, which is why the second entry's
     * `balance_after` counts the first one.
     *
     * @param array{seller_id: int, credit: float, reference_id: string, currency: string, legal_entity_id?: int|null, chains_apart?: bool} $args
     * @return array{id: int|null, balance_after: float|null, currency: string|null, held_for: float}
     */
    public static function appendWithASnapshotTakenFirst(array $args): array
    {
        DB::beginTransaction();

        VendorLedgerEntry::query()->where('seller_id', (int) $args['seller_id'])->orderByDesc('id')->first();

        Rendezvous::mark(self::SECOND_DELIVERY_HAS_READ);
        $heldFor = Rendezvous::waitFor(self::FIRST_DELIVERY_IS_ON_THE_LEDGER);

        $written = self::append($args);
        DB::commit();

        return $written + ['held_for' => $heldFor];
    }
}
