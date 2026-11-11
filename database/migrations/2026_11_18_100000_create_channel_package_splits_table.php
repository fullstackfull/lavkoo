<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One request to cut one shipment package into several — and the row that keeps it from being cut twice.
 *
 * ## Why a row at all, when the package can simply be read
 *
 * Trendyol documents **no idempotency** for any of its four split endpoints and says **nothing** about what a repeated
 * identical request does. It also makes the split **asynchronous**: "New packages will be created asynchronously". Those two
 * facts together are the whole reason this table exists.
 *
 * The package's own status is the proof a split happened — the original becomes `UnPacked` and its id becomes invalid — but
 * that proof arrives LATE. A run that sends a split and dies before recording anything leaves a package still reading
 * `Created`; a second run reading that status would conclude nothing had happened and cut it again, and Trendyol's pages do
 * not say what that produces. So the intent is written down **before** the request leaves, and the row then waits for the
 * proof instead of guessing at it.
 *
 * That is also why this is NOT the same design as a return decision. A return decision can never be resolved — Trendyol
 * publishes no way to ask what became of one. A split can: `state = may_have_sent` is a question with an answer, and
 * `ChannelPackageSplits` goes and reads it. The fence is the same; what follows the fence is not.
 *
 * ## What is kept
 *
 * The plan, exactly as it was sent, and nothing about a buyer. `response_was_empty` is not a defect: every international
 * split endpoint documents 200 with no content schema, so an empty answer is the documented success and the new package ids
 * exist only in Trendyol until the order is read again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_package_splits')) {
            return;
        }

        Schema::create('channel_package_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0)->index();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('channel', 24)->index();
            $table->string('external_order_id', 120)->index();
            // The package being cut. Its id becomes INVALID once the cut lands, which is why it is kept here: afterwards it
            // exists nowhere else.
            $table->string('package_id', 120);
            $table->string('kind', 24)->comment('lines | groups | line_quantities | package_details -- which of the four endpoints');
            $table->json('plan')->comment('the body as it was sent, so what was asked for survives the package it was asked about');
            $table->boolean('keep_previous_status')->default(false);
            $table->unsignedSmallInteger('expected_packages')->default(0)->comment('how many the plan asks for, not counting the remainder package Trendyol adds itself');

            // pending       -> recorded here, nothing sent
            // may_have_sent -> **WRITTEN BEFORE THE REQUEST.** The package may already be cut; no run decides again from here.
            // confirmed     -> the package was read back and really is split (UnPacked, or its successors exist)
            // needs_review  -> it went out, and reading the package back still does not say. A person looks.
            // failed        -> refused before anything left, or Trendyol answered a refusal
            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('checks')->default(0)->comment('how many times the package was read back looking for the proof');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->comment('the admin who asked for the split, where a person asked');
            $table->string('observed_status', 32)->nullable()->comment('what the package said when it was last read back');
            $table->json('new_package_ids')->nullable()->comment('read from the order afterwards -- NEVER from the split response, which documents no body');
            $table->string('failure_code', 120)->nullable();
            $table->text('failure_detail')->nullable();
            $table->timestamps();

            // **ONE ROW PER PACKAGE, ENFORCED HERE AND NOT BY A READ.**
            //
            // This was an index and nothing more, with `request()` looking for an open row before inserting. Four processes
            // asked to cut one package at the same instant, all four found nothing, all four inserted, all four fenced their
            // own row and all four sent -- the package cut four times, which is four labels and four courier charges the
            // pharmacy pays. A check followed by an insert is not a guard; only the database is. Caught by this module's own
            // concurrency tests on a real MySQL, where they are the only place such a race can happen at all.
            //
            // A clean failure may still be asked for again: `request()` REVIVES this row rather than inserting a second one,
            // through a conditional update only one process can win. The history stays on one row as well.
            $table->unique(['account_id', 'package_id'], 'cps_package_unique');
            $table->index(['state', 'available_at', 'id'], 'cps_due_idx');
            $table->index(['account_id', 'external_order_id'], 'cps_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_package_splits');
    }
};
