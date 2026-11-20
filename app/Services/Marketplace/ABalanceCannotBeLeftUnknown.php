<?php

namespace App\Services\Marketplace;

use RuntimeException;

/**
 * A settlement of a ledger that mixes currencies records no opening and no closing balance, and the table of this installation
 * will not hold one that is unknown.
 *
 * It is raised for the seller it concerns, inside the transaction that would have drawn their settlement, so nothing of theirs
 * is claimed. A run over every seller catches it, settles the sellers whose ledger is in one currency, and reports the rest:
 * one seller whose ledger needs a column changed must not leave every seller after them unsettled night after night.
 */
class ABalanceCannotBeLeftUnknown extends RuntimeException
{
    public function __construct(public readonly int|string $sellerId, public readonly string $sellerIs)
    {
        parent::__construct(sprintf(
            'The ledger of %s %s mixes currencies, so a settlement of it records no opening and no closing balance, and '
            . 'vendor_settlements.opening_balance and vendor_settlements.closing_balance of this installation do not accept NULL. '
            . 'They must accept NULL before this seller is settled while settlements are drawn in one currency '
            . '(gcc.settlements.one_currency). Nothing of this seller was claimed.',
            $sellerIs,
            $sellerId,
        ));
    }
}
