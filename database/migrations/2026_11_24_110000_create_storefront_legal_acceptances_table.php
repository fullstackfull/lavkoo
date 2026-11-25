<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record that a customer accepted one legal text in one storefront (GCC-11). It keeps the text's version and hash, so the acceptance
 * stays provable after the text is superseded. Rows are written only for document types that need explicit acceptance, and identity is
 * kept only when the privacy policy allows it. Additive: the table is new and nothing reads it yet.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('storefront_legal_acceptances')) {
            return;
        }

        Schema::create('storefront_legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->char('storefront_country', 2);
            $table->string('legal_entity', 64)->nullable();
            $table->string('document_type', 32);
            $table->string('locale', 8);
            $table->unsignedBigInteger('legal_document_id');
            $table->unsignedInteger('document_version');
            $table->char('document_hash', 64);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->char('guest_hash', 64)->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['storefront_country', 'document_type', 'locale'], 'storefront_legal_acc_country_idx');
            $table->index('order_id', 'storefront_legal_acc_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_legal_acceptances');
    }
};
