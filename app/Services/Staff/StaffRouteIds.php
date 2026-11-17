<?php

namespace App\Services\Staff;

/**
 * What counts as an id when a staff country scope reads one out of a request (GCC-14c): a value is an id only when it is digits without a leading zero. `10abc`, `010`
 * and `1e1` mean the row 10 to MySQL, so they are refused here instead of being looked up as what the database would make of them. The product screens and the order
 * screens read their ids the same way.
 */
final class StaffRouteIds
{
    /** @return list<int>|null the id, as a list of one, or null when the value is not an id */
    public static function one(mixed $value): ?array
    {
        $isId = (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1);

        return $isId ? [(int) $value] : null;
    }

    /** @return list<int>|null the ids, or null when one of them is not an id; an empty list names nothing, so nothing is out of scope: the action refuses it itself */
    public static function many(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $ids = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            $id = self::one(value: $item);
            if ($id === null) {
                return null;
            }
            $ids[] = $id[0];
        }

        return $ids;
    }

    public static function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
