<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_requests`, the gateway-agnostic payment ledger every gateway in this codebase writes to.
 *
 * It has no migration here -- it comes from the installation dump -- so a test that reads or writes a payment attempt builds it. One definition, shared,
 * because two drifting copies of a dump table is how a test comes to pass against a shape production does not have.
 */
trait BuildsPaymentRequestsTable
{
    protected function createPaymentRequestsTable(bool $fresh = false): void
    {
        if ($fresh) {
            Schema::dropIfExists('payment_requests');
        } elseif (Schema::hasTable('payment_requests')) {
            return;
        }

        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('payer_id')->nullable();
            $table->string('receiver_id')->nullable();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('success_hook')->nullable();
            $table->string('failure_hook')->nullable();
            $table->text('additional_data')->nullable();
            $table->text('payer_information')->nullable();
            $table->text('receiver_information')->nullable();
            $table->text('external_redirect_link')->nullable();
            $table->string('attribute')->nullable();
            $table->string('attribute_id')->nullable();
            $table->string('payment_platform')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });
    }
}
