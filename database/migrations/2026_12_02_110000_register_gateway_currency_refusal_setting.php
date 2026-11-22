<?php

use App\Services\Payments\GatewayCurrencySupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the switch that makes a gateway refuse a currency it cannot take (D2-V1), SWITCHED OFF.
 *
 * The row is written so an operator can find the setting at all; its value is `0`, so every installation
 * -- a shipped Saudi one included -- behaves exactly as it did before this migration ran. A value an
 * operator has already set is left alone: this only ever creates the row it finds missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('business_settings') || DB::table('business_settings')->where('type', GatewayCurrencySupport::SETTING)->exists()) {
            return;
        }

        DB::table('business_settings')->insert([
            'type' => GatewayCurrencySupport::SETTING,
            'value' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('business_settings')) {
            DB::table('business_settings')->where('type', GatewayCurrencySupport::SETTING)->delete();
        }
    }
};
