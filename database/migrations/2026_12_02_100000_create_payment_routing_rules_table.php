<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The table the payment routing rules were always written to, and which did not exist.
 *
 * App\Models\PaymentRoutingRule, App\Services\Marketplace\PaymentRoutingService and the admin screen
 * at Admin\Marketplace\PaymentRoutingController (create, update and delete) were all shipped, and NO
 * MIGRATION IN THIS REPOSITORY CREATES THE TABLE. Where this repository is the whole schema, every
 * read is therefore turned away by the service's own `Schema::hasTable()` guard and answers the
 * gateway list unchanged, and a save from the admin screen can only fail.
 *
 * WHAT CANNOT BE DETERMINED FROM HERE, said rather than assumed: whether the installed SQL dump
 * already has this table. `.gitignore` excludes `*.sql`, so installation/backup/database.sql is not in
 * this repository and is not in the container this was written in -- no claim about the live schema can
 * be sourced from here, including a claim that the table is missing from it.
 *
 * WHICH IS WHY THE FIRST LINE OF up() IS A hasTable() EARLY-OUT, and why that matters rather than
 * being boilerplate: if the dump already carries the table, this migration does NOTHING and alters no
 * column of it. If it does not, the table is created empty. Safe either way, which is the only
 * standard available to a migration whose starting schema cannot be read.
 *
 * It ARRIVES EMPTY where it is created, and the resolver's contract is that with no rule the gateway
 * list comes back unchanged -- so the checkout, the payment start and the mobile app's /api/v1/config
 * behave exactly as they do today until an administrator writes a rule. The table existing is what
 * makes the feature testable; a rule existing is what makes it act.
 *
 * Nothing existing is altered and nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_routing_rules')) {
            return;
        }

        Schema::create('payment_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            // The gateway's key as addon_settings spells it (`paymob_accept`, `fatoorah`, ...). Matched
            // against PaymentGatewayCatalog's keys, so it is compared and never displayed as data.
            $gatewayCode = $table->string('gateway_code', 60);
            // hide | prefer. Compared, never free text.
            $action = $table->string('action', 24);
            $table->decimal('min_amount', 18, 2)->nullable();
            $table->decimal('max_amount', 18, 2)->nullable();
            /*
             * The destination this rule is for. An operator types it, so it may be an ISO code, an
             * English name or an Arabic one -- PaymentRoutingRule::countryMatches() resolves both sides
             * to ISO before comparing, and falls back to the text when either side names no country it
             * can recognise. It therefore holds what a person wrote, in any script, and stays
             * utf8mb4: it is NOT one of the byte-compared columns below.
             */
            $table->string('country', 120)->nullable();
            // Lower runs first, as the sibling fulfillment_routing_rules table reads it.
            $table->unsignedInteger('priority')->default(10);
            // active | inactive. Compared in SQL by the service, so it is byte-compared below.
            $status = $table->string('status', 24)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority'], 'prr_status_priority_idx');
            $table->index('gateway_code', 'prr_gateway_code_idx');

            /*
             * THE THREE COLUMNS THIS APPLICATION COMPARES ARE COMPARED BYTE FOR BYTE on MySQL and
             * MariaDB, as PHP compares them. On the table's own utf8mb4_unicode_ci collation the engine
             * decides `Hide` and `hide`, or `ACTIVE` and `active`, are one value while PHP's `===` does
             * not -- so a rule could be read on one engine and skipped on another, and a rule written
             * with the wrong case could act where its author did not expect. This project has already
             * measured that divergence on a gateway column: the same request answered 401 on SQLite and
             * 200 on MariaDB. SQLite compares byte for byte by default, so this only has to be said to
             * the two MySQL-family engines.
             */
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $gatewayCode->charset('ascii')->collation('ascii_bin');
                $action->charset('ascii')->collation('ascii_bin');
                $status->charset('ascii')->collation('ascii_bin');
            }
        });
    }

    public function down(): void
    {
        // Only while nothing has been written: dropping a table of rules an operator typed would throw
        // their work away, and a rollback is not the place to decide that.
        if (Schema::hasTable('payment_routing_rules') && DB::table('payment_routing_rules')->count() === 0) {
            Schema::drop('payment_routing_rules');
        }
    }
};
