<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\Concurrency\ConcurrentRunner;

/**
 * Six customers check out the last three units at the same instant, each through the real storefront order writer in its own PHP process,
 * against a real MySQL or MariaDB: three orders, stock exactly zero, three clean refusals and no deadlock escaping to a customer. Needs a
 * real engine: SQLite in memory is private to one process.
 */
class CheckoutStockConcurrencyTest extends ApiAddressTestCase
{
    private const PRODUCT = 1;

    private const STOCK = 3;

    private const CUSTOMERS = 6;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
    }

    /** @return array<string, string> a hash of every language file, by path */
    private function languageFileHashes(): array
    {
        $hashes = [];
        foreach (Finder::create()->files()->in(base_path('resources/lang')) as $file) {
            $hashes[$file->getRelativePathname()] = md5_file($file->getRealPath());
        }
        ksort($hashes);

        return $hashes;
    }

    public function test_six_customers_ordering_the_last_three_units_at_once_get_three_orders_and_three_refusals(): void
    {
        $languageFilesBefore = $this->languageFileHashes();
        $workerArguments = [];
        for ($customerNumber = 0; $customerNumber < self::CUSTOMERS; $customerNumber++) {
            $customer = $this->makeCustomer(overrides: ['email' => "checkout{$customerNumber}@example.test"]);
            $this->putOneProductInTheCart(callerId: $customer->id, stock: self::STOCK);
            $workerArguments[] = ['customer' => $customer->id, 'address' => $this->makeLegacyAddress(customerId: $customer->id)->id];
        }

        $reports = ConcurrentRunner::run('checkout.generate_order', $workerArguments);

        foreach ($reports as $index => $report) {
            $this->assertTrue($report['ok'], "worker {$index} let an exception escape: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? ''));
        }
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the checkouts ran at the same time');
        $orderIdsPerWorker = array_column($reports, 'result');
        $placed = array_filter($orderIdsPerWorker, static fn (array $orderIds): bool => count($orderIds) === 1);
        $refused = array_filter($orderIdsPerWorker, static fn (array $orderIds): bool => $orderIds === []);
        $this->assertCount(self::STOCK, $placed, 'one order per unit in stock');
        $this->assertCount(self::CUSTOMERS - self::STOCK, $refused, 'every other checkout was refused, not oversold');
        $this->assertCount(self::STOCK, array_unique(array_merge(...array_values($placed))), 'three distinct order ids');

        $this->assertSame(0, (int) DB::table('products')->where('id', self::PRODUCT)->value('current_stock'), 'stock is exactly zero, never negative');
        $this->assertSame(self::STOCK, DB::table('orders')->count());
        $this->assertSame(self::STOCK, (int) DB::table('order_details')->sum('qty'));
        $this->assertSame($languageFilesBefore, $this->languageFileHashes(), 'the workers wrote nothing into resources/lang');
    }
}
