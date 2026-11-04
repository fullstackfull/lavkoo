<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();

            $table->uuid('u_id');
            $table->unsignedBigInteger('order_id');
            $table->string('edit_by')->nullable();
            $table->unsignedBigInteger('edited_user_id')->nullable();
            $table->string('edited_user_name')->nullable();

            $table->decimal('order_amount', 21, 12)->default(0);
            $table->decimal('order_due_amount', 21, 12)->default(0);
            $table->string('order_due_payment_status')->nullable()->comment("paid or unpaid");
            $table->json('order_due_payment_info')->nullable();
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_due_transaction_ref')->nullable();
            $table->text('order_due_payment_note')->nullable();

            $table->decimal('order_return_amount', 21, 12)->default(0);
            $table->string('order_return_payment_status')->nullable()->comment('pending or returned');
            $table->string('order_return_payment_method')->nullable();
            $table->json('order_return_payment_info')->nullable();
            $table->string('order_return_transaction_ref')->nullable();
            $table->text('order_return_payment_note')->nullable();

            $table->timestamps();


            $table->unique('u_id');
            $table->index('order_id');
            $table->index('edited_user_id');

            $table->index(['order_id', 'created_at']);
            $table->index(['order_due_payment_status', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_edit_histories');
    }
};
