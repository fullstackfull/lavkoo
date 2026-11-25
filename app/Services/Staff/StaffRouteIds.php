<?php

namespace App\Services\Staff;

use Illuminate\Database\Eloquent\Model;

/**
 * What counts as an id when a staff country scope reads one out of a request (GCC-14c): a value is an id only when it is digits without a leading zero. `10abc`, `010`
 * and `1e1` mean the row 10 to MySQL, so they are refused here instead of being looked up as what the database would make of them. The product screens and the order
 * screens read their ids the same way.
 *
 * A BOUND MODEL is an id too (GCC-14c slice 6). `SubstituteBindings` runs in the `web` group before a controller's own middleware, so on a route written
 * `{account}` against an action typed `SalesChannelAccount $account`, `$request->route('account')` hands back the MODEL and not the digits -- and reading it as
 * "not an id" would have refused every such screen to every restricted employee while looking like a working guard. The slices before this one had no bound
 * parameter at all, which is why the question only arises here.
 */
final class StaffRouteIds
{
    /** @return list<int>|null the id, as a list of one, or null when the value is not an id */
    public static function one(mixed $value): ?array
    {
        if ($value instanceof Model) {
            return self::one(value: $value->getKey());
        }

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
