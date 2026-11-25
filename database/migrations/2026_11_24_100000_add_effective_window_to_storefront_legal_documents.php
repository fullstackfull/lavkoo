<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a legal text takes effect and when it was revoked (GCC-11). A storefront reads the newest effective text for each document type
 * and locale: a scheduled text waits, and a revoked text keeps the storefront closed until a replacement takes effect. Additive: rows
 * that exist keep null, which means not effective, so nothing becomes sellable by this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('storefront_legal_documents') || Schema::hasColumn('storefront_legal_documents', 'effective_from')) {
            return;
        }

        Schema::table('storefront_legal_documents', function (Blueprint $table) {
            $table->timestamp('effective_from')->nullable()->after('published_at');
            $table->timestamp('revoked_at')->nullable()->after('effective_from');
            $table->index(['country_code', 'document_type', 'locale', 'effective_from'], 'storefront_legal_docs_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_legal_documents', function (Blueprint $table) {
            $table->dropIndex('storefront_legal_docs_effective_idx');
            $table->dropColumn(['effective_from', 'revoked_at']);
        });
    }
};
