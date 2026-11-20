<?php

namespace Tests\Support\Concurrency;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\VendorLedger;

/**
 * What a worker process does in the seller-ledger concurrency test. Everything goes through the application's own writer, because what is being tested is whether
 * `VendorLedger::record()` keeps a chain's running balance right when two processes append to it at the same instant.
 */
final class VendorLedgerOperations
{
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
}
