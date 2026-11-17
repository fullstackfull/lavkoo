<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A small world with known numbers, for the tests of what the platform admin API reports: products of four countries and one that is not
 * configured, orders in every status, some that cannot be given a market, refund requests, sellers with their own stock limits.
 *
 * "Now" is 2026-11-17 12:00 in the application time zone, so the default window of thirty days is 2026-10-19 to 2026-11-17.
 *
 * What it holds, and so what a report must say (live products, a window of the last thirty days):
 *
 *   stock    SA zero 1 low 3   AE zero 0 low 1   KW zero 2 low 1   (QA, BH, OM nothing)   not a market: zero 1
 *   orders   SA 5 (pending 1, confirmed 1, processing 1, delivered 2)   AE 1 (processing)   KW 3 (pending, delivered, canceled)
 *            unattributed 4 (pending 2, confirmed 1, delivered 1)
 *   backlog  SA 4   AE 1   KW 1   unattributed 3          (pending, confirmed or processing, created within the last 92 days)
 *   refunds  SA 2 (1 pending)   KW 1 (1 pending)   unattributed 2 (1 pending)
 */
trait SeedsPlatformMarketData
{
    protected function seedMarketScenario(): void
    {
        $this->travelTo('2026-11-17 12:00:00');
        DB::table('business_settings')->insert(['type' => 'stock_limit', 'value' => '5']);
        Cache::flush();

        $this->seedSellers();
        $this->seedProducts();
        $this->seedOrders();
        $this->seedRefundRequests();
    }

    private function seedSellers(): void
    {
        DB::table('sellers')->insert([
            ['id' => 10, 'f_name' => 'Approved shop', 'status' => 'approved', 'stock_limit' => 2, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'f_name' => 'Pending shop', 'status' => 'pending', 'stock_limit' => 0, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'f_name' => 'Deleted shop', 'status' => 'approved', 'stock_limit' => 0, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function seedProducts(): void
    {
        $this->product(id: 1, country: 'SA', stock: 0);
        $this->product(id: 2, country: 'SA', stock: 1);
        $this->product(id: 3, country: 'SA', stock: 5);
        $this->product(id: 4, country: 'SA', stock: 6);
        $this->product(id: 5, country: 'SA', stock: 2, sellerId: 10);
        $this->product(id: 6, country: 'SA', stock: 3, sellerId: 10);

        $this->product(id: 7, country: 'KW', stock: 0);
        $this->product(id: 8, country: 'KW', stock: 0);
        $this->product(id: 9, country: 'KW', stock: 4);

        $this->product(id: 10, country: 'AE', stock: 0, status: 0);
        $this->product(id: 11, country: 'AE', stock: 0, requestStatus: 0);
        $this->product(id: 12, country: 'AE', stock: 0, type: 'digital');
        $this->product(id: 13, country: 'AE', stock: 0, sellerId: 11);
        $this->product(id: 14, country: 'AE', stock: 0, sellerId: 12);
        $this->product(id: 15, country: 'AE', stock: 2);

        $this->product(id: 16, country: 'XX', stock: 0);
    }

    private function seedOrders(): void
    {
        $this->order(id: 1, status: 'pending', at: '2026-11-17 09:00:00', products: [1]);
        $this->order(id: 2, status: 'confirmed', at: '2026-11-15 10:00:00', products: [2, 3]);
        $this->order(id: 3, status: 'delivered', at: '2026-11-10 10:00:00', products: [4]);
        $this->order(id: 4, status: 'pending', at: '2026-11-16 10:00:00', products: [7]);
        $this->order(id: 5, status: 'delivered', at: '2026-11-12 10:00:00', products: [8, 9]);
        $this->order(id: 6, status: 'canceled', at: '2026-11-11 10:00:00', products: [7]);
        $this->order(id: 7, status: 'processing', at: '2026-11-14 10:00:00', products: [15]);

        $this->order(id: 8, status: 'pending', at: '2026-11-13 10:00:00', products: [1, 7]);
        $this->order(id: 9, status: 'pending', at: '2026-11-13 11:00:00', products: []);
        $this->order(id: 10, status: 'confirmed', at: '2026-11-13 12:00:00', products: [9999]);
        $this->order(id: 11, status: 'delivered', at: '2026-11-13 13:00:00', products: [16]);

        $this->order(id: 12, status: 'pending', at: '2026-10-10 10:00:00', products: [1]);
        $this->order(id: 13, status: 'pending', at: '2026-07-01 10:00:00', products: [1]);
        $this->order(id: 14, status: 'delivered', at: '2026-10-19 00:00:00', products: [4]);
        $this->order(id: 15, status: 'delivered', at: '2026-10-18 23:59:59', products: [4]);
        $this->order(id: 16, status: 'processing', at: '2026-11-17 18:00:00', products: [3]);
        $this->order(id: 17, status: 'delivered', at: '2026-11-18 00:00:00', products: [4]);
    }

    private function seedRefundRequests(): void
    {
        $this->refund(status: 'pending', at: '2026-11-16 10:00:00', product: 1);
        $this->refund(status: 'approved', at: '2026-11-12 10:00:00', product: 2);
        $this->refund(status: 'pending', at: '2026-11-15 10:00:00', product: 7);
        $this->refund(status: 'pending', at: '2026-10-01 10:00:00', product: 1);
        $this->refund(status: 'pending', at: '2026-11-14 10:00:00', product: 9999);
        $this->refund(status: 'refunded', at: '2026-11-14 11:00:00', product: 16);
    }

    protected function product(int $id, string $country, int $stock, ?int $sellerId = null, int $status = 1, int $requestStatus = 1, string $type = 'physical'): void
    {
        DB::table('products')->insert([
            'id' => $id,
            'name' => "Product {$id}",
            'added_by' => $sellerId === null ? 'admin' : 'seller',
            'user_id' => $sellerId,
            'product_type' => $type,
            'status' => $status,
            'request_status' => $requestStatus,
            'current_stock' => $stock,
            'storefront_country' => $country,
        ]);
    }

    /** @param list<int> $products */
    protected function order(int $id, string $status, string $at, array $products): void
    {
        DB::table('orders')->insert(['id' => $id, 'order_status' => $status, 'created_at' => $at, 'updated_at' => $at]);
        foreach ($products as $product) {
            DB::table('order_details')->insert(['order_id' => $id, 'product_id' => $product, 'qty' => 1, 'created_at' => $at, 'updated_at' => $at]);
        }
    }

    protected function refund(string $status, string $at, int $product): void
    {
        DB::table('refund_requests')->insert(['status' => $status, 'product_id' => $product, 'created_at' => $at, 'updated_at' => $at]);
    }
}
