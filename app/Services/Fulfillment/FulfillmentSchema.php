<?php

namespace App\Services\Fulfillment;

use Illuminate\Support\Facades\Schema;

/**
 * Has the fulfillment migration been run on this database?
 *
 * Code deployed before its migration must leave the platform exactly as it was. Every place the existing
 * order and product flows touch this feature asks here first, so an environment that has not migrated yet
 * behaves as if the feature did not exist instead of erroring on a missing table.
 */
final class FulfillmentSchema
{
    private static ?bool $ready = null;

    private static ?bool $productColumn = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('provider_fulfillments') && Schema::hasTable('provider_fulfillment_items');
    }

    /** Has the `products.fulfillment_provider` column been added yet? */
    public static function productColumnReady(): bool
    {
        return self::$productColumn ??= Schema::hasColumn('products', 'fulfillment_provider');
    }

    public static function flush(): void
    {
        self::$ready = null;
        self::$productColumn = null;
    }
}
