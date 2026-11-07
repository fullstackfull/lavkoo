<?php

namespace Tests\Unit\SalesChannels;

use App\Services\SalesChannels\Inventory\PoolFigures;
use App\Services\SalesChannels\Inventory\StockAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** One formula for what a pool may still offer: Net = max(0, P - Hs - Hc - B), deficit = max(0, Hs + Hc - P). */
class PoolFiguresTest extends TestCase
{
    /** @return iterable<string, array{int, int, int, int, int, int}> */
    public static function pools(): iterable
    {
        //                          P   Hs  Hc  B   net deficit
        yield 'the worked example' => [20, 3, 2, 1, 14, 0];
        yield 'nothing held' => [20, 0, 0, 0, 20, 0];
        yield 'only channel orders' => [20, 0, 2, 0, 18, 0];
        yield 'only store orders' => [20, 3, 0, 0, 17, 0];
        yield 'the buffer is more than is free' => [4, 1, 1, 5, 0, 0];
        yield 'promised exactly what there is' => [5, 3, 2, 0, 0, 0];
        yield 'promised more than there is' => [3, 2, 2, 0, 0, 1];
        yield 'a buffer is not a promise' => [3, 2, 2, 9, 0, 1];
        yield 'an empty pool' => [0, 0, 0, 0, 0, 0];
        yield 'an empty pool with promises' => [0, 1, 1, 0, 0, 2];
    }

    #[DataProvider('pools')]
    public function test_net_and_deficit(int $available, int $storeHolds, int $channelHolds, int $buffer, int $net, int $deficit): void
    {
        $figures = new PoolFigures($available, StockAvailability::CONFIRMED, null, 'provider_mapping', $storeHolds, $channelHolds, $buffer);

        $this->assertSame($net, $figures->net());
        $this->assertSame($deficit, $figures->deficit());
        $this->assertSame(['net' => $net, 'deficit' => $deficit], PoolFigures::balance($available, $storeHolds, $channelHolds, $buffer));
    }

    public function test_a_pool_without_a_figure_has_no_net(): void
    {
        $figures = new PoolFigures(null, StockAvailability::UNKNOWN, null, 'provider_mapping', 0, 0, 0);

        $this->assertNull($figures->net());
        $this->assertSame(0, $figures->deficit());
        $this->assertFalse($figures->isPublishable());
    }

    /** @return iterable<string, array{string, ?string, bool}> */
    public static function qualities(): iterable
    {
        yield 'confirmed' => [StockAvailability::CONFIRMED, null, true];
        yield 'typed by a person' => [StockAvailability::MANUAL, null, true];
        yield 'stale' => [StockAvailability::STALE, null, false];
        yield 'unknown' => [StockAvailability::UNKNOWN, null, false];
        yield 'a refused pool is never offered' => [StockAvailability::CONFIRMED, PoolFigures::VARIANT_KEY_REQUIRED, false];
        yield 'the store owns it' => [StockAvailability::MANUAL, PoolFigures::STOREFRONT_OWNED, false];
    }

    #[DataProvider('qualities')]
    public function test_only_a_confirmed_or_typed_figure_that_is_not_refused_is_publishable(string $quality, ?string $refusal, bool $publishable): void
    {
        $figures = new PoolFigures(10, $quality, null, 'provider_mapping', 0, 0, 0, $refusal);

        $this->assertSame($publishable, $figures->isPublishable());
    }

    /** @return iterable<string, array{string, ?string, int, bool}> */
    public static function coverage(): iterable
    {
        yield 'a confirmed figure that is enough' => [StockAvailability::CONFIRMED, null, 2, true];
        yield 'a confirmed figure that is not enough' => [StockAvailability::CONFIRMED, null, 11, false];
        yield 'typed and enough' => [StockAvailability::MANUAL, null, 10, true];
        yield 'stale' => [StockAvailability::STALE, null, 1, false];
        yield 'unknown' => [StockAvailability::UNKNOWN, null, 1, false];
        yield 'the store\'s own stock is read though it is not offered' => [StockAvailability::CONFIRMED, PoolFigures::STOREFRONT_OWNED, 2, true];
        yield 'the store\'s own stock that is not enough' => [StockAvailability::CONFIRMED, PoolFigures::STOREFRONT_OWNED, 11, false];
        yield 'a product sold per variant asked for without one' => [StockAvailability::CONFIRMED, PoolFigures::VARIANT_KEY_REQUIRED, 1, false];
        yield 'a variant that has no figure of its own' => [StockAvailability::CONFIRMED, PoolFigures::VARIANT_FIGURE_MISSING, 1, false];
    }

    #[DataProvider('coverage')]
    public function test_a_pool_covers_an_order_when_its_figure_is_good_and_enough_is_left(string $quality, ?string $refusal, int $units, bool $covers): void
    {
        $figures = new PoolFigures(10, $quality, null, 'provider_mapping', 0, 0, 0, $refusal);

        $this->assertSame($covers, $figures->covers($units));
    }
}
