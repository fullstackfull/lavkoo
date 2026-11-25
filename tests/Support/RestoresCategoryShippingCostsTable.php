<?php

namespace Tests\Support;

use CreateCategoryShippingCostsTable;
use Illuminate\Support\Facades\Schema;

/**
 * Puts `category_shipping_costs` back as the migrations leave it, for a test that has to build its own version of the table.
 *
 * The table has a real create migration, so it exists for the whole of a test run: a class that dropped it and walked away would take it from
 * every class that runs after it in the same process, which on the in-memory database is all of them. The two tables H1-14 touches differ
 * here -- `shipping_methods` has no create migration, so being absent IS its migrated state.
 *
 * The create migration declares a NAMED class rather than returning an anonymous one, so the file RETURNS NOTHING and requiring it a second
 * time in one process is a fatal error. It is loaded at most once and then instantiated by name.
 */
trait RestoresCategoryShippingCostsTable
{
    protected function restoreCategoryShippingCostsTable(): void
    {
        Schema::dropIfExists('category_shipping_costs');

        if (!class_exists(CreateCategoryShippingCostsTable::class, autoload: false)) {
            require database_path('migrations/2022_03_13_121514_create_category_shipping_costs_table.php');
        }

        (new CreateCategoryShippingCostsTable())->up();
        (require database_path('migrations/2026_01_22_153441_update_category_shipping_costs_table.php'))->up();
        (require database_path('migrations/2026_12_22_100100_add_storefront_country_to_category_shipping_costs.php'))->up();
    }
}
