<?php

namespace App\Services\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The chain of seller-ledger entries that one running balance belongs to (E-17).
 *
 * A ledger entry carries the balance as of itself, so the entries of one balance form a chain: each
 * one reads the balance the one before it wrote. The platform has always had exactly one chain per
 * seller, which is right while every amount a seller earns is the same kind of money.
 *
 * It stops being right the moment a seller sells in two markets. The selling company differs, the
 * currency differs, and `balance_after` adds them anyway, so the statement of a seller who earned
 * 100 SAR in Riyadh and 100 AED in Dubai says 200 of nothing. The row lock has the same problem from
 * the other side: it serialises a Dubai sale behind a Riyadh one that has no bearing on it.
 *
 * So a chain is named by the seller, the selling company and the currency, and this class is the one
 * place that says so. Everything that reads a balance or takes the lock narrows its query here, so
 * the writer and the readers cannot disagree about which entries belong together.
 *
 * ## Dormant until it is switched on
 *
 * While `gcc.ledgers.keyed_by_entity_and_currency` is off a chain is the seller's whole ledger, which
 * is what every reader of it saw before this class existed. Saudi Arabia is the live market and its
 * ledger is one company in one currency, so nothing there changes when the switch is thrown either:
 * the split only has an effect once a second company or a second currency is on the ledger.
 *
 * ## Why the currency is compared through a resolved code
 *
 * An entry that names no currency is in the store's base currency — the ledger wrote NULL for years
 * before the column was filled. An entry labelled `sar ` is in the same currency as one labelled
 * `SAR`. Both have to land in the same chain as the base-currency entries, or a seller's history
 * would break into chains that are really one. The caller resolves the label to a code and says
 * whether that code is the base currency; this class then matches the unlabelled rows to the base
 * chain and nothing else.
 */
final class LedgerChain
{
    public const ENTITY_COLUMN = 'legal_entity_id';

    private function __construct(
        public readonly int|string $sellerId,
        public readonly string $sellerIs,
        public readonly ?int $legalEntityId,
        public readonly string $currency,
        private readonly bool $currencyIsTheBaseOne,
        public readonly bool $isKeptApart,
    ) {
    }

    /**
     * The chain an entry of this seller, company and currency belongs to.
     *
     * @param string $currency the resolved code, as VendorLedger resolves a label: upper case, trimmed, the base currency where the entry names none
     * @param bool   $currencyIsTheBaseOne whether that code is the store's base currency, which is the chain the unlabelled entries belong to
     */
    public static function of(
        int|string $sellerId,
        string $sellerIs = 'seller',
        ?int $legalEntityId = null,
        string $currency = '',
        bool $currencyIsTheBaseOne = true,
    ): self {
        return new self(
            sellerId: $sellerId,
            sellerIs: $sellerIs,
            legalEntityId: $legalEntityId,
            currency: $currency,
            currencyIsTheBaseOne: $currencyIsTheBaseOne,
            isKeptApart: self::areKeptApart(),
        );
    }

    /** Whether a seller's money is kept in a chain of its own per selling company and currency, or in the one chain the platform has always had. */
    public static function areKeptApart(): bool
    {
        return (bool) config('gcc.ledgers.keyed_by_entity_and_currency');
    }

    /**
     * Whether the selling company is part of the key at all.
     *
     * `vendor_ledger_entries` has no migration in this repository: the live table came from the
     * installation dump and the tests build their own. An installation whose table has no company
     * column still keys its chains by seller and currency rather than refusing to work.
     */
    public static function entityIsRecorded(): bool
    {
        return Schema::hasTable('vendor_ledger_entries')
            && Schema::hasColumn('vendor_ledger_entries', self::ENTITY_COLUMN);
    }

    /** Narrows a query to this chain. While chains are not kept apart, that is the seller's whole ledger. */
    public function narrow(Builder $query): Builder
    {
        $query = $query->forSeller($this->sellerId, $this->sellerIs);

        if (!$this->isKeptApart) {
            return $query;
        }

        if (self::entityIsRecorded()) {
            $query = $query->where(self::ENTITY_COLUMN, $this->legalEntityId);
        }

        return $query->where(fn (Builder $ofThisCurrency): Builder => $this->sameCurrency($ofThisCurrency));
    }

    /** What the entries of this chain carry in their company column, for a writer about to append one. */
    public function entityForWriting(): array
    {
        return self::entityIsRecorded() ? [self::ENTITY_COLUMN => $this->legalEntityId] : [];
    }

    private function sameCurrency(Builder $query): Builder
    {
        $query = $query->whereRaw('UPPER(TRIM(COALESCE(currency, ?))) = ?', ['', $this->currency]);

        if (!$this->currencyIsTheBaseOne) {
            return $query;
        }

        return $query->orWhereNull('currency')->orWhereRaw("TRIM(currency) = ''");
    }
}
