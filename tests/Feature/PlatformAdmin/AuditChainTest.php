<?php

namespace Tests\Feature\PlatformAdmin;

use App\Exceptions\PlatformAdminAuditChainException;
use App\Models\PlatformAdminAuditLog;
use App\Services\PlatformAdmin\PlatformAdminAudit;
use App\Services\PlatformAdmin\PlatformAdminAuditChain;
use RuntimeException;
use Illuminate\Support\Facades\DB;

/**
 * Every line the writer writes is chained to the one before it and moves the tail, in one transaction that holds the row of the tail, and a line that is
 * not written leaves no trace in either.
 */
class AuditChainTest extends PlatformAdminTestCase
{
    public function test_the_first_line_follows_the_starting_value_and_moves_the_tail(): void
    {
        $this->travelTo('2026-11-17 10:00:00');

        $line = $this->record(action: 'grant.created', adminId: 7);

        $row = $this->row($line->getKey());
        $this->assertSame(PlatformAdminAuditChain::GENESIS, $row->prev_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->hash);
        $this->assertSame([(int) $line->getKey(), $row->hash], $this->tail());
    }

    public function test_every_line_follows_the_hash_of_the_one_before_and_the_tail_follows_the_newest(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $ids = [];

        $times = [];
        foreach (range(1, 12) as $number) {
            $this->travelTo(now()->addSeconds($number));
            $times[] = now()->format('Y-m-d H:i:s');
            $ids[] = (int) $this->record(action: "step.{$number}", adminId: $number, context: ['number' => $number])->getKey();
        }

        $rows = DB::table('platform_admin_audit_logs')->orderBy('id')->get();
        $this->assertSame($ids, $rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($times, $rows->pluck('created_at')->all(), 'every line is stamped with the second it was written');
        $previous = PlatformAdminAuditChain::GENESIS;
        foreach ($rows as $row) {
            $this->assertSame($previous, $row->prev_hash, "line {$row->id} follows the line before it");
            $previous = $row->hash;
        }
        $this->assertSame([$ids[11], $rows->last()->hash], $this->tail());
    }

    public function test_the_hash_of_a_line_is_that_of_its_content_as_stored(): void
    {
        $this->travelTo('2026-11-17 10:00:00');
        $this->record(action: 'overview.view', adminId: 7, market: 'KW', tokenId: 12, ip: '203.0.113.9', userAgent: 'LavkooSeller/1.0', context: ['status' => 200, 'params' => ['market' => 'KW']]);

        $row = DB::table('platform_admin_audit_logs')->first();

        $this->assertSame('2026-11-17 10:00:00', $row->created_at);
        $this->assertSame(PlatformAdminAuditChain::hashOf(
            adminId: 7, action: 'overview.view', market: 'KW', tokenId: 12, ip: '203.0.113.9', userAgent: 'LavkooSeller/1.0',
            context: ['status' => 200, 'params' => ['market' => 'KW']], createdAt: '2026-11-17 10:00:00', previousHash: PlatformAdminAuditChain::GENESIS,
        ), $row->hash);
        $this->assertSame($row->hash, PlatformAdminAuditChain::hashOfRow(row: $row));
    }

    public function test_the_hash_is_of_the_user_agent_as_it_is_stored_which_is_clipped_and_scrubbed(): void
    {
        $this->record(action: 'auth.login', adminId: 7, userAgent: str_repeat('é', 300) . "\xFF");

        $row = DB::table('platform_admin_audit_logs')->first();

        $this->assertSame(255, mb_strlen($row->user_agent));
        $this->assertSame($row->hash, PlatformAdminAuditChain::hashOfRow(row: $row));
    }

    public function test_a_line_that_is_written_inside_a_transaction_that_rolls_back_leaves_no_line_and_does_not_move_the_tail(): void
    {
        $this->record(action: 'kept', adminId: 7);
        $before = [DB::table('platform_admin_audit_logs')->count(), $this->tail()];

        try {
            DB::transaction(function (): void {
                $this->record(action: 'rolled.back', adminId: 7);

                throw new RuntimeException('the work that was audited failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($before, [DB::table('platform_admin_audit_logs')->count(), $this->tail()]);
        $line = $this->record(action: 'next', adminId: 7);
        $this->assertSame($before[1][1], $this->row($line->getKey())->prev_hash, 'the next line follows the one that was kept');
    }

    public function test_a_line_whose_insert_fails_does_not_move_the_tail(): void
    {
        $this->record(action: 'kept', adminId: 7);
        $before = $this->tail();
        PlatformAdminAuditLog::creating(static function (): never {
            throw new RuntimeException('the insert failed');
        });

        try {
            $this->record(action: 'never.written', adminId: 7);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $failure) {
            $this->assertSame('the insert failed', $failure->getMessage());
        }

        $this->assertSame($before, $this->tail());
        $this->assertSame(1, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_a_trail_with_no_tail_row_refuses_to_take_a_line_and_writes_nothing(): void
    {
        DB::table('platform_admin_audit_tail')->delete();

        try {
            $this->record(action: 'grant.created', adminId: 7);
            $this->fail('a line must not be written without the tail');
        } catch (PlatformAdminAuditChainException $failure) {
            $this->assertStringContainsString('tail', $failure->getMessage());
        }

        $this->assertSame(0, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_the_hash_does_not_depend_on_the_time_zone_of_the_process(): void
    {
        $this->travelTo('2026-11-17 23:30:00');
        $this->record(action: 'one', adminId: 7);
        $zone = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Kiritimati');
            config(['app.timezone' => 'Pacific/Kiritimati']);
            $row = DB::table('platform_admin_audit_logs')->first();

            $this->assertSame($row->hash, PlatformAdminAuditChain::hashOfRow(row: $row));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_a_context_that_comes_back_from_the_engine_with_its_keys_in_another_order_is_still_the_line_that_was_written(): void
    {
        $this->record(action: 'grant.updated', adminId: 7, context: ['source' => 'console', 'before' => ['level' => 1, 'countries' => null], 'after' => ['level' => 1, 'countries' => ['KW']]]);
        DB::table('platform_admin_audit_logs')->update(['context' => '{"after":{"countries":["KW"],"level":1},"before":{"countries":null,"level":1},"source":"console"}']);

        $row = DB::table('platform_admin_audit_logs')->first();

        $this->assertSame($row->hash, PlatformAdminAuditChain::hashOfRow(row: $row));
    }

    public function test_a_write_takes_the_row_of_the_tail_for_update_so_that_two_writers_take_turns(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no row locks: the statement that takes one can be seen on MySQL and MariaDB only.');
        }

        $statements = $this->statementsRunBy(fn () => $this->record(action: 'grant.created', adminId: 7));

        $holdingTheTail = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'platform_admin_audit_tail') && str_ends_with(rtrim($sql), 'for update'));
        $this->assertCount(1, $holdingTheTail);
        $position = array_key_first($holdingTheTail);
        $insert = array_key_first(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'insert into') && str_contains($sql, 'platform_admin_audit_logs')));
        $this->assertLessThan($insert, $position, 'the tail is held before the line is read from it and written');
    }

    public function test_the_lines_of_requests_and_of_the_console_are_one_chain(): void
    {
        $admin = $this->platformAdmin();
        $this->artisan('gcc:platform-admin:revoke', ['admin_id' => $admin['id']])->assertExitCode(0);
        $this->artisan('gcc:platform-admin:grant', ['admin_id' => $admin['id'], '--confirm-owner' => true])->assertExitCode(0);
        $token = $this->postJson(self::PREFIX . '/auth/login', ['email' => $admin['email'], 'password' => $admin['password'], 'device_name' => 'Pixel'])->assertOk()->json('data.token');
        $this->getJson(self::PREFIX . '/me', $this->bearer($token))->assertOk();
        $this->getJson(self::PREFIX . '/overview', $this->bearer($token))->assertOk();

        $rows = DB::table('platform_admin_audit_logs')->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(5, $rows->count());
        $previous = PlatformAdminAuditChain::GENESIS;
        foreach ($rows as $row) {
            $this->assertSame($previous, $row->prev_hash, "{$row->action} follows the line before it");
            $this->assertSame($row->hash, PlatformAdminAuditChain::hashOfRow(row: $row), "{$row->action} is hashed as it is stored");
            $previous = $row->hash;
        }
        $this->assertSame([(int) $rows->last()->id, $rows->last()->hash], $this->tail());
    }

    /** @param array<string, mixed> $context */
    private function record(string $action, ?int $adminId = null, ?string $market = null, ?int $tokenId = null, ?string $ip = null, ?string $userAgent = null, array $context = []): PlatformAdminAuditLog
    {
        return app(PlatformAdminAudit::class)->record(action: $action, adminId: $adminId, market: $market, tokenId: $tokenId, ip: $ip, userAgent: $userAgent, context: $context);
    }

    private function row(int|string $id): object
    {
        return DB::table('platform_admin_audit_logs')->where('id', $id)->first();
    }

    /** @return array{0: ?int, 1: ?string} */
    private function tail(): array
    {
        $tail = DB::table('platform_admin_audit_tail')->first();

        return [$tail->last_line_id === null ? null : (int) $tail->last_line_id, $tail->last_hash];
    }
}
