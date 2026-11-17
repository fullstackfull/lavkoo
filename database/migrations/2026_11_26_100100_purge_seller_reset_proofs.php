<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empties the tables a seller's proof of a reset could have been planted in.
 *
 * Until the fix that comes with this migration, `firebase-auth-token-store` kept any token for any phone in `phone_or_email_verifications`
 * for anybody, the seller reset took that table as proof, and `firebase-auth-verify` recorded a proof in `password_resets` for the phone the
 * caller typed instead of the phone Google had verified. The code that reads those rows no longer takes them for proof, but a row written
 * before the deploy is still there and still matches what its writer knows. Nothing tells a planted row from a real one, so every row of
 * these kinds goes: the codes in flight (a seller asks for a new one) and the seller rows of `password_resets`.
 *
 * Customers' and delivery men's rows are cleared by the customer reset fix. Nothing else is read or written, and there is nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('phone_or_email_verifications')) {
            DB::table('phone_or_email_verifications')->delete();
        }

        if (Schema::hasTable('password_resets') && Schema::hasColumn('password_resets', 'user_type')) {
            DB::table('password_resets')->where('user_type', 'seller')->delete();
        }
    }

    public function down(): void
    {
        // The rows are codes in flight and cannot be restored; there is nothing to undo.
    }
};
