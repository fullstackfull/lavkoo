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
use App\Services\Staff\AdminStepUp;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\ReadsRouteMiddleware;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * An action that changes who may do what asks the employee to prove who they are again (G-14, first criterion).
 *
 * The panel carries out staff management, the role form and the database clean on the strength of a session that may be hours old. This
 * covers the primitive that asks again -- `App\Services\Staff\AdminStepUp` and `EnsureAdminStepUp` -- on the routes it is declared on.
 *
 * **The switch ships off and the first test says so.** Every sensitive action is driven with the switch off and no password field, and each
 * one still goes through and still writes, which is the platform of today.
 *
 * Each refusal below has its unrestricted twin, because a refusal on its own cannot tell a guard from a wrong address: the same request
 * carrying the right password is driven immediately afterwards and the row IS written. The asker is an account holding the master role and
 * not the first admin, which is the only account staff management lets in.
 */
class AdminStepUpTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use ReadsRouteMiddleware;
    use RestoresLanguageFiles;

    private const MASTER_ROLE = 1;

    private const ASKER = 9;

    private const EMPLOYEE = 3;

    private const ORDINARY_ROLE = 3;

    private const PEOPLE_ROLE = 9;

    private const OWN_PASSWORD = 'Their#Own1Password';

    private const WRONG_PASSWORD = 'Not#Their1Password';

    /**
     * Every screen of staff management and of the role form that shows a form the guard protects, against the path that form posts to.
     *
     * The proof travels WITH the action ({@see AdminStepUp}), so the field belongs inside the form whose button it protects and a form on a
     * step-up route without it is an action that is always refused. The status switches of the employee list and of the role list, and the
     * role list's delete, are deliberately NOT here: they are driven by the panel's JavaScript from a confirmation dialog, which has
     * nowhere to type a password, and giving them one is a change to that dialog rather than to a Blade form.
     */
    private const FORMS = [
        '/admin/employee/add' => 'employee/add',
        '/admin/employee/update/' . self::EMPLOYEE => 'employee/update/' . self::EMPLOYEE,
        '/admin/custom-role/add' => 'custom-role/add',
        '/admin/custom-role/update/' . self::ORDINARY_ROLE => 'custom-role/update/' . self::ORDINARY_ROLE,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        Schema::table('admins', function (Blueprint $table): void {
            foreach (['phone', 'password', 'image', 'identify_type', 'identify_number'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('identify_image')->nullable();
        });
        DB::table('admin_roles')->insert(['id' => self::PEOPLE_ROLE, 'name' => 'People only', 'module_access' => json_encode(['people']), 'status' => 1]);
        DB::table('admins')->insert(['id' => self::ASKER, 'name' => 'Another master', 'email' => 'another-master@example.test', 'admin_role_id' => self::MASTER_ROLE, 'status' => 1]);
        DB::table('admins')->update(['password' => bcrypt(self::OWN_PASSWORD), 'phone' => '0500000000']);
        // The employee list pages by the shop's own limit, and the repository's parameter is typed, so a shop without the setting is a 500
        // on a screen this test only passes through.
        DB::table('business_settings')->insert(['type' => 'pagination_limit', 'value' => '25']);

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();
        RateLimiter::clear('gcc-admin-step-up:' . self::ASKER);
        config()->set('gcc.staff.step_up', ['enabled' => false, 'minutes' => 10, 'attempts_per_minute' => 5]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- off, which is how it ships ---------------------------------------------------------------------------------------

    public function test_with_the_switch_off_every_sensitive_action_is_carried_out_without_a_password(): void
    {
        $asked = $this->signedInAsTheAsker();

        $asked->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm())->assertRedirect();
        $this->assertSame('moved@example.test', $this->employee()->email, 'the employee form still writes');

        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0])->assertRedirect();
        $this->assertSame(0, (int) $this->employee()->status);

        $asked->post('/admin/custom-role/update/' . self::ORDINARY_ROLE, ['name' => 'Renamed', 'modules' => ['people']])->assertRedirect();
        $this->assertSame('Renamed', $this->role()->name, 'the role form still writes');

        $asked->withHeaders($this->asAjax())->post('/admin/custom-role/employee-role-status', ['id' => self::ORDINARY_ROLE, 'status' => 0])->assertOk();
        $this->assertSame(0, (int) $this->role()->status);

        $asked->post('/admin/employee/add', $this->aNewEmployeeForm())->assertRedirect();
        $this->assertTrue(DB::table('admins')->where('email', 'new@example.test')->exists(), 'a new employee is still added');

        $asked->post('/admin/custom-role/add', ['name' => 'A new role', 'modules' => ['people']])->assertRedirect();
        $this->assertTrue(DB::table('admin_roles')->where('name', 'A new role')->exists(), 'a new role is still added');

        $asked->withHeaders($this->asAjax())->post('/admin/custom-role/delete', ['id' => self::ORDINARY_ROLE])->assertOk();
        $this->assertFalse(DB::table('admin_roles')->where('id', self::ORDINARY_ROLE)->exists(), 'a role is still deleted');
    }

    public function test_with_the_switch_off_nothing_is_stamped_on_the_session_even_when_a_password_is_sent(): void
    {
        $this->signedInAsTheAsker()
            ->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + $this->theirOwnPassword())
            ->assertRedirect();

        $this->assertNull(session(AdminStepUp::SESSION_KEY), 'a switched-off guard reads nothing and writes nothing');
    }

    /**
     * Off, the four forms are the forms they are today: `<x-k.step-up />` renders nothing, so neither the field nor its label reaches a
     * screen nobody asked to change -- a password box on a screen that does not need one is how an operator learns to ignore one.
     */
    public function test_with_the_switch_off_no_staff_or_role_form_shows_a_password_field(): void
    {
        $this->assertFalse(app(AdminStepUp::class)->isRequired(), 'the step-up switch ships off');

        foreach (self::FORMS as $screen => $action) {
            $page = $this->screen($screen);
            $fields = $this->fieldNamesOfTheFormPosting(page: $page, action: $action);

            $this->assertContains('name', $fields, "{$screen} really rendered its own form");
            $this->assertNotContains(AdminStepUp::PASSWORD_FIELD, $fields, "{$screen} must be the screen it is today");
            $this->assertStringNotContainsString(AdminStepUp::PASSWORD_FIELD, $page, 'nor may the field appear anywhere else on it');
        }
    }

    // ---- on: refused without the password, and the twin that proves it is the guard ---------------------------------------

    public function test_the_employee_form_is_refused_without_the_password_and_taken_with_it(): void
    {
        $this->switchItOn();
        $before = $this->employee();

        $this->signedInAsTheAsker()->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm())->assertRedirect();
        $this->assertSame($before->email, $this->employee()->email, 'an unconfirmed employee form writes nothing');

        $this->signedInAsTheAsker()->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm() + $this->theirOwnPassword())->assertRedirect();
        $this->assertSame('moved@example.test', $this->employee()->email, 'the same request carrying the password is carried out');
    }

    public function test_an_employee_is_not_added_or_switched_off_without_the_password(): void
    {
        $this->switchItOn();
        $asked = $this->signedInAsTheAsker();

        $asked->post('/admin/employee/add', $this->aNewEmployeeForm())->assertRedirect();
        $this->assertFalse(DB::table('admins')->where('email', 'new@example.test')->exists());

        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0])->assertRedirect();
        $this->assertSame(1, (int) $this->employee()->status);

        $asked->post('/admin/employee/add', $this->aNewEmployeeForm() + $this->theirOwnPassword())->assertRedirect();
        $this->assertTrue(DB::table('admins')->where('email', 'new@example.test')->exists(), 'confirmed, the same form adds the employee');
    }

    public function test_a_role_is_not_added_rewritten_switched_off_or_deleted_without_the_password(): void
    {
        $this->switchItOn();
        $asked = $this->signedInAsTheAsker();
        $before = $this->role();

        $asked->post('/admin/custom-role/add', ['name' => 'A new role', 'modules' => ['people']])->assertRedirect();
        $this->assertFalse(DB::table('admin_roles')->where('name', 'A new role')->exists());

        $asked->post('/admin/custom-role/update/' . self::ORDINARY_ROLE, ['name' => 'Renamed', 'modules' => ['people']])->assertRedirect();
        $asked->withHeaders($this->asAjax())->post('/admin/custom-role/employee-role-status', ['id' => self::ORDINARY_ROLE, 'status' => 0])->assertForbidden();
        $asked->withHeaders($this->asAjax())->post('/admin/custom-role/delete', ['id' => self::ORDINARY_ROLE])->assertForbidden();
        $this->assertSame((array) $before, (array) $this->role(), 'no unconfirmed request touched the role');

        $asked->post('/admin/custom-role/update/' . self::ORDINARY_ROLE, ['name' => 'Renamed', 'modules' => ['people']] + $this->theirOwnPassword())->assertRedirect();
        $this->assertSame('Renamed', $this->role()->name, 'confirmed, the same form rewrites the role');
    }

    /** An action asked for by the panel's JavaScript is told in a shape it can read, and the code is the one the screen branches on. */
    public function test_an_ajax_refusal_names_the_step_up_rather_than_a_bare_failure(): void
    {
        $this->switchItOn();

        $this->signedInAsTheAsker()
            ->withHeaders($this->asAjax())
            ->post('/admin/custom-role/employee-role-status', ['id' => self::ORDINARY_ROLE, 'status' => 0])
            ->assertForbidden()
            ->assertJsonPath('code', 'step_up_required');
    }

    public function test_a_wrong_password_writes_nothing(): void
    {
        $this->switchItOn();
        $before = $this->employee();

        $this->signedInAsTheAsker()
            ->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm() + [AdminStepUp::PASSWORD_FIELD => self::WRONG_PASSWORD])
            ->assertRedirect();

        $this->assertSame($before->email, $this->employee()->email);
        $this->assertNull(session(AdminStepUp::SESSION_KEY), 'a refused proof stamps nothing');
    }

    // ---- what a proof is worth, and for how long --------------------------------------------------------------------------

    public function test_one_proof_serves_the_actions_that_follow_it_inside_the_window(): void
    {
        $this->switchItOn();
        $asked = $this->signedInAsTheAsker();

        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + $this->theirOwnPassword())->assertRedirect();
        $this->assertSame(0, (int) $this->employee()->status);

        $this->travel(9)->minutes();
        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 1])->assertRedirect();
        $this->assertSame(1, (int) $this->employee()->status, 'a proof minutes old is still a proof');
    }

    public function test_a_proof_stops_being_one_once_the_window_has_passed(): void
    {
        $this->switchItOn();
        $asked = $this->signedInAsTheAsker();

        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + $this->theirOwnPassword())->assertRedirect();

        $this->travel(11)->minutes();
        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 1])->assertRedirect();
        $this->assertSame(0, (int) $this->employee()->status, 'a proof older than the window is not a proof');
    }

    /**
     * A window of zero would ask for the password on every request, which is not a security property but a way of making the screen
     * unusable, so the service reads it as one minute. The figure is pinned because the config note promises it.
     */
    public function test_a_window_of_zero_is_read_as_one_minute(): void
    {
        $this->switchItOn(minutes: 0);
        $asked = $this->signedInAsTheAsker();

        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + $this->theirOwnPassword())->assertRedirect();
        $this->assertSame(0, (int) $this->employee()->status);

        $this->travel(2)->minutes();
        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 1])->assertRedirect();
        $this->assertSame(0, (int) $this->employee()->status, 'one minute, and not for ever');
    }

    /** An account that signs out and another that signs in on the same session inherits nothing: the stamp records whose it is. */
    public function test_a_proof_made_by_another_account_is_not_inherited(): void
    {
        $this->switchItOn();
        DB::table('admins')->insert(['id' => 7, 'name' => 'Second master', 'email' => 'second@example.test', 'admin_role_id' => self::MASTER_ROLE, 'status' => 1, 'password' => bcrypt(self::OWN_PASSWORD), 'phone' => '0500000007']);

        $this->signedInAs(7)->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + $this->theirOwnPassword())->assertRedirect();
        $this->assertSame(0, (int) $this->employee()->status);

        $this->signedInAsTheAsker()->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 1])->assertRedirect();
        $this->assertSame(0, (int) $this->employee()->status, "another account's proof is not this account's");
    }

    /** A clock moved backwards, or a stamp written by anything but this service, must not be fresh until the clock catches up. */
    public function test_a_stamp_in_the_future_is_not_a_proof(): void
    {
        $this->switchItOn();

        $this->signedInAsTheAsker()
            ->withSession([AdminStepUp::SESSION_KEY => ['admin' => self::ASKER, 'at' => now()->addHour()->getTimestamp()]])
            ->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0])
            ->assertRedirect();

        $this->assertSame(1, (int) $this->employee()->status);
    }

    public function test_a_stamp_the_service_did_not_write_is_not_a_proof(): void
    {
        $this->switchItOn();

        foreach ([['at' => now()->getTimestamp()], ['admin' => self::ASKER], 'yes', ['admin' => 'x', 'at' => 'y']] as $nonsense) {
            $this->signedInAsTheAsker()
                ->withSession([AdminStepUp::SESSION_KEY => $nonsense])
                ->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0])
                ->assertRedirect();

            $this->assertSame(1, (int) $this->employee()->status, 'a stamp of the wrong shape proves nothing');
        }
    }

    /**
     * An employee row whose password is empty would otherwise be confirmable by anybody typing anything, because a hash check against an
     * empty string is a refusal only by accident of the driver.
     */
    public function test_an_account_with_no_password_stored_cannot_confirm(): void
    {
        $this->switchItOn();
        DB::table('admins')->where('id', self::ASKER)->update(['password' => '']);

        $this->signedInAsTheAsker()
            ->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + [AdminStepUp::PASSWORD_FIELD => ''])
            ->assertRedirect();
        $this->assertSame(1, (int) $this->employee()->status);

        $this->signedInAsTheAsker()
            ->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + $this->theirOwnPassword())
            ->assertRedirect();
        $this->assertSame(1, (int) $this->employee()->status, 'nothing a person can type confirms an account with no password');
    }

    /** Guessing at a step-up is guessing at the password, so a burst of wrong ones stops the right one being read. */
    public function test_guessing_is_limited_and_the_limit_outlasts_the_right_password(): void
    {
        $this->switchItOn();
        $asked = $this->signedInAsTheAsker();

        for ($try = 0; $try < 5; $try++) {
            $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + [AdminStepUp::PASSWORD_FIELD => self::WRONG_PASSWORD])->assertRedirect();
        }

        $asked->post('/admin/employee/status', ['id' => self::EMPLOYEE, 'status' => 0] + $this->theirOwnPassword())->assertRedirect();
        $this->assertSame(1, (int) $this->employee()->status, 'the right password is not read while the limit holds');
    }

    // ---- the screens that only show a form are not asked for a password ---------------------------------------------------

    public function test_the_forms_and_the_lists_still_open_with_the_switch_on(): void
    {
        $this->switchItOn();
        $asked = $this->signedInAsTheAsker();

        $asked->get('/admin/employee/list')->assertOk();
        $asked->get('/admin/employee/add')->assertOk();
        $asked->get('/admin/employee/update/' . self::EMPLOYEE)->assertOk();
        $asked->get('/admin/custom-role/add')->assertOk();
        $asked->get('/admin/custom-role/update/' . self::ORDINARY_ROLE)->assertOk();
    }

    // ---- the screens, which is where the proof is typed --------------------------------------------------------------------

    /** On, every form the guard protects carries the field the guard reads, inside the form whose button it protects. */
    public function test_with_the_switch_on_each_staff_and_role_form_carries_the_field(): void
    {
        $this->switchItOn();

        foreach (self::FORMS as $screen => $action) {
            $this->assertContains(
                AdminStepUp::PASSWORD_FIELD,
                $this->fieldNamesOfTheFormPosting(page: $this->screen($screen), action: $action),
                "the form of {$screen} carries the field its own middleware reads, or the action is always refused",
            );
        }
    }

    /**
     * Staff management end to end: the screen is rendered, the field it carries is read off it, and only then is the action driven -- so a
     * screen that lost the field fails before any password is sent. Each refusal has its unrestricted twin, because a refusal on its own
     * cannot tell a guard from a broken screen or a rejected form.
     */
    public function test_the_field_the_employee_screens_carry_is_what_takes_those_actions_through(): void
    {
        $this->switchItOn();
        $asked = $this->signedInAsTheAsker();

        $field = $this->theConfirmationFieldOf(screen: '/admin/employee/add', action: 'employee/add');
        $asked->post('/admin/employee/add', $this->aNewEmployeeForm())->assertRedirect();
        $this->assertFalse(DB::table('admins')->where('email', 'new@example.test')->exists(), 'an unconfirmed add writes nothing');
        $asked->post('/admin/employee/add', $this->aNewEmployeeForm() + [$field => self::OWN_PASSWORD])->assertRedirect();
        $this->assertTrue(DB::table('admins')->where('email', 'new@example.test')->exists(), 'the field the screen carries adds the employee');

        $this->theProofGoesStale();

        $field = $this->theConfirmationFieldOf(screen: '/admin/employee/update/' . self::EMPLOYEE, action: 'employee/update/' . self::EMPLOYEE);
        $asked->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm())->assertRedirect();
        $this->assertNotSame('moved@example.test', $this->employee()->email, 'an unconfirmed employee form writes nothing');
        $asked->post('/admin/employee/update/' . self::EMPLOYEE, $this->anEmployeeForm() + [$field => self::OWN_PASSWORD])->assertRedirect();
        $this->assertSame('moved@example.test', $this->employee()->email, 'the field the screen carries rewrites the employee');
    }

    /** The role form end to end, read off its own screens, each refusal with its unrestricted twin. */
    public function test_the_field_the_role_screens_carry_is_what_takes_those_actions_through(): void
    {
        $this->switchItOn();
        $asked = $this->signedInAsTheAsker();

        $field = $this->theConfirmationFieldOf(screen: '/admin/custom-role/add', action: 'custom-role/add');
        $asked->post('/admin/custom-role/add', ['name' => 'A new role', 'modules' => ['people']])->assertRedirect();
        $this->assertFalse(DB::table('admin_roles')->where('name', 'A new role')->exists(), 'an unconfirmed add writes nothing');
        $asked->post('/admin/custom-role/add', ['name' => 'A new role', 'modules' => ['people'], $field => self::OWN_PASSWORD])->assertRedirect();
        $this->assertTrue(DB::table('admin_roles')->where('name', 'A new role')->exists(), 'the field the screen carries adds the role');

        $this->theProofGoesStale();

        $field = $this->theConfirmationFieldOf(screen: '/admin/custom-role/update/' . self::ORDINARY_ROLE, action: 'custom-role/update/' . self::ORDINARY_ROLE);
        $asked->post('/admin/custom-role/update/' . self::ORDINARY_ROLE, ['name' => 'Renamed', 'modules' => ['people']])->assertRedirect();
        $this->assertNotSame('Renamed', $this->role()->name, 'an unconfirmed role form writes nothing');
        $asked->post('/admin/custom-role/update/' . self::ORDINARY_ROLE, ['name' => 'Renamed', 'modules' => ['people'], $field => self::OWN_PASSWORD])->assertRedirect();
        $this->assertSame('Renamed', $this->role()->name, 'the field the screen carries rewrites the role');
    }

    /**
     * The guard is a route declaration, so the set of routes carrying it is the feature. Written out rather than matched by prefix, so a
     * write route that loses it, or a read route that gains it, fails here.
     */
    public function test_the_guard_is_declared_on_the_writes_and_on_nothing_else(): void
    {
        $guarded = [
            ['POST', 'admin/employee/add'],
            ['POST', 'admin/employee/update/3'],
            ['POST', 'admin/employee/status'],
            ['POST', 'admin/custom-role/add'],
            ['POST', 'admin/custom-role/update/3'],
            ['POST', 'admin/custom-role/employee-role-status'],
            ['POST', 'admin/custom-role/delete'],
            ['POST', 'admin/system-setup/db-clean'],
        ];
        $open = [
            ['GET', 'admin/employee/list'],
            ['GET', 'admin/employee/add'],
            ['GET', 'admin/employee/update/3'],
            ['GET', 'admin/employee/export'],
            ['GET', 'admin/custom-role/add'],
            ['GET', 'admin/custom-role/update/3'],
            ['GET', 'admin/custom-role/export'],
        ];

        foreach ($guarded as [$method, $uri]) {
            $this->assertContains('admin_step_up', $this->declaredMiddleware(route: $this->routeFor(method: $method, uri: $uri)), "{$method} {$uri} must ask the employee to confirm");
        }

        foreach ($open as [$method, $uri]) {
            $this->assertNotContains('admin_step_up', $this->declaredMiddleware(route: $this->routeFor(method: $method, uri: $uri)), "{$method} {$uri} shows a screen and must not ask for a password");
        }
    }

    // ---- what the tests read and send ------------------------------------------------------------------------------------

    private function switchItOn(int $minutes = 10): void
    {
        config()->set('gcc.staff.step_up', ['enabled' => true, 'minutes' => $minutes, 'attempts_per_minute' => 5]);
    }

    private function signedInAsTheAsker(): static
    {
        return $this->signedInAs(self::ASKER);
    }

    private function signedInAs(int $id): static
    {
        return $this->actingAs(Admin::findOrFail($id), 'admin');
    }

    /** @return array<string, string> */
    private function theirOwnPassword(): array
    {
        return [AdminStepUp::PASSWORD_FIELD => self::OWN_PASSWORD];
    }

    /** @return array<string, string> */
    private function asAjax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    /** The field a screen really carries, read off the rendered form: an action is driven with it only once the screen has been proved. */
    private function theConfirmationFieldOf(string $screen, string $action): string
    {
        $this->assertContains(
            AdminStepUp::PASSWORD_FIELD,
            $this->fieldNamesOfTheFormPosting(page: $this->screen($screen), action: $action),
            "{$screen} must carry the field its own middleware reads, or the action below is refused however right the password is",
        );

        return AdminStepUp::PASSWORD_FIELD;
    }

    /**
     * The names of the inputs of one form of a rendered screen, scoped to that form: a field in the NEXT form on the page -- the role
     * list's status switches sit on the role screen beside its create form -- is not this form's field.
     *
     * @return list<string>
     */
    private function fieldNamesOfTheFormPosting(string $page, string $action): array
    {
        $opening = '#<form[^>]*action="[^"]*' . preg_quote($action, '#') . '"[^>]*>#';

        $this->assertSame(1, preg_match($opening, $page, $matched, PREG_OFFSET_CAPTURE), "the screen shows a form posting to {$action}");
        $body = $this->upToTheEndOfTheForm(page: $page, from: $matched[0][1] + strlen((string) $matched[0][0]));
        preg_match_all('#<input[^>]*\bname="([^"]+)"#', $body, $names);

        return $names[1];
    }

    /**
     * The form's own markup and no more. Cut with a plain search rather than with a `(?:(?!</form>).)*` pattern, which PCRE's JIT gives up
     * on -- `preg_match` returns false, not 0 -- over a form the size of the role form's permission grid. Measured on this screen.
     */
    private function upToTheEndOfTheForm(string $page, int $from): string
    {
        $closing = strpos($page, '</form>', $from);

        $this->assertNotFalse($closing, 'the form is closed');

        return substr($page, $from, $closing - $from);
    }

    /** A proof lasts the window, so the pair that follows is asked again rather than carried by the proof of the pair before it. */
    private function theProofGoesStale(): void
    {
        $this->travel(app(AdminStepUp::class)->minutes() + 1)->minutes();
    }

    private function screen(string $uri): string
    {
        return (string) $this->signedInAsTheAsker()->get($uri)->assertOk()->getContent();
    }

    private function employee(): object
    {
        return DB::table('admins')->where('id', self::EMPLOYEE)->first();
    }

    private function role(): object
    {
        return DB::table('admin_roles')->where('id', self::ORDINARY_ROLE)->first();
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

    /** @return array<string, mixed> */
    private function aNewEmployeeForm(): array
    {
        return ['email' => 'new@example.test', 'image' => UploadedFile::fake()->image('face.png')] + $this->anEmployeeForm();
    }
}
