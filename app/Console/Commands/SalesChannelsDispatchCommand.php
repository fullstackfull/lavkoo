<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\RoutingAction;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Dispatch\ChannelOrderDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SalesChannelsDispatchCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:dispatch {--account= : only this account id} {--limit=50 : orders per run}';

    protected $description = 'Turn open channel orders that a routing rule sends to a provider into provider shipments (accounts set to automatic dispatch only; nothing happens unless every dispatch switch is on)';

    public function handle(ChannelOrderDispatcher $dispatcher): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        if (!config('sales_channels.managed_dispatch_enabled', false) || !config('sales_channels.buyer_data_transfer_enabled', false)) {
            $this->line('Managed dispatch or buyer-data transfer is switched off; nothing to do.');

            return self::SUCCESS;
        }

        $automatic = SalesChannelAccount::where('enabled', true)->when($this->option('account'), fn ($query, $id) => $query->whereKey($id))->get()
            ->filter(static fn (SalesChannelAccount $account): bool => $account->setting('dispatch_mode', 'manual') === 'auto')->pluck('id');
        $counts = [];
        foreach ($this->openOrders($automatic->all(), (int) $this->option('limit')) as $mapping) {
            $state = $dispatcher->dispatch($mapping, trigger: 'auto')->state;
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        $this->info($counts === [] ? 'No order is waiting to be dispatched.' : 'Dispatch: ' . collect($counts)->map(static fn (int $count, string $state): string => "{$state} {$count}")->implode(', '));

        return self::SUCCESS;
    }

    /**
     * @param list<int> $accountIds
     * @return iterable<ExternalOrderMapping>
     */
    private function openOrders(array $accountIds, int $limit): iterable
    {
        return ExternalOrderMapping::with('account')
            ->whereIn('account_id', $accountIds)->whereNull('order_id')
            ->whereIn('status', [ChannelOrderStatus::New->value, ChannelOrderStatus::Processing->value, ChannelOrderStatus::AwaitingReview->value])
            ->whereExists(static fn ($decision) => $decision->select(DB::raw(1))->from('fulfillment_routing_decisions as decisions')
                ->whereColumn('decisions.external_order_mapping_id', 'external_order_mappings.id')->where('decisions.is_current', true)->where('decisions.action', RoutingAction::RouteToProvider->value))
            ->whereNotExists(static fn ($waiting) => $waiting->select(DB::raw(1))->from('channel_order_dispatches as attempts')
                ->whereColumn('attempts.mapping_id', 'external_order_mappings.id')
                ->where(static fn ($closed) => $closed->where('attempts.next_attempt_at', '>', now())->orWhere('attempts.attempts', '>=', (int) config('sales_channels.dispatch.max_attempts', 5))))
            ->orderBy('external_order_mappings.id')->limit($limit)->get();
    }
}
