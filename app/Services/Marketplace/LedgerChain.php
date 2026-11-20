<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
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
 * 100 SAR in Riyadh and 100 AED in Dubai says 200 of nothing.
 *
 * So a chain is named by the seller, the selling company and the currency, and this class is the one
 * place that says so. Everything that reads a balance or takes the lock narrows its query here, so
 * the writer and the readers cannot disagree about which entries belong together.
 *
 * ## What the narrowed lock does NOT do
 *
 * Narrowing the lock to the chain does not stop two markets waiting for each other, and this class
 * does not claim it does. `ORDER BY id DESC LIMIT 1 FOR UPDATE` is served by walking the primary key
 * unless an index covers the narrowing predicates, and InnoDB locks the rows of other chains it walks
 * over on the way. Measured on MariaDB 10.11 against this table with no index, with (seller_id), with
 * (seller_id, seller_is) and with (seller_id, seller_is, currency), a second writer waits exactly as
 * it does for the single-chain query that shipped; only an index that includes `legal_entity_id`
 * frees it, and `vendor_ledger_entries` has no migration in this repository to add one in. So the
 * lock is narrowed because a chain's balance is its own, not because two markets stop queueing: that
 * needs an index on the live table, which is the owner's to approve.
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
 * before the column was filled. (The company half of the key is dormant for the same reason and more:
 * no writer in the application passes one yet, so `legal_entity_id` is NULL on every live entry and
 * the key is seller and currency in practice. There is no source of legal entities and no mapping
 * from a storefront or a market to one; until there is, the company is a column the chain is ready
 * for rather than one it uses.) An entry labelled `sar ` is in the same currency as one labelled
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
     * Neither currency argument has a default, on purpose. `VendorLedger::chain()` is how a caller
     * names a chain, because resolving a label to a code and deciding whether that code is the base
     * currency both need the store's settings. A default here let a caller ask for `AED` and be given
     * a chain that also read every row naming no currency — the base currency's history, in another
     * currency's balance.
     *
     * @param string $currency the resolved code, as VendorLedger resolves a label: upper case, trimmed, the base currency where the entry names none
     * @param bool   $currencyIsTheBaseOne whether that code is the store's base currency, which is the chain the unlabelled entries belong to
     */
    public static function of(
        int|string $sellerId,
        string $currency,
        bool $currencyIsTheBaseOne,
        string $sellerIs = 'seller',
        ?int $legalEntityId = null,
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

    /**
     * Whether an entry already on the ledger belongs to this chain.
     *
     * Asked of the database through `narrow()` rather than compared again in PHP, so that what belongs together cannot
     * come to be spelled two ways: a writer deciding whether the event it is about to append is already in this chain
     * has to read the row exactly as a reader of this chain's balance reads it. While chains are not kept apart every
     * entry of the seller is in the one chain they have always had, and this answers so.
     */
    public function holds(VendorLedgerEntry $entry): bool
    {
        return $this->narrow(VendorLedgerEntry::query())->whereKey($entry->getKey())->exists();
    }

    /** This chain in the words a message to an operator uses. */
    public function describe(): string
    {
        return self::inWords(company: $this->legalEntityId, currency: $this->currency);
    }

    /** The chain an entry's own labels name, in those same words, so two chains in one sentence can be compared by eye. */
    public static function describeTheChainOf(VendorLedgerEntry $entry): string
    {
        return self::inWords(
            company: $entry->getAttribute(self::ENTITY_COLUMN),
            currency: (string) $entry->currency,
        );
    }

    /** What the entries of this chain carry in their company column, for a writer about to append one. */
    public function entityForWriting(): array
    {
        return self::entityIsRecorded() ? [self::ENTITY_COLUMN => $this->legalEntityId] : [];
    }

    private static function inWords(int|string|null $company, string $currency): string
    {
        return sprintf(
            'currency %s and selling company %s',
            trim($currency) !== '' ? mb_strtoupper(trim($currency)) : 'none',
            $company === null ? 'none' : $company,
        );
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
