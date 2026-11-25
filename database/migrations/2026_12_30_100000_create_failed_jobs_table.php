<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a queued job that has exhausted its tries goes.
 *
 * `config/queue.php` has always pointed the failed-job driver at the database, and three readers already expect the table:
 * `SystemHealthService::queue()` counts every failure and the last day's, `QueueCollector` reports them to the monitoring page, and
 * `FulfillmentPreflight` checks it beside `jobs`. No migration of this repository created it. On a host built from migrations alone the
 * result is the worst shape a failure can take: the worker's attempt to RECORD the failure fails too, so the job disappears, and the
 * monitoring meant to notice queries a table that is not there. Silence then reads exactly like success.
 *
 * Guarded, so it is a no-op wherever the table already exists -- the installation dump this repository does not carry may well create it
 * -- and so a half-applied deploy can run `up()` again (M2-16).
 *
 * `uuid` is nullable on purpose. The configured driver is `database`, which writes connection, queue, payload, exception and failed_at
 * and no uuid; `database-uuids` writes the uuid as well. `config/queue.php` reads the driver from `QUEUE_FAILED_DRIVER`, so which one is
 * in force is an operator's choice rather than this file's assumption, and a table that serves only one of them would turn flipping that
 * variable into the same vanishing failure this migration exists to stop.
 *
 * `failed_at` is DATETIME and not TIMESTAMP. A failure is stamped `now()`, so TIMESTAMP would be correct until 2038 and then silently
 * wrong; DATETIME costs nothing and has no cliff.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('failed_jobs')) {
            return;
        }

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->nullable()->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->dateTime('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
