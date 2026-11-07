<?php

namespace Tests\Unit\SalesChannels;

use App\Services\SalesChannels\Inventory\Allocation\AllocationMember;
use App\Services\SalesChannels\Inventory\Allocation\AllocationResult;
use App\Services\SalesChannels\Inventory\Allocation\StockAllocator;
use App\Services\SalesChannels\Inventory\Allocation\StockShare;
use App\Services\SalesChannels\Inventory\Allocation\StorefrontReserve;
use PHPUnit\Framework\TestCase;

/** How one pool's net is divided: never more than the net in total, whole units, monotone in the net, and the old formula when there is one listing and nothing reserved. */
class StockAllocatorTest extends TestCase
{
    private StockAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new StockAllocator();
    }

    /** @return array<int, int> entitlement by listing id */
    private function entitlements(AllocationResult $result): array
    {
        return array_map(static fn ($share): int => $share->entitlement, $result->shares);
    }

    // ---- the worked cases --------------------------------------------------------------------------------------------------------

    public function test_the_worked_example_half_for_the_storefront_and_an_equal_split_of_the_rest(): void
    {
        $result = $this->allocator->allocate(14, new StorefrontReserve(StorefrontReserve::PERCENT, 50), [new AllocationMember(1), new AllocationMember(2)]);

        $this->assertSame(7, $result->storefrontReserve);
        $this->assertSame([1 => 3, 2 => 3], $this->entitlements($result));
        $this->assertSame(1, $result->unassigned(), 'the unit rounding leaves over stays with the storefront');
    }

    public function test_a_reserve_in_units_is_never_more_than_the_net(): void
    {
        $result = $this->allocator->allocate(4, new StorefrontReserve(StorefrontReserve::FIXED, 10), [new AllocationMember(1)]);

        $this->assertSame([4, [1 => 0]], [$result->storefrontReserve, $this->entitlements($result)]);
    }

    public function test_fixed_shares_fill_in_priority_order_until_the_net_is_gone(): void
    {
        $result = $this->allocator->allocate(10, StorefrontReserve::none(), [
            new AllocationMember(1, StockShare::FIXED, 6, priority: 2),
            new AllocationMember(2, StockShare::FIXED, 6, priority: 1),
            new AllocationMember(3),
        ]);

        $this->assertSame([1 => 4, 2 => 6, 3 => 0], $this->entitlements($result));
    }

    public function test_listings_that_fill_by_priority_take_all_they_can_one_after_the_other_before_percentages_and_equal_parts(): void
    {
        $result = $this->allocator->allocate(20, StorefrontReserve::none(), [
            new AllocationMember(1, StockShare::PRIORITY, priority: 1, cap: 7),
            new AllocationMember(2, StockShare::PRIORITY, priority: 2, cap: 5),
            new AllocationMember(3, StockShare::PERCENT, 25),
            new AllocationMember(4),
        ]);

        $this->assertSame([1 => 7, 2 => 5, 3 => 5, 4 => 3], $this->entitlements($result), '7 + 5 first, then 25% of 20, then what is left (3) to the one that asked for nothing');
    }

    public function test_percentages_are_of_the_pool_and_what_nobody_claims_stays_unassigned(): void
    {
        $result = $this->allocator->allocate(20, StorefrontReserve::none(), [new AllocationMember(1, StockShare::PERCENT, 30), new AllocationMember(2, StockShare::PERCENT, 20)]);

        $this->assertSame([1 => 6, 2 => 4], $this->entitlements($result));
        $this->assertSame(10, $result->unassigned());
    }

    public function test_an_equal_split_gives_a_capped_listing_its_cap_and_the_difference_to_the_others(): void
    {
        $result = $this->allocator->allocate(10, StorefrontReserve::none(), [new AllocationMember(1, cap: 2), new AllocationMember(2)]);

        $this->assertSame([1 => 2, 2 => 8], $this->entitlements($result));
    }

    public function test_an_equal_split_rounds_down_and_leaves_the_rest_to_the_storefront(): void
    {
        $result = $this->allocator->allocate(7, StorefrontReserve::none(), [new AllocationMember(1), new AllocationMember(2), new AllocationMember(3)]);

        $this->assertSame([1 => 2, 2 => 2, 3 => 2], $this->entitlements($result));
        $this->assertSame(1, $result->unassigned());
    }

    public function test_what_is_shown_is_the_entitlement_less_the_buffer_and_the_expected_sales_and_never_below_zero(): void
    {
        $result = $this->allocator->allocate(10, StorefrontReserve::none(), [new AllocationMember(1, buffer: 1, lag: 1), new AllocationMember(2, buffer: 9)]);

        $this->assertSame([1 => 5, 2 => 5], $this->entitlements($result));
        $this->assertSame([3, 0], [$result->shares[1]->publish(), $result->shares[2]->publish()]);
    }

    public function test_a_pool_with_nothing_in_it_offers_nothing(): void
    {
        $result = $this->allocator->allocate(0, new StorefrontReserve(StorefrontReserve::PERCENT, 50), [new AllocationMember(1, StockShare::FIXED, 5), new AllocationMember(2)]);

        $this->assertSame([0, [1 => 0, 2 => 0]], [$result->storefrontReserve, $this->entitlements($result)]);
    }

    public function test_a_pool_with_no_listing_leaves_everything_to_the_storefront(): void
    {
        $result = $this->allocator->allocate(20, new StorefrontReserve(StorefrontReserve::PERCENT, 10), []);

        $this->assertSame([0, 2, 18], [$result->entitled(), $result->storefrontReserve, $result->unassigned()]);
    }

    // ---- the properties, over many configurations --------------------------------------------------------------------------------

    /** @return list<AllocationMember> */
    private function randomMembers(): array
    {
        $members = [];
        foreach (range(1, mt_rand(0, 5)) as $id) {
            if ($id === 1 && mt_rand(0, 6) === 0) {
                continue;
            }
            $mode = [null, null, StockShare::FIXED, StockShare::PRIORITY, StockShare::PERCENT][mt_rand(0, 4)];
            $members[] = new AllocationMember(
                listingId: $id,
                mode: $mode,
                value: $mode === StockShare::PERCENT ? mt_rand(0, 100) : mt_rand(0, 12),
                priority: mt_rand(0, 2) === 0 ? null : mt_rand(1, 3),
                cap: mt_rand(0, 2) === 0 ? mt_rand(0, 15) : null,
                buffer: mt_rand(0, 3) === 0 ? mt_rand(0, 4) : 0,
                lag: mt_rand(0, 3) === 0 ? mt_rand(0, 4) : 0,
            );
        }

        return $members;
    }

    private function randomReserve(): StorefrontReserve
    {
        return match (mt_rand(0, 2)) {
            0 => StorefrontReserve::none(),
            1 => new StorefrontReserve(StorefrontReserve::PERCENT, mt_rand(0, 100)),
            default => new StorefrontReserve(StorefrontReserve::FIXED, mt_rand(0, 14)),
        };
    }

    public function test_properties_hold_for_many_random_configurations(): void
    {
        mt_srand(20261110);
        for ($case = 0; $case < 700; $case++) {
            $members = $this->randomMembers();
            $reserve = $this->randomReserve();
            $previous = null;

            foreach (range(0, 40) as $net) {
                $result = $this->allocator->allocate($net, $reserve, $members);
                $where = "case {$case}, net {$net}";

                $this->assertLessThanOrEqual($net, $result->storefrontReserve + $result->entitled(), "{$where}: the reserve and the entitlements never add up to more than the net");
                $this->assertGreaterThanOrEqual(0, $result->unassigned(), $where);
                foreach ($members as $member) {
                    $share = $result->shares[$member->listingId];
                    $this->assertGreaterThanOrEqual(0, $share->entitlement, $where);
                    if ($member->ceiling() !== null) {
                        $this->assertLessThanOrEqual($member->ceiling(), $share->entitlement, "{$where}: an entitlement stops at the cap plus what is held back");
                        $this->assertLessThanOrEqual($member->cap, $share->publish(), "{$where}: what is shown is never over the cap");
                    }
                    if ($previous !== null) {
                        $this->assertGreaterThanOrEqual($previous->shares[$member->listingId]->entitlement, $share->entitlement, "{$where}: a bigger net never gives a listing less");
                        $this->assertGreaterThanOrEqual($previous->shares[$member->listingId]->publish(), $share->publish(), $where);
                    }
                }
                if ($previous !== null) {
                    $this->assertGreaterThanOrEqual($previous->storefrontReserve, $result->storefrontReserve, "{$where}: the reserve does not shrink either");
                }
                $previous = $result;
            }
        }
    }

    public function test_the_same_inputs_give_the_same_result_whatever_the_order_they_come_in(): void
    {
        mt_srand(77);
        for ($case = 0; $case < 300; $case++) {
            $members = $this->randomMembers();
            $reserve = $this->randomReserve();
            $net = mt_rand(0, 40);
            $shuffled = $members;
            shuffle($shuffled);

            $first = $this->allocator->allocate($net, $reserve, $members);
            $second = $this->allocator->allocate($net, $reserve, $shuffled);

            $this->assertEquals($first, $this->allocator->allocate($net, $reserve, $members), 'idempotent');
            $this->assertEquals($this->entitlements($first), array_replace($this->entitlements($first), $this->entitlements($second)), 'the order of the list changes nothing');
            $this->assertSame($first->storefrontReserve, $second->storefrontReserve);
        }
    }

    public function test_one_listing_and_no_reserve_is_the_formula_the_publisher_always_used(): void
    {
        foreach (range(0, 30) as $net) {
            foreach ([null, 0, 3, 12] as $cap) {
                foreach ([0, 2, 5] as $buffer) {
                    $result = $this->allocator->allocate($net, StorefrontReserve::none(), [new AllocationMember(1, cap: $cap, buffer: $buffer)]);

                    $legacy = max(0, $net - $buffer);
                    $legacy = $cap === null ? $legacy : min($legacy, $cap);
                    $this->assertSame($legacy, $result->shares[1]->publish(), "net {$net}, cap " . var_export($cap, true) . ", buffer {$buffer}");
                }
            }
        }
    }

    public function test_equal_listings_are_told_apart_only_by_their_id(): void
    {
        $result = $this->allocator->allocate(5, StorefrontReserve::none(), [
            new AllocationMember(9, StockShare::PRIORITY, priority: 1, cap: 3),
            new AllocationMember(4, StockShare::PRIORITY, priority: 1, cap: 3),
        ]);

        $this->assertSame([9 => 2, 4 => 3], $this->entitlements($result), 'the same priority: the lower id goes first');
    }
}
