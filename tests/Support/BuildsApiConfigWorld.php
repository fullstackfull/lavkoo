<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What GET /api/v1/config reads besides the store tables of BuildsApiAddressSchema, so the real answer the apps start from can be asked for in a test: the gateway
 * settings, the login set-up, and the few lists and settings the controller walks through. For a test case that extends ApiAddressTestCase.
 *
 * Like the other schema builders these tables are INFERRED from the models and the code that reads them, not from the installation dump.
 */
trait BuildsApiConfigWorld
{
    protected function buildApiConfigWorld(): void
    {
        $this->createOrderPipelineTables();

        Schema::create('addon_settings', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('test');
            $table->integer('is_active')->default(0);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });
        Schema::create('login_setups', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('meta_app_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('app_type')->nullable();
            $table->string('platform')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('help_topics', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('default');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('colors', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('tag')->nullable();
            $table->integer('visit_count')->default(0);
            $table->timestamps();
        });
        // Guarded: more than one world builder creates this table, and a test that builds two of them raised "table already exists" before a single assertion ran.
        if (!Schema::hasTable('system_tax_setups')) {
            Schema::create('system_tax_setups', function (Blueprint $table): void {
            $table->id();
            $table->string('tax_type')->nullable();
            $table->boolean('is_included')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            });
        }
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('status')->default(false);
            $table->string('api_key')->nullable();
            $table->timestamps();
        });

        DB::table('login_setups')->insert([
            ['key' => 'login_options', 'value' => json_encode(['manual_login' => 1, 'otp_login' => 0, 'social_login' => 0])],
            ['key' => 'social_media_for_login', 'value' => json_encode(['google' => 0, 'facebook' => 0, 'apple' => 0])],
        ]);
        foreach ([
            'social_login' => [],
            'apple_login' => [],
            'pnc_language' => ['en'],
            'language' => [['id' => 1, 'name' => 'English', 'code' => 'en', 'direction' => 'ltr', 'status' => 1, 'default' => true]],
            'colors' => ['primary' => '#1455ac', 'secondary' => '#f58300'],
            'firebase_otp_verification' => ['status' => 0, 'web_api_key' => ''],
        ] as $type => $value) {
            DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => json_encode($value)]);
        }
        clearWebConfigCacheKeys();
    }

    /** One gateway that is switched on, as the payment settings screen saves it. */
    protected function switchAGatewayOn(string $gateway = 'ssl_commerz'): void
    {
        DB::table('addon_settings')->insert([
            'id' => '00000000-0000-0000-0000-' . str_pad((string) (DB::table('addon_settings')->count() + 1), 12, '0', STR_PAD_LEFT),
            'key_name' => $gateway, 'settings_type' => 'payment_config', 'mode' => 'live', 'is_active' => 1,
            'live_values' => json_encode(['status' => 1, 'mode' => 'live']), 'test_values' => json_encode(['status' => 1, 'mode' => 'live']),
            'additional_data' => json_encode(['gateway_title' => ucwords(str_replace('_', ' ', $gateway)), 'gateway_image' => '']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
