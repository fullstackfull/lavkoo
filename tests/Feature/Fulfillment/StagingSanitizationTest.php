<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Fulfillment\Readiness\Staging\OutboundAudit;
use App\Services\Fulfillment\Readiness\Staging\OutboundChannel;
use App\Services\Fulfillment\Readiness\Staging\SinkTarget;
use App\Services\Fulfillment\Readiness\Staging\SqlDialect;
use App\Services\Fulfillment\Readiness\Staging\StagingChannels;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The plan-only sanitization generator and the read-only outbound audit, against a synthetic copy of production in which
 * every channel is armed with a canary secret: the audit must see them, the generated plan must neutralise them (the test
 * runs the plan on the throwaway database), and the audit must then go quiet. The plan and the report must never carry a canary.
 */
class StagingSanitizationTest extends FulfillmentTestCase
{
    private const SECRETS = [
        'CANARY-SMTP-PASSWORD', 'canary-smtp-user@leak.test', 'CANARY-SENDGRID-PASSWORD', 'CANARY-FCM-PRIVATE-KEY', 'CANARY-FCM-WEB',
        'CANARY-SMS-LIVE', 'CANARY-SMS-TEST', 'CANARY-ZENDER-SECRET', 'CANARY-FIREBASE-WEB-KEY', 'CANARY-STRIPE-LIVE', 'CANARY-PAYPAL-LEGACY',
        'CANARY-SYRIA-SECRET', 'CANARY-SYRIA-WEBHOOK', 'CANARY-M5-CREDENTIALS', 'CANARY-CHATWOOT-TOKEN', 'CANARY-S3-SECRET', 'CANARY-AI-KEY',
        'CANARY-RECAPTCHA-SECRET', 'CANARY-MAPS-KEY', 'CANARY-DEVICE-TOKEN-1', 'CANARY-DEVICE-TOKEN-2', 'CANARY-PIXEL',
    ];

    private const PERSONAL = [
        'CANARY-CUSTOMER-NAME', 'canary.customer@leak.test', '0599900111', 'CANARY-STREET-9', 'CANARY-NOTE-77', 'CANARY-VENDOR-NAME', 'CANARY-COURIER-ID',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['fulfillment.external_writes_enabled' => false]);
        $this->buildProductionLikeTables();
        $this->armEveryChannel();
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function audit(array $options = []): array
    {
        $exit = Artisan::call('fulfillment:outbound-audit', ['--json' => true] + $options);
        $output = Artisan::output();

        return [$exit, (array) json_decode($output, true), $output];
    }

    /** @return array<string, mixed> */
    private function channel(array $report, string $key): array
    {
        foreach ($report['checks'] as $check) {
            if (($check['facts']['channel'] ?? null) === $key) {
                return $check;
            }
        }

        $this->fail("no channel '{$key}' in the audit");
    }

    private function plan(): string
    {
        Artisan::call('fulfillment:sanitization-plan', ['--expect-database' => (string) DB::connection()->getDatabaseName()]);

        return Artisan::output();
    }

    /** @return list<string> the statements of a plan, comments removed, without the trailing semicolon */
    private function statementsOf(string $plan): array
    {
        $statements = [];
        foreach (explode("\n", $plan) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '--')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }

    /** Runs a plan on the (throwaway) test database, the way an operator would; returns the verification counts. */
    private function applyPlan(string $plan): array
    {
        $counts = [];
        foreach ($this->statementsOf($plan) as $statement) {
            if (!str_starts_with($statement, 'SELECT')) {
                DB::unprepared($statement);

                continue;
            }

            $row = (array) DB::select($statement)[0];
            if (isset($row['target'])) {
                $counts[(string) $row['target']] = (int) ($row['rows_still_armed'] ?? $row['rows_not_scrubbed']);
            }
        }

        return $counts;
    }

    // ── the audit sees what is armed ─────────────────────────────────────────────────────────────────────────

    public function test_the_audit_finds_every_armed_channel_and_counts_its_rows(): void
    {
        [$exit, $report] = $this->audit();

        $this->assertSame(1, $exit);
        foreach (['queue', 'sessions_and_tokens', 'mail', 'abandoned_cart_mail', 'monitoring_alerts', 'sms_gateways', 'zender', 'firebase_otp', 'push', 'payment_gateways', 'delivery_syria', 'fulfillment_providers', 'chatwoot', 'seller_webhooks', 'remote_storage', 'analytics', 'ai_provider', 'recaptcha', 'maps', 'social_login'] as $key) {
            $check = $this->channel($report, $key);
            $this->assertContains($check['status'], ['fail', 'warn'], "{$key} should be reported as armed: {$check['detail']}");
            $this->assertNotEmpty($check['facts']['armed'], $key);
        }

        $mail = $this->channel($report, 'mail');
        $this->assertSame('fail', $mail['status']);
        $this->assertSame([['table' => 'business_settings', 'armed_rows' => 2]], $mail['facts']['armed']);
        $this->assertSame([['table' => 'jobs', 'armed_rows' => 3], ['table' => 'failed_jobs', 'armed_rows' => 1]], $this->channel($report, 'queue')['facts']['armed']);
        $this->assertSame('warn', $this->channel($report, 'maps')['status'], 'a low-risk channel warns instead of failing');
        $this->assertStringContainsString('Manual step', $this->channel($report, 'social_login')['detail']);
    }

    public function test_the_audit_reports_counts_and_names_never_a_secret_or_a_person(): void
    {
        [, , $json] = $this->audit();
        Artisan::call('fulfillment:outbound-audit');
        $table = Artisan::output();

        foreach ([...self::SECRETS, ...self::PERSONAL] as $canary) {
            $this->assertStringNotContainsString($canary, $json, $canary);
            $this->assertStringNotContainsString($canary, $table, $canary);
        }
    }

    public function test_the_audit_only_reads(): void
    {
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        [, $report] = $this->audit();

        $this->assertSame(0, $report['read_only_proof']['write_statements']);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|show|describe|desc|explain|with\b|set\s+session\s+transaction\s+read\s+(only|write)|pragma\s+query_only|pragma\s+table)/i', $sql);
        }
    }

    public function test_a_channel_the_operator_knowingly_leaves_armed_is_reported_but_not_failed(): void
    {
        [, $report] = $this->audit(['--allow-channel' => ['fulfillment_providers']]);

        $this->assertSame('info', $this->channel($report, 'fulfillment_providers')['status']);
        $this->assertStringContainsString('allowed by --allow-channel', $this->channel($report, 'fulfillment_providers')['detail']);
        $this->assertSame('fail', $this->channel($report, 'mail')['status']);
    }

    public function test_a_channel_whose_tables_do_not_exist_is_reported_as_unable_to_act(): void
    {
        Schema::drop('zender_settings');

        [, $report] = $this->audit();

        $zender = $this->channel($report, 'zender');
        $this->assertSame('info', $zender['status']);
        $this->assertStringContainsString('cannot be armed from here', $zender['detail']);
    }

    public function test_a_condition_the_database_cannot_evaluate_is_a_warning_not_a_crash(): void
    {
        $broken = new OutboundChannel(
            key: 'broken',
            label: 'A channel whose condition the database rejects',
            risk: OutboundChannel::CRITICAL,
            why: 'Test.',
            targets: [new SinkTarget(table: 'zender_settings', columns: ['enabled'], armed: static fn (): string => 'enabled = (((', set: static fn (): string => 'enabled = 0')],
        );

        $checks = app(OutboundAudit::class)->checks(new ReadOnlyProbe(DB::connection()), channels: [$broken]);

        $this->assertCount(1, $checks);
        $this->assertSame('warn', $checks[0]->status);
        $this->assertStringContainsString('could not be evaluated', $checks[0]->detail);
        $this->assertStringNotContainsString('(((', $checks[0]->detail, 'the SQL is not echoed back');
    }

    // ── the plan ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_plan_is_statements_only_and_never_runs_or_carries_anything(): void
    {
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $before = DB::table('business_settings')->count();

        $plan = $this->plan();

        $this->assertStringContainsString('SANITIZATION PLAN', $plan);
        $this->assertStringContainsString('Nothing here has been run', $plan);
        $this->assertStringContainsString('-- 0. Guard', $plan);
        $this->assertStringContainsString('-- Verification', $plan);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|show|describe|desc|explain|with\b|set\s+session\s+transaction\s+read\s+(only|write)|pragma\s+)/i', $sql, 'the generator ran something that is not a read');
        }
        $this->assertSame($before, DB::table('business_settings')->count());
        foreach ([...self::SECRETS, ...self::PERSONAL] as $canary) {
            $this->assertStringNotContainsString($canary, $plan, "the plan carries '{$canary}'");
        }
    }

    public function test_the_plan_requires_the_expected_database_and_refuses_another_one_or_production(): void
    {
        $this->assertSame(1, Artisan::call('fulfillment:sanitization-plan'));
        $this->assertStringContainsString('--expect-database is required', Artisan::output());

        $this->assertSame(1, Artisan::call('fulfillment:sanitization-plan', ['--expect-database' => 'pharmacy_production']));
        $this->assertStringContainsString('different database', Artisan::output());

        $this->app['env'] = 'production';
        DB::enableQueryLog();
        $this->assertSame(1, Artisan::call('fulfillment:sanitization-plan', ['--expect-database' => (string) DB::connection()->getDatabaseName()]));
        $this->assertStringContainsString('APP_ENV is production', Artisan::output());
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_plan_leaves_out_what_this_database_does_not_have_and_says_so(): void
    {
        Schema::drop('zender_settings');
        Schema::drop('seller_webhooks');

        $plan = $this->plan();

        $this->assertStringNotContainsString('UPDATE zender_settings', $plan);
        $this->assertStringContainsString('zender: table zender_settings is not present', $plan);
        $this->assertStringContainsString('seller_webhooks: table seller_webhooks is not present', $plan);
        $this->assertStringContainsString('UPDATE seller_webhook_deliveries', $plan);
    }

    public function test_running_the_plan_neutralises_every_channel_and_the_audit_goes_quiet(): void
    {
        $counts = $this->applyPlan($this->plan());

        $this->assertNotEmpty($counts);
        foreach ($counts as $target => $stillArmed) {
            // The social-login row has no portable statement: it is reported as manual, not verified here.
            $this->assertSame(0, $stillArmed, "'{$target}' still armed or not scrubbed after the plan");
        }

        [, $report] = $this->audit();
        foreach ($report['checks'] as $check) {
            if (($check['section'] ?? '') === 'outbound' && ($check['facts']['channel'] ?? '') !== 'social_login') {
                $this->assertContains($check['status'], ['pass', 'info'], "{$check['name']} still armed after the plan: {$check['detail']}");
            }
        }
        $this->assertSame('warn', $this->channel($report, 'social_login')['status'], 'the manual step stays visible until it is done');
    }

    public function test_after_the_plan_no_secret_remains_in_the_database(): void
    {
        $this->applyPlan($this->plan());

        foreach (['business_settings' => ['value'], 'addon_settings' => ['live_values', 'test_values'], 'zender_settings' => ['secret'], 'integration_settings' => ['credentials'], 'ai_settings' => ['api_key'], 'users' => ['cm_firebase_token', 'cm_firebase_token_web'], 'sellers' => ['cm_firebase_token']] as $table => $columns) {
            foreach ($columns as $column) {
                foreach (self::SECRETS as $canary) {
                    $this->assertSame(0, DB::table($table)->where($column, 'like', "%{$canary}%")->count(), "{$table}.{$column} still holds {$canary}");
                }
            }
        }
    }

    public function test_the_plan_can_be_run_twice(): void
    {
        $plan = $this->plan();
        $this->applyPlan($plan);

        $second = $this->applyPlan($plan);

        $this->assertSame(array_fill_keys(array_keys($second), 0), $second);
    }

    public function test_personal_data_is_replaced_by_placeholders_that_keep_their_shape_and_stay_unique(): void
    {
        $this->applyPlan($this->plan());

        $users = DB::table('users')->orderBy('id')->get();
        $this->assertCount(3, $users);
        foreach ($users as $user) {
            $this->assertMatchesRegularExpression('/^customer\d+@staging\.invalid$/', $user->email);
            $this->assertMatchesRegularExpression('/^05\d{8}$/', $user->phone);
            $this->assertSame('', $user->password, 'nobody can sign in with a production password');
        }
        $this->assertCount(3, array_unique($users->pluck('email')->all()));
        $this->assertCount(3, array_unique($users->pluck('phone')->all()));

        $address = DB::table('shipping_addresses')->first();
        $this->assertSame('Recipient', $address->contact_person_name);
        $this->assertSame('', $address->address);
        $this->assertSame('الرياض', $address->city, 'the city keeps its shape');

        $order = json_decode((string) DB::table('orders')->where('id', 100)->value('shipping_address_data'), true);
        $this->assertSame('Recipient', $order['contact_person_name']);
        $this->assertMatchesRegularExpression('/^05\d{8}$/', $order['phone']);
        $this->assertSame('', $order['address']);
        $this->assertSame('الرياض', $order['city']);
        $this->assertSame('', (string) DB::table('orders')->where('id', 100)->value('order_note'));
        foreach (self::PERSONAL as $canary) {
            foreach (['users', 'sellers', 'delivery_men', 'shipping_addresses', 'orders'] as $table) {
                $this->assertSame(0, DB::table($table)->where(DB::raw('1'), 1)->get()->filter(static fn ($row): bool => str_contains(json_encode($row, JSON_UNESCAPED_UNICODE), $canary))->count(), "{$table} still holds {$canary}");
            }
        }
    }

    public function test_provider_switches_are_returned_to_dispatch_off_and_dry_run_on(): void
    {
        $this->applyPlan($this->plan());

        foreach (['m5azn', 'torod_point'] as $provider) {
            $row = DB::table('integration_settings')->where('provider', $provider)->first();
            $settings = json_decode((string) $row->settings, true);
            $this->assertEquals(0, $row->enabled);
            $this->assertNull($row->credentials);
            $this->assertEmpty($settings['dispatch_enabled']);
            $this->assertNotEmpty($settings['dry_run']);
        }
    }

    public function test_what_a_person_confirmed_about_the_production_account_does_not_travel_with_a_copy(): void
    {
        $this->applyPlan($this->plan());

        $settings = json_decode((string) DB::table('integration_settings')->where('provider', 'torod_point')->value('settings'), true);
        $this->assertNull($settings['invoice_round_trip'], 'the invoice number round trip was shown for the production account');
        $this->assertNull($settings['stock_field_confirmed_at']);
        $this->assertNull($settings['stock_field_confirmed_path']);
        $this->assertSame('714', $settings['company_id'], 'nothing else about the settings is rewritten');
    }

    /** @param array<string, mixed> $confirmation */
    #[DataProvider('confirmationsAlone')]
    public function test_a_confirmation_alone_keeps_a_provider_row_armed_until_it_is_cleaned(array $confirmation): void
    {
        foreach (['m5azn', 'torod_point'] as $provider) {
            $settings = json_decode((string) DB::table('integration_settings')->where('provider', $provider)->value('settings'), true);
            DB::table('integration_settings')->where('provider', $provider)->update([
                'enabled' => 0,
                'credentials' => null,
                'settings' => json_encode(array_merge($settings, [
                    'dispatch_enabled' => false, 'auto_dispatch' => false, 'product_sync_enabled' => false, 'inventory_sync_enabled' => false,
                    'import_enabled' => false, 'push_products_enabled' => false, 'webhooks_enabled' => false, 'dry_run' => true,
                    'invoice_round_trip' => null, 'stock_field_confirmed_at' => null, 'stock_field_confirmed_path' => null,
                ], $provider === 'torod_point' ? $confirmation : [])),
            ]);
        }

        [, $report] = $this->audit();
        $this->assertSame(
            [['table' => 'integration_settings', 'armed_rows' => 1]],
            $this->channel($report, 'fulfillment_providers')['facts']['armed'],
            'what was confirmed about the production account is not true of a copy, so the audit reports the row',
        );

        $this->applyPlan($this->plan());

        $cleaned = json_decode((string) DB::table('integration_settings')->where('provider', 'torod_point')->value('settings'), true);
        foreach (['invoice_round_trip', 'stock_field_confirmed_at', 'stock_field_confirmed_path'] as $key) {
            $this->assertNull($cleaned[$key] ?? null, $key);
        }
        [, $after] = $this->audit();
        $this->assertSame([], $this->channel($after, 'fulfillment_providers')['facts']['armed']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function confirmationsAlone(): iterable
    {
        yield 'the invoice round trip' => [['invoice_round_trip' => ['confirmed_at' => '2026-11-05 10:00:00', 'invoice_path' => 'invoiceNumber', 'company_id' => '714']]];
        yield 'the stock field with its path' => [['stock_field_confirmed_at' => '2026-11-05 10:00:00', 'stock_field_confirmed_path' => 'sellableQuantity']];
        yield 'a stock path alone' => [['stock_field_confirmed_path' => 'sellableQuantity']];
    }

    // ── the guard, the registry and the dialect ──────────────────────────────────────────────────────────────

    public function test_on_mysql_the_guard_statement_fails_for_a_database_that_is_not_the_expected_one(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL only: SQLite has no database name to guard.');
        }
        $name = (string) DB::connection()->getDatabaseName();
        $sql = SqlDialect::forDriver(DB::getDriverName());

        $this->assertSame(1, count(DB::select($sql->guardDatabase($name))));

        $this->expectException(QueryException::class);
        DB::select($sql->guardDatabase('some_other_database'));
    }

    public function test_every_channel_has_a_unique_key_and_every_target_a_statement_and_a_check(): void
    {
        $keys = [];
        foreach (StagingChannels::all() as $channel) {
            $this->assertNotContains($channel->key, $keys, 'duplicate channel key');
            $keys[] = $channel->key;
            $this->assertContains($channel->risk, [OutboundChannel::CRITICAL, OutboundChannel::HIGH, OutboundChannel::MEDIUM, OutboundChannel::LOW]);
            $this->assertNotSame('', $channel->why);
            $this->assertNotEmpty($channel->targets);
            foreach ($channel->targets as $target) {
                foreach (['sqlite', 'mysql'] as $driver) {
                    $sql = SqlDialect::forDriver($driver);
                    $this->assertNotSame('', $target->armedCondition($sql));
                    $this->assertStringContainsString('COUNT(*)', $target->verification($sql, 'x'));
                    if ($target->canSink()) {
                        $this->assertMatchesRegularExpression('/^(UPDATE|DELETE FROM|TRUNCATE TABLE) ' . $target->table . '\b/', $target->statement($sql));
                    }
                }
            }
        }
        $this->assertContains('delivery_syria', $keys, 'the courier that creates a real parcel at checkout is in the registry');
        $this->assertSame(['social_login'], array_values(array_map(static fn (OutboundChannel $channel): string => $channel->key, array_filter(StagingChannels::all(), static fn (OutboundChannel $channel): bool => $channel->manual !== null))));
    }

    public function test_the_environment_audit_names_what_is_unsafe_without_printing_values(): void
    {
        config(['mail.driver' => 'smtp']);
        $this->setEnvironment('OPENAI_API_KEY', 'CANARY-OPENAI-ENV');
        $this->setEnvironment('PURCHASE_CODE', 'CANARY-PURCHASE-CODE');
        try {
            [$exit, $report, $json] = $this->audit();
        } finally {
            $this->setEnvironment('OPENAI_API_KEY', null);
            $this->setEnvironment('PURCHASE_CODE', null);
        }

        $this->assertSame(1, $exit);
        $byName = array_column($report['checks'], null, 'name');
        $this->assertSame('fail', $byName['third-party credentials in the environment']['status']);
        $this->assertStringContainsString('OPENAI_API_KEY', $byName['third-party credentials in the environment']['detail']);
        $this->assertSame('warn', $byName['licence identity variables']['status']);
        $this->assertSame('fail', $byName['vendor licence call']['status'], 'DEVELOPMENT_ENVIRONMENT is not set');
        $this->assertSame('fail', $byName['mail transport']['status']);
        $this->assertStringContainsString('MAIL_DRIVER, not MAIL_MAILER', $byName['mail transport']['detail']);
        $this->assertStringNotContainsString('CANARY-OPENAI-ENV', $json);
        $this->assertStringNotContainsString('CANARY-PURCHASE-CODE', $json);
    }

    public function test_the_licence_call_is_reported_safe_once_the_development_switch_is_on(): void
    {
        $this->setEnvironment('DEVELOPMENT_ENVIRONMENT', 'true');
        try {
            [, $report] = $this->audit();
        } finally {
            $this->setEnvironment('DEVELOPMENT_ENVIRONMENT', null);
        }

        $this->assertSame('pass', array_column($report['checks'], null, 'name')['vendor licence call']['status']);
    }

    public function test_the_production_host_is_looked_for_in_stored_text_and_in_the_application_url(): void
    {
        DB::table('business_settings')->insert(['type' => 'download_app_link', 'value' => 'https://shop.example-production.test/app']);
        config(['app.url' => 'https://shop.example-production.test']);

        [, $report] = $this->audit(['--production-host' => 'shop.example-production.test']);

        $byName = array_column($report['checks'], null, 'name');
        $this->assertSame('warn', $byName['the production host written into the data']['status']);
        $this->assertSame('business_settings', $byName['the production host written into the data']['facts']['rows_containing_the_host'][0]['table']);
        $this->assertSame('fail', $byName['application URL']['status']);

        $this->assertSame(1, Artisan::call('fulfillment:outbound-audit', ['--production-host' => 'not a host; drop table']));
        $this->assertStringContainsString('plain host name', Artisan::output());
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    // ── a synthetic copy of production ───────────────────────────────────────────────────────────────────────

    private function buildProductionLikeTables(): void
    {
        $this->createTable('addon_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key_name');
            $table->string('settings_type');
            $table->boolean('is_active')->default(0);
            $table->string('mode')->default('live');
            $table->longText('live_values')->nullable();
            $table->longText('test_values')->nullable();
        });
        $this->createTable('zender_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(0);
            $table->text('secret')->nullable();
        });
        $this->createTable('seller_webhooks', static function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('active');
        });
        $this->createTable('seller_webhook_deliveries', static function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('pending');
        });
        $this->createTable('shipping_methods', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('status')->default(1);
        });
        $this->createTable('analytic_scripts', static function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(0);
        });
        $this->createTable('ai_settings', static function (Blueprint $table): void {
            $table->id();
            $table->integer('status')->default(0);
            $table->text('api_key')->nullable();
        });
        $this->createTable('monitoring_alert_rules', static function (Blueprint $table): void {
            $table->id();
            $table->boolean('notify_email')->default(1);
        });
        $this->createTable('notification_template_mappings', static function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(0);
        });
        foreach (['jobs', 'failed_jobs', 'sessions', 'password_resets', 'oauth_access_tokens'] as $name) {
            $this->createTable($name, static function (Blueprint $table): void {
                $table->id();
                $table->text('payload')->nullable();
            });
        }
        $person = static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('street_address')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
        };
        $this->createTable('users', $person);
        $this->createTable('sellers', $person);
        $this->createTable('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('identity_number')->nullable();
            $table->string('fcm_token')->nullable();
        });
        $this->createTable('shipping_addresses', static function (Blueprint $table): void {
            $table->id();
            $table->string('contact_person_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
        });
        Schema::table('orders', static fn (Blueprint $table) => $table->longText('billing_address_data')->nullable());
    }

    private function createTable(string $name, \Closure $definition): void
    {
        Schema::dropIfExists($name);
        Schema::create($name, $definition);
    }

    private function armEveryChannel(): void
    {
        $setting = static fn (string $type, mixed $value): array => ['type' => $type, 'value' => is_string($value) ? $value : json_encode($value)];
        DB::table('business_settings')->insert([
            $setting('mail_config', ['status' => 1, 'host' => 'smtp.leak.test', 'username' => 'canary-smtp-user@leak.test', 'password' => 'CANARY-SMTP-PASSWORD']),
            $setting('mail_config_sendgrid', ['status' => 0, 'host' => 'smtp.sendgrid.net', 'username' => 'apikey', 'password' => 'CANARY-SENDGRID-PASSWORD']),
            $setting('abandoned_cart_reminder_status', '1'),
            $setting('push_notification_key', '{"project_id":"p","private_key":"CANARY-FCM-PRIVATE-KEY"}'),
            $setting('fcm_credentials', ['apiKey' => 'CANARY-FCM-WEB', 'projectId' => 'p']),
            $setting('firebase_otp_verification', ['status' => 1, 'web_api_key' => 'CANARY-FIREBASE-WEB-KEY']),
            $setting('paypal', ['status' => 1, 'paypal_client_id' => 'CANARY-PAYPAL-LEGACY']),
            $setting('delivery_syria', ['status' => 1, 'secret_token' => 'CANARY-SYRIA-SECRET', 'webhook_token' => 'CANARY-SYRIA-WEBHOOK']),
            $setting('delivery_syria_webhook_token', 'CANARY-SYRIA-WEBHOOK'),
            $setting('storage_connection_type', 's3'),
            $setting('storage_connection_s3_credential', ['key' => 'CANARY-S3-SECRET']),
            $setting('recaptcha', ['status' => 1, 'site_key' => 'public', 'secret_key' => 'CANARY-RECAPTCHA-SECRET']),
            $setting('map_api_key', 'CANARY-MAPS-KEY'),
            $setting('pixel_analytics', 'CANARY-PIXEL'),
            $setting('social_login', [['login_medium' => 'google', 'client_id' => 'x', 'client_secret' => 'y', 'status' => 1]]),
        ]);
        DB::table('addon_settings')->insert([
            ['key_name' => 'twilio', 'settings_type' => 'sms_config', 'is_active' => 1, 'live_values' => 'CANARY-SMS-LIVE', 'test_values' => 'CANARY-SMS-TEST'],
            ['key_name' => 'stripe', 'settings_type' => 'payment_config', 'is_active' => 1, 'live_values' => 'CANARY-STRIPE-LIVE', 'test_values' => null],
        ]);
        DB::table('zender_settings')->insert(['enabled' => 1, 'secret' => 'CANARY-ZENDER-SECRET']);
        $this->enableProvider(ProviderCode::M5azn, ['dispatch_enabled' => true, 'dry_run' => false], ['email' => 'seller@example.test', 'password' => 'CANARY-M5-CREDENTIALS']);
        $this->enableProvider(ProviderCode::TorodPoint, [
            'dispatch_enabled' => true, 'dry_run' => false, 'company_id' => '714',
            'stock_field_confirmed_at' => '2026-11-05 10:00:00', 'stock_field_confirmed_path' => 'sellableQuantity',
            'invoice_round_trip' => ['confirmed_at' => '2026-11-05 10:00:00', 'invoice_path' => 'invoiceNumber', 'company_id' => '714', 'evidence' => 'Confirmed by the provider for the production account.'],
        ], ['fulfillment_username' => 'u', 'fulfillment_password' => 'CANARY-M5-CREDENTIALS']);
        DB::table('integration_settings')->insert(['provider' => 'chatwoot', 'name' => 'Chatwoot', 'enabled' => 1, 'credentials' => 'CANARY-CHATWOOT-TOKEN', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_template_mappings')->insert(['enabled' => 1]);
        DB::table('seller_webhooks')->insert([['status' => 'active'], ['status' => 'paused']]);
        DB::table('seller_webhook_deliveries')->insert([['status' => 'pending'], ['status' => 'delivered']]);
        DB::table('shipping_methods')->insert([['name' => 'DeliverySyria', 'status' => 1], ['name' => 'Standard', 'status' => 1]]);
        DB::table('analytic_scripts')->insert(['is_active' => 1]);
        DB::table('ai_settings')->insert(['status' => 1, 'api_key' => 'CANARY-AI-KEY']);
        DB::table('monitoring_alert_rules')->insert(['notify_email' => 1]);
        DB::table('jobs')->insert([['payload' => 'a'], ['payload' => 'b'], ['payload' => 'c']]);
        DB::table('failed_jobs')->insert(['payload' => 'x']);
        DB::table('sessions')->insert(['payload' => 's']);

        foreach ([['users', 'CANARY-CUSTOMER-NAME', 'canary.customer@leak.test'], ['users', 'Second Person', 'two@leak.test'], ['users', 'Third Person', 'three@leak.test']] as $index => [$table, $name, $email]) {
            DB::table($table)->insert(['name' => $name, 'f_name' => $name, 'l_name' => 'Surname', 'email' => $email, 'phone' => $index === 0 ? '0599900111' : '055000000' . $index, 'password' => '$2y$10$hash', 'street_address' => 'CANARY-STREET-9', 'cm_firebase_token' => 'CANARY-DEVICE-TOKEN-' . min(2, $index + 1), 'cm_firebase_token_web' => 'CANARY-DEVICE-TOKEN-2']);
        }
        DB::table('sellers')->insert(['f_name' => 'CANARY-VENDOR-NAME', 'email' => 'vendor@leak.test', 'phone' => '0555555555', 'password' => 'x', 'cm_firebase_token' => 'CANARY-DEVICE-TOKEN-1']);
        DB::table('delivery_men')->insert(['f_name' => 'Courier', 'email' => 'courier@leak.test', 'phone' => '0566666666', 'password' => 'x', 'identity_number' => 'CANARY-COURIER-ID', 'fcm_token' => 'CANARY-DEVICE-TOKEN-1']);
        DB::table('shipping_addresses')->insert(['contact_person_name' => 'CANARY-CUSTOMER-NAME', 'phone' => '0599900111', 'address' => 'CANARY-STREET-9', 'city' => 'الرياض', 'zip' => '12345']);
        $this->makeOrder(100, ['order_note' => 'CANARY-NOTE-77', 'shipping_address_data' => json_encode(['contact_person_name' => 'CANARY-CUSTOMER-NAME', 'phone' => '0599900111', 'address' => 'CANARY-STREET-9', 'city' => 'الرياض', 'email' => 'canary.customer@leak.test'], JSON_UNESCAPED_UNICODE)]);
    }
}
