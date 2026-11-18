<?php

namespace App\Services\Wallet\Reconciliation;

/** What the operator asked the reconciliation to do. */
final readonly class ReconciliationOptions
{
    /** The most ids of users an evidence file may name: it is read into one IN list. */
    public const MAX_EXPLAINED = 10000;

    public const MAX_LISTED = 200;

    /** The one difference the migration plan accepts without evidence (section 3.7): float residue of at most a millionth of a unit per account. */
    public const DEFAULT_TOLERANCE = '0.000001';

    /**
     * @param list<LedgerSpec> $ledgers
     * @param string $tolerance largest difference, in the unit of the balance, that is called float residue and not a difference
     * @param list<int> $explainedUserIds users whose difference an operator has explained with evidence (class E)
     * @param int $listUnexplained how many ids of users with an unexplained difference are listed (0: none; ids only, never a name)
     * @param string $amountTolerance largest gap between a wallet debit and the total of the orders it paid for
     * @param int $windowSeconds largest time between an order and the debit that paid it
     */
    public function __construct(
        public array $ledgers,
        public string $tolerance = self::DEFAULT_TOLERANCE,
        public array $explainedUserIds = [],
        public int $listUnexplained = 0,
        public bool $matchOrders = true,
        public string $amountTolerance = '0.02',
        public int $windowSeconds = 600,
    ) {
    }

    public function listLimit(): int
    {
        return max(0, min(self::MAX_LISTED, $this->listUnexplained));
    }
}
