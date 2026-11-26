<?php

namespace Tests\Support\Concurrency;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationMarket;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Allocation\MemberShare;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocation;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Inventory\PoolNet;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * What a worker process does in {@see \Tests\Feature\Concurrency\CountryPoolPublishTest}: divide, hold units of, and publish ONE market's
 * pool of a SKU that two markets both sell (M2-08, M2-12).
 *
 * **Why these cannot be the existing `stock.*` operations.** `StockOperations::key()` builds `new PoolKey(location, product, variant)` --
 * the three-part identity the platform had before a pool could name a market -- so every operation there addresses the DEFAULT market's
 * pool whatever the product's own market says. That is right for what those tests measure and is exactly wrong here: a test of two
 * markets' pools has to reach each pool the way production reaches it. So the key is built through `PoolKey::ofListing()`, which asks
 * {@see \App\Services\SalesChannels\Inventory\Allocation\PoolMarket} for the product's market, and a worker that was given the wrong
 * market would address the wrong row rather than silently share one.
 *
 * **The switch has to be set in THIS process.** `config()` in the parent reaches no worker: each one boots the application itself from the
 * inherited environment, and `gcc.storefronts.enabled` is off in `config/gcc.php`. So every operation here takes `storefronts` and sets it
 * before it touches a pool -- which is also what makes the dormant cases of that test real, since they pass `false` and go down the same
 * code path the platform runs today.
 */
final class CountryPoolOperations
{
    /** @param array{location: int, product: int, storefronts?: bool} $args */
    public static function divide(array $args): array
    {
        self::storefronts($args);

        return self::summary(app(PoolAllocationService::class)->allocate(self::key($args)));
    }

    /**
     * Buys units of ONE market's product: a hold against that market's shelf, then the pool marked as the importer marks it.
     *
     * @param array{location: int, product: int, quantity: int, reference: string, account: int, times?: int, storefronts?: bool, pause_microseconds?: int} $args
     * @return array{held: int, net: int|null, pool: string}
     */
    public static function buy(array $args): array
    {
        self::storefronts($args);
        $key = self::key($args);
        $times = max(1, (int) ($args['times'] ?? 1));

        for ($time = 1; $time <= $times; $time++) {
            app(ReservationService::class)->reserve(
                $args['reference'] . '-' . $time,
                (int) $args['location'],
                (int) $args['product'],
                '',
                (int) $args['quantity'],
                accountId: (int) $args['account'],
            );
            app(DirtyPools::class)->mark([$key]);
            usleep((int) ($args['pause_microseconds'] ?? 0));
        }

        return [
            'held' => $times * (int) $args['quantity'],
            'net' => app(PoolNet::class)->for(self::location($args), (int) $args['product'])->net(),
            'pool' => (string) $key,
        ];
    }

    /**
     * One stock publish of one market's account against a channel that accepts everything.
     *
     * `detail` is returned as well as `result`, because a market that is not open for sale is refused by
     * {@see \App\Services\SalesChannels\Publishing\ChannelAccountMarketStop} BEFORE the pool is ever consulted, and a test that could not
     * tell that apart from a pool that gave the account nothing would prove nothing about either.
     *
     * @param array{account: int, storefronts?: bool} $args
     * @return array{result: string, detail: string|null, sent: array<string, int>}
     */
    public static function publish(array $args): array
    {
        self::storefronts($args);
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-' . getmypid()])]);

        $outcome = app(StockPricePublisher::class)->publishStock(SalesChannelAccount::findOrFail($args['account']));

        $sent = [];
        foreach (Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/products/price-and-inventory')) as $pair) {
            foreach ($pair[0]->data()['items'] as $item) {
                $sent[$item['barcode']] = (int) $item['quantity'];
            }
        }

        return ['result' => (string) $outcome['result'], 'detail' => $outcome['detail'] ?? null, 'sent' => $sent];
    }

    /** @param array<string, mixed> $args */
    private static function storefronts(array $args): void
    {
        config([
            'gcc.storefronts.enabled' => (bool) ($args['storefronts'] ?? false),
            'sales_channels.enabled' => true,
            'sales_channels.external_writes_enabled' => true,
        ]);

        app(StorefrontMarketRows::class)->forget();
        app(InventoryLocationMarket::class)->forget();
    }

    /** The pool key the way production builds it: from the listing's product, through the market the product names. */
    private static function key(array $args): PoolKey
    {
        $listing = (new SalesChannelProductMapping())->forceFill(['product_id' => (int) $args['product'], 'variant_key' => '']);

        return PoolKey::ofListing($listing, (int) $args['location']);
    }

    private static function location(array $args): \App\Models\InventoryLocation
    {
        return \App\Models\InventoryLocation::findOrFail((int) $args['location']);
    }

    /** @return array{version: int, net: int|null, pool: string, publish: array<int, int>} */
    private static function summary(PoolAllocation $allocation): array
    {
        return [
            'version' => $allocation->version,
            'net' => $allocation->net,
            'pool' => (string) $allocation->key,
            'publish' => array_map(static fn (MemberShare $share): int => $share->publish(), $allocation->shares),
        ];
    }
}
