<?php

namespace Tests\Feature\PlatformAdmin;

use App\Console\ScheduleDefinition;
use App\Exceptions\PlatformAdminAuditChainException;
use App\Models\PlatformAdminAuditLog;
use App\Services\PlatformAdmin\ConsoleOperator;
use App\Services\PlatformAdmin\PlatformAdminAudit;
use App\Services\PlatformAdmin\PlatformAdminAuditChain;
use App\Services\PlatformAdmin\PlatformAdminAuditPruner;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * `gcc:platform-admin:audit:prune` is the only way a line leaves the trail. It needs the days to keep and `--confirm`, refuses a trail that does not verify,
 * cuts the run of old lines from the start and never the newest, leaves a checkpoint that the chain continues from, and says so in the chain.
 */
class AuditPruneCommandTest extends PlatformAdminTestCase
{
    private const NOW = '2026-11-17 12:00:00';

    /** @var list<int> the ids of the lines written by build(), in the order they were given */
    private array $ids = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_needs_the_days_and_refuses_anything_that_is_not_a_whole_number_of_at_least_one(): void
    {
        $this->build(daysAgo: [100, 50, 1]);

        foreach ([null, '0', '-3', 'abc', '1.5', '', ' 30'] as $days) {
            [$exit, $output] = $this->prune($days === null ? [] : ['--days' => $days, '--confirm' => true]);

            $this->assertSame(1, $exit, var_export($days, true));
            $this->assertStringContainsString('--days', $output);
        }
        $this->assertSame(3, DB::table('platform_admin_audit_logs')->count());
        $this->assertSame(0, DB::table('platform_admin_audit_checkpoints')->count());
    }

    public function test_without_confirm_it_says_what_it_would_remove_and_changes_nothing(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $before = [json_encode(DB::table('platform_admin_audit_logs')->get()), json_encode(DB::table('platform_admin_audit_tail')->get())];

        [$exit, $output] = $this->prune(['--days' => 60]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Would remove 3 lines', $output);
        $this->assertStringContainsString("{$this->ids[0]} to {$this->ids[2]}", $output);
        $this->assertStringContainsString('--confirm', $output);
        $this->assertSame($before, [json_encode(DB::table('platform_admin_audit_logs')->get()), json_encode(DB::table('platform_admin_audit_tail')->get())]);
        $this->assertSame(0, DB::table('platform_admin_audit_checkpoints')->count());
    }

    public function test_with_confirm_it_removes_the_old_lines_from_the_start_and_keeps_the_rest_and_its_own_line(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $lastRemoved = DB::table('platform_admin_audit_logs')->where('id', $this->ids[2])->first();

        [$exit, $output] = $this->prune(['--days' => 60, '--confirm' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Removed 3 lines', $output);
        $remaining = DB::table('platform_admin_audit_logs')->orderBy('id')->pluck('action', 'id')->all();
        $this->assertSame(['step.4', 'step.5', 'step.6', 'step.7', 'audit.pruned'], array_values($remaining));
        $this->assertSame([$this->ids[3], $this->ids[4], $this->ids[5], $this->ids[6]], array_slice(array_keys($remaining), 0, 4));
        $checkpoint = DB::table('platform_admin_audit_checkpoints')->first();
        $this->assertSame([$this->ids[2], $lastRemoved->hash, 3, 60], [(int) $checkpoint->last_line_id, $checkpoint->last_hash, (int) $checkpoint->lines_removed, (int) $checkpoint->days]);
        $this->assertSame(self::NOW, $checkpoint->pruned_at);
        $operator = ConsoleOperator::describe();
        $this->assertSame($operator['user'] . '@' . $operator['host'], $checkpoint->operator);
        $this->assertStringContainsString($lastRemoved->hash, $output);
    }

    public function test_the_line_it_writes_is_chained_and_says_what_was_cut_and_who_did_it(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $newestBefore = DB::table('platform_admin_audit_logs')->where('id', $this->ids[6])->first();
        $lastRemoved = DB::table('platform_admin_audit_logs')->where('id', $this->ids[2])->first();

        $this->prune(['--days' => 60, '--confirm' => true]);

        $line = DB::table('platform_admin_audit_logs')->where('action', 'audit.pruned')->first();
        $this->assertSame($newestBefore->hash, $line->prev_hash);
        $this->assertSame($line->hash, PlatformAdminAuditChain::hashOfRow(row: $line));
        $this->assertSame($this->sortedByKey([
            'source' => 'console', 'operator' => ConsoleOperator::describe(), 'days' => 60, 'lines_removed' => 3, 'last_removed_id' => $this->ids[2], 'last_removed_hash' => $lastRemoved->hash,
        ]), $this->contextOf($line));
        $this->assertNull($line->admin_id);
        $tail = DB::table('platform_admin_audit_tail')->first();
        $this->assertSame([(int) $line->id, $line->hash], [(int) $tail->last_line_id, $tail->last_hash]);
    }

    public function test_the_trail_verifies_after_a_prune_and_the_first_line_left_follows_the_checkpoint(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $this->prune(['--days' => 60, '--confirm' => true]);

        $checkpoint = DB::table('platform_admin_audit_checkpoints')->first();
        $first = DB::table('platform_admin_audit_logs')->orderBy('id')->first();
        [$exit, $output] = $this->verify();

        $this->assertSame($checkpoint->last_hash, $first->prev_hash);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('checkpoint', $output);
    }

    public function test_a_line_that_the_checkpoint_says_was_removed_but_is_still_there_is_found_the_last_one_removed_included(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $removed = [
            'an earlier one' => (array) DB::table('platform_admin_audit_logs')->where('id', $this->ids[1])->first(),
            'the last one' => (array) DB::table('platform_admin_audit_logs')->where('id', $this->ids[2])->first(),
        ];
        $this->prune(['--days' => 60, '--confirm' => true]);

        foreach ($removed as $which => $line) {
            DB::table('platform_admin_audit_logs')->insert($line);

            [$exit, $output] = $this->verify();

            $this->assertSame(1, $exit, $which);
            $this->assertStringContainsString("broken at line {$line['id']}", $output, $which);
            $this->assertStringContainsString('checkpoint', $output, $which);
            DB::table('platform_admin_audit_logs')->where('id', $line['id'])->delete();
        }
    }

    public function test_an_unhashed_line_right_after_a_checkpoint_that_holds_a_hash_is_not_one_from_before_the_chain(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $this->prune(['--days' => 60, '--confirm' => true]);
        DB::table('platform_admin_audit_logs')->delete();
        DB::table('platform_admin_audit_logs')->insert(['id' => $this->ids[2] + 1, 'action' => 'slipped.in', 'created_at' => '2026-11-16 10:00:00']);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('broken at line ' . ($this->ids[2] + 1), $output);
        $this->assertStringContainsString('no hash', $output);
    }

    public function test_a_checkpoint_that_was_changed_breaks_the_line_that_follows_it(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $this->prune(['--days' => 60, '--confirm' => true]);
        DB::table('platform_admin_audit_checkpoints')->update(['last_hash' => str_repeat('e', 64)]);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[3]}", $output);
    }

    public function test_the_cut_is_the_run_from_the_start_that_is_all_older_so_a_newer_line_ends_it_even_when_a_later_one_is_old(): void
    {
        $this->build(daysAgo: [100, 5, 100, 3]);

        $this->prune(['--days' => 60, '--confirm' => true]);

        $this->assertSame([$this->ids[1], $this->ids[2], $this->ids[3]], DB::table('platform_admin_audit_logs')->where('action', '!=', 'audit.pruned')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, $this->verify()[0]);
    }

    public function test_a_line_exactly_as_old_as_the_days_is_kept_and_one_a_second_older_is_not(): void
    {
        $this->buildAt(moments: [
            Carbon::parse(self::NOW)->subDays(60)->subSecond(),
            Carbon::parse(self::NOW)->subDays(60),
            Carbon::parse(self::NOW)->subDay(),
        ]);

        $this->prune(['--days' => 60, '--confirm' => true]);

        $this->assertSame([$this->ids[1], $this->ids[2]], DB::table('platform_admin_audit_logs')->where('action', '!=', 'audit.pruned')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_newest_line_is_never_cut_even_when_every_line_is_older_than_the_days(): void
    {
        $this->build(daysAgo: [100, 90, 80]);

        [$exit, $output] = $this->prune(['--days' => 10, '--confirm' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Removed 2 lines', $output);
        $this->assertSame([$this->ids[2]], DB::table('platform_admin_audit_logs')->where('action', '!=', 'audit.pruned')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, $this->verify()[0]);
    }

    public function test_when_no_line_is_old_enough_or_only_one_line_exists_nothing_is_done(): void
    {
        $this->build(daysAgo: [100, 90, 80]);
        [$exit, $output] = $this->prune(['--days' => 500, '--confirm' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing to remove', $output);
        $this->assertSame(3, DB::table('platform_admin_audit_logs')->count());
        $this->assertSame(0, DB::table('platform_admin_audit_checkpoints')->count());

        DB::table('platform_admin_audit_logs')->where('id', '>', $this->ids[0])->delete();
        DB::table('platform_admin_audit_tail')->update(['last_line_id' => $this->ids[0], 'last_hash' => DB::table('platform_admin_audit_logs')->value('hash')]);
        [$exit, $output] = $this->prune(['--days' => 10, '--confirm' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing to remove', $output);
        $this->assertSame(1, DB::table('platform_admin_audit_logs')->count());
    }

    public function test_a_trail_that_does_not_verify_is_not_cut_and_the_break_is_named(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[4])->update(['ip' => '198.51.100.9']);
        $before = json_encode(DB::table('platform_admin_audit_logs')->get());

        [$exit, $output] = $this->prune(['--days' => 60, '--confirm' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not verify', $output);
        $this->assertStringContainsString("line {$this->ids[4]}", $output);
        $this->assertSame($before, json_encode(DB::table('platform_admin_audit_logs')->get()));
        $this->assertSame(0, DB::table('platform_admin_audit_checkpoints')->count());
    }

    public function test_a_dry_run_on_a_trail_that_does_not_verify_says_so_too(): void
    {
        $this->build(daysAgo: [100, 90, 5]);
        DB::table('platform_admin_audit_tail')->delete();

        [$exit, $output] = $this->prune(['--days' => 60]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not verify', $output);
    }

    public function test_a_second_prune_adds_a_checkpoint_and_the_trail_verifies_from_the_newest(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $this->prune(['--days' => 60, '--confirm' => true]);
        Carbon::setTestNow(Carbon::parse(self::NOW)->addDays(31));

        [$exit] = $this->prune(['--days' => 45, '--confirm' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(2, DB::table('platform_admin_audit_checkpoints')->count());
        $newest = DB::table('platform_admin_audit_checkpoints')->orderByDesc('id')->first();
        $this->assertSame($this->ids[4], (int) $newest->last_line_id);
        $this->assertSame(0, $this->verify()[0]);
    }

    public function test_lines_from_before_the_chain_are_cut_with_the_rest_and_the_first_hashed_line_follows_the_starting_value(): void
    {
        DB::table('platform_admin_audit_logs')->insert([
            ['action' => 'old.one', 'created_at' => Carbon::parse(self::NOW)->subDays(300)->format('Y-m-d H:i:s')],
            ['action' => 'old.two', 'created_at' => Carbon::parse(self::NOW)->subDays(299)->format('Y-m-d H:i:s')],
        ]);
        $this->build(daysAgo: [5, 1]);

        [$exit, $output] = $this->prune(['--days' => 60, '--confirm' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Removed 2 lines', $output);
        $this->assertSame(PlatformAdminAuditChain::GENESIS, DB::table('platform_admin_audit_checkpoints')->value('last_hash'));
        $this->assertSame(0, $this->verify()[0]);
    }

    public function test_what_is_removed_the_checkpoint_and_the_line_of_the_prune_are_all_or_nothing(): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        PlatformAdminAuditLog::creating(static function (PlatformAdminAuditLog $line): void {
            if ($line->action === 'audit.pruned') {
                throw new RuntimeException('the line of the prune could not be written');
            }
        });
        $before = [json_encode(DB::table('platform_admin_audit_logs')->get()), json_encode(DB::table('platform_admin_audit_tail')->get())];

        try {
            $this->prune(['--days' => 60, '--confirm' => true]);
            $this->fail('the failure must reach the operator');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('could not be written', $failure->getMessage());
        }

        $this->assertSame($before, [json_encode(DB::table('platform_admin_audit_logs')->get()), json_encode(DB::table('platform_admin_audit_tail')->get())]);
        $this->assertSame(0, DB::table('platform_admin_audit_checkpoints')->count());
    }

    /** @return array<string, array{string, string}> */
    public static function changesAfterThePlan(): array
    {
        return [
            'the line after the run follows something else' => ['next link', 'changed'],
            'a line of the run was removed' => ['run line removed', 'changed'],
            'the last line of the run has another hash' => ['last hash', 'changed'],
            'the last line of the run is gone' => ['last line gone', 'changed'],
            'the tail is gone' => ['tail gone', 'tail'],
        ];
    }

    #[DataProvider('changesAfterThePlan')]
    public function test_a_plan_made_before_the_trail_changed_is_not_carried_out(string $change, string $message): void
    {
        $this->build(daysAgo: [100, 90, 80, 40, 30, 5, 1]);
        $pruner = app(PlatformAdminAuditPruner::class);
        $plan = $pruner->planFor(days: 60);
        $this->assertNotNull($plan);
        match ($change) {
            'next link' => DB::table('platform_admin_audit_logs')->where('id', $this->ids[3])->update(['prev_hash' => str_repeat('f', 64)]),
            'run line removed' => DB::table('platform_admin_audit_logs')->where('id', $this->ids[1])->delete(),
            'last hash' => DB::table('platform_admin_audit_logs')->where('id', $this->ids[2])->update(['hash' => str_repeat('f', 64)]),
            'last line gone' => DB::table('platform_admin_audit_logs')->where('id', $this->ids[2])->delete(),
            'tail gone' => DB::table('platform_admin_audit_tail')->delete(),
        };
        $remaining = DB::table('platform_admin_audit_logs')->count();

        try {
            $pruner->prune(plan: $plan, days: 60);
            $this->fail('a plan for a trail that is no longer the same must not be carried out');
        } catch (PlatformAdminAuditChainException $refusal) {
            $this->assertStringContainsString($message, $refusal->getMessage());
        }

        $this->assertSame($remaining, DB::table('platform_admin_audit_logs')->count());
        $this->assertSame(0, DB::table('platform_admin_audit_checkpoints')->count());
    }

    public function test_the_prune_removes_lines_by_the_table_and_not_through_the_model(): void
    {
        $this->build(daysAgo: [100, 90, 5]);
        $statements = $this->statementsRunBy(fn () => $this->prune(['--days' => 60, '--confirm' => true]));

        $deletes = array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'delete from') && str_contains($sql, 'platform_admin_audit_logs'));
        $this->assertCount(1, $deletes, 'one statement removes the whole run');
    }

    public function test_the_prune_holds_the_tail_before_it_checks_the_plan_and_removes_the_lines_so_that_no_writer_adds_a_line_in_between(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no row locks: the statement that takes one can be seen on MySQL and MariaDB only.');
        }

        $this->build(daysAgo: [100, 90, 5]);

        $statements = $this->statementsRunBy(fn () => $this->prune(['--days' => 60, '--confirm' => true]));

        $holdingTheTail = array_keys(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'platform_admin_audit_tail') && str_ends_with(rtrim($sql), 'for update')));
        $removal = array_keys(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'delete from') && str_contains($sql, 'platform_admin_audit_logs')));
        $this->assertNotEmpty($holdingTheTail);
        $this->assertNotEmpty($removal);
        $this->assertLessThan($removal[0], $holdingTheTail[0]);
    }

    public function test_nothing_schedules_it_or_any_command_of_the_platform_admin_api(): void
    {
        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);
        $commands = collect($schedule->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

        $this->assertStringNotContainsString('platform-admin', $commands);
        $this->assertStringNotContainsString('audit', $commands);

        Artisan::call('schedule:list');
        $this->assertStringNotContainsString('platform-admin', Artisan::output());
    }

    public function test_it_works_with_the_api_switched_off_and_says_what_to_do_when_the_migrations_have_not_run(): void
    {
        config(['gcc.platform_admin.enabled' => false]);
        $this->build(daysAgo: [100, 5]);
        $this->assertSame(0, $this->prune(['--days' => 60])[0]);

        Schema::dropIfExists('platform_admin_audit_checkpoints');
        [$exit, $output] = $this->prune(['--days' => 60, '--confirm' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Run the migrations first', $output);
    }

    /** @return array{0: int, 1: string} */
    private function prune(array $options): array
    {
        $exit = Artisan::call('gcc:platform-admin:audit:prune', $options);

        return [$exit, Artisan::output()];
    }

    /** @return array{0: int, 1: string} */
    private function verify(): array
    {
        $exit = Artisan::call('gcc:platform-admin:audit:verify');

        return [$exit, Artisan::output()];
    }

    /**
     * Writes one line for each of the given ages, in the order given, each stamped that many days before the moment of the test.
     *
     * @param list<int> $daysAgo
     */
    private function build(array $daysAgo): void
    {
        $this->buildAt(moments: array_map(static fn (int $days): Carbon => Carbon::parse(self::NOW)->subDays($days), $daysAgo));
    }

    /** @param list<Carbon> $moments the moment each line is stamped with, in the order they are written */
    private function buildAt(array $moments): void
    {
        foreach ($moments as $number => $moment) {
            Carbon::setTestNow($moment);
            $this->ids[] = (int) app(PlatformAdminAudit::class)->record(action: 'step.' . ($number + 1), adminId: 7, ip: '203.0.113.9', context: ['source' => 'console'])->getKey();
        }

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }
}
