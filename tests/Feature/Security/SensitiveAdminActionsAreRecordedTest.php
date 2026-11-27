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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * An action that changes who may do what fails if the line recording it cannot be written (G-14, third criterion).
 *
 * **A CLAIM OF THE CARD IS CORRECTED.** G-14 says role, employee and staff-scope changes are not recorded. They partly are:
 * `AuditTrailObserver` watches `Admin` and `AdminRole`, so adding an employee, rewriting one and switching one off have always left a
 * line. Two things were actually wrong, and this covers both.
 *
 * 1. **A ROLE could be rewritten, switched off or deleted with the trail saying nothing.** `AdminRoleRepository` writes with
 *    `where('id', $id)->update(...)` and `where($params)->delete()`, which instantiate no model and raise no event, so the observer saw
 *    only `access.role_created`. A role's `module_access` is the whole of what its holders may do. `AdminRole` now carries
 *    `AuditedBuilder`, as the settings models do, and the three lines appear. That half is NOT behind a switch: it only adds lines,
 *    through `record()`, which cannot fail an action.
 * 2. **The line could silently fail.** `AuditLogger::record()` swallows a failure on purpose -- a missing note is better than a
 *    rolled-back payout -- so a host whose trail was missing or broken carried the change out and recorded nothing.
 *    `SensitiveAdminAction` opens a transaction and marks the trail fail-closed for the length of the action, so the change is taken back.
 *    Laravel fires `updated` AFTER the row is written and not inside a transaction, which is why the transaction has to be opened here and
 *    cannot be left to the model.
 *
 * No second line is written. A trail somebody reads to answer a question is worse for having two lines per action, so the existing one way
 * of recording is the one that was made to fail closed.
 *
 * **The switch ships off and the first tests say so.**
 *
 * **What is INERT HERE, and it is a finding rather than a tidy-up:** the asker's country scope goes on a required line, and on these
 * routes it is always `unrestricted`, because staff management lets in only a master account and `StaffCountryScope` never restricts one.
 * It is asserted below as `unrestricted` so that a change making it say something else is noticed.
 *
 * The field is no longer inert on the platform: emptying a table from the database screen is wrapped as well, that screen is behind
 * `module:system_settings` rather than behind a master account, and {@see EmptyingATableIsRecordedTest} drives it as a restricted employee
 * and reads their two countries off the line. A withdrawal approval and a coupon change (G-14's second criterion) are still unwrapped.
 */
class SensitiveAdminActionsAreRecordedTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const MASTER_ROLE = 1;

    private const ASKER = 9;

    private const EMPLOYEE = 3;

    private const ORDINARY_ROLE = 3;

    private const PEOPLE_ROLE = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->buildAuditLogTable();
        Schema::table('admins', function (Blueprint $table): void {
            foreach (['phone', 'password', 'image', 'identify_type', 'identify_number'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('identify_image')->nullable();
        });
        DB::table('admin_roles')->insert(['id' => self::PEOPLE_ROLE, 'name' => 'People only', 'module_access' => json_encode(['people']), 'status' => 1]);
        DB::table('admins')->insert(['id' => self::ASKER, 'name' => 'Another master', 'email' => 'another-master@example.test', 'admin_role_id' => self::MASTER_ROLE, 'status' => 1]);
        DB::table('admins')->update(['password' => bcrypt('Their#Own1Password'), 'phone' => '0500000000']);
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
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- off, which is how it ships ---------------------------------------------------------------------------------------

    public function test_with_the_switch_off_the_seven_actions_write_their_rows_and_the_trail_records_what_it_always_did(): void
    {
        $this->driveEverySensitiveAction();

        $this->assertTrue(DB::table('admins')->where('email', 'new@example.test')->exists(), 'the employee was still added');
        $this->assertFalse(DB::table('admin_roles')->where('id', self::ORDINARY_ROLE)->exists(), 'the role was still deleted, which is the last of the seven');
        $this->assertSame([], array_values(array_filter(
            DB::table('audit_logs')->pluck('context')->all(),
            static fn ($context): bool => $context !== null && str_contains((string) $context, 'asker_scope'),
        )), 'no line gains a key while the switch is off');
    }

    /** With it off the action is carried out even on a host whose trail is missing altogether, because the trail is never asked about. */
    public function test_with_the_switch_off_an_action_is_carried_out_on_a_host_that_has_no_trail(): void
    {
        Schema::drop('audit_logs');

        try {
            $this->signedInAsTheAsker()->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0])->assertRedirect();
            $this->assertSame(0, (int) $this->employee()->status);
        } finally {
            $this->buildAuditLogTable();
        }
    }

    // ---- on: a line per action, with the actor, the scope and both sides --------------------------------------------------

    /**
     * The whole set, on one tree, so a line that stops being written is seen. `access.employee_updated` appears twice because the employee
     * form and the status action are two of the seven, and `access.role_updated` twice for the same reason.
     */
    public function test_every_one_of_the_seven_actions_leaves_a_line(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);

        $this->driveEverySensitiveAction();

        $actions = DB::table('audit_logs')->orderBy('action')->orderBy('id')->pluck('action')->all();
        $this->assertSame([
            'access.employee_created',
            'access.employee_updated',
            'access.employee_updated',
            'access.role_created',
            'access.role_deleted',
            'access.role_updated',
            'access.role_updated',
        ], $actions);
    }

    /** The half of this that needs no switch: a role rewritten, switched off or deleted by a mass statement used to leave nothing. */
    public function test_a_role_rewritten_switched_off_or_deleted_leaves_a_line_even_with_the_switch_off(): void
    {
        $asked = $this->signedInAsTheAsker();
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $asked->post('/admin/custom-role/update/' . self::ORDINARY_ROLE, ['name' => 'Renamed', 'modules' => ['people']])->assertRedirect();
        $asked->withHeaders($ajax)->post('/admin/custom-role/employee-role-status', ['id' => self::ORDINARY_ROLE, 'status' => 0])->assertOk();
        $asked->withHeaders($ajax)->post('/admin/custom-role/delete', ['id' => self::ORDINARY_ROLE])->assertOk();

        $this->assertSame(
            ['access.role_updated', 'access.role_updated', 'access.role_deleted'],
            DB::table('audit_logs')->orderBy('id')->pluck('action')->all(),
        );
    }

    /** A role's line has to say what the role now PERMITS, or it says a role changed without saying what changed. */
    public function test_a_role_line_records_the_modules_it_grants_on_both_sides(): void
    {
        $this->signedInAsTheAsker()->post('/admin/custom-role/update/' . self::ORDINARY_ROLE, ['name' => 'Renamed', 'modules' => ['people', 'orders']])->assertRedirect();

        $line = DB::table('audit_logs')->where('action', 'access.role_updated')->sole();
        $this->assertStringContainsString('people', (string) $line->after);
        $this->assertStringContainsString('orders', (string) $line->after);
        $this->assertNotSame((string) $line->before, (string) $line->after, 'both sides are recorded and they differ');
        $this->assertSame(['admin', self::ASKER], [$line->actor_type, (int) $line->actor_id], 'and who did it');
    }



    /**
     * The asker's country scope goes on a required line, and ON THESE ROUTES IT IS ALWAYS `unrestricted` -- even with scope rows written
     * for the asker and the scope switch on -- because staff management lets in only a master account and `StaffCountryScope` never
     * restricts one. That is measured here rather than assumed, and the assertion is the measurement: the field earns its place the day a
     * sensitive action a restricted employee can reach is wrapped.
     */
    public function test_the_askers_scope_is_on_a_required_line_and_is_unrestricted_on_these_routes(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);
        config()->set('gcc.staff.country_scopes', true);
        Schema::create(StaffCountryScope::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('country', 2);
            $table->timestamps();
        });
        StaffCountryScope::forgetTheSchema();

        try {
            DB::table(StaffCountryScope::TABLE)->insert([
                ['admin_id' => self::ASKER, 'country' => 'KW'],
                ['admin_id' => self::ASKER, 'country' => 'AE'],
            ]);

            $this->signedInAsTheAsker()->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0])->assertRedirect();

            $context = json_decode(DB::table('audit_logs')->where('action', 'access.employee_updated')->sole()->context, true);
            $this->assertSame(['unrestricted'], $context['asker_scope'], 'a master account is unrestricted however many rows it holds');
        } finally {
            Schema::drop(StaffCountryScope::TABLE);
            StaffCountryScope::forgetTheSchema();
        }
    }

    /** Outside a required action nothing changes about a line, which is what keeps the rest of the trail exactly as it was. */
    public function test_a_line_outside_a_required_action_gains_no_scope_key(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', false);

        $this->signedInAsTheAsker()->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0])->assertRedirect();

        $line = DB::table('audit_logs')->where('action', 'access.employee_updated')->sole();
        $this->assertStringNotContainsString('asker_scope', (string) $line->context);
    }

    /**
     * An audit line is read by more people than the row it describes, and the panel's own translation screen has already shown what
     * happens to a value that reaches a list. `AuditTrail::summarise` is what keeps a credential out; this drives the real form, with a
     * password in it, and reads the whole line back.
     */
    public function test_no_line_carries_a_password_or_its_hash(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);

        $this->signedInAsTheAsker()->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm())->assertRedirect();

        foreach (DB::table('audit_logs')->get() as $line) {
            $whole = $line->before . '|' . $line->after . '|' . $line->context;
            $this->assertStringNotContainsString('Chosen#By1Somebody', $whole);
            $this->assertStringNotContainsString('$2y$', $whole, 'no bcrypt hash reaches the trail');
        }
    }


    // ---- a line that cannot be written takes the change back -------------------------------------------------------------

    public function test_a_change_whose_line_cannot_be_written_is_taken_back(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);
        $before = $this->employee();
        Schema::drop('audit_logs');

        try {
            $this->signedInAsTheAsker()->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm());

            $this->assertSame($before->email, $this->employee()->email, 'a permission change nobody can trace is not a change this platform makes');
            $this->assertSame($before->admin_role_id, $this->employee()->admin_role_id);
        } finally {
            $this->buildAuditLogTable();
        }
    }

    public function test_a_role_whose_line_cannot_be_written_is_not_added_and_not_deleted(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);
        $roles = DB::table('admin_roles')->orderBy('id')->pluck('name', 'id')->all();
        Schema::drop('audit_logs');

        try {
            $this->signedInAsTheAsker()->post('/admin/custom-role/add', ['name' => 'A new role', 'modules' => ['people']]);
            $this->signedInAsTheAsker()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->post('/admin/custom-role/delete', ['id' => self::ORDINARY_ROLE]);

            $this->assertSame($roles, DB::table('admin_roles')->orderBy('id')->pluck('name', 'id')->all());
        } finally {
            $this->buildAuditLogTable();
        }
    }

    /** The refusal is loud by design: an action that reported success while nothing recorded it would be the lie the guard exists to stop. */
    public function test_the_refusal_names_the_action_it_could_not_record(): void
    {
        config()->set('gcc.staff.sensitive_actions.recorded', true);
        Schema::drop('audit_logs');

        try {
            $this->withoutExceptionHandling();
            $this->expectException(\App\Exceptions\AuditLineNotWrittenException::class);
            $this->expectExceptionMessageMatches('/could not be written: access\.employee_updated/');

            $this->signedInAsTheAsker()->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0]);
        } finally {
            $this->buildAuditLogTable();
        }
    }

    // ---- what the tests read and send ------------------------------------------------------------------------------------

    private function driveEverySensitiveAction(): void
    {
        $asked = $this->signedInAsTheAsker();
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $asked->post('/admin/employee/add', ['email' => 'new@example.test', 'image' => UploadedFile::fake()->image('face.png')] + $this->anEmployeeForm())->assertRedirect();
        $asked->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm())->assertRedirect();
        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0])->assertRedirect();
        $asked->post('/admin/custom-role/add', ['name' => 'A new role', 'modules' => ['people']])->assertRedirect();
        $asked->post('/admin/custom-role/update/' . self::ORDINARY_ROLE, ['name' => 'Renamed', 'modules' => ['people']])->assertRedirect();
        $asked->withHeaders($ajax)->post('/admin/custom-role/employee-role-status', ['id' => self::ORDINARY_ROLE, 'status' => 0])->assertOk();
        $asked->withHeaders($ajax)->post('/admin/custom-role/delete', ['id' => self::ORDINARY_ROLE])->assertOk();
    }

    private function signedInAsTheAsker(): static
    {
        return $this->actingAs(Admin::findOrFail(self::ASKER), 'admin');
    }

    private function employee(): object
    {
        return DB::table('admins')->where('id', self::EMPLOYEE)->first();
    }

    /** @return array<string, mixed> */
    private function anEmployeeForm(): array
    {
        return [
            'name' => 'Renamed', 'phone' => '0599999999', 'email' => 'moved@example.test', 'role_id' => self::PEOPLE_ROLE,
            'identify_type' => 'nid', 'identify_number' => '1',
            'password' => 'Chosen#By1Somebody', 'confirm_password' => 'Chosen#By1Somebody',
        ];
    }
}
