<?php

namespace App\Services\Marketplace;

use App\Models\OrderItemCommission;
use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial reconciliation (Phase 3, Stage E).
 *
 * A read-only integrity check over the financial core built in Stage B — the vendor ledger, the
 * commission snapshots, and the settlements. It asserts the invariants those pieces are supposed to
 * hold and reports anything that drifts. It writes nothing; a green report is the expected result and
 * a valuable one — it is the monitor that would catch a future bug the moment it corrupts a balance.
 *
 * Each check returns its scope, how many things it examined, and a list of discrepancies (empty when
 * everything reconciles). Every comparison is derived in PHP from portable aggregate queries, so it
 * runs identically on MariaDB and the SQLite test database.
 */
class ReconciliationService
{
    /** A cent of slack absorbs rounding noise without hiding a real discrepancy. */
    private const EPSILON = 0.01;

    /**
     * Run every check.
     *
     * @return array<int, array{key: string, label: string, scope: string, checked: int, discrepancies: array}>
     */
    public function run(): array
    {
        return [
            $this->ledgerRunningBalanceIntegrity(),
            $this->commissionSnapshotVsLedger(),
            $this->settlementIntegrity(),
            $this->pendingEarningsCanMature(),
            $this->legacyWithdrawsBridged(),
        ];
    }

    /**
     * Consolidation monitor: every APPROVED legacy withdraw (money that left through seller_wallets)
     * should be mirrored into the vendor ledger as a `legacy_withdraw` debit, so the ledger is the
     * single record of all payouts and its withdrawable() cannot be double-drawn. New approvals are
     * bridged automatically; a non-zero count here is the historical backlog from before the bridge —
     * legacy payouts that predate consolidation and are not yet reflected in the ledger.
     */
    public function legacyWithdrawsBridged(): array
    {
        $discrepancies = [];
        $checked = 0;

        if (Schema::hasTable('withdraw_requests') && Schema::hasTable('vendor_ledger_entries')) {
            $checked = 1;
            $unbridged = DB::table('withdraw_requests')
                ->where('approved', 1)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('vendor_ledger_entries')
                        ->whereColumn('vendor_ledger_entries.reference_id', 'withdraw_requests.id')
                        ->where('vendor_ledger_entries.reference_type', 'legacy_withdraw');
                })
                ->count();

            if ($unbridged > 0) {
                $discrepancies[] = [
                    'ref' => 'approved legacy withdrawals not mirrored in the ledger',
                    'expected' => 0,
                    'actual' => $unbridged,
                    'delta' => $unbridged,
                ];
            }
        }

        return [
            'key' => 'legacy_withdraws_bridged',
            'label' => 'Legacy withdrawals are mirrored into the ledger',
            'scope' => 'global count',
            'checked' => $checked,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Every pending order earning must carry an `available_at`, or `releaseMatured()` can never move it
     * to available and the seller can never be settled or paid. This is the monitor for the maturation
     * defect the audit found: a pending `order_earning` with a null `available_at` is stuck money, and a
     * non-zero count here is the alarm that earnings are being booked without a maturation date again.
     */
    public function pendingEarningsCanMature(): array
    {
        $discrepancies = [];
        $checked = 0;

        if (Schema::hasTable('vendor_ledger_entries')) {
            $checked = 1;
            $stuck = VendorLedgerEntry::where('entry_type', VendorLedgerEntry::TYPE_ORDER_EARNING)
                ->where('status', VendorLedgerEntry::STATUS_PENDING)
                ->whereNull('available_at')
                ->count();

            if ($stuck > 0) {
                $discrepancies[] = [
                    'ref' => 'order earnings with no maturation date',
                    'expected' => 0,
                    'actual' => $stuck,
                    'delta' => $stuck,
                ];
            }
        }

        return [
            'key' => 'earnings_can_mature',
            'label' => 'Pending order earnings carry a maturation date',
            'scope' => 'global count',
            'checked' => $checked,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Every seller's last running balance must equal the net of all their entries. This is the core
     * invariant of the running-balance ledger: if `balance_after` was ever maintained wrongly, the
     * cumulative figure and the independent sum diverge here.
     */
    public function ledgerRunningBalanceIntegrity(): array
    {
        $discrepancies = [];
        $checked = 0;

        if (Schema::hasTable('vendor_ledger_entries')) {
            $keys = VendorLedgerEntry::query()
                ->selectRaw('seller_id, seller_is, SUM(credit) as credits, SUM(debit) as debits, MAX(id) as last_id')
                ->groupBy('seller_id', 'seller_is')->get();

            foreach ($keys as $k) {
                $checked++;
                $net = round((float) $k->credits - (float) $k->debits, 2);
                $lastBalance = round((float) VendorLedgerEntry::where('id', $k->last_id)->value('balance_after'), 2);

                if (abs($net - $lastBalance) > self::EPSILON) {
                    $discrepancies[] = [
                        'ref' => "seller {$k->seller_id} ({$k->seller_is})",
                        'expected' => $net,
                        'actual' => $lastBalance,
                        'delta' => round($lastBalance - $net, 2),
                    ];
                }
            }
        }

        return [
            'key' => 'ledger_running_balance',
            'label' => 'Ledger running balance matches the sum of entries',
            'scope' => 'per seller',
            'checked' => $checked,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Every commission snapshot has a matching ledger `commission_charge`. At order time each line
     * writes both, so the totals must agree; a divergence means a charge was recorded without its
     * snapshot or vice versa.
     */
    public function commissionSnapshotVsLedger(): array
    {
        $discrepancies = [];
        $checked = 0;

        if (Schema::hasTable('order_item_commissions') && Schema::hasTable('vendor_ledger_entries')) {
            $checked = 1;
            $snapshotTotal = round((float) OrderItemCommission::sum('commission_amount'), 2);
            $ledgerTotal = round((float) VendorLedgerEntry::where('entry_type', VendorLedgerEntry::TYPE_COMMISSION_CHARGE)->sum('debit'), 2);

            if (abs($snapshotTotal - $ledgerTotal) > self::EPSILON) {
                $discrepancies[] = [
                    'ref' => 'all commission',
                    'expected' => $snapshotTotal,
                    'actual' => $ledgerTotal,
                    'delta' => round($ledgerTotal - $snapshotTotal, 2),
                ];
            }
        }

        return [
            'key' => 'commission_snapshot_vs_ledger',
            'label' => 'Commission snapshots equal the ledger commission charges',
            'scope' => 'global total',
            'checked' => $checked,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Each settlement's claimed entries must sum to its stored net, and their count must match. A
     * settlement is meant to be an immutable roll-up of the entries it claimed via settlement_id, so
     * the roll-up and the entries can never disagree.
     */
    public function settlementIntegrity(): array
    {
        $discrepancies = [];
        $checked = 0;

        if (Schema::hasTable('vendor_settlements') && Schema::hasTable('vendor_ledger_entries')) {
            foreach (VendorSettlement::query()->cursor() as $settlement) {
                $checked++;
                $rows = VendorLedgerEntry::where('settlement_id', $settlement->id)
                    ->selectRaw('SUM(credit) as credits, SUM(debit) as debits, COUNT(*) as n')->first();

                $net = round((float) ($rows->credits ?? 0) - (float) ($rows->debits ?? 0), 2);
                $storedNet = round((float) $settlement->net_amount, 2);
                $count = (int) ($rows->n ?? 0);

                if (abs($net - $storedNet) > self::EPSILON) {
                    $discrepancies[] = [
                        'ref' => "settlement {$settlement->reference}",
                        'expected' => $storedNet,
                        'actual' => $net,
                        'delta' => round($net - $storedNet, 2),
                    ];
                } elseif ($settlement->entry_count !== null && (int) $settlement->entry_count !== $count) {
                    $discrepancies[] = [
                        'ref' => "settlement {$settlement->reference} (entry count)",
                        'expected' => (int) $settlement->entry_count,
                        'actual' => $count,
                        'delta' => $count - (int) $settlement->entry_count,
                    ];
                }
            }
        }

        return [
            'key' => 'settlement_integrity',
            'label' => 'Settlements match the entries they claimed',
            'scope' => 'per settlement',
            'checked' => $checked,
            'discrepancies' => $discrepancies,
        ];
    }

    /** True when every check reconciles. */
    public function isClean(array $results): bool
    {
        foreach ($results as $r) {
            if (!empty($r['discrepancies'])) {
                return false;
            }
        }

        return true;
    }
}
