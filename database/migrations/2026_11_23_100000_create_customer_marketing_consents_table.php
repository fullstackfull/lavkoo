<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer answered about marketing, once per answer, never edited.
 *
 * Additive and guarded: the table is created only if it is not already there, nothing else is touched, and the feature
 * stays dormant until this runs (App\Services\Marketing\MarketingConsentGate::enabled checks for the table, the way
 * docs/address/README.md describes for the address work), so the code can be deployed first.
 *
 * A row is one DECISION, not a current state: granting writes a row, withdrawing writes another row, and the answer in
 * force is the newest row for that subject, market, channel and purpose. Nothing updates a row and nothing deletes one
 * (App\Models\CustomerMarketingConsent refuses both), which is why there is no unique key here: a person may change
 * their mind as often as they like and the history has to keep every step.
 *
 * `storefront` and `legal_entity_id` are nullable and nothing fills them yet. main has no storefront layer
 * (orders.storefront_country lives on an unmerged branch) and `legal_entities` has no migration anywhere in this
 * repository, so `legal_entity_id` carries NO foreign key: the column is the shape the record needs, not a link to a
 * table that is not here.
 *
 * down() refuses to drop the table once it holds a row. A consent record is the evidence that a person agreed; losing
 * it to a rollback would leave the store sending mail it cannot prove it was allowed to send.
 */
return new class extends Migration
{
    private const TABLE = 'customer_marketing_consents';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();

            // Null for a subject that has no account: the newsletter form takes an e-mail address and nothing else.
            $table->unsignedBigInteger('customer_id')->nullable();

            // The subject as the unsubscribe link names them: a keyed hash of "customer:<id>" or "email:<address>",
            // so a link carries no account id and no address that anybody could read, walk or re-use.
            $table->char('subject_hash', 64);

            // The market the answer was given for: an ISO code (SA, AE, KW, QA, BH, OM).
            $table->char('market', 2);

            // The storefront, when there is a storefront layer to name one. Nothing fills it on this head.
            $table->string('storefront', 64)->nullable();

            // The selling company. No foreign key: `legal_entities` does not exist in this repository.
            $table->unsignedBigInteger('legal_entity_id')->nullable();

            $table->string('channel', 32);
            $table->string('purpose', 32);

            // 'granted' or 'withdrawn'.
            $table->string('decision', 16);

            // The text the person was shown, so a later version of it does not change what they agreed to.
            $table->string('text_version', 64)->nullable();
            $table->char('text_hash', 64)->nullable();

            // Where the answer came from: the registration form, the account page, the newsletter box, an
            // unsubscribe link.
            $table->string('source', 32);

            $table->timestamp('recorded_at');

            // created_at only. There is no updated_at because a row is never updated.
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_hash', 'market', 'channel', 'purpose'], 'marketing_consents_subject_idx');
            $table->index(['customer_id', 'market', 'channel', 'purpose'], 'marketing_consents_customer_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Refusing to drop ' . self::TABLE . ': it holds consent decisions, which are the store\'s evidence that it was allowed to send. Export them first.');
        }

        Schema::drop(self::TABLE);
    }
};
