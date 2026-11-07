<?php

namespace App\Services\SalesChannels\Publishing;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\PublishesStockAndPrices;
use App\Services\SalesChannels\Contracts\ReportsPublishResults;
use App\Services\SalesChannels\Data\PublishResult;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Inventory\PoolNet;
use App\Services\SalesChannels\Sync\ChannelSyncRunner;
use App\Services\SalesChannels\Sync\SyncLease;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Decides what a channel is shown for a listing and sends only what is worth sending.
 *
 * STOCK. The quantity comes from the location the listing is set to take it from, and only a quantity that location's own source of truth confirmed recently (or a person
 * typed and labelled as such) is ever offered: an estimate, a stale figure or no figure means nothing is published. Units already promised (to open channel orders, and to store
 * orders the provider has not counted yet: see PoolNet) are subtracted, then the safety buffer, then the cap. Amazon FBA listings and FBA stock are never touched (Amazon holds
 * that stock); a location may only feed the channels it lists.
 *
 * PRICE. Only a price a person set for the listing (`price_source = manual`) is published. A channel that owns its price is left alone. Taking the storefront's own price is
 * not offered: the storefront price depends on variations, clearance sales and currency conversion, and publishing it to a marketplace needs a designed conversion and an owner's
 * sign-off first.
 *
 * NO LOOPS, NO NOISE. What was last pushed is remembered; a push that would repeat it is skipped unless the channel was read back with something different. Reading a channel never
 * changes our stock. A change that failed is retried, but a value that has failed three times is left for a person instead of being sent again every cycle.
 *
 * Whatever the connector accepts is only QUEUED: it is confirmed later, by the batch result where the channel gives one or by reading the listing back.
 */
class StockPricePublisher
{
    public const STOCK = 'publish_stock';
    public const PRICE = 'publish_price';
    public const MAX_FAILURES = 3;

    /** Both channels take prices with two decimals: a price is judged (and sent) as the channel will read it. */
    private const PRICE_SCALE = 2;

    /** Trendyol keeps a batch result for four hours; a little less is trusted. */
    private const BATCH_KEPT_MINUTES = 230;

    private const CHANNEL_KEYS = ['trendyol' => 'trendyol', 'amazon' => 'amazon_fbm'];

    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ChannelSyncRunner $runner,
        private readonly PoolNet $pools,
        private readonly PublishPolicyResolver $policies,
    ) {
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function publishStock(SalesChannelAccount $account): array
    {
        return $this->runner->run($account, self::STOCK, fn (SyncLease $lease): array => ['stats' => $this->push($account, self::STOCK)]);
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function publishPrices(SalesChannelAccount $account): array
    {
        return $this->runner->run($account, self::PRICE, fn (SyncLease $lease): array => ['stats' => $this->push($account, self::PRICE)]);
    }

    /** @return array<string, mixed> */
    private function push(SalesChannelAccount $account, string $kind): array
    {
        $connector = $this->connectors->able($account, PublishesStockAndPrices::class);
        $stats = ['considered' => 0, 'sent' => 0, 'accepted' => 0, 'failed' => 0, 'dry_run' => false, 'skipped' => []];
        $updates = [];

        foreach (SalesChannelProductMapping::where('account_id', $account->id)->mapped()->orderBy('id')->cursor() as $mapping) {
            $policy = $this->policies->resolve($mapping);
            if (!($kind === self::STOCK ? $policy->inventoryPush : $policy->pricePush)) {
                continue;
            }
            $stats['considered']++;
            $update = $kind === self::STOCK ? $this->planStock($account, $mapping, $policy) : $this->planPrice($account, $mapping, $policy);
            if (is_string($update)) {
                $stats['skipped'][$update] = ($stats['skipped'][$update] ?? 0) + 1;
                continue;
            }
            $updates[$mapping->id] = $update;
        }

        if ($updates === []) {
            return $stats;
        }

        $stats['sent'] = count($updates);
        $result = $kind === self::STOCK ? $connector->publishStock($account, array_values($updates)) : $connector->publishPrices($account, array_values($updates));
        $stats['dry_run'] = $result->dryRun;
        if (!$result->dryRun) {
            $stats['accepted'] = count($result->accepted);
            $stats['failed'] = count($result->failed);
            $this->remember($kind, $updates, $result);
        }

        return $stats;
    }

    /** @return PublishUpdate|string the update to send, or the reason nothing is sent */
    private function planStock(SalesChannelAccount $account, SalesChannelProductMapping $mapping, PublishPolicy $policy): PublishUpdate|string
    {
        if ($mapping->fulfillment_mode === 'fba') {
            return 'fba_listing';
        }
        // A quantity goes to the merchant-fulfilled offer (`DEFAULT`): on a listing whose offer Amazon did not describe that would start one, so only a listing known to have one takes it.
        if ($account->channel === 'amazon' && $mapping->fulfillment_mode === null) {
            return 'fulfillment_mode_unknown';
        }
        $location = $policy->stockLocationId === null ? null : InventoryLocation::find($policy->stockLocationId);
        if ($location === null || !$location->is_active) {
            return 'no_stock_source';
        }
        if (!in_array(self::CHANNEL_KEYS[$account->channel] ?? $account->channel, (array) ($location->publishable_channels ?? []), true)) {
            return 'location_not_for_this_channel';
        }

        $pool = $this->pools->for($location, (int) $mapping->product_id, (string) $mapping->variant_key);
        if ($pool->refusal !== null) {
            return $pool->refusal;
        }
        if (!$pool->isPublishable()) {
            return 'stock_not_confirmed:' . $pool->quality;
        }

        $quantity = max(0, (int) $pool->net() - $policy->safetyBuffer);
        if ($policy->maxQuantity !== null) {
            $quantity = min($quantity, $policy->maxQuantity);
        }
        $skip = $this->skipReason($mapping, 'stock', (string) $quantity, $mapping->last_published_quantity, $mapping->remote_quantity);
        if ($skip !== null) {
            return $skip;
        }

        return new PublishUpdate((int) $mapping->id, $mapping->listing_key, $mapping->external_sku, $mapping->external_barcode, quantity: $quantity, currency: $account->currency, fulfillmentMode: $mapping->fulfillment_mode);
    }

    /** @return PublishUpdate|string */
    private function planPrice(SalesChannelAccount $account, SalesChannelProductMapping $mapping, PublishPolicy $policy): PublishUpdate|string
    {
        if ($policy->priceSource === 'channel') {
            return 'channel_owns_price';
        }
        if ($policy->priceSource !== 'manual') {
            return 'storefront_price_not_supported';
        }
        $manual = $mapping->snapshot['manual_price'] ?? null;
        if (!is_string($manual) && !is_numeric($manual)) {
            return 'no_manual_price';
        }
        $price = Money::of((string) $manual)->round(scale: self::PRICE_SCALE);
        if (!$price->isPositive()) {
            return 'price_not_positive';
        }
        $skip = $this->skipReason($mapping, 'price', $price->toStorage(), $mapping->last_published_price, $mapping->remote_price);
        if ($skip !== null) {
            return $skip;
        }

        return new PublishUpdate((int) $mapping->id, $mapping->listing_key, $mapping->external_sku, $mapping->external_barcode, price: $price, currency: $account->currency, fulfillmentMode: $mapping->fulfillment_mode);
    }

    /**
     * Why sending would be pointless, or null when it is worth sending: it is what the channel already shows, it is still awaiting the result of the same change, or the same
     * change has failed too often to be worth repeating without a person looking at it.
     */
    private function skipReason(SalesChannelProductMapping $mapping, string $field, string $wanted, mixed $lastPublished, mixed $remote): ?string
    {
        $state = $mapping->snapshot['publish'][$field] ?? [];
        $sameAttempt = ($state['attempted'] ?? null) === $wanted;

        $notTaken = in_array($state['state'] ?? null, ['failed', 'unconfirmed'], true);
        if ($notTaken && $sameAttempt && (int) ($state['failures'] ?? 0) >= self::MAX_FAILURES) {
            return 'failed_repeatedly';
        }
        if (($state['state'] ?? null) === 'failed') {
            return null;
        }
        if (($state['state'] ?? null) === 'queued' && $sameAttempt && CarbonImmutable::parse($state['at'])->gt(now()->subMinutes(60))) {
            return 'awaiting_result';
        }
        $same = static fn (mixed $held): bool => $held !== null && ($field === 'price' ? Money::of((string) $held)->equals($wanted) : (int) $held === (int) $wanted);

        return $same($lastPublished) && ($remote === null || $same($remote)) ? 'unchanged' : null;
    }

    /** @param array<int, PublishUpdate> $updates */
    private function remember(string $kind, array $updates, PublishResult $result): void
    {
        $field = $kind === self::STOCK ? 'stock' : 'price';
        foreach ($updates as $mappingId => $update) {
            $mapping = SalesChannelProductMapping::find($mappingId);
            if ($mapping === null) {
                continue;
            }
            $attempted = $kind === self::STOCK ? (string) $update->quantity : $update->price->toStorage();
            $snapshot = $mapping->snapshot ?? [];
            $previous = $snapshot['publish'][$field] ?? [];
            // The streak belongs to the value: it carries over every time the same value is sent again, and starts over when the value changes or the channel applied it.
            $streak = ($previous['attempted'] ?? null) === $attempted ? (int) ($previous['failures'] ?? 0) : 0;

            if (isset($result->accepted[$mappingId])) {
                $snapshot['publish'][$field] = ['state' => 'queued', 'ref' => $result->accepted[$mappingId], 'attempted' => $attempted, 'at' => now()->toIso8601String(), 'failures' => $streak];
                $mapping->forceFill($kind === self::STOCK ? ['last_published_quantity' => $update->quantity, 'last_published_at' => now()] : ['last_published_price' => $update->price->toStorage(), 'last_published_at' => now()]);
            } else {
                $transient = in_array($mappingId, $result->transient, true);
                $snapshot['publish'][$field] = [
                    'state' => 'failed',
                    'error' => mb_substr((string) ($result->failed[$mappingId] ?? 'The channel did not take the update.'), 0, 300),
                    'attempted' => $attempted,
                    'at' => now()->toIso8601String(),
                    // A request that never arrived, or met a busy or failing channel, says nothing against the value: it is tried again without counting.
                    'failures' => $transient ? $streak : $streak + 1,
                    'transient' => $transient,
                ];
            }
            $mapping->forceFill(['snapshot' => $snapshot])->save();
        }
    }

    /**
     * Follows up what the channel QUEUED: a batch result where the channel gives one, otherwise the listing as last read back. A change the channel reports failed is marked so it
     * can be corrected (and retried at most three times); one that the listing shows to be different long after it was sent is marked unconfirmed.
     *
     * A batch the channel can not be asked about right now (a switch is off, the channel is busy or failing, the batch is gone) stays queued and is counted as unreadable;
     * the other batches are followed all the same.
     *
     * @return array{applied: int, failed: int, unconfirmed: int, pending: int, unreadable: int}
     */
    public function settle(SalesChannelAccount $account): array
    {
        $counts = ['applied' => 0, 'failed' => 0, 'unconfirmed' => 0, 'pending' => 0, 'unreadable' => 0];
        $connector = $this->connectors->for($account);
        $batches = [];

        foreach (SalesChannelProductMapping::where('account_id', $account->id)->mapped()->cursor() as $mapping) {
            foreach (['stock', 'price'] as $field) {
                $state = $mapping->snapshot['publish'][$field] ?? null;
                if (($state['state'] ?? null) !== 'queued') {
                    continue;
                }
                $batchStillKept = CarbonImmutable::parse($state['at'])->gt(now()->subMinutes(self::BATCH_KEPT_MINUTES));
                if ($connector instanceof ReportsPublishResults && $batchStillKept) {
                    $batches[(string) $state['ref']][] = [$mapping->id, $field];
                    continue;
                }
                $this->settleByReadBack($mapping, $field, $counts);
            }
        }

        if ($connector instanceof ReportsPublishResults) {
            foreach ($batches as $ref => $members) {
                $this->settleBatch($account, $connector, (string) $ref, $members, $counts);
            }
        }

        return $counts;
    }

    /** @param array<string, int> $counts */
    private function settleByReadBack(SalesChannelProductMapping $mapping, string $field, array &$counts): void
    {
        $state = $mapping->snapshot['publish'][$field];
        $remote = $field === 'stock' ? $mapping->remote_quantity : $mapping->remote_price;
        $matches = $remote !== null && ($field === 'stock' ? (int) $remote === (int) $state['attempted'] : Money::of((string) $remote)->equals($state['attempted']));
        $readSince = $mapping->last_seen_at !== null && $mapping->last_seen_at->gt(CarbonImmutable::parse($state['at']));

        if ($matches) {
            $this->setState($mapping, $field, 'applied');
            $counts['applied']++;
        } elseif ($readSince && CarbonImmutable::parse($state['at'])->lt(now()->subMinutes(60))) {
            $this->setState($mapping, $field, 'unconfirmed', failures: (int) ($state['failures'] ?? 0) + 1);
            $counts['unconfirmed']++;
        } else {
            $counts['pending']++;
        }
    }

    /**
     * @param list<array{0: int, 1: string}> $members
     * @param array<string, int> $counts
     */
    private function settleBatch(SalesChannelAccount $account, ReportsPublishResults $connector, string $ref, array $members, array &$counts): void
    {
        try {
            $outcome = $connector->batchResult($account, $ref);
        } catch (RuntimeException) {
            $counts['unreadable'] += count($members);

            return;
        }
        foreach ($members as [$mappingId, $field]) {
            $mapping = SalesChannelProductMapping::find($mappingId);
            $item = $outcome['items'][$mapping->external_barcode ?? $mapping->listing_key] ?? null;
            if ($item === null || !in_array($item['status'], ['SUCCESS', 'FAILED'], true)) {
                $counts['pending']++;
                continue;
            }
            if ($item['status'] === 'SUCCESS') {
                $this->setState($mapping, $field, 'applied');
                $counts['applied']++;
                continue;
            }
            $snapshot = $mapping->snapshot;
            $failures = (int) ($snapshot['publish'][$field]['failures'] ?? 0) + 1;
            $snapshot['publish'][$field] = array_merge($snapshot['publish'][$field], ['state' => 'failed', 'error' => mb_substr(implode('; ', $item['reasons']), 0, 300), 'failures' => $failures]);
            $mapping->forceFill(['snapshot' => $snapshot])->save();
            $counts['failed']++;
        }
    }

    private function setState(SalesChannelProductMapping $mapping, string $field, string $state, ?int $failures = null): void
    {
        $snapshot = $mapping->snapshot;
        $snapshot['publish'][$field]['state'] = $state;
        $snapshot['publish'][$field]['settled_at'] = now()->toIso8601String();
        $snapshot['publish'][$field]['failures'] = $state === 'applied' ? 0 : ($failures ?? (int) ($snapshot['publish'][$field]['failures'] ?? 0));
        $mapping->forceFill(['snapshot' => $snapshot])->save();
    }
}
