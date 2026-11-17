<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;

class LoginTest extends PlatformAdminTestCase
{
    private const LOGIN = self::PREFIX . '/auth/login';

    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.platform_admin.login_attempts_per_minute' => 1000]);
    }

    public function test_a_valid_sign_in_returns_a_token_once_and_keeps_only_its_hash(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();

        $response = $this->signIn($admin, deviceName: 'Pixel 9', headers: ['User-Agent' => 'LavkooSeller/1.0']);

        $response->assertOk()->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'me' => ['admin_id', 'name', 'level', 'scope', 'markets', 'token_expires_at']]]);
        $token = $response->json('data.token');
        $this->assertMatchesRegularExpression('/^lkpa_[A-Za-z0-9]{64}$/', $token);
        $this->assertSame('Bearer', $response->json('data.token_type'));
        $expiry = Carbon::parse('2026-11-17 10:00:00')->addMinutes(480)->utc()->toIso8601ZuluString();
        $this->assertSame($expiry, $response->json('data.expires_at'));
        $this->assertSame($expiry, $response->json('data.me.token_expires_at'));

        $row = DB::table('platform_admin_tokens')->first();
        $this->assertSame(1, DB::table('platform_admin_tokens')->count());
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame(hash('sha256', (string) DB::table('admins')->where('id', $admin['id'])->value('password')), $row->password_fingerprint);
        $this->assertSame([$admin['id'], $admin['grant_id'], 'Pixel 9', '127.0.0.1', 'LavkooSeller/1.0'], [(int) $row->admin_id, (int) $row->grant_id, $row->device_name, $row->ip, $row->user_agent]);
        $this->assertNull($row->last_used_at);
        $this->assertNull($row->revoked_at);
        $this->assertNoSecretIsStored($token, $admin['password']);
    }

    public function test_the_token_signs_the_admin_in_at_once(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->signIn($admin)->json('data.token');

        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk()->assertJsonPath('data.admin_id', $admin['id']);
    }

    public function test_a_sign_in_is_audited_without_the_password_or_the_token(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->signIn($admin, deviceName: 'Pixel 9', headers: ['User-Agent' => str_repeat('u', 400)])->json('data.token');

        $line = DB::table('platform_admin_audit_logs')->first();
        $this->assertSame(1, DB::table('platform_admin_audit_logs')->count());
        $this->assertSame('auth.login', $line->action);
        $this->assertSame($admin['id'], (int) $line->admin_id);
        $this->assertSame((int) DB::table('platform_admin_tokens')->value('id'), (int) $line->token_id);
        $this->assertNull($line->market);
        $this->assertSame('127.0.0.1', $line->ip);
        $this->assertSame(255, strlen($line->user_agent), 'the user agent is truncated');
        $this->assertSame(['device_name' => 'Pixel 9'], json_decode($line->context, true));
        $this->assertNoSecretIsStored($token, $admin['password']);
    }

    public function test_a_second_sign_in_makes_a_second_session_and_the_first_stays_valid(): void
    {
        $admin = $this->platformAdmin();
        $first = $this->signIn($admin, deviceName: 'Phone')->json('data.token');
        $second = $this->signIn($admin, deviceName: 'Tablet')->json('data.token');

        $this->assertNotSame($first, $second);
        $this->getJson(self::PREFIX . '/me', $this->bearer($first))->assertOk();
        $this->getJson(self::PREFIX . '/me', $this->bearer($second))->assertOk();
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function lifetimes(): array
    {
        return [
            'the default' => [480, 480],
            'a short one is kept' => [60, 60],
            'a day is the most' => [1440, 1440],
            'more than a day is cut to a day' => [100_000, 1440],
            'zero means the default' => [0, 480],
            'a negative number means the default' => [-5, 480],
        ];
    }

    #[DataProvider('lifetimes')]
    public function test_the_lifetime_of_a_token_is_clamped(int $configured, int $expectedMinutes): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        config(['gcc.platform_admin.token_ttl_minutes' => $configured]);

        $response = $this->signIn($this->platformAdmin());

        $this->assertSame(Carbon::parse('2026-11-17 10:00:00')->addMinutes($expectedMinutes)->utc()->toIso8601ZuluString(), $response->json('data.expires_at'));
        $this->assertSame(Carbon::parse('2026-11-17 10:00:00')->addMinutes($expectedMinutes)->format('Y-m-d H:i:s'), DB::table('platform_admin_tokens')->value('expires_at'));
    }

    public function test_every_failure_answers_the_same_and_does_the_same_work(): void
    {
        $hasher = $this->countingHasher();
        $active = $this->platformAdmin();
        $revoked = $this->platformAdmin(grantOverrides: ['revoked_at' => now()]);
        $inactive = $this->platformAdmin(adminOverrides: ['status' => 0]);
        $withoutGrant = $this->makeAdmin();
        $unknownPassword = Str::random(20);

        $causes = [
            'no such admin' => ['email' => 'nobody@example.test', 'password' => $unknownPassword, 'device_name' => 'Pixel'],
            'wrong password' => ['email' => $active['email'], 'password' => $unknownPassword, 'device_name' => 'Pixel'],
            'inactive admin' => ['email' => $inactive['email'], 'password' => $inactive['password'], 'device_name' => 'Pixel'],
            'admin without a grant' => ['email' => $withoutGrant['email'], 'password' => $withoutGrant['password'], 'device_name' => 'Pixel'],
            'revoked grant' => ['email' => $revoked['email'], 'password' => $revoked['password'], 'device_name' => 'Pixel'],
            'no password' => ['email' => $active['email'], 'device_name' => 'Pixel'],
            'no e-mail' => ['password' => $unknownPassword, 'device_name' => 'Pixel'],
            'no device name' => ['email' => $active['email'], 'password' => $active['password']],
            'a device name that is too long' => ['email' => $active['email'], 'password' => $active['password'], 'device_name' => str_repeat('d', 101)],
            'an array for the e-mail' => ['email' => [$active['email']], 'password' => $active['password'], 'device_name' => 'Pixel'],
            'an array for the password' => ['email' => $active['email'], 'password' => [$active['password']], 'device_name' => 'Pixel'],
            'a password that is far too long' => ['email' => $active['email'], 'password' => str_repeat('p', 5000), 'device_name' => 'Pixel'],
            'an e-mail that is far too long' => ['email' => str_repeat('e', 300) . '@example.test', 'password' => $unknownPassword, 'device_name' => 'Pixel'],
            'an empty body' => [],
        ];

        $bodies = [];
        $selects = [];
        foreach ($causes as $cause => $payload) {
            $hasher->checks = 0;
            $hasher->hashes = [];
            $statements = $this->statementsRunBy(function () use ($payload, &$response): void {
                $response = $this->postJson(self::LOGIN, $payload);
            });

            $response->assertStatus(401)->assertExactJson($this->uniformRefusal());
            $bodies[$cause] = $response->getContent();
            $selects[$cause] = count(array_filter($statements, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')));
            $this->assertSame(1, $hasher->checks, "{$cause}: exactly one password check, so that nothing tells an existing admin from one that does not exist");

            $info = password_get_info($hasher->hashes[0]);
            $this->assertSame(['bcrypt', (int) config('hashing.bcrypt.rounds')], [$info['algoName'], $info['options']['cost'] ?? null], "{$cause}: the check is a real one, against a hash of the cost every hash here has, not against nothing");
        }

        $this->assertCount(1, array_unique($bodies), 'one body for every cause');
        $this->assertCount(1, array_unique($selects), 'one number of reads for every cause: ' . json_encode($selects));
    }

    public function test_a_failure_with_a_grant_is_audited_with_its_reason_and_one_without_is_only_logged(): void
    {
        $log = $this->captureLog();
        $active = $this->platformAdmin();
        $revoked = $this->platformAdmin(grantOverrides: ['revoked_at' => now()]);
        $inactive = $this->platformAdmin(adminOverrides: ['status' => 0]);
        $withoutGrant = $this->makeAdmin();

        $this->signIn(['email' => $active['email'], 'password' => 'not the password']);
        $this->signIn($revoked);
        $this->signIn($inactive);
        $this->signIn($withoutGrant);
        $this->signIn(['email' => 'nobody@example.test', 'password' => 'whatever']);

        $lines = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame(['auth.login_failed', 'auth.login_failed', 'auth.login_failed'], $lines->pluck('action')->all());
        $this->assertSame([$active['id'], $revoked['id'], $inactive['id']], $lines->pluck('admin_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['wrong_password', 'revoked_grant', 'inactive_admin'], $lines->map(fn ($line) => json_decode($line->context, true)['reason'])->all());
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());

        $reasons = array_map(static fn (LogRecord $record): string => $record->context['reason'] ?? '', $log->getRecords());
        $this->assertSame(['wrong_password', 'revoked_grant', 'inactive_admin', 'no_grant', 'unknown_admin'], array_values(array_filter($reasons)), 'every failure reaches the log, with its reason');
    }

    public function test_unauthenticated_traffic_cannot_make_any_table_grow(): void
    {
        $before = $this->ownRowCounts();

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $this->postJson(self::LOGIN, ['email' => "nobody{$attempt}@example.test", 'password' => 'x', 'device_name' => 'Pixel'])->assertStatus(401);
            $this->postJson(self::LOGIN, [])->assertStatus(401);
            $this->getJson(self::PREFIX . '/me', $this->bearer('lkpa_' . Str::random(64)))->assertStatus(401);
        }

        $this->assertSame($before, $this->ownRowCounts());
    }

    public function test_failed_sign_ins_of_a_real_grant_are_audited_only_a_few_times_an_hour(): void
    {
        config(['gcc.platform_admin.failed_login_audit_per_hour' => 3]);
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->signIn(['email' => $admin['email'], 'password' => 'wrong']);
        }
        $this->signIn(['email' => $other['email'], 'password' => 'wrong']);

        $this->assertSame(3, DB::table('platform_admin_audit_logs')->where('admin_id', $admin['id'])->count(), 'a grant gets three lines an hour, however many sign-ins fail');
        $this->assertSame(1, DB::table('platform_admin_audit_logs')->where('admin_id', $other['id'])->count(), 'and the limit is per grant');

        $this->travelTo('2026-11-17 11:00:01');
        $this->signIn(['email' => $admin['email'], 'password' => 'wrong']);
        $this->assertSame(4, DB::table('platform_admin_audit_logs')->where('admin_id', $admin['id'])->count());
    }

    public function test_the_failure_is_audited_after_the_answer_has_been_given(): void
    {
        $admin = $this->platformAdmin();
        $kernel = $this->app->make(HttpKernel::class);
        $request = Request::create(self::LOGIN, 'POST', ['email' => $admin['email'], 'password' => 'wrong', 'device_name' => 'Pixel'], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $kernel->handle($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count(), 'nothing is written while the answer is made, so a failure costs the caller the same whoever they claim to be');

        $kernel->terminate($request, $response);

        $this->assertSame(1, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_neither_the_log_nor_the_audit_trail_ever_holds_a_password_a_token_or_a_whole_address(): void
    {
        $log = $this->captureLog();
        $admin = $this->platformAdmin();
        $other = $this->makeAdmin();
        $wrongPassword = 'wrong-' . Str::random(16);

        $this->signIn(['email' => $admin['email'], 'password' => $wrongPassword]);
        $this->signIn(['email' => 'nobody@example.test', 'password' => $wrongPassword]);
        $this->signIn($other);
        $token = $this->signIn($admin)->json('data.token');
        $this->getJson(self::PREFIX . '/me', $this->bearer($token));

        $written = json_encode(array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $log->getRecords()));
        $written .= json_encode(DB::table('platform_admin_audit_logs')->get());

        foreach ([$wrongPassword, $admin['password'], $other['password'], $token, hash('sha256', $token), $admin['email'], $other['email'], 'nobody@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
        $this->assertNotEmpty($log->getRecords(), 'the check must have something to read');
    }

    public function test_the_sixth_sign_in_of_a_minute_from_one_address_with_one_e_mail_is_throttled(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 5]);
        $admin = $this->platformAdmin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        }
        $response = $this->signIn($admin);

        $response->assertStatus(429)->assertExactJson(['errors' => [['code' => 'platform_admin_throttled', 'message' => 'Too many attempts. Try again later.']]]);
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame(0, DB::table('platform_admin_tokens')->count(), 'the right password does not get through while it is throttled');
    }

    public function test_one_address_cannot_try_many_e_mails_but_may_try_more_than_one_e_mail_alone_could(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 2]);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->signIn(['email' => "nobody{$attempt}@example.test", 'password' => 'wrong'])->assertStatus(401);
        }

        $this->signIn(['email' => 'another@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_an_office_behind_one_address_is_not_locked_out_by_the_typos_of_one_person(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 3]);
        $typist = $this->platformAdmin();
        $colleague = $this->platformAdmin();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->signIn(['email' => $typist['email'], 'password' => 'wrong'])->assertStatus(401);
        }

        $this->signIn($typist)->assertStatus(429);
        $this->signIn($colleague)->assertOk();
    }

    public function test_a_sign_in_that_succeeds_starts_the_count_of_that_e_mail_from_that_address_again(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 3]);
        $admin = $this->platformAdmin();

        $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        $this->signIn($admin)->assertOk();

        $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        $this->signIn($admin)->assertOk();
    }

    public function test_the_throttle_counts_an_address_and_an_e_mail_together_and_leaves_other_addresses_alone(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 3]);
        $admin = $this->platformAdmin();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        }

        $this->signIn($admin)->assertStatus(429);
        $this->signIn($admin, server: ['REMOTE_ADDR' => '10.20.30.40'])->assertOk();
    }

    public function test_the_limit_is_the_configured_one(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 2]);
        $admin = $this->platformAdmin();

        $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);

        $this->signIn($admin)->assertStatus(429);
    }

    public function test_a_throttled_request_reads_nothing_and_checks_no_password(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 1]);
        $hasher = $this->countingHasher();
        $this->signIn(['email' => 'nobody@example.test', 'password' => 'wrong']);
        $hasher->checks = 0;

        $statements = $this->statementsRunBy(fn () => $this->signIn(['email' => 'nobody@example.test', 'password' => 'wrong'])->assertStatus(429));

        $selectsOfTheGate = array_filter($statements, static fn (string $sql): bool => !str_contains($sql, 'sqlite_master') && !str_contains($sql, 'information_schema'));
        $this->assertSame([], array_values($selectsOfTheGate));
        $this->assertSame(0, $hasher->checks);
    }

    public function test_the_throttle_window_ends_after_a_minute(): void
    {
        config(['gcc.platform_admin.login_attempts_per_minute' => 1]);
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();

        $this->signIn(['email' => $admin['email'], 'password' => 'wrong'])->assertStatus(401);
        $this->signIn($admin)->assertStatus(429);

        $this->travelTo('2026-11-17 10:01:01');
        $this->signIn($admin)->assertOk();
    }

    public function test_a_sign_in_removes_the_sessions_of_that_admin_that_ended_over_thirty_days_ago_and_no_others(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();
        $long = $this->mintToken($admin, ['expires_at' => now()->subDays(31)]);
        $recent = $this->mintToken($admin, ['expires_at' => now()->subDays(29)]);
        $others = $this->mintToken($other, ['expires_at' => now()->subDays(40)]);

        $this->signIn($admin)->assertOk();

        $this->assertNull(DB::table('platform_admin_tokens')->where('token_hash', hash('sha256', $long))->first());
        $this->assertNotNull(DB::table('platform_admin_tokens')->where('token_hash', hash('sha256', $recent))->first());
        $this->assertNotNull(DB::table('platform_admin_tokens')->where('token_hash', hash('sha256', $others))->first(), "another admin's rows are not this sign-in's business");
    }

    public function test_a_body_that_is_not_json_is_the_same_refusal(): void
    {
        $this->call('POST', self::LOGIN, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{not json')
            ->assertStatus(401)
            ->assertExactJson($this->uniformRefusal());
    }

    public function test_a_form_post_signs_in_as_well(): void
    {
        $admin = $this->platformAdmin();

        $this->post(self::LOGIN, ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Browser'], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_a_bearer_header_on_the_sign_in_request_changes_nothing(): void
    {
        $admin = $this->platformAdmin();
        $stranger = $this->platformAdmin();
        $strangerToken = $this->mintToken($stranger);

        $response = $this->postJson(self::LOGIN, ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Pixel'], $this->bearer($strangerToken));

        $response->assertOk()->assertJsonPath('data.me.admin_id', $admin['id']);
    }

    public function test_the_sign_in_never_starts_a_session_or_sets_a_cookie_and_leaves_the_admin_guard_signed_out(): void
    {
        $admin = $this->platformAdmin();

        $response = $this->signIn($admin);

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies(), 'no cookie: the sign-in starts no session');
        $this->assertGuest('admin');
    }

    public function test_the_response_carries_no_hash_no_fingerprint_and_no_address(): void
    {
        $admin = $this->platformAdmin();

        $content = $this->signIn($admin)->getContent();

        foreach (['token_hash', 'password_fingerprint', 'password', $admin['email'], hash('sha256', (string) DB::table('admins')->where('id', $admin['id'])->value('password'))] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    /**
     * @param array<string, mixed> $credentials an admin made by platformAdmin(), or the fields to send
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function signIn(array $credentials, string $deviceName = 'Test phone', array $headers = [], array $server = []): TestResponse
    {
        $payload = ['email' => $credentials['email'], 'password' => $credentials['password']] + ['device_name' => $deviceName];

        return $this->withServerVariables($server)->postJson(self::LOGIN, $payload, $headers);
    }

    private function assertNoSecretIsStored(string $token, string $password): void
    {
        $stored = '';
        foreach (['platform_admin_tokens', 'platform_admin_audit_logs', 'platform_admin_grants'] as $table) {
            $stored .= json_encode(DB::table($table)->get());
        }

        $this->assertStringNotContainsString($token, $stored, 'the plain token is not stored');
        $this->assertStringNotContainsString($password, $stored, 'the password is not stored');
    }

    /** @return array<string, int> */
    private function ownRowCounts(): array
    {
        return [
            'tokens' => DB::table('platform_admin_tokens')->count(),
            'grants' => DB::table('platform_admin_grants')->count(),
            'audit' => DB::table('platform_admin_audit_logs')->count(),
        ];
    }

    private function countingHasher(): object
    {
        $hasher = new class($this->app->make(Hasher::class)) implements Hasher {
            public int $checks = 0;

            /** @var list<string> the hashes it was asked to check against */
            public array $hashes = [];

            public function __construct(private readonly Hasher $inner)
            {
            }

            public function info($hashedValue): array
            {
                return $this->inner->info($hashedValue);
            }

            public function make($value, array $options = []): string
            {
                return $this->inner->make($value, $options);
            }

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->checks++;
                $this->hashes[] = (string) $hashedValue;

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->inner->needsRehash($hashedValue, $options);
            }
        };
        $this->app->instance(Hasher::class, $hasher);

        return $hasher;
    }
}
