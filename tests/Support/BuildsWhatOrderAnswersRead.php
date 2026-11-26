<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer's order answers load BESIDES the order itself: the review of a delivery man, the colours and attributes of a product, the
 * delivery verification images, the edit history and the delivery men. The checkout world of ApiAddressTestCase has none of them, and an
 * answer that eager-loads one is a 500 without it.
 *
 * Like the other schema builders this is INFERRED from the models and the controllers that read it, not from the installation dump. It is one
 * copy on purpose: two tests of the same answers that each built their own would drift, and the one that was not updated would be the one
 * that passed.
 */
trait BuildsWhatOrderAnswersRead
{
    protected function addWhatTheOrderAnswersRead(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
        });
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('order_delivery_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->text('image');
            $table->timestamps();
        });
        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });
    }
}
