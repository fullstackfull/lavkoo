<?php

namespace Tests\Feature\PlatformAdmin;

use App\Exceptions\PlatformAdminAuditImmutableException;
use App\Models\PlatformAdminAuditLog;
use App\Services\PlatformAdmin\PlatformAdminAudit;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A line of the trail is written once. Nothing built on the model, one line or a query, changes or removes one; a line leaves only by the console command
 * that prunes, which works on the table itself.
 */
class AuditImmutabilityTest extends PlatformAdminTestCase
{
    private PlatformAdminAuditLog $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-17 10:00:00');
        $this->line = app(PlatformAdminAudit::class)->record(action: 'grant.created', adminId: 7, context: ['source' => 'console']);
        app(PlatformAdminAudit::class)->record(action: 'grant.updated', adminId: 7);
    }

    public function test_a_line_can_be_written_and_read_back(): void
    {
        $fresh = PlatformAdminAuditLog::query()->findOrFail($this->line->getKey());

        $this->assertSame('grant.created', $fresh->action);
        $this->assertSame(['source' => 'console'], $fresh->context);
        $this->assertSame(2, PlatformAdminAuditLog::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function ways(): array
    {
        return [
            'update on the line' => ['line update'],
            'a changed line saved' => ['line save'],
            'delete on the line' => ['line delete'],
            'destroy by id' => ['destroy'],
            'increment on the line' => ['line increment'],
            'update on a query' => ['query update'],
            'delete on a query' => ['query delete'],
            'delete on a query of one line' => ['query delete one'],
            'forceDelete on a query' => ['query force delete'],
            'increment on a query' => ['query increment'],
            'decrement on a query' => ['query decrement'],
            'upsert on a query' => ['query upsert'],
            'truncate on a query' => ['query truncate'],
            'updateOrCreate on an existing line' => ['update or create'],
            'a query that updates every line through the model' => ['model update all'],
        ];
    }

    #[DataProvider('ways')]
    public function test_nothing_changes_or_removes_a_line_through_the_model_or_a_query_built_on_it(string $way): void
    {
        $before = json_encode(DB::table('platform_admin_audit_logs')->orderBy('id')->get());

        try {
            $this->attempt($way);
            $this->fail("{$way} must be refused");
        } catch (PlatformAdminAuditImmutableException $refusal) {
            $this->assertStringContainsString('append only', $refusal->getMessage());
            $this->assertStringContainsString('audit:prune', $refusal->getMessage());
        }

        $this->assertSame($before, json_encode(DB::table('platform_admin_audit_logs')->orderBy('id')->get()), 'the table is as it was');
    }

    public function test_a_line_can_still_be_added_through_the_query_of_the_model_and_the_table_itself_is_not_the_model(): void
    {
        PlatformAdminAuditLog::query()->insert(['action' => 'inserted', 'created_at' => '2026-11-17 10:00:00']);

        $this->assertSame(3, DB::table('platform_admin_audit_logs')->count());
        $this->assertSame(1, DB::table('platform_admin_audit_logs')->where('action', 'inserted')->delete(), 'what the prune command uses');
    }

    private function attempt(string $way): void
    {
        $id = $this->line->getKey();

        match ($way) {
            'line update' => $this->line->update(['action' => 'changed']),
            'line save' => (function () {
                $this->line->action = 'changed';
                $this->line->save();
            })(),
            'line delete' => PlatformAdminAuditLog::query()->findOrFail($id)->delete(),
            'destroy' => PlatformAdminAuditLog::destroy($id),
            'line increment' => $this->line->increment('token_id'),
            'query update' => PlatformAdminAuditLog::query()->where('admin_id', 7)->update(['action' => 'changed']),
            'query delete' => PlatformAdminAuditLog::query()->delete(),
            'query delete one' => PlatformAdminAuditLog::query()->whereKey($id)->delete(),
            'query force delete' => PlatformAdminAuditLog::query()->forceDelete(),
            'query increment' => PlatformAdminAuditLog::query()->increment('token_id'),
            'query decrement' => PlatformAdminAuditLog::query()->decrement('token_id'),
            'query upsert' => PlatformAdminAuditLog::query()->upsert([['id' => $id, 'action' => 'changed', 'created_at' => '2026-11-17 10:00:00']], ['id'], ['action']),
            'query truncate' => PlatformAdminAuditLog::query()->truncate(),
            'update or create' => PlatformAdminAuditLog::query()->updateOrCreate(['id' => $id], ['action' => 'changed']),
            'model update all' => PlatformAdminAuditLog::query()->get()->each->update(['ip' => '203.0.113.9']),
        };
    }
}
