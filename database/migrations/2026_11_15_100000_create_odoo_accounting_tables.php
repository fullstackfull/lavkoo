<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three tables the Odoo posting needs, and nothing else: a map from our own cost vocabulary to the customer's chart of accounts, an outbox of what is to be posted, and
 * the reconciliation a person reads.
 *
 * NO TABLE HERE HOLDS A COST. `OperationalCosts` remains the only engine for costs, FIFO, commissions and profitability (docs/sales-channels/odoo-accounting.md), and Odoo is
 * the official ledger, not a second engine. Every amount posted is READ from `operational_cost_entries`; none is computed, re-derived or stored again here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- what our vocabulary means in the customer's chart of accounts ------------------------------------------
        //
        // Nothing is posted without a mapping: an unmapped component is a refusal a person can act on, never a guess at
        // an account number. The map is per company AND per channel, because two channels may not share one entry.
        if (!Schema::hasTable('odoo_account_mappings')) {
            Schema::create('odoo_account_mappings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // Odoo's own company id: the map is only valid for the company it was read against.
                $table->unsignedBigInteger('odoo_company_id');
                // Which of our facts this row maps: a cost component, or one of the journal-line types (sale, refund, channel_fee, cost_of_goods, shipping_cost, payout).
                $table->string('fact_kind', 32);
                $table->string('fact_code', 64);
                // Null means "any": a mapping may be general or narrowed to one channel, and the narrowest match wins.
                // AN EMPTY STRING MEANS "ANY", AND THESE ARE NOT NULLABLE ON PURPOSE.
                //
                // A unique index does not constrain NULLs: in MySQL, MariaDB, SQLite and Postgres alike, NULL is not equal to
                // NULL, so every row carrying one passes. Had these stayed nullable, the general mapping -- the one that applies
                // to every channel and every currency, and so the one most worth protecting -- could have been inserted any
                // number of times, each copy claiming a different Odoo account for the same fact, and which one won would depend
                // on row order.
                //
                // So "any" is spelled with a value the index can compare: `''` here, and `0` for a company id, since Odoo
                // numbers its own companies from 1.
                $table->string('sales_channel', 24)->default('')->comment('empty means every channel');
                $table->string('currency', 8)->default('')->comment('empty means every currency');
                // Odoo's ids, READ from Odoo and never invented here. The human-readable code is kept beside each so a
                // person can see what was chosen without opening Odoo, and so a changed chart is visible.
                $table->unsignedBigInteger('odoo_account_id');
                $table->string('odoo_account_code', 64)->nullable();
                $table->unsignedBigInteger('odoo_journal_id');
                $table->string('odoo_journal_code', 64)->nullable();
                $table->unsignedBigInteger('odoo_tax_id')->nullable();
                $table->string('odoo_tax_name', 120)->nullable();
                $table->unsignedBigInteger('odoo_analytic_account_id')->nullable()->comment('the cost centre, where the customer uses one');
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable()->comment('an unapproved mapping is never posted through');
                $table->timestamp('verified_at')->nullable()->comment('when these ids were last confirmed to exist in Odoo');
                $table->timestamps();

                $table->unique(['seller_id', 'odoo_company_id', 'fact_kind', 'fact_code', 'sales_channel', 'currency'], 'oam_fact_unique');
            });
        }

        // ---- the outbox ---------------------------------------------------------------------------------------------
        //
        // Shaped after `channel_stock_events` on purpose: the same claim, attempts, retry and dead semantics, because the
        // same failures happen. What is different is the unique key, and it is the whole point of the table.
        if (!Schema::hasTable('odoo_posting_queue')) {
            Schema::create('odoo_posting_queue', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // Not part of this table's unique key -- `(source_type, source_id, source_version)` is, and all three are NOT
                // NULL -- so a null here constrains nothing and costs nothing. It is simply unknown until a company is read.
                $table->unsignedBigInteger('odoo_company_id')->nullable();
                // WHAT IS BEING POSTED, and which version of it. A value that changes after posting is a NEW version and
                // a new row -- never a second posting of the same one, and never an edit of a final entry in Odoo.
                $table->string('source_type', 64)->comment('operational_cost_entry, and whatever else the ledger later offers');
                $table->unsignedBigInteger('source_id');
                $table->string('source_version', 64)->comment('changes when the fact changes; the pair below is what makes a retry safe');
                $table->string('sales_channel', 24)->nullable()->index();
                // OUR key, written into the entry's `ref` at Odoo so the same fact can be FOUND there. This is what lets a retry
                // ask "did my last attempt land?" instead of guessing, and it is the only uniqueness Odoo can be asked for:
                // Odoo documents no idempotency mechanism of its own -- no external-reference field, no constraint, no upsert.
                $table->string('odoo_ref', 120)->index()->comment('searched in Odoo before any create; never reused for a different fact');

                // **THE STATE SAYS WHAT HAPPENED TO THE BOOKS; `claimed_by` AND `claimed_at` SAY WHO IS WORKING ON IT.**
                //
                // They were one column until a review found the hole that made: ownership as a state (`claimed`) meant a row
                // being worked read as a row nothing had been posted for, and `reclaimAbandoned()` then set it back to
                // `pending`. A run that died between `account.move.create` leaving this host and PHP recording the answer left
                // exactly that, so the next run asked Odoo, read "no such entry" -- which a lagging read says as readily as an
                // absent entry -- and posted the same cost a second time.
                //
                // pending         -> waiting its turn, and nothing has been sent for it
                // may_have_posted -> a create may be in the books. WRITTEN BEFORE THE REQUEST GOES, so a crash at any later
                //                    instant still forbids a second create. Such a row may be ASKED about for ever; it may
                //                    never be created from again by a machine. Only proof that Odoo did not process the
                //                    request lifts it.
                // posted          -> Odoo reports the entry (a draft; final posting is a separate, approved step)
                // failed          -> not posted; with `available_at` it is tried again, without one it waits on a person
                // needs_review    -> THE STATE THAT NEVER BECOMES DUE: Odoo can neither confirm nor deny the entry. Retrying
                //                    might post it twice; abandoning it might lose it. A person decides.
                // dead            -> attempts exhausted, or the fact itself is not postable
                $table->string('state', 16)->default('pending');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable()->comment('not worked before this: a retry after a failure');
                // Ownership, and ownership only. Giving up an expired claim never changes the state above -- which is the fix
                // the comment on that column records -- and every settle is guarded by `claimed_by`, so a run that was
                // overtaken cannot write over the row its successor holds.
                $table->timestamp('claimed_at')->nullable();
                $table->string('claimed_by', 64)->nullable()->comment('which run holds it, so a run that died can be told from one still working');
                $table->timestamp('worked_at')->nullable();

                // What Odoo said. `odoo_move_id` is written only once Odoo reports the entry, so a row with none was
                // never posted -- and a row WITH one is never posted again.
                $table->unsignedBigInteger('odoo_move_id')->nullable();
                $table->string('odoo_move_ref', 120)->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamp('verified_at')->nullable()->comment('when Odoo was asked whether it holds the entry, after an answer we could not read');
                $table->timestamp('needs_review_since')->nullable()->comment('set with state needs_review: how long a person has been owed a decision');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('outcome', 32)->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->text('failure_detail')->nullable();
                $table->timestamps();

                // THE defence against a duplicate entry in the customer's books. A retry of the same fact at the same
                // version collides here rather than posting twice.
                $table->unique(['source_type', 'source_id', 'source_version'], 'opq_source_version_unique');
                $table->index(['state', 'available_at', 'id'], 'opq_due_idx');
                $table->index(['odoo_company_id', 'state'], 'opq_company_state_idx');
            });
        }

        // ---- the three-way reconciliation a person reads --------------------------------------------------------------
        //
        // Three figures for one period and one channel, each from its own place, and the differences BETWEEN them. Nothing
        // here closes a difference automatically: a difference is a thing to look at, and a row that hid it would be worse
        // than no row.
        if (!Schema::hasTable('odoo_reconciliations')) {
            Schema::create('odoo_reconciliations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // Not nullable, for the reason given on the mapping table above: a unique index does not constrain NULLs, so a
                // reconciliation row for "every company, every channel" could otherwise be written once per run, and the report
                // would show the same period several times over with different figures.
                $table->unsignedBigInteger('odoo_company_id')->default(0)->comment('0 means every company; Odoo numbers its own from 1');
                $table->string('sales_channel', 24)->default('')->comment('empty means every channel');
                $table->date('period_start');
                $table->date('period_end');
                $table->string('currency', 8);
                $table->string('fact_kind', 32);
                // 1. what the channel or provider says, where it says anything at all (noon says nothing: no settlement endpoint).
                $table->decimal('source_amount', 20, 6)->nullable();
                $table->string('source_basis', 64)->nullable()->comment('which statement or report the figure came from, or null where the channel publishes none');
                // 2. our own ledger.
                $table->decimal('ledger_amount', 20, 6)->nullable();
                // 3. Odoo.
                $table->decimal('odoo_amount', 20, 6)->nullable();
                $table->timestamp('odoo_read_at')->nullable();
                $table->unsignedInteger('entries_counted')->default(0);
                $table->unsignedInteger('entries_not_posted')->default(0);
                $table->timestamps();

                $table->unique(['seller_id', 'odoo_company_id', 'sales_channel', 'period_start', 'period_end', 'currency', 'fact_kind'], 'orc_period_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_reconciliations');
        Schema::dropIfExists('odoo_posting_queue');
        Schema::dropIfExists('odoo_account_mappings');
    }
};
