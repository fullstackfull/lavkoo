<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox: every change that could change what a channel should be shown, written in the SAME transaction as the change itself. One new table of the module's own; nothing is
 * added to a platform table and no foreign key is created.
 *
 * Why it exists. Until now a change marked its pools with a timestamp (`inventory_pools.dirty_at`) in a statement of its own, AFTER the change had been written. Two things
 * follow from that, and both of them oversell:
 *
 *   - a process that dies between the two leaves the reservation written and nothing marked, so a unit a channel has already sold stays on offer until the next full sweep;
 *   - the mark is skipped entirely while the division is switched off, so the same gap is permanent on such a host.
 *
 * A row here is written by the same transaction that writes the reservation, so either both exist or neither does. The row is the DURABLE INTENTION to work the pool out again
 * and send what lowers a quantity; it is not the quantity, and it carries no figure: by the time it is worked, the figures are read afresh. An event that cannot be worked
 * stays, and a dead one is kept for a person to look at rather than dropped.
 *
 * It is not a second queue of its own. The worker hands the pools to the division and the publisher that already exist, so every gate they apply — the host's switches, the
 * account's capability and approval, a stale figure, an FBA listing the channel owns — applies exactly as before.
 *
 * And a row is never closed on a claim nobody checked: `worked` means a pass attended to it, and the columns below say what that pass actually achieved — whether the pool was
 * divided again, whether the storefront's number was written, whether the publisher was asked and what it answered. A publisher that was busy, rate limited or failed leaves
 * the row OPEN for another pass; only a settled answer closes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_stock_events')) {
            return;
        }

        Schema::create('channel_stock_events', function (Blueprint $table) {
            $table->id();
            // The pool the change affects. A change of a reservation affects the pool it left AND the pool it went to, so it writes one row for each.
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');
            $table->string('variant_key', 191)->default('');
            // Why: order_imported | order_changed | order_cancelled | units_returned | reservation_changed | provider_figure | listing_settings | pool_settings | manual
            $table->string('reason', 32);
            // What changed, for a person reading the row later. Never a figure: the figures are read afresh when the row is worked.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // pending | claimed | worked | dead. `worked` says a pass ATTENDED to it, never that a channel accepted anything: what the pass came to is `outcome` below.
            $table->string('state', 16)->default('pending');
            // Whether the storefront's own stock still has to be written for it. False once the change itself wrote it (a channel hold does, in its own transaction).
            $table->boolean('rewrites_storefront_stock')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->comment('not worked before this: a retry after a failure');
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by', 64)->nullable()->comment('which run holds it, so a run that died can be told from one still working');
            $table->timestamp('worked_at')->nullable();

            // The four facts, kept apart, because conflating any two of them is how a quantity ends up wrong while every screen says it is fine:
            //  1. RECORDED            — the row exists (created_at), in the transaction of the change itself;
            //  2. RECOMPUTED          — the pool was really divided again (recomputed_at), and the storefront's own number written (storefront_written_at);
            //  3. ASKED TO BE SENT    — the publisher was really asked, and what it made of it (outcome, publish_result, publish_detail);
            //  4. CONFIRMED BY THE CHANNEL — NOT here. It is the listing's own `publish_state` (queued → applied), because only the listing knows what the channel
            //     confirmed about it, and a submission a channel merely took is not an applied quantity.
            $table->timestamp('recomputed_at')->nullable();
            $table->timestamp('storefront_written_at')->nullable();
            // nothing_to_do | recomputed_only | sent | not_sent
            $table->string('outcome', 32)->nullable();
            // The publisher's own word for the run: ok | skipped | busy | rate_limited | failed. `skipped` means a switch or an approval refused it before anything left
            // this host, which is a settled answer; busy, rate_limited and failed are not, and the event goes back rather than being called worked.
            $table->string('publish_result', 16)->nullable();
            // Why, as a code of ours or the transport's — never what a channel said: what a channel says about a product can repeat the whole payload back.
            $table->string('publish_detail', 64)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();

            // What the worker asks for: the oldest pending row that is due. `state` first, because all but a moment's worth of the table is `done`.
            $table->index(['state', 'available_at', 'id'], 'cse_due_idx');
            // Every row of one pool, for the hub's lag figure and for a person looking at one product.
            $table->index(['product_id', 'variant_key'], 'cse_product_idx');
            $table->index(['location_id', 'state'], 'cse_location_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_stock_events');
    }
};
