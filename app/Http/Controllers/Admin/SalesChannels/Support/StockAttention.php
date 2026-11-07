<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Http\Controllers\Admin\SalesChannels\ChannelLabels;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryPool;
use App\Models\InventoryReservation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\OrderFreshness;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use App\Services\SalesChannels\Inventory\PoolKey;
use Illuminate\Support\Collection;

/**
 * What a person should look at about the stock shared between channels, each with the link that resolves it: pools promised more than they hold (with the orders holding them,
 * newest first: nothing here cancels an order), pools whose channels are shown more than is left, listings still showing stock the source no longer confirms, accounts whose
 * order import is behind (so no quantity of theirs is being raised), order units no pool is told about, and reservations held for days.
 */
final class StockAttention
{
    private const LIMIT = 8;

    private const HOLDING_ORDERS_SHOWN = 3;

    public function __construct(
        private readonly ChannelLabels $labels,
        private readonly ProductDirectory $products,
        private readonly OrderFreshness $freshness,
        private readonly ChannelOrderReservations $reservations,
    ) {
    }

    /**
     * @param Collection<int, SalesChannelAccount> $accounts
     * @return list<array{title: string, total: int, items: list<array{title: string, detail: string, url: string}>, moreUrl: string|null}>
     */
    public function sections(Collection $accounts): array
    {
        $listings = SalesChannelProductMapping::whereNotNull('allocation')->get(['id', 'account_id', 'listing_key', 'allocation', 'last_published_quantity']);

        return [
            $this->deficits(),
            $this->offeredBeyondWhatIsLeft($listings),
            $this->showingStockTheSourceDoesNotConfirm($listings),
            $this->raisesFrozen($accounts, $listings),
            $this->unattributed($accounts),
            $this->agedHolds(),
        ];
    }

    private function deficits(): array
    {
        $pools = InventoryPool::where('deficit', '>', 0)->orderByDesc('deficit')->orderBy('id')->get();
        $names = $this->products->named($pools->pluck('product_id'));

        return $this->section(translate('attention_stock_deficit'), $pools->count(), $pools->take(self::LIMIT)->map(fn (InventoryPool $pool): array => [
            'title' => $this->poolName($pool, $names),
            'detail' => number_format($pool->deficit) . ' ' . translate('units_promised_beyond_the_stock') . $this->holdingOrders($pool),
            'url' => route('admin.sales-channels.inventory.index', ['location' => $pool->location_id]),
        ])->values()->all());
    }

    /** @param Collection<int, SalesChannelProductMapping> $listings */
    private function offeredBeyondWhatIsLeft(Collection $listings): array
    {
        $shown = $listings->groupBy(static fn (SalesChannelProductMapping $listing): string => (string) ($listing->allocation['pool'] ?? ''))
            ->map(static fn (Collection $group): int => (int) $group->sum('last_published_quantity'));
        $pools = InventoryPool::whereNotNull('last_net')->orderBy('id')->get()->filter(fn (InventoryPool $pool): bool => ($shown[(string) $this->key($pool)] ?? 0) > $pool->last_net)->values();
        $names = $this->products->named($pools->pluck('product_id'));

        return $this->section(translate('attention_stock_over_offered'), $pools->count(), $pools->take(self::LIMIT)->map(fn (InventoryPool $pool): array => [
            'title' => $this->poolName($pool, $names),
            'detail' => translate('shown_on_channels') . ': ' . number_format($shown[(string) $this->key($pool)]) . ' · ' . translate('net_to_offer') . ': ' . number_format($pool->last_net),
            'url' => route('admin.sales-channels.inventory.index', ['location' => $pool->location_id]),
        ])->all());
    }

    /** @param Collection<int, SalesChannelProductMapping> $listings */
    private function showingStockTheSourceDoesNotConfirm(Collection $listings): array
    {
        $showing = $listings->filter(static fn (SalesChannelProductMapping $listing): bool => str_starts_with((string) ($listing->allocation['reason'] ?? ''), 'stock_not_confirmed') && (int) $listing->last_published_quantity > 0)->values();

        return $this->section(translate('attention_stock_source_not_confirmed'), $showing->count(), $showing->take(self::LIMIT)->map(fn (SalesChannelProductMapping $listing): array => [
            'title' => $listing->listing_key,
            'detail' => $this->labels->publishSkip((string) $listing->allocation['reason']) . ' · ' . translate('we_last_sent') . ': ' . (int) $listing->last_published_quantity,
            'url' => route('admin.sales-channels.products.index', ['account' => $listing->account_id, 'q' => $listing->listing_key]),
        ])->all());
    }

    /**
     * @param Collection<int, SalesChannelAccount> $accounts
     * @param Collection<int, SalesChannelProductMapping> $listings
     */
    private function raisesFrozen(Collection $accounts, Collection $listings): array
    {
        $behind = $this->freshness->why($this->freshness->staleAccounts($listings->pluck('account_id')->unique()->map(static fn ($id): int => (int) $id)->values()->all()));

        return $this->section(translate('attention_stock_raises_frozen'), count($behind), collect($behind)->take(self::LIMIT)->map(fn (array $why, int $accountId): array => [
            'title' => (string) ($accounts->firstWhere('id', $accountId)?->name ?? '#' . $accountId),
            'detail' => $this->whyFrozen($why),
            'url' => route('admin.sales-channels.accounts.show', $accountId),
        ])->values()->all());
    }

    /** @param array{reason: string, last_success_at: \Carbon\CarbonInterface|null} $why */
    private function whyFrozen(array $why): string
    {
        return match ($why['reason']) {
            OrderFreshness::IMPORT_OFF => translate('order_import_is_off_so_a_quantity_here_is_never_raised'),
            OrderFreshness::BACKLOG => translate('order_import_is_part_way_through_a_backlog'),
            OrderFreshness::NEVER_COMPLETED => translate('no_successful_order_import_yet'),
            default => translate('last_successful_order_import') . ': ' . $why['last_success_at']->format('Y-m-d H:i'),
        };
    }

    /** @param Collection<int, SalesChannelAccount> $accounts */
    private function unattributed(Collection $accounts): array
    {
        $units = $this->reservations->unattributedUnits();

        return $this->section(translate('attention_stock_unattributed'), array_sum($units), collect($units)->take(self::LIMIT)->map(fn (int $count, int $accountId): array => [
            'title' => (string) ($accounts->firstWhere('id', $accountId)?->name ?? '#' . $accountId),
            'detail' => number_format($count) . ' ' . translate('units_on_orders_linked_to_no_product_or_place'),
            'url' => route('admin.sales-channels.orders.index', ['review' => 1]),
        ])->values()->all(), route('admin.sales-channels.orders.index', ['review' => 1]));
    }

    private function agedHolds(): array
    {
        $holds = InventoryReservation::where('state', InventoryReservation::RESERVED)->where('reserved_at', '<', now()->subDays((int) config('sales_channels.stock.allocation.aged_hold_days')));
        $oldest = (clone $holds)->orderBy('reserved_at')->orderBy('id')->limit(self::LIMIT)->get();
        $orders = ExternalOrderMapping::whereIn('id', $oldest->pluck('external_order_mapping_id')->filter())->get()->keyBy('id');

        return $this->section(translate('attention_stock_aged_holds'), (clone $holds)->count(), $oldest->map(fn (InventoryReservation $hold): array => [
            'title' => ($order = $orders->get($hold->external_order_mapping_id)) === null ? $hold->reservation_key : $order->external_order_id,
            'detail' => number_format($hold->quantity) . ' ' . translate('units_held_since') . ' ' . $hold->reserved_at->format('Y-m-d'),
            'url' => $order === null ? route('admin.sales-channels.orders.index') : route('admin.sales-channels.orders.show', $order),
        ])->all());
    }

    /** The newest orders holding the pool, as ", held by orders: A, B". */
    private function holdingOrders(InventoryPool $pool): string
    {
        $mappingIds = InventoryReservation::where($this->key($pool)->columns())->whereIn('state', [InventoryReservation::RESERVED, InventoryReservation::COMMITTED])
            ->whereNotNull('external_order_mapping_id')->orderByDesc('id')->limit(self::HOLDING_ORDERS_SHOWN)->pluck('external_order_mapping_id');
        $ids = ExternalOrderMapping::whereIn('id', $mappingIds)->pluck('external_order_id', 'id');
        $newestFirst = $mappingIds->map(static fn ($id): ?string => $ids[$id] ?? null)->filter()->implode(', ');

        return $newestFirst === '' ? '' : ' · ' . translate('held_by_orders') . ': ' . $newestFirst;
    }

    private function key(InventoryPool $pool): PoolKey
    {
        return new PoolKey((int) $pool->location_id, (int) $pool->product_id, (string) $pool->variant_key);
    }

    /** @param Collection<int, \App\Models\Product> $names */
    private function poolName(InventoryPool $pool, Collection $names): string
    {
        return ($names->get($pool->product_id)?->name ?? '#' . $pool->product_id) . ($pool->variant_key !== '' ? ' · ' . $pool->variant_key : '');
    }

    /**
     * @param list<array{title: string, detail: string, url: string}> $items
     * @return array{title: string, total: int, items: list<array{title: string, detail: string, url: string}>, moreUrl: string|null}
     */
    private function section(string $title, int $total, array $items, ?string $moreUrl = null): array
    {
        return ['title' => $title, 'total' => $total, 'items' => $items, 'moreUrl' => $moreUrl];
    }
}
