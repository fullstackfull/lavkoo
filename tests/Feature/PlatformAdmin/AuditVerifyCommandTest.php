<?php

namespace Tests\Feature\PlatformAdmin;

use App\Services\PlatformAdmin\PlatformAdminAudit;
use App\Services\PlatformAdmin\PlatformAdminAuditChain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `gcc:platform-admin:audit:verify` reads the whole trail and the tail and says whether the chain holds, and where it does not: the first line that is wrong.
 * It reads only.
 */
class AuditVerifyCommandTest extends PlatformAdminTestCase
{
    /** @var list<int> the ids of the lines written by build() */
    private array $ids = [];

    public function test_an_unbroken_trail_passes_and_says_how_many_lines_it_read_and_the_id_and_hash_of_the_newest(): void
    {
        $this->build(lines: 30);
        $newest = DB::table('platform_admin_audit_logs')->orderByDesc('id')->first();

        [$exit, $output] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('The audit trail is intact', $output);
        $this->assertStringContainsString('30 lines', $output);
        $this->assertStringContainsString("line {$newest->id}", $output);
        $this->assertStringContainsString($newest->hash, $output);
    }

    public function test_a_trail_with_no_line_passes(): void
    {
        [$exit, $output] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no line', $output);
    }

    public function test_lines_from_before_the_chain_began_are_read_as_older_than_it_and_do_not_break_it(): void
    {
        DB::table('platform_admin_audit_logs')->insert([
            ['action' => 'old.one', 'created_at' => '2026-10-01 10:00:00'],
            ['action' => 'old.two', 'created_at' => '2026-10-02 10:00:00'],
        ]);
        $this->build(lines: 3);

        [$exit, $output] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('3 lines', $output);
        $this->assertStringContainsString('2 lines from before the chain', $output);
    }

    public function test_a_trail_of_only_older_lines_passes_with_an_empty_tail(): void
    {
        DB::table('platform_admin_audit_logs')->insert(['action' => 'old.one', 'created_at' => '2026-10-01 10:00:00']);

        [$exit] = $this->verify();

        $this->assertSame(0, $exit);
    }

    /** @return array<string, array{string, mixed}> */
    public static function tamperings(): array
    {
        return [
            'the admin' => ['admin_id', 99],
            'the action' => ['action', 'grant.revoked'],
            'the market' => ['market', 'AE'],
            'the session' => ['token_id', 4242],
            'the address' => ['ip', '198.51.100.7'],
            'the user agent' => ['user_agent', 'Somebody/1.0'],
            'the context' => ['context', '{"source":"console","extra":true}'],
            'the time' => ['created_at', '2026-11-17 10:00:07'],
        ];
    }

    #[DataProvider('tamperings')]
    public function test_a_change_to_any_part_of_a_line_is_found_at_that_line(string $column, mixed $value): void
    {
        $this->build(lines: 12);
        $target = $this->ids[5];
        DB::table('platform_admin_audit_logs')->where('id', $target)->update([$column => $value]);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$target}", $output);
        $this->assertStringContainsString('content', $output);
    }

    public function test_a_hash_that_was_edited_is_found_at_its_line_and_so_is_a_previous_hash(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[3])->update(['hash' => str_repeat('a', 64)]);

        $this->assertStringContainsString("broken at line {$this->ids[3]}", $this->verify()[1]);

        $this->build(lines: 0, fresh: true);
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[7])->update(['prev_hash' => str_repeat('b', 64)]);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[7]}", $output);
        $this->assertStringContainsString('follow', $output);
    }

    public function test_a_line_removed_from_the_middle_is_found_at_the_line_after_it(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[5])->delete();

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[6]}", $output);
        $this->assertStringContainsString('follow', $output);
    }

    public function test_the_first_line_removed_is_found_at_the_new_first_line(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[0])->delete();

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[1]}", $output);
    }

    public function test_the_newest_line_removed_is_found_by_the_tail_which_still_holds_it(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[11])->delete();

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[11]}", $output);
        $this->assertStringContainsString('tail', $output);
    }

    public function test_the_newest_lines_removed_are_found_whatever_their_number(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', '>', $this->ids[8])->delete();

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[11]}", $output);
    }

    public function test_every_line_removed_is_found_by_the_tail(): void
    {
        $this->build(lines: 5);
        DB::table('platform_admin_audit_logs')->delete();

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[4]}", $output);
    }

    public function test_a_line_put_between_two_others_that_did_not_come_from_the_writer_is_found_at_the_line_after_it(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', '>', $this->ids[5])->increment('id', 100000);
        DB::table('platform_admin_audit_logs')->where('id', '>', 100000)->decrement('id', 99999);
        $forged = $this->forgedAfter(previous: $this->ids[5], id: $this->ids[5] + 1);
        DB::table('platform_admin_audit_logs')->insert($forged);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('broken at line ' . ($this->ids[6] + 1), $output);
        $this->assertStringContainsString('follow', $output);
    }

    public function test_an_unhashed_line_that_turns_up_after_hashed_ones_is_found(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->insert(['id' => $this->ids[11] + 1, 'action' => 'slipped.in', 'created_at' => '2026-11-17 10:00:30']);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('broken at line ' . ($this->ids[11] + 1), $output);
        $this->assertStringContainsString('no hash', $output);
    }

    public function test_a_line_with_a_link_and_no_hash_is_not_one_from_before_the_chain(): void
    {
        DB::table('platform_admin_audit_logs')->insert(['id' => 1, 'action' => 'half.made', 'created_at' => '2026-10-01 10:00:00', 'prev_hash' => PlatformAdminAuditChain::GENESIS, 'hash' => null]);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('broken at line 1', $output);
        $this->assertStringContainsString('no hash', $output);
    }

    public function test_hashed_lines_added_after_the_newest_without_moving_the_tail_are_found_at_the_first_of_them(): void
    {
        $this->build(lines: 12);
        $first = $this->forgedAfter(previous: $this->ids[11], id: $this->ids[11] + 1);
        DB::table('platform_admin_audit_logs')->insert($first);
        DB::table('platform_admin_audit_logs')->insert($this->forgedAfter(previous: $first['id'], id: $first['id'] + 1));

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$first['id']}", $output);
        $this->assertStringContainsString('tail', $output);
    }

    public function test_two_lines_that_changed_places_are_found_at_the_first_of_them(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[4])->update(['id' => 9001]);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[5])->update(['id' => $this->ids[4]]);
        DB::table('platform_admin_audit_logs')->where('id', 9001)->update(['id' => $this->ids[5]]);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[4]}", $output);
    }

    public function test_a_missing_tail_is_found(): void
    {
        $this->build(lines: 5);
        DB::table('platform_admin_audit_tail')->delete();

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('tail row is missing', $output);
    }

    public function test_a_tail_that_holds_another_hash_or_another_line_is_found_at_the_newest_line(): void
    {
        $this->build(lines: 5);
        DB::table('platform_admin_audit_tail')->update(['last_hash' => str_repeat('c', 64)]);

        $this->assertStringContainsString("broken at line {$this->ids[4]}", $this->verify()[1]);

        DB::table('platform_admin_audit_tail')->update(['last_hash' => DB::table('platform_admin_audit_logs')->where('id', $this->ids[4])->value('hash'), 'last_line_id' => $this->ids[3]]);

        $this->assertStringContainsString("broken at line {$this->ids[4]}", $this->verify()[1]);
    }

    public function test_a_tail_that_says_no_line_was_written_when_hashed_lines_exist_is_found_at_the_first_of_them(): void
    {
        $this->build(lines: 5);
        DB::table('platform_admin_audit_tail')->update(['last_line_id' => null, 'last_hash' => null]);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("broken at line {$this->ids[0]}", $output);
        $this->assertStringContainsString('tail', $output);
    }

    public function test_a_tail_that_holds_a_line_when_there_are_only_older_lines_is_found(): void
    {
        DB::table('platform_admin_audit_logs')->insert(['action' => 'old.one', 'created_at' => '2026-10-01 10:00:00']);
        DB::table('platform_admin_audit_tail')->update(['last_line_id' => 5, 'last_hash' => str_repeat('d', 64)]);

        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('broken at line 5', $output);
    }

    public function test_the_first_broken_line_is_the_one_named_when_several_are_broken(): void
    {
        $this->build(lines: 12);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[9])->update(['action' => 'later']);
        DB::table('platform_admin_audit_logs')->where('id', $this->ids[2])->update(['action' => 'earlier']);

        $this->assertStringContainsString("broken at line {$this->ids[2]}", $this->verify()[1]);
    }

    public function test_verifying_reads_only_and_writes_no_line_of_its_own(): void
    {
        $this->build(lines: 8);
        $before = [json_encode(DB::table('platform_admin_audit_logs')->get()), json_encode(DB::table('platform_admin_audit_tail')->get())];

        $statements = $this->statementsRunBy(fn () => $this->verify());

        foreach ($this->withoutSchemaChecks($statements) as $sql) {
            $this->assertStringStartsWith('select', $sql, 'nothing is written');
        }
        $this->assertSame($before, [json_encode(DB::table('platform_admin_audit_logs')->get()), json_encode(DB::table('platform_admin_audit_tail')->get())]);
    }

    public function test_a_trail_longer_than_one_read_is_verified_as_a_whole(): void
    {
        $this->build(lines: 1205);

        [$exit, $output] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1205 lines', $output);

        DB::table('platform_admin_audit_logs')->where('id', $this->ids[1100])->update(['ip' => '198.51.100.9']);
        $this->assertStringContainsString("broken at line {$this->ids[1100]}", $this->verify()[1]);
    }

    public function test_it_works_with_the_api_switched_off_and_says_what_to_do_when_the_migrations_have_not_run(): void
    {
        config(['gcc.platform_admin.enabled' => false]);
        $this->assertSame(0, $this->verify()[0]);

        Schema::dropIfExists('platform_admin_audit_tail');
        [$exit, $output] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Run the migrations first', $output);
        $this->assertStringContainsString('platform_admin_audit_tail', $output);
    }

    /** @return array{0: int, 1: string} */
    private function verify(): array
    {
        $exit = Artisan::call('gcc:platform-admin:audit:verify');

        return [$exit, Artisan::output()];
    }

    /** Writes lines through the writer, one second apart. */
    private function build(int $lines, bool $fresh = false): void
    {
        if ($fresh) {
            DB::table('platform_admin_audit_logs')->delete();
            DB::table('platform_admin_audit_tail')->update(['last_line_id' => null, 'last_hash' => null]);
            $this->ids = [];
        }

        $this->travelTo('2026-11-17 10:00:00');
        for ($number = 1; $number <= $lines; $number++) {
            $this->travelTo(now()->addSecond());
            $this->ids[] = (int) app(PlatformAdminAudit::class)->record(
                action: "step.{$number}", adminId: $number % 3 + 1, market: $number % 2 === 0 ? 'KW' : null, tokenId: $number, ip: '203.0.113.9', userAgent: 'LavkooSeller/1.0', context: ['source' => 'console'],
            )->getKey();
        }
    }

    /**
     * A line that hashes correctly on its own and follows the line it names, as one forged by somebody who knows the format and writes the table directly.
     *
     * @return array<string, mixed>
     */
    private function forgedAfter(int $previous, int $id): array
    {
        $previousHash = (string) DB::table('platform_admin_audit_logs')->where('id', $previous)->value('hash');
        $createdAt = '2026-11-17 10:00:30';

        return [
            'id' => $id, 'admin_id' => 1, 'action' => 'forged', 'market' => null, 'token_id' => null, 'ip' => null, 'user_agent' => null, 'context' => null, 'created_at' => $createdAt, 'prev_hash' => $previousHash,
            'hash' => PlatformAdminAuditChain::hashOf(adminId: 1, action: 'forged', market: null, tokenId: null, ip: null, userAgent: null, context: null, createdAt: $createdAt, previousHash: $previousHash),
        ];
    }
}
