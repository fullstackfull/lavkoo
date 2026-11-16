<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Until this fix, a seller's remember-me token was in the body of every public product response, and the web panel accepts a cookie made of
 * a seller id and that token. Anything that read a product page since the seller last ticked "Remember me" could hold a working credential,
 * so every stored one is cleared: a seller who used it signs in again. Data only, no schema; there is nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sellers') && Schema::hasColumn('sellers', 'remember_token')) {
            DB::table('sellers')->whereNotNull('remember_token')->update(['remember_token' => null]);
        }
    }

    public function down(): void
    {
        // The cleared tokens were credentials that had to stop working; they are not restored.
    }
};
