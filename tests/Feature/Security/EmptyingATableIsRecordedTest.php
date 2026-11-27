<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Emptying a table from the database screen writes a line with the actor, their country scope and both sides, and the emptying fails if the
 * line cannot be written (G-14, third criterion).
 *
 * This is the action of the set that the trail said NOTHING about, rather than said unreliably. Adding or rewriting an employee or a role
 * goes through a model and `AuditTrailObserver` has always left a line; emptying a table is `DB::table($name)->delete()`, which
 * instantiates no model and raises no event, so the one screen on the panel that destroys rows in bulk destroyed them with no actor, no
 * date and no count. `SensitiveAdminAction::performAndRecord()` is the seam: the delete and the line are one transaction, the line goes
 * through `recordOrFail()`, and a line that cannot be written takes the emptying back with it.
 *
 * **It is also the first of these actions a RESTRICTED employee can reach**, which is what makes the asker's country scope on the line more
 * than a field. Staff management lets in only a master account, so the scope there is always `unrestricted` (recorded as a finding in
 * {@see SensitiveAdminActionsAreRecordedTest}); the database screen is behind `module:system_settings`, which an ordinary role may hold, so
 * the line of an employee with two countries says those two countries.
 *
 * `gcc.staff.sensitive_actions.recorded` ships OFF and the first group is that: the delete is the delete it has always been, no line is
 * written, and it works on a host whose `audit_logs` table does not exist -- which is most of them.
 */
class EmptyingATableIsRecordedTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const MASTER_ROLE = 1;

    private const MASTER = 9;

    private const RESTRICTED_EMPLOYEE = 11;

    private const SETTINGS_ROLE = 7;

    /** A table of no consequence, created for this test, so that nothing the platform needs is ever the subject of the emptying. */
    private const TABLE = 'db_clean_playground';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->buildAuditLogTable();
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('note')->nullable();
        });
        DB::table(self::TABLE)->insert([['note' => 'first'], ['note' => 'second'], ['note' => 'third']]);

        DB::table('admin_roles')->insert([
            'id' => self::SETTINGS_ROLE, 'name' => 'Settings only', 'module_access' => json_encode(['system_settings']), 'status' => 1,
        ]);
        DB::table('admins')->insert([
            ['id' => self::MASTER, 'name' => 'A master', 'email' => 'master@example.test', 'admin_role_id' => self::MASTER_ROLE, 'status' => 1],
            ['id' => self::RESTRICTED_EMPLOYEE, 'name' => 'A restricted employee', 'email' => 'restricted@example.test', 'admin_role_id' => self::SETTINGS_ROLE, 'status' => 1],
        ]);
        DB::table('business_settings')->insert(['type' => 'pagination_limit', 'value' => '25']);

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();
        config()->set('gcc.staff.sensitive_actions.recorded', false);
        config()->set('gcc.staff.step_up.enabled', false);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- off, which is how it ships ---------------------------------------------------------------------------------------

    /**
     * Off, the rows go and the trail gains NOTHING -- no line, which is a different answer from a line with nothing on it. The count is
     * asserted rather than the absence of one action's name, because a line of any shape would be a change to a trail nobody asked to
     * change.
     */
    public function test_with_the_switch_off_the_table_is_emptied_and_no_line_is_written(): void
    {
        $this->emptyThePlayground()->assertRedirect();

        $this->assertSame(0, DB::table(self::TABLE)->count(), 'the rows were still emptied');
        $this->assertSame(0, DB::table('audit_logs')->count(), 'and the trail is the trail it was');
    }

    /** With it off the action is carried out on a host whose trail is missing altogether, because the trail is never asked about. */
    public function test_with_the_switch_off_the_table_is_emptied_on_a_host_that_has_no_trail(): void
    {
        Schema::drop('audit_logs');

        try {
            $this->emptyThePlayground()->assertRedirect();

            $this->assertSame(0, DB::table(self::TABLE)->count());
        } finally {
            $this->buildAuditLogTable();
        }
    }

    // ---- on: one line, with the actor, the scope and both sides -----------------------------------------------------------

    public function test_with_the_switch_on_the_emptying_leaves_one_line_naming_the_actor_and_the_table(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);

        $this->emptyThePlayground()->assertRedirect();

        $line = DB::table('audit_logs')->sole();
        $this->assertSame('system.tables_emptied', $line->action);
        $this->assertSame(['admin', self::MASTER], [$line->actor_type, (int) $line->actor_id], 'who emptied it');
        $this->assertStringContainsString(self::TABLE, (string) $line->context, 'and which tables');
        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    /**
     * Both sides, as COUNTS. "Which tables" is not what a reader of the trail needs: how many rows were destroyed is, and that figure
     * exists nowhere else once the rows are gone.
     */
    public function test_the_line_records_how_many_rows_each_table_held_and_holds(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);

        $this->emptyThePlayground()->assertRedirect();

        $line = DB::table('audit_logs')->sole();
        $this->assertSame([self::TABLE => 3], json_decode((string) $line->before, true), 'three rows were destroyed');
        $this->assertSame([self::TABLE => 0], json_decode((string) $line->after, true), 'and none are left');
    }

    /**
     * The asker's country scope, on a line a RESTRICTED employee left. The database screen is behind `module:system_settings`, which an
     * ordinary role may hold, so this is where the scope field stops being inert: the employee holds Kuwait and the United Arab Emirates
     * and the line says so.
     */
    public function test_the_line_of_a_restricted_employee_carries_their_countries_and_not_unrestricted(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);
        config()->set('gcc.staff.country_scopes', true);
        Schema::create(StaffCountryScope::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('country', 2);
            $table->timestamps();
        });
        // A scope is enforced only where the products carry their country, which is what StaffCountryScope::enabled() asks: without the
        // column every employee reads as unrestricted, which is the state every installation is in today.
        Schema::table('products', static function (Blueprint $table): void {
            $table->string(StorefrontCatalog::COLUMN, 2)->nullable();
        });
        StaffCountryScope::forgetTheSchema();

        try {
            DB::table(StaffCountryScope::TABLE)->insert([
                ['admin_id' => self::RESTRICTED_EMPLOYEE, 'country' => 'KW'],
                ['admin_id' => self::RESTRICTED_EMPLOYEE, 'country' => 'AE'],
            ]);

            $this->emptyThePlayground(self::RESTRICTED_EMPLOYEE)->assertRedirect();

            $context = json_decode((string) DB::table('audit_logs')->sole()->context, true);
            $this->assertSame(['AE', 'KW'], $context['asker_scope'], 'the line answers which countries the asker could work in');
            $this->assertSame(0, DB::table(self::TABLE)->count(), 'and the employee really did empty the table');
        } finally {
            Schema::drop(StaffCountryScope::TABLE);
            StaffCountryScope::forgetTheSchema();
        }
    }

    // ---- a line that cannot be written takes the emptying back ------------------------------------------------------------

    /** The criterion itself: rows nobody can account for are not rows this platform destroys. */
    public function test_an_emptying_whose_line_cannot_be_written_destroys_nothing(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);
        Schema::drop('audit_logs');

        try {
            $this->emptyThePlayground();

            $this->assertSame(3, DB::table(self::TABLE)->count(), 'the rows are still there');
        } finally {
            $this->buildAuditLogTable();
        }
    }

    /**
     * What the refusal is named, measured rather than assumed.
     *
     * `recordOrFail()` throws an `AuditLineNotWrittenException` naming the action, and the screen's own `catch (Exception)` -- which was
     * there before this and is what turns a failed delete into a message rather than a stack trace -- reports it to the operator as the
     * same "failed" the screen reports for any other failure. So the operator is told the emptying did not happen, which is true, and WHY
     * is in the exception rather than on the screen. Asserted here so that the day the screen says something more precise, this says so.
     */
    public function test_the_failure_reaches_the_operator_as_the_screens_own_failure_and_the_reason_is_named_in_the_exception(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);
        Schema::drop('audit_logs');

        try {
            $this->emptyThePlayground()->assertRedirect();
            $this->assertSame(3, DB::table(self::TABLE)->count(), 'the operator is told it failed, and it did');

            $thrown = null;
            try {
                app(\App\Services\Staff\SensitiveAdminAction::class)->performAndRecord(
                    write: static fn (): bool => true,
                    record: static fn () => app(\App\Services\AuditLogger::class)->recordOrFail(action: 'system.tables_emptied'),
                );
            } catch (\Throwable $failed) {
                $thrown = $failed;
            }

            // A host whose table does not exist is told so by `recordOrFail()` before anything else, as a plain RuntimeException; a host
            // whose WRITE fails gets AuditLineNotWrittenException, which extends it. Both are asserted through the base class, because the
            // claim is that the seam throws and names the action, not which of its two shapes a dropped table produces.
            $this->assertInstanceOf(\RuntimeException::class, $thrown, 'the seam throws rather than returning quietly');
            $this->assertStringContainsString('system.tables_emptied', $thrown->getMessage(), 'and names the action it could not record');
        } finally {
            $this->buildAuditLogTable();
        }
    }

    private function emptyThePlayground(int $asker = self::MASTER): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(Admin::findOrFail($asker), 'admin')->post('/admin/system-setup/db-clean', ['tables' => [self::TABLE]]);
    }
}
