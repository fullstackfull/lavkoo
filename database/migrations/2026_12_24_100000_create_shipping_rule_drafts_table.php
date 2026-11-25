<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shipping rule copied towards another market, as an UNPUBLISHED DRAFT (H1-14's last criterion, owner's ruling of 2026-11-25).
 *
 * The decision this carries out, in the owner's words: copying is a draft that is not published, does not affect the live market, and carries
 * no carrier credentials, contracts or sensitive information; publishing afterwards is a separate action with permissions and an audit trail.
 *
 * **Its own table, and that is the whole design.** A draft could have been a flag on `shipping_methods`, and then every listing, every
 * charge, every picker and every seller API answer would have had to remember to exclude it -- and the one that forgot would offer a market a
 * delivery option nobody had approved, at a price copied from another country. Here no storefront query knows this table exists, so a draft
 * CANNOT reach a shop: not by a missed filter, not by a raw query, not by a relation somebody adds next year. "Does not affect the live
 * market" is then a property of the schema rather than a rule people have to keep.
 *
 * **What is copied is what a delivery option IS to a customer**: its name, its cost and how long it takes. What is NOT copied, deliberately:
 *
 *  - the carrier's credentials and base address. Those live in `integration_settings` and are keyed by carrier and country (H1-11); a copy
 *    that carried them would hand one market's account to another, which is the opposite of what per-country accounts are for.
 *  - `creator_id` and `creator_type`. A method belongs to the admin or to one seller, and copying that into another market would give a
 *    shop in Kuwait a rule owned by a shop in Saudi Arabia.
 *  - the switch. A published draft arrives switched OFF, so publishing cannot by itself change what any shop offers.
 *
 * The source is remembered by id AND by the market it was copied from, because the source may be edited or deleted before anybody publishes,
 * and a draft then still says where it came from. Nothing reads the source again at publish time: what is published is what was copied and
 * reviewed, not whatever the source has become since.
 *
 * One draft per (target market, source method): copying the same rule towards the same market again refreshes the draft rather than piling
 * up a second one for a person to choose between.
 */
return new class extends Migration {
    private const TABLE = 'shipping_rule_drafts';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // Where it came from. The id may name a method that has since been edited or deleted; the market is kept beside it so a draft
            // still says where it came from when the row is gone. '' is the platform-wide source, which is every method today.
            $table->unsignedBigInteger('source_method_id')->nullable();
            $source = $table->char('source_country', 2)->default('');

            // The market it is drafted FOR. Never empty: a draft towards no market would be a draft of the default storefront's own rule.
            $target = $table->char('target_country', 2);

            // What a delivery option is to a customer, and nothing else.
            $table->string('title')->nullable();
            $table->decimal('cost', 21, 12)->default(0);
            $table->string('duration')->nullable();

            // Who copied it, and who published it. A name as well as an id, because an administrator may be deleted and the record of who
            // approved a market's delivery prices must outlive the account.
            $table->unsignedBigInteger('copied_by')->nullable();
            $table->string('copied_by_name', 120)->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->string('published_by_name', 120)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_method_id')->nullable()->comment('the live method publishing made, so a draft is never published twice');

            $table->timestamps();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $source->charset('ascii')->collation('ascii_bin');
                $target->charset('ascii')->collation('ascii_bin');
            }

            $table->unique(['target_country', 'source_method_id'], 'shipping_rule_drafts_target_source_unique');
            $table->index(['target_country', 'published_at'], 'shipping_rule_drafts_target_state_index');
        });
    }

    /**
     * Dropping the table forgets drafts nobody has published and the record of who published the rest.
     *
     * The published METHODS are untouched by this -- they are rows of `shipping_methods` and live on -- so a rollback loses the audit trail
     * of how they got there and any draft still waiting for a decision. Refused while either exists; a database with no drafts at all rolls
     * back, which is every database that has not used the feature.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (\Illuminate\Support\Facades\DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Shipping rule drafts exist. Rolling this migration back would forget the drafts nobody has published and who published the rest.');
        }

        Schema::dropIfExists(self::TABLE);
    }
};
