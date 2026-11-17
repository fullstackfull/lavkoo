<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legal text each GCC storefront publishes (GCC-11). One row per version of one document for one country and locale. A storefront may
 * sell only once every required document type has published, approved, non-template text. Existing business pages and settings are not
 * changed by this table; the default storefront is grandfathered in code.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('storefront_legal_documents', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            $table->string('document_type', 32);
            $table->string('locale', 8)->default('en');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->longText('body');
            $table->char('body_hash', 64);
            $table->char('template_hash', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['country_code', 'document_type', 'locale', 'version'], 'storefront_legal_docs_version_unique');
            $table->index(['country_code', 'status'], 'storefront_legal_docs_country_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_legal_documents');
    }
};
