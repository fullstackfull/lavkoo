<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates a MINIMAL LOCAL TEST SCHEMA so the application can boot without the proprietary
 * installation/backup/database.sql dump (which is git-ignored and absent from the repository).
 *
 * WHY THIS EXISTS
 * 6Valley's core tables (business_settings, users, products, orders, categories, brands, sellers,
 * shops, …) are created by NO migration — of 307 migrations, 229 are ALTER-only and assume the dump
 * has already been imported. `php artisan migrate` therefore dies on the 6th migration. This gives
 * developers and CI a bootable app for validating features whose own tables ARE fully migrated.
 *
 * SCOPE AND LIMITS — READ BEFORE TRUSTING A RESULT
 * These table definitions are inferred from the models, NOT copied from the real 6Valley schema.
 * They are deliberately minimal: enough columns for the boot path and the admin shell to render.
 * They are SUFFICIENT for validating the theme system, SEO subsystem and admin UI shell (whose
 * tables come from real migrations), and NOT sufficient for validating catalogue/order/checkout
 * behaviour, because a hand-built `products` table would not faithfully reproduce production.
 *
 * Refuses to run outside the local/testing environment, and never touches an existing table.
 */
class BootstrapTestSchema extends Command
{
    protected $signature = 'dev:bootstrap-test-schema {--force : Run even if APP_ENV is not local/testing}';

    protected $description = 'Create a minimal local TEST schema so the app boots without the proprietary SQL dump';

    public function handle(): int
    {
        $env = app()->environment();
        if (!in_array($env, ['local', 'testing'], true) && !$this->option('force')) {
            $this->error("Refusing to run in '{$env}'. This command is for local/testing only.");
            return self::FAILURE;
        }

        $created = 0;
        foreach ($this->definitions() as $table => $definition) {
            if (Schema::hasTable($table)) {
                $this->line("  skip (exists): {$table}");
                continue;
            }
            Schema::create($table, $definition);
            $created++;
            $this->info("  created: {$table}");
        }

        $this->seedMinimum();
        $this->newLine();
        $this->info("Done. {$created} table(s) created.");
        $this->warn('Minimal TEST schema — inferred from models, not the real 6Valley dump.');
        $this->warn('Valid for theme/SEO/admin-shell validation; NOT for catalogue/order/checkout validation.');

        return self::SUCCESS;
    }

    /** @return array<string, callable> */
    private function definitions(): array
    {
        return [
            'business_settings' => function (Blueprint $t) {
                $t->id(); $t->string('type')->nullable()->index(); $t->longText('value')->nullable(); $t->timestamps();
            },
            'currencies' => function (Blueprint $t) {
                $t->id(); $t->string('name')->nullable(); $t->string('symbol', 20)->nullable();
                $t->string('code', 20)->nullable(); $t->decimal('exchange_rate', 20, 8)->default(1);
                $t->boolean('status')->default(1); $t->timestamps();
            },
            'social_media' => function (Blueprint $t) {
                $t->id(); $t->string('name')->nullable(); $t->string('link', 2048)->nullable();
                $t->boolean('active_status')->default(1); $t->timestamps();
            },
            'admin_roles' => function (Blueprint $t) {
                $t->id(); $t->string('name')->nullable(); $t->longText('module_access')->nullable();
                $t->boolean('status')->default(1); $t->timestamps();
            },
            'admins' => function (Blueprint $t) {
                $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable();
                $t->string('password')->nullable(); $t->unsignedBigInteger('admin_role_id')->default(1);
                $t->string('image')->nullable(); $t->rememberToken(); $t->timestamps();
            },
            'settings' => function (Blueprint $t) {
                $t->id(); $t->string('key_name')->nullable()->index(); $t->boolean('live_values')->nullable();
                $t->longText('test_values')->nullable(); $t->string('settings_type')->nullable()->index();
                $t->string('mode')->nullable(); $t->boolean('is_active')->default(0); $t->timestamps();
            },
            'login_setups' => function (Blueprint $t) {
                $t->id(); $t->string('key')->nullable()->index(); $t->longText('value')->nullable(); $t->timestamps();
            },
            'analytic_scripts' => function (Blueprint $t) {
                $t->id(); $t->string('key')->nullable(); $t->longText('value')->nullable();
                $t->boolean('status')->default(0); $t->timestamps();
            },
            'business_pages' => function (Blueprint $t) {
                $t->id(); $t->string('page_name')->nullable(); $t->longText('value')->nullable(); $t->timestamps();
            },
            'translations' => function (Blueprint $t) {
                $t->id(); $t->string('translationable_type')->nullable(); $t->unsignedBigInteger('translationable_id')->nullable();
                $t->string('locale')->nullable(); $t->string('key')->nullable(); $t->longText('value')->nullable(); $t->timestamps();
            },
            'notifications' => function (Blueprint $t) {
                $t->id(); $t->string('title')->nullable(); $t->longText('description')->nullable();
                $t->boolean('status')->default(1); $t->timestamps();
            },
            'guest_users' => function (Blueprint $t) {
                $t->id(); $t->string('ip_address')->nullable(); $t->timestamps();
            },
            'users' => function (Blueprint $t) {
                $t->id(); $t->string('name')->nullable(); $t->string('f_name')->nullable();
                $t->string('l_name')->nullable(); $t->string('email')->nullable();
                $t->string('phone')->nullable(); $t->string('password')->nullable();
                $t->string('image')->nullable(); $t->boolean('is_active')->default(1);
                $t->rememberToken(); $t->timestamps();
            },
            'v2_sidebar_pins' => function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('admin_id')->nullable(); $t->string('route_name')->nullable(); $t->timestamps();
            },
        ];
    }

    /** The few rows the boot path reads before any page can render. */
    private function seedMinimum(): void
    {
        $now = now();

        if (DB::table('business_settings')->count() === 0) {
            $rows = [
                ['type' => 'company_name',        'value' => 'Local Test Store'],
                ['type' => 'company_email',       'value' => 'test@example.test'],
                ['type' => 'company_phone',       'value' => '00000000'],
                ['type' => 'currency_model',      'value' => 'multi_currency'],
                ['type' => 'system_default_currency', 'value' => '1'],
                ['type' => 'language',            'value' => json_encode([['id' => 1, 'name' => 'English', 'code' => 'en', 'status' => 1, 'default' => true]])],
                ['type' => 'pnc_language',        'value' => json_encode(['en', 'ar'])],
                ['type' => 'maintenance_mode',    'value' => json_encode(['maintenance_status' => 0])],
                ['type' => 'theme',               'value' => 'default'],
            ];
            foreach ($rows as $row) {
                DB::table('business_settings')->insert($row + ['created_at' => $now, 'updated_at' => $now]);
            }
        }

        if (DB::table('currencies')->count() === 0) {
            DB::table('currencies')->insert([
                'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD',
                'exchange_rate' => 1, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (DB::table('admin_roles')->count() === 0) {
            DB::table('admin_roles')->insert([
                'id' => 1, 'name' => 'Master Admin', 'module_access' => json_encode(['all']),
                'status' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (DB::table('admins')->count() === 0) {
            // Local-only credentials for a throwaway sqlite file; the command refuses non-local envs.
            DB::table('admins')->insert([
                'name' => 'Local Admin', 'email' => 'admin@local.test',
                'password' => bcrypt('password'), 'admin_role_id' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
