<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives MyFatoorah a row in `addon_settings`, switched OFF and with no credentials.
 *
 * The admin gateway page lists the intersection of `GlobalConstant::DEFAULT_PAYMENT_GATEWAYS` and the
 * rows that exist in this table. `fatoorah` was in every name list in the codebase — the constants, the
 * admin validation rules, the supported-currency map, the route table in the Payment trait — but no row
 * was ever created and no controller was ever written, so the card had nothing to render and the route
 * it pointed at did not exist. This migration supplies the missing row; the controller and the route
 * come with the rest of this change.
 *
 * `fatoorah` is kept as the key rather than introducing `my_fatoorah`: it is the identifier already
 * written into those lists and into `payment_requests.payment_method` for anything historical, and a
 * second key would put two MyFatoorah cards side by side on the settings page. The customer-facing name
 * is carried by `additional_data.gateway_title`, which is what the storefront and the app display.
 *
 * Switched off, with empty credentials, is deliberate: nothing about this change may make a live store
 * start offering a payment method nobody has configured.
 */
return new class extends Migration {
    private const KEY = 'fatoorah';

    public function up(): void
    {
        if (!Schema::hasTable('addon_settings')) {
            return;
        }

        $existing = DB::table('addon_settings')
            ->where('key_name', self::KEY)
            ->where('settings_type', 'payment_config')
            ->first();

        if ($existing) {
            // Someone has already configured this gateway by hand. Do not touch their credentials or
            // their switch; only make sure the display name and the image reference exist.
            $this->backfillAdditionalData($existing);

            return;
        }

        $now = now();

        // Only non-secret scaffolding. Real keys are written encrypted by the admin settings page.
        $emptyValues = json_encode([
            'status' => 0,
            'mode' => 'test',
        ], JSON_UNESCAPED_UNICODE);

        $row = [
            'key_name' => self::KEY,
            'settings_type' => 'payment_config',
            'mode' => 'test',
            'is_active' => 0,
            'live_values' => $emptyValues,
            'test_values' => $emptyValues,
            'additional_data' => $this->additionalData(),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // `addon_settings` ids are uuids (App\Models\Setting uses HasUuid) on installs that have the
        // char(36) column, and auto-increment on older ones. Insert with an id only when it is needed.
        if ($this->idNeedsValue()) {
            $row['id'] = (string) Str::uuid();
        }

        DB::table('addon_settings')->insert($row);
    }

    public function down(): void
    {
        if (!Schema::hasTable('addon_settings')) {
            return;
        }

        // Only remove the row if it was never configured: dropping a row that holds a live API key, or
        // that a paid order's history points at, would lose real information on a rollback.
        $row = DB::table('addon_settings')
            ->where('key_name', self::KEY)
            ->where('settings_type', 'payment_config')
            ->first();

        if (!$row) {
            return;
        }

        $live = json_decode((string) $row->live_values, true) ?: [];
        $test = json_decode((string) $row->test_values, true) ?: [];
        $configured = !empty($live['api_key']) || !empty($test['api_key']);

        if (!$configured && (int) $row->is_active === 0) {
            DB::table('addon_settings')->where('id', $row->id)->delete();
        }
    }

    private function additionalData(): string
    {
        return json_encode([
            // What the customer sees on the web checkout and in the app's payment list.
            'gateway_title' => 'MyFatoorah — ماي فاتورة السعودية',
            // Resolved against storage/app/public/payment_modules/gateway_image/ and falls back to
            // public/assets/back-end/img/modal/payment-methods/fatoorah.png when absent.
            'gateway_image' => '',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function backfillAdditionalData(object $existing): void
    {
        $data = json_decode((string) ($existing->additional_data ?? ''), true);
        $data = is_array($data) ? $data : [];

        if (!empty($data['gateway_title'])) {
            return;
        }

        $data['gateway_title'] = 'MyFatoorah — ماي فاتورة السعودية';
        $data['gateway_image'] = $data['gateway_image'] ?? '';

        DB::table('addon_settings')
            ->where('id', $existing->id)
            ->update([
                'additional_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    /** True when `addon_settings.id` has no default and must be supplied by us. */
    private function idNeedsValue(): bool
    {
        try {
            $type = Schema::getColumnType('addon_settings', 'id');
        } catch (\Throwable) {
            return true;
        }

        // A uuid/char primary key needs a value; an integer identity column does not.
        return !in_array(strtolower((string) $type), ['integer', 'bigint', 'int'], true);
    }
};
