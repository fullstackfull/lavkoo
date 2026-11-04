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
        Schema::create('vendor_withdraw_method_infos', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id');
            $table->foreignId('withdraw_method_id');
            $table->string('method_name')->nullable();
            $table->json('method_info')->nullable();
            $table->boolean('is_active')->default(0)->nullable();
            $table->boolean('is_default')->default(0)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_withdraw_method_infos');
    }
};
