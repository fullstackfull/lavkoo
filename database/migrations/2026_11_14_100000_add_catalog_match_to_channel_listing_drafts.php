<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the channel's own catalogue answered about this product, kept on the draft.
 *
 * It is kept rather than asked again for two reasons. The answer costs a rate-limited call, and a screen that re-asked on every render would spend the account's quota on
 * nothing. And the answer that matters most is `unknown` — "the catalogue could not be asked" — which has to survive long enough for a person to see it, because the whole
 * point of recording it is that nobody may create a listing while it stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('channel_listing_drafts') || Schema::hasColumn('channel_listing_drafts', 'catalog_match')) {
            return;
        }

        Schema::table('channel_listing_drafts', function (Blueprint $table): void {
            // The whole answer: its state, the identifiers it was asked with, and every candidate. A list, because `several` is a real answer and the owner has to see all of them.
            $table->json('catalog_match')->nullable()->after('requirements_read_at');
            $table->timestamp('catalog_read_at')->nullable()->after('catalog_match');
            // The channel's id for the product a PERSON chose from those candidates. Never written by a machine: with several candidates there is no right one to pick.
            $table->string('catalog_external_id', 191)->nullable()->after('catalog_read_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('channel_listing_drafts')) {
            return;
        }

        Schema::table('channel_listing_drafts', function (Blueprint $table): void {
            foreach (['catalog_match', 'catalog_read_at', 'catalog_external_id'] as $column) {
                if (Schema::hasColumn('channel_listing_drafts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
