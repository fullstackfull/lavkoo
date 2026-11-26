<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns a gateway refund's money is computed from make room for a third decimal (D2-10).
 *
 * The MyFatoorah tables were built for Saudi Arabia, where the smallest coin is a halala, so the refund amount and the two figures a
 * refund's headroom is computed from were declared `decimal(24, 2)`. Three of the six markets price in thousandths -- 1 KWD is 1,000 fils,
 * 1 BHD 1,000 fils, 1 OMR 1,000 baisa -- and a `decimal(24, 2)` column rounds a fils amount away on the way in, whatever the code that
 * carried it there did. So the capacity has to exist in the columns before the exact handling can mean anything, and that is all this
 * migration does.
 *
 * **Why `expected_amount` and `captured_amount` are here and not only the refund's own amount.** The headroom is
 * `captured_amount ?? expected_amount` less what has already been asked for. Leaving either at two places would store a payment of 10.005
 * as 10.01 and hand the refund path a ceiling five fils above what the customer ever paid -- which is the over-refund this whole change
 * exists to prevent, reintroduced by the column rather than by the code.
 *
 * **It changes no Saudi behaviour and no stored value.** Widening the scale of a decimal column is lossless: 150.00 becomes 150.000, which
 * is the same number. The payment VERIFICATION path reads `expected_amount` through `App\Services\MyFatoorah\Money`, which formats to two
 * decimals and compares in whole halalas; it is given the same number it was given before and answers the same, which
 * `ARefundIsExactInTheCurrencysMinorUnitTest` asserts against the real engine. Nothing here is behind
 * `gcc.refunds.gateway_refunds_in_the_currencys_minor_unit`: a column that CAN hold a fils amount does not make the platform behave
 * differently while nothing writes one, and widening it at the moment the switch is turned on would put a table rewrite in the middle of a
 * financial change. With the switch off a refund is still rounded to two places and still reads back at two.
 *
 * **What it costs.** MySQL and MariaDB rewrite a table to change a decimal's scale, so the upgrade takes time proportional to the number of
 * payment attempts and refunds recorded, and those tables are locked for writes while it does. They were created on 2026-11-08 and this
 * gateway has never been enabled in production, so they are small; an installation where they are not should run `php artisan migrate` in a
 * window where no payment is being taken.
 *
 * `down()` narrows the columns back, which rounds any fils already stored to the nearest halala: there is no way to put a third decimal
 * into a two-decimal column, and a rollback that silently kept it would be the worse answer.
 */
return new class extends Migration
{
    private const THOUSANDTHS = 3;

    private const HUNDREDTHS = 2;

    /** The width these columns already have. Only the scale moves. */
    private const DIGITS = 24;

    public function up(): void
    {
        $this->setScaleTo(self::THOUSANDTHS);
    }

    public function down(): void
    {
        $this->setScaleTo(self::HUNDREDTHS);
    }

    /**
     * Both tables and all three columns are named here in full, rather than taken from a constant or a loop, so that the guard which reads
     * this directory for migrations touching a customer table can see which tables this one touches
     * (`CustomerTablesAreNotRewrittenTest`). A table a reader cannot name could be any table at all.
     */
    private function setScaleTo(int $decimals): void
    {
        if (Schema::hasTable('myfatoorah_refunds') && Schema::hasColumn('myfatoorah_refunds', 'amount')) {
            Schema::table('myfatoorah_refunds', function (Blueprint $blueprint) use ($decimals): void {
                $blueprint->decimal('amount', self::DIGITS, $decimals)->change();
            });
        }

        if (!Schema::hasTable('myfatoorah_payment_attempts')) {
            return;
        }

        Schema::table('myfatoorah_payment_attempts', function (Blueprint $blueprint) use ($decimals): void {
            if (Schema::hasColumn('myfatoorah_payment_attempts', 'expected_amount')) {
                $blueprint->decimal('expected_amount', self::DIGITS, $decimals)->change();
            }

            if (Schema::hasColumn('myfatoorah_payment_attempts', 'captured_amount')) {
                $blueprint->decimal('captured_amount', self::DIGITS, $decimals)->nullable()->change();
            }
        });
    }
};
