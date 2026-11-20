<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
use RuntimeException;

/**
 * The event a writer is appending is already on the seller ledger, and not in the chain the writer resolved for it (E-17.2).
 *
 * A reference names ONE event on this ledger: every `reference_id` a writer passes is the key of one row of one table of this database, so an entry already there for the
 * same seller, entry type and reference is the same money. The writer refuses rather than choose between the two ways of being wrong. Appending a second entry credits one
 * order line twice, which is what narrowing the idempotency lookup to the chain did once the store's base currency setting moved between two deliveries of one line. Handing
 * the caller the entry of the other chain is the write that never arrives. Which chain the money belongs to is not the writer's to decide: the key it resolved this time
 * disagrees with the key the first entry was written under, and only the operator knows which of the two is right.
 *
 * It is raised inside the writer's transaction, so nothing is appended and nothing is changed. The money is whole: the event is on the ledger exactly once, in the chain
 * this names.
 */
class AnEventIsAlreadyOnAnotherChain extends RuntimeException
{
    public function __construct(public readonly VendorLedgerEntry $entry, public readonly LedgerChain $chain)
    {
        parent::__construct(sprintf(
            'The %s of %s %s about %s #%s is already on the seller ledger as entry #%s, which carries %s. This write resolved %s for the same event, so one of the two is '
            . 'wrong: appending it would credit the same event twice, and handing back the entry of another chain would lose this write. A chain key moves between two '
            . 'deliveries of one event when the store base currency setting changes or cannot be read (VendorLedger::baseCurrency), or when a caller names a currency or a '
            . 'selling company that disagrees with the entry already there. Label that entry, or record this event with the chain it belongs to. Nothing was appended.',
            $entry->entry_type,
            $entry->seller_is,
            $entry->seller_id,
            $entry->reference_type ?? 'no reference type',
            $entry->reference_id ?? '',
            $entry->getKey(),
            LedgerChain::describeTheChainOf(entry: $entry),
            $chain->describe(),
        ));
    }
}
