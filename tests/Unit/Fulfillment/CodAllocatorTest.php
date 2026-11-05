<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\CodAllocator;
use PHPUnit\Framework\TestCase;

class CodAllocatorTest extends TestCase
{
    public function test_the_parts_always_sum_exactly_to_what_is_owed(): void
    {
        $allocator = new CodAllocator();

        foreach ([[100.00, [30, 30, 30]], [99.99, [1, 1, 1]], [0.05, [3, 5]], [1234.56, [333.33, 333.33, 567.9]], [10.00, [1, 2, 3, 4]]] as [$owed, $values]) {
            $parts = $allocator->allocate($owed, $values);
            $this->assertNotNull($parts);
            $this->assertSame((int) round($owed * 100), (int) round(array_sum($parts) * 100), "split of {$owed} drifted");
        }
    }

    public function test_the_split_is_proportional_to_value_shipped(): void
    {
        $parts = (new CodAllocator())->allocate(100.0, ['m5azn' => 25.0, 'torod_point' => 75.0]);

        $this->assertSame(['m5azn' => 25.0, 'torod_point' => 75.0], $parts);
    }

    public function test_the_rounding_remainder_goes_to_the_largest_shipment(): void
    {
        $parts = (new CodAllocator())->allocate(100.0, ['a' => 1, 'b' => 1, 'c' => 1]);

        $this->assertSame(10000, (int) round(array_sum($parts) * 100));
        $this->assertSame(33.34, max($parts));
    }

    public function test_nothing_owed_means_nobody_collects(): void
    {
        $this->assertSame(['a' => 0.0, 'b' => 0.0], (new CodAllocator())->allocate(0.0, ['a' => 10, 'b' => 20]));
    }

    public function test_it_refuses_to_split_when_there_is_no_value_to_weigh(): void
    {
        $this->assertNull((new CodAllocator())->allocate(50.0, ['a' => 0, 'b' => 0]));
    }

    public function test_no_collector_is_ever_asked_for_the_whole_amount_when_two_share_it(): void
    {
        $parts = (new CodAllocator())->allocate(80.0, ['m5azn' => 30, 'torod_point' => 50]);

        foreach ($parts as $part) {
            $this->assertLessThan(80.0, $part);
        }
    }
}
