<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelSyncState;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use Carbon\CarbonInterface;

/**
 * Whether the orders of an account are known to be all imported. A quantity is only RAISED when they are: an order the channel has taken and we have not imported yet is stock sold
 * that the quantity does not show, and raising the quantity then offers it a second time.
 *
 * The proof is a COMPLETE sweep of the account's orders that finished within `order_fresh_minutes`: a sweep that stopped part of the way through a backlog (it leaves a cursor
 * and still stamps its time) proves nothing about the orders it has not read yet. An account whose orders are never imported (no order import switched on, or none ever finished) has
 * no proof, so a quantity of a pool it offers is never raised: every sale it makes is unknown to us. Lowering, and the first quantity a listing is shown, do not need proof.
 */
class OrderFreshness
{
    public const IMPORT_OFF = 'import_off';
    public const BACKLOG = 'backlog';
    public const NEVER_COMPLETED = 'never_completed';
    public const LATE = 'late';

    /**
     * @param list<int> $accountIds
     * @return list<int> the accounts without proof that their orders are all imported
     */
    public function staleAccounts(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $limit = now()->subMinutes((int) config('sales_channels.stock.allocation.order_fresh_minutes'));
        $proven = SalesChannelSyncState::whereIn('account_id', $accountIds)->where('resource', ChannelSyncService::ORDERS)
            ->whereNull('cursor')->where('last_success_at', '>=', $limit)
            ->pluck('account_id')->map(static fn ($id): int => (int) $id)->all();

        return array_values(array_diff($accountIds, $proven));
    }

    /**
     * Did the account's order import finish a COMPLETE sweep that began after the given moment? Then every order the channel held at that moment has been imported, so a quantity the
     * channel showed then is explained by the orders we know: a sale it made is a hold (or a cancelled order that gave its units back), and nothing it sold is still unknown.
     */
    public function importedSince(int $accountId, CarbonInterface $moment): bool
    {
        return SalesChannelSyncState::where('account_id', $accountId)->where('resource', ChannelSyncService::ORDERS)
            ->whereNull('cursor')->whereColumn('last_success_at', '>=', 'last_attempt_at')->where('last_attempt_at', '>=', $moment)
            ->exists();
    }

    /**
     * Why each of these accounts has no proof, for a screen: it does not import orders at all, its last sweep stopped part of the way through a backlog, none ever finished, or the
     * last one finished too long ago.
     *
     * @param list<int> $staleAccountIds the result of `staleAccounts`
     * @return array<int, array{reason: string, last_success_at: CarbonInterface|null}> by account id
     */
    public function why(array $staleAccountIds): array
    {
        $accounts = SalesChannelAccount::whereIn('id', $staleAccountIds)->get(['id', 'capabilities'])->keyBy('id');
        $states = SalesChannelSyncState::whereIn('account_id', $staleAccountIds)->where('resource', ChannelSyncService::ORDERS)->get(['account_id', 'cursor', 'last_success_at'])->keyBy('account_id');
        $why = [];
        foreach ($staleAccountIds as $accountId) {
            $state = $states->get($accountId);
            $why[$accountId] = [
                'reason' => match (true) {
                    !($accounts->get($accountId)?->hasCapability(IntegrationCapability::OrderImport) ?? false) => self::IMPORT_OFF,
                    $state?->cursor !== null => self::BACKLOG,
                    $state?->last_success_at === null => self::NEVER_COMPLETED,
                    default => self::LATE,
                },
                'last_success_at' => $state?->last_success_at,
            ];
        }

        return $why;
    }
}
