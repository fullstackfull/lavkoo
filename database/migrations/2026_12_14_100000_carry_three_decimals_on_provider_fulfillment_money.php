<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns that hold a provider shipment's money make room for a third decimal (C-15).
 *
 * Fulfilment was built for Saudi Arabia, where the smallest coin is a halala and every amount is a two-decimal amount, so the four columns
 * that hold a shipment's money and the line total they are summed from were declared `decimal(14, 2)`. Three of the six markets price in
 * thousandths — 1 KWD is 1,000 fils, 1 BHD 1,000 fils, 1 OMR 1,000 baisa — and a `decimal(14, 2)` column rounds a fils amount away on the
 * way in, whatever the code that carried it there did. So the capacity has to exist in the column before the first Kuwaiti, Bahraini or
 * Omani shipment, and that is all this migration does.
 *
 * **It changes no Saudi behaviour and no stored value.** Widening the scale of a decimal column is lossless: 12.34 becomes 12.340, which is
 * the same number, and a riyal shipment is still read, written, printed and sent as two decimals, because the decimals a row is formatted
 * with come from the currency that row names ({@see \App\Casts\MoneyInItsCurrency}) and the riyal has two. Nothing here is behind the
 * storefronts switch: a column that can hold a fils amount does not make the platform behave differently while there are no fils amounts,
 * and the alternative — widening it at the moment a market opens — would put a table rewrite in the middle of opening a market.
 *
 * **What it costs.** MySQL and MariaDB rewrite a table to change a decimal's scale, so the upgrade takes time proportional to the number of
 * provider shipments recorded, and the table is locked for writes while it does. On this platform that table was created on 2026-11-05 and
 * no non-Saudi provider account has ever been enabled, so it is small; an installation where it is not should run `php artisan migrate` in a
 * window where no fulfilment is being dispatched.
 *
 * `down()` narrows the columns back, which rounds any fils already stored to the nearest halala: there is no way to put a third decimal into
 * a two-decimal column, and a rollback that silently kept it would be the worse answer.
 */
return new class extends Migration
{
    /** The money columns of a provider shipment, and the line total they are summed from. */
    private const SHIPMENT_MONEY = ['cod_amount', 'items_total', 'shipping_allocation', 'total_amount'];

    private const THOUSANDTHS = 3;

    private const HUNDREDTHS = 2;

    public function up(): void
    {
        $this->setScaleTo(self::THOUSANDTHS);
    }

    public function down(): void
    {
        $this->setScaleTo(self::HUNDREDTHS);
    }

    /**
     * Both tables are named here in full, rather than taken from a constant or a loop, so that the guard which reads this directory for
     * migrations that touch a customer table can see which tables this one touches (`CustomerTablesAreNotRewrittenTest`). A table a reader
     * cannot name could be any table at all.
     */
    private function setScaleTo(int $decimals): void
    {
        if (Schema::hasTable('provider_fulfillments')) {
            Schema::table('provider_fulfillments', function (Blueprint $blueprint) use ($decimals): void {
                foreach (self::SHIPMENT_MONEY as $column) {
                    if (Schema::hasColumn('provider_fulfillments', $column)) {
                        $blueprint->decimal($column, 14, $decimals)->default(0)->change();
                    }
                }

                if (Schema::hasColumn('provider_fulfillments', 'provider_cost')) {
                    $blueprint->decimal('provider_cost', 14, $decimals)->nullable()->change();
                }
            });
        }

        if (Schema::hasTable('provider_fulfillment_items') && Schema::hasColumn('provider_fulfillment_items', 'line_total')) {
            Schema::table('provider_fulfillment_items', function (Blueprint $blueprint) use ($decimals): void {
                $blueprint->decimal('line_total', 14, $decimals)->default(0)->change();
            });
        }
    }
};
