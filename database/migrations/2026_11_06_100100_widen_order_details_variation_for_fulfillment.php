<?php

use App\Services\Fulfillment\Support\LegacyColumnWidener;
use Illuminate\Database\Migrations\Migration;

/**
 * order_details.variation holds the options a customer chose, as the JSON the platform writes at checkout. The pilot server's column is
 * varchar(255); JSON text escaped with \uXXXX needs up to six characters per Arabic letter, so a product with a few Arabic options can
 * exceed it and the line is then refused or cut off in the middle of the JSON. The integration itself reads the variant from
 * order_details.variant and from the product, not from this column; this keeps the platform's own checkout safe for the Arabic option
 * names that imported M5AZN products carry.
 *
 * Additive and idempotent: the column becomes TEXT with its nullability, collation and comment restated, no row is written, a column that
 * is already TEXT is left alone, an index or a default on it stops the change with a message instead of guessing, and it does nothing on
 * any database but MySQL and MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        LegacyColumnWidener::convertToText(table: 'order_details', column: 'variation');
    }

    public function down(): void
    {
        // Turning the column back into a varchar(255) could cut off options that are in use, so it stays TEXT.
    }
};
