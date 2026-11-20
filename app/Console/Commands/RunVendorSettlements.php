<?php

namespace App\Console\Commands;

use App\Services\Marketplace\SettlementCurrency;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use Illuminate\Console\Command;

/**
 * Creates settlements on a schedule (Phase 3, Stage B).
 *
 * Built to be hard to fire by accident, for the same reason the abandoned-cart command was: this one
 * decides what a marketplace owes its sellers.
 *
 *  - `--dry-run` prints the same table and writes nothing. It is the intended first run.
 *  - Calculating never pays anybody. Settlements land as `calculated` and a human approves them —
 *    the maker-checker split the specification asks for.
 *  - Releasing matured entries is opt-in via `--release`, so a settlement run cannot quietly change
 *    which money is considered payable as a side effect of reporting on it.
 *  - While a settlement is drawn in one currency (`gcc.settlements.one_currency`), a run draws one
 *    for each currency a vendor has money waiting in, and both reports name the currency of every
 *    line instead of adding two.
 *
 * Schedule daily/weekly to match the marketplace's settlement period.
 */
class RunVendorSettlements extends Command
{
    protected $signature = 'marketplace:settle
                            {--dry-run : Show what would be settled and write nothing}
                            {--release : First move matured pending entries to available}
                            {--seller= : Settle one vendor only}';

    protected $description = 'Calculate vendor settlements from the ledger (never pays; a human approves)';

    public function handle(SettlementEngine $engine, VendorLedger $ledger, SettlementCurrency $currency): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('release')) {
            $released = $dryRun ? 0 : $ledger->releaseMatured();
            $this->info($dryRun
                ? 'Dry run: matured entries would be released first.'
                : sprintf('Released %d matured entr%s to available.', $released, $released === 1 ? 'y' : 'ies'));
        }

        if ($dryRun && $currency->areToldApart()) {
            return $this->reportWhatEachCurrencyWouldSettle(engine: $engine, currency: $currency);
        }

        if ($dryRun) {
            // Deliberately reports rather than calculates: calculating claims entries, and a dry run
            // that mutated the thing it is reporting on would not be a dry run.
            $pending = \App\Models\VendorLedgerEntry::query()
                ->whereNull('settlement_id')
                ->where('status', \App\Models\VendorLedgerEntry::STATUS_AVAILABLE)
                ->when($this->option('seller'), fn ($q) => $q->where('seller_id', $this->option('seller')))
                ->selectRaw('seller_id, COUNT(*) as entries, SUM(credit) as credits, SUM(debit) as debits')
                ->groupBy('seller_id')
                ->get();

            if ($pending->isEmpty()) {
                $this->info('Nothing is waiting to be settled.');

                return self::SUCCESS;
            }

            $this->table(
                ['vendor', 'entries', 'credits', 'debits', 'net'],
                $pending->map(fn ($row) => [
                    $row->seller_id,
                    $row->entries,
                    number_format((float) $row->credits, 2),
                    number_format((float) $row->debits, 2),
                    number_format((float) $row->credits - (float) $row->debits, 2),
                ])->all()
            );
            $this->info(sprintf('Dry run: %d settlement(s) would be created. Nothing was written.', $pending->count()));

            return self::SUCCESS;
        }

        $settlements = $this->option('seller')
            ? $engine->calculateEachCurrency(sellerId: $this->option('seller'))
            : $engine->calculateForAll();

        if (!$settlements) {
            $this->info('Nothing is waiting to be settled.');

            return self::SUCCESS;
        }

        if ($currency->areToldApart()) {
            $this->table(
                ['reference', 'vendor', 'currency', 'entries', 'net', 'status'],
                array_map(fn ($s) => [$s->reference, $s->seller_id, $currency->of(label: $s->currency), $s->entry_count, $currency->amount(amount: $s->net_amount), $s->status], $settlements)
            );
        } else {
            $this->table(
                ['reference', 'vendor', 'entries', 'net', 'status'],
                array_map(fn ($s) => [$s->reference, $s->seller_id, $s->entry_count, number_format($s->net_amount, 2), $s->status], $settlements)
            );
        }

        $this->info(sprintf('%d settlement(s) calculated. None is paid — each needs approval.', count($settlements)));

        return $this->reportTheSellersThatWereLeft(engine: $engine);
    }

    /**
     * The sellers a run over every seller could not settle: their ledger mixes currencies, so their settlement would record no
     * opening and no closing balance, and this installation's `vendor_settlements` will not hold one that is unknown. Nothing
     * of theirs was claimed and the sellers before and after them were settled. The run ends in failure all the same, so that
     * a night which did not finish its work is not reported as one that did.
     */
    private function reportTheSellersThatWereLeft(SettlementEngine $engine): int
    {
        $left = $engine->sellersWhoseBalanceCannotBeLeftUnknown();

        if (!$left) {
            return self::SUCCESS;
        }

        foreach ($left as $refusal) {
            $this->error($refusal->getMessage());
        }
        $this->error(sprintf('%d seller(s) were left unsettled. Nothing of theirs was claimed.', count($left)));

        return self::FAILURE;
    }

    /**
     * The dry run while a settlement is drawn in one currency: a line for each settlement the run
     * would draw, from the engine's own account of what is waiting, so the report cannot add two
     * currencies the run keeps apart.
     */
    private function reportWhatEachCurrencyWouldSettle(SettlementEngine $engine, SettlementCurrency $currency): int
    {
        $waiting = $engine->waiting(sellerId: $this->option('seller'));

        if (!$waiting) {
            $this->info('Nothing is waiting to be settled.');

            return self::SUCCESS;
        }

        $this->table(
            ['vendor', 'currency', 'entries', 'credits', 'debits', 'net'],
            array_map(fn (array $line) => [
                $line['seller_id'],
                $line['currency'],
                $line['entries'],
                $currency->amount(amount: $line['credits']),
                $currency->amount(amount: $line['debits']),
                $currency->amount(amount: $line['net']),
            ], $waiting)
        );

        if (!$engine->canLeaveABalanceUnknown()) {
            $this->error('Dry run: the run would refuse to draw. opening_balance and closing_balance of vendor_settlements must accept NULL first. Nothing was written.');

            return self::FAILURE;
        }

        $this->info(sprintf('Dry run: %d settlement(s) would be created. Nothing was written.', count($waiting)));

        return self::SUCCESS;
    }
}
