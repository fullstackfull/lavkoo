<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a session last proved the second factor again (L-15's step-up).
 *
 * Signing in proves it once, for a session that may last eight hours. An action that takes something out of the platform -- an export of a market's
 * orders -- asks for it again, within minutes of the request, so that a phone left unlocked on a desk is not the same thing as the operator.
 *
 * Additive and nullable: a session that exists today has stepped up never, which is what every session was before this column, and no route asks for a
 * step-up until one is added that carries the middleware.
 *
 * DATETIME and not TIMESTAMP. The two expiry columns of this API are TIMESTAMP and that is checked and right -- their times to live are 480 and 5
 * minutes, so nothing far-future is ever written -- but this column records a moment that is compared against `now()` minus a few minutes, and the
 * project has already been bitten once by a TIMESTAMP column storing `0000-00-00` for a date past 2038. A column that could ever hold a far date is
 * DATETIME here.
 */
return new class extends Migration {
    private const COLUMN = 'stepped_up_at';

    public function up(): void
    {
        if (Schema::hasTable('platform_admin_tokens') && !Schema::hasColumn('platform_admin_tokens', self::COLUMN)) {
            Schema::table('platform_admin_tokens', fn (Blueprint $table) => $table->dateTime(self::COLUMN)->nullable());
        }
    }

    /** Dropping it only makes every session one that has not stepped up, which is what it was before. */
    public function down(): void
    {
        if (Schema::hasColumn('platform_admin_tokens', self::COLUMN)) {
            Schema::table('platform_admin_tokens', fn (Blueprint $table) => $table->dropColumn(self::COLUMN));
        }
    }
};
