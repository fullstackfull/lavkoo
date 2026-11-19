<?php

namespace Tests\Support;

use App\Models\VendorLedgerEntry;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Writes a seller's ledger for the settlement tests through the real writer (VendorLedger::record), so every entry carries the running balance and the currency the application gives it. An entry is one the
 * seller can be paid (`available`) unless a test says otherwise, because that is what a settlement draws on.
 */
trait WritesSellerLedger
{
    protected const SELLER = 7;

    private int $lastEntryReference = 0;

    /** The store's default currency is the row of `currencies` that the `system_default_currency` setting points at. */
    protected function useDefaultCurrency(string $code): void
    {
        $id = DB::table('currencies')->insertGetId(['name' => $code . ' currency', 'code' => $code, 'symbol' => $code . '-symbol', 'exchange_rate' => 1, 'status' => 1]);
        $this->storeSetting(type: 'system_default_currency', value: (string) json_encode($id));
    }

    /**
     * The application remembers its settings for hours, and on a real engine it booted over the tables the previous test left behind, so what it remembered is forgotten
     * whenever a test writes one.
     */
    protected function storeSetting(string $type, string $value): void
    {
        DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        Cache::flush();
    }

    /** What the seller earned. The changes are parameters of VendorLedger::record(). */
    protected function earning(float $amount, ?string $currency = 'SAR', array $changes = []): VendorLedgerEntry
    {
        return $this->ledgerEntry(changes: $changes + ['credit' => $amount, 'currency' => $currency]);
    }

    /** What the marketplace took from the seller. The changes are parameters of VendorLedger::record(). */
    protected function charge(float $amount, ?string $currency = 'SAR', array $changes = []): VendorLedgerEntry
    {
        return $this->ledgerEntry(changes: $changes + ['entryType' => VendorLedgerEntry::TYPE_COMMISSION_CHARGE, 'debit' => $amount, 'currency' => $currency]);
    }

    /** One entry of seller 7 about a reference of its own, so no two entries are taken for the same event. */
    protected function ledgerEntry(array $changes = []): VendorLedgerEntry
    {
        return app(VendorLedger::class)->record(...$changes + [
            'sellerId' => self::SELLER,
            'entryType' => VendorLedgerEntry::TYPE_ORDER_EARNING,
            'status' => VendorLedgerEntry::STATUS_AVAILABLE,
            'referenceType' => 'order_details',
            'referenceId' => ++$this->lastEntryReference,
        ]);
    }

    /** An entry as a row written before the ledger recorded a currency would be: the writer always stamps one when it can read the store's, so the label is taken off afterwards. */
    protected function withoutACurrency(VendorLedgerEntry $entry, ?string $label = null): VendorLedgerEntry
    {
        DB::table('vendor_ledger_entries')->where('id', $entry->id)->update(['currency' => $label]);

        return $entry->refresh();
    }

    /** @return list<int> the ids of the entries a settlement claimed, in the order they were written */
    protected function entriesClaimedBy(int $settlementId): array
    {
        return VendorLedgerEntry::query()->where('settlement_id', $settlementId)->orderBy('id')->pluck('id')->all();
    }

    /** @return list<int> the ids of the entries no settlement has claimed, in the order they were written */
    protected function unclaimedEntries(): array
    {
        return VendorLedgerEntry::query()->whereNull('settlement_id')->orderBy('id')->pluck('id')->all();
    }
}
