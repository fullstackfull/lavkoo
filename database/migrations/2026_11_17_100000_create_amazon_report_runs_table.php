<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Amazon report we asked for or found — and the reason it exists is that **Amazon documents no idempotency for
 * reports at all**.
 *
 * Nothing in the Reports API carries a client-supplied key, nothing rejects a second request for the same window, and nothing
 * says a report may not be requested twice. So two runs that both decide "last month's listings have not been fetched" both
 * call `createReport`, and `createReport` is rated at 0.0167 calls a second (one a minute, burst 15). A duplicate here does not
 * corrupt anything, but it spends an allowance the account cannot get back, and the second document is a second set of rows for
 * a consumer to de-duplicate downstream.
 *
 * Two columns do the work:
 *
 *  - `request_key` is UNIQUE: what was asked for, of which account, for which window. A second ask collides instead of sending.
 *  - `amazon_report_id` is written the instant Amazon gives one, so a run that dies after the request still knows which report
 *    to poll. Without it the report would be made, paid for, and never found — Amazon's own `getReports` is the only way back,
 *    and it is rated at one call per 45 seconds.
 *
 * NOTHING HERE HOLDS REPORT CONTENT. The presigned document URL is good for five minutes and Amazon's own page says
 * "Unencrypted report content must never be stored on disk, even temporarily", so a document is fetched, read and dropped; this
 * table keeps only what is needed to find it again and a note of what reading it produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('amazon_report_runs')) {
            return;
        }

        Schema::create('amazon_report_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0)->index();
            $table->unsignedBigInteger('account_id')->index();
            $table->string('report_type', 64)->index();
            // What was asked for, of which account, for which window -- the key that makes a second ask a collision rather than
            // a second call. It is OURS: Amazon offers nothing of the kind.
            $table->string('request_key', 191)->unique();
            // Null for a settlement report, which is never requested: Amazon schedules those and they are FOUND, so this row
            // describes a document that already existed rather than one we asked for.
            $table->boolean('requested')->default(true);
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->string('marketplace_id', 24)->nullable();

            // Amazon's own identifiers. The report id is written the moment it is known, BEFORE anything is polled, so a run
            // that dies between the request and the first poll leaves a row that says which report to ask about.
            $table->string('amazon_report_id', 120)->nullable()->index();
            $table->string('amazon_document_id', 191)->nullable();
            $table->string('processing_status', 24)->nullable()->comment('Amazon processingStatus, verbatim');

            // pending   -> nothing asked yet
            // requested -> Amazon has it (or it was found); the report id is known and it is being polled
            // read      -> the document was fetched and handed to a consumer
            // empty     -> processing ended with no document, which includes Amazon's "no data to return"
            // failed    -> FATAL, refused, or a document we could not read; the reason is recorded
            // unknown   -> we could not be told. **NEVER read as "there is no report"**
            $table->string('state', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_refused')->default(0);
            // What reading it produced, for the screen: an invoice id, a comparison summary, the refusal code. Never content.
            $table->string('outcome', 64)->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->text('failure_detail')->nullable();
            // The document's own digest, so the same document read twice is recognisable even when Amazon numbers it anew.
            $table->string('document_hash', 64)->nullable()->index();
            $table->timestamps();

            $table->index(['account_id', 'report_type', 'state'], 'arr_account_type_state_idx');
            $table->index(['state', 'available_at', 'id'], 'arr_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_report_runs');
    }
};
