<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

/**
 * Divides one pool's net between the listings that offer it, so that what all of them may be shown never adds up to more than the net. A pure function: the same inputs give the
 * same result, and nothing is read or written.
 *
 * ORDER. The storefront's reserve comes off first. Then, from what is left, in this order: listings with a FIXED number of units, listings that fill by PRIORITY (each takes all it
 * can before the next), listings with a PERCENT of the pool, and finally an equal split of the rest among the listings that asked for nothing in particular (a listing that cannot
 * take its equal part, for its cap, gives the difference back to the others). Within a mode the lower priority number goes first, then the lower listing id.
 *
 * WHOLE UNITS. Every amount is worked out exactly (in hundredths of a unit) and rounded DOWN once, at the end. What the rounding leaves over is nobody's: the storefront keeps it.
 * Rounding only at the end is what makes the result monotone: a bigger net never gives any listing less (and a smaller one never gives any listing more), so when the net moves
 * every channel moves the same way and none has to be lowered before another may be raised.
 *
 * LIMITS. A listing is never entitled to more than its cap plus what is held back from it (its buffer and its lag), so what it may be SHOWN (entitlement less buffer and lag) is
 * never over its cap. With one listing, no reserve and no lag this is the formula the publisher always used: the net less the buffer, within the cap.
 */
class StockAllocator
{
    private const ALL = PHP_INT_MAX;

    /** @param list<AllocationMember> $members */
    public function allocate(int $net, StorefrontReserve $reserve, array $members): AllocationResult
    {
        $net = max(0, $net);
        $budget = $net * 100 - $reserve->hundredths($net);
        $granted = [];

        foreach ([StockShare::FIXED, StockShare::PRIORITY, StockShare::PERCENT] as $mode) {
            foreach ($this->inOrder(array_filter($members, static fn (AllocationMember $member): bool => $member->mode === $mode)) as $member) {
                $demand = match ($mode) {
                    StockShare::FIXED => max(0, $member->value) * 100,
                    StockShare::PERCENT => $net * min(100, max(0, $member->value)),
                    default => self::ALL,
                };
                $take = min($demand, $member->ceiling() === null ? self::ALL : $member->ceiling() * 100, $budget);
                $granted[$member->listingId] = $take;
                $budget -= $take;
            }
        }
        $granted += array_map(static fn (int $units): int => $units * 100, $this->equalParts(array_filter($members, static fn (AllocationMember $member): bool => !in_array($member->mode, StockShare::MODES, true)), $budget));

        $shares = [];
        foreach ($members as $member) {
            $rule = in_array($member->mode, StockShare::MODES, true) ? $member->mode : StockShare::EQUAL;
            $shares[$member->listingId] = new MemberShare(
                listingId: $member->listingId,
                rule: $rule,
                entitlement: intdiv($granted[$member->listingId], 100),
                buffer: max(0, $member->buffer),
                lag: max(0, $member->lag),
            );
        }

        return new AllocationResult(net: $net, storefrontReserve: intdiv($reserve->hundredths($net), 100), shares: $shares);
    }

    /**
     * The equal part of what is left (in hundredths), for each listing: the level every listing rises to together, listings stopping at their ceiling and handing the rest on.
     *
     * @param array<int, AllocationMember> $members
     * @return array<int, int> whole units by listing id
     */
    private function equalParts(array $members, int $budget): array
    {
        $ascending = array_values($members);
        usort($ascending, static fn (AllocationMember $a, AllocationMember $b): int => [$a->ceiling() ?? self::ALL, $a->listingId] <=> [$b->ceiling() ?? self::ALL, $b->listingId]);

        $units = [];
        $spent = 0;
        foreach ($ascending as $index => $member) {
            $ceiling = $member->ceiling();
            if ($ceiling === null || $ceiling * 100 * (count($ascending) - $index) > $budget - $spent) {
                break;
            }
            $spent += $ceiling * 100;
            $units[$member->listingId] = $ceiling;
        }
        $rest = array_slice($ascending, count($units));
        foreach ($rest as $member) {
            $units[$member->listingId] = intdiv($budget - $spent, 100 * count($rest));
        }

        return $units;
    }

    /**
     * @param array<int, AllocationMember> $members
     * @return list<AllocationMember>
     */
    private function inOrder(array $members): array
    {
        $ordered = array_values($members);
        usort($ordered, static fn (AllocationMember $a, AllocationMember $b): int => [$a->priority ?? self::ALL, $a->listingId] <=> [$b->priority ?? self::ALL, $b->listingId]);

        return $ordered;
    }
}
