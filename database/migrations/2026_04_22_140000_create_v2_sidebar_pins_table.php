<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_sidebar_pins', function (Blueprint $table) {
            $table->id();
            // 'admin' (admins.id) or 'seller' (sellers.id) — kept as a
            // simple string instead of a polymorphic relation because
            // the v2 chrome only ever serves these two user types.
            $table->string('user_type', 16);
            $table->unsignedBigInteger('user_id');
            // Pin id is either a parent's data-item value (e.g. "orders")
            // or a child marker "child:" + the child's full href.
            // 190 chars × 4 bytes (utf8mb4) = 760 bytes; composite index
            // total = 832 bytes, within MySQL/MariaDB's 1000-byte key limit.
            $table->string('pin_id', 190);
            $table->timestamps();

            $table->unique(['user_type', 'user_id', 'pin_id'], 'v2_sidebar_pins_unique');
            $table->index(['user_type', 'user_id'], 'v2_sidebar_pins_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_sidebar_pins');
    }
};
