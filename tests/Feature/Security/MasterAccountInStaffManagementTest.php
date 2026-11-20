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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * A master account is not an employee for staff management to rewrite, and the master role is not a role to manage.
 *
 * Staff management refused to hand out the master role and never asked whether the account it was about to rewrite or switch
 * off already WAS a master account. Its two lists leave the first admin and the master role out, so no screen ever offered
 * them; a request that named them anyway was carried out. Whoever holds the permission of staff management could set the
 * password of the first admin, change its e-mail, or switch it off. Each test below fails on the code before the fix.
 *
 * The id is read where the application reads it. `$request['id']` prefers a value in the body over the one in the address, so
 * a request addressed to an ordinary employee and carrying another id in its body is a second spelling of the same request,
 * and it is driven here as well.
 *
 * An account's own profile (the routes that take an id from the address) is a separate change with tests of its own.
 */
class MasterAccountInStaffManagementTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const MASTER = 1;

    private const INTEGRATIONS_EMPLOYEE = 3;

    /** An employee whose role holds staff management and nothing else. */
    private const PEOPLE_EMPLOYEE = 9;

    private const PEOPLE_ROLE = 9;

    private const MASTER_ROLE = 1;

    private const OWN_PASSWORD = 'Their#Own1Password';

    private const CHOSEN_PASSWORD = 'Chosen#By1Somebody';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        Schema::table('admins', function (Blueprint $table): void {
            foreach (['phone', 'password', 'image', 'identify_type', 'identify_number'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('identify_image')->nullable();
        });
        DB::table('admin_roles')->insert(['id' => self::PEOPLE_ROLE, 'name' => 'People only', 'module_access' => json_encode(['people']), 'status' => 1]);
        DB::table('admins')->insert(['id' => self::PEOPLE_EMPLOYEE, 'name' => 'People employee', 'email' => 'people@example.test', 'admin_role_id' => self::PEOPLE_ROLE]);
        DB::table('admins')->update(['password' => bcrypt(self::OWN_PASSWORD), 'phone' => '0500000000']);

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();
    }

    // ---- staff management: a master account is not an employee to manage ------------------------------------------------

    public function test_staff_management_does_not_rewrite_the_first_admin(): void
    {
        $before = $this->everyAccount();

        $this->signedInAs(self::PEOPLE_EMPLOYEE)->post('/admin/employee/update/' . self::MASTER, $this->anEmployeeForm())->assertRedirect();

        $this->assertSame($before, $this->everyAccount());
        $this->assertSame(1, DB::table('admins')->where('admin_role_id', self::MASTER_ROLE)->count(), 'the platform must never be left with no master account');
    }

    public function test_an_id_in_the_body_is_refused_as_surely_as_an_id_in_the_address(): void
    {
        $before = $this->everyAccount();

        $this->signedInAs(self::PEOPLE_EMPLOYEE)
            ->post('/admin/employee/update/' . self::INTEGRATIONS_EMPLOYEE, ['id' => self::MASTER] + $this->anEmployeeForm())
            ->assertRedirect();

        $this->assertSame($before, $this->everyAccount());
    }

    public function test_staff_management_does_not_switch_the_first_admin_off_whichever_way_it_is_asked(): void
    {
        $asked = $this->signedInAs(self::PEOPLE_EMPLOYEE);

        $asked->post('/admin/employee/status', ['id' => self::MASTER, 'status' => 0])->assertRedirect();
        $this->assertSame(1, (int) $this->account(self::MASTER)->status);

        $asked->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->post('/admin/employee/status', ['id' => self::MASTER, 'status' => 0])->assertForbidden();
        $this->assertSame(1, (int) $this->account(self::MASTER)->status);
    }

    public function test_a_second_account_holding_the_master_role_is_no_more_manageable_than_the_first(): void
    {
        DB::table('admins')->insert(['id' => 7, 'name' => 'Second master', 'email' => 'second@example.test', 'admin_role_id' => self::MASTER_ROLE, 'password' => bcrypt(self::OWN_PASSWORD), 'phone' => '0500000000']);
        $before = $this->everyAccount();
        $asked = $this->signedInAs(self::PEOPLE_EMPLOYEE);

        $asked->post('/admin/employee/update/7', $this->anEmployeeForm())->assertRedirect();
        $asked->post('/admin/employee/status', ['id' => 7, 'status' => 0])->assertRedirect();
        $asked->get('/admin/employee/update/7')->assertRedirect();

        $this->assertSame($before, $this->everyAccount());
    }

    /** Such an account must always be removable, and by one account only: otherwise whoever obtains the master role keeps it. */
    public function test_the_first_admin_alone_switches_off_and_demotes_another_account_that_holds_the_master_role(): void
    {
        DB::table('admins')->insert(['id' => 7, 'name' => 'Second master', 'email' => 'second@example.test', 'admin_role_id' => self::MASTER_ROLE, 'password' => bcrypt(self::OWN_PASSWORD), 'phone' => '0500000007', 'status' => 1]);
        DB::table('admins')->insert(['id' => 8, 'name' => 'Third master', 'email' => 'third@example.test', 'admin_role_id' => self::MASTER_ROLE, 'password' => bcrypt(self::OWN_PASSWORD), 'phone' => '0500000008', 'status' => 1]);

        $before = $this->everyAccount();
        $this->signedInAs(8)->post('/admin/employee/status', ['id' => 7, 'status' => 0])->assertRedirect();
        $this->signedInAs(8)->post('/admin/employee/update/7', $this->anEmployeeForm())->assertRedirect();
        $this->assertSame($before, $this->everyAccount(), 'an account holding the master role does not manage its equals');

        $this->signedInAs(self::MASTER)->post('/admin/employee/status', ['id' => 7, 'status' => 0])->assertRedirect();
        $this->signedInAs(self::MASTER)->post('/admin/employee/update/7', $this->anEmployeeForm())->assertRedirect();

        $second = $this->account(7);
        $this->assertSame(0, (int) $second->status);
        $this->assertSame(self::PEOPLE_ROLE, (int) $second->admin_role_id);
        $this->assertSame($this->everyAccountBut(7), array_diff_key($before, [7 => true]), 'and nobody else was touched');
    }

    /** The first admin is the account with id 1, whatever role its row carries. */
    public function test_the_first_admin_is_out_of_reach_even_when_its_row_carries_another_role(): void
    {
        DB::table('admins')->where('id', self::MASTER)->update(['admin_role_id' => self::PEOPLE_ROLE]);
        $before = $this->everyAccount();
        $asked = $this->signedInAs(self::PEOPLE_EMPLOYEE);

        $asked->post('/admin/employee/update/' . self::MASTER, $this->anEmployeeForm())->assertRedirect();
        $asked->post('/admin/employee/status', ['id' => self::MASTER, 'status' => 0])->assertRedirect();

        $this->assertSame($before, $this->everyAccount());
    }

    public function test_the_record_of_a_master_account_is_not_shown_by_staff_management(): void
    {
        DB::table('admins')->insert(['id' => 7, 'name' => 'Second master', 'email' => 'second@example.test', 'admin_role_id' => self::MASTER_ROLE, 'password' => bcrypt(self::OWN_PASSWORD), 'phone' => '0500000007', 'status' => 1]);
        $asked = $this->signedInAs(self::PEOPLE_EMPLOYEE);

        $asked->get('/admin/employee/view/' . self::MASTER)->assertRedirect(route('admin.employee.list'));
        $asked->get('/admin/employee/view/7')->assertRedirect(route('admin.employee.list'));
        $asked->get('/admin/employee/update/7')->assertRedirect(route('admin.employee.list'));
    }

    public function test_the_first_admin_does_not_manage_a_master_account_through_staff_management_either(): void
    {
        $before = $this->everyAccount();

        $this->signedInAs(self::MASTER)->post('/admin/employee/update/' . self::MASTER, $this->anEmployeeForm())->assertRedirect();

        $this->assertSame($before, $this->everyAccount(), 'a master account is changed through its own profile, never through staff management');
    }

    public function test_staff_management_still_rewrites_and_switches_off_an_ordinary_employee(): void
    {
        $others = $this->everyAccountBut(self::INTEGRATIONS_EMPLOYEE);
        $asked = $this->signedInAs(self::PEOPLE_EMPLOYEE);

        $asked->post('/admin/employee/update/' . self::INTEGRATIONS_EMPLOYEE, $this->anEmployeeForm())->assertRedirect();
        $asked->post('/admin/employee/status', ['id' => self::INTEGRATIONS_EMPLOYEE, 'status' => 0])->assertRedirect();

        $employee = $this->account(self::INTEGRATIONS_EMPLOYEE);
        $this->assertSame(['moved@example.test', self::PEOPLE_ROLE, 0], [$employee->email, (int) $employee->admin_role_id, (int) $employee->status]);
        $this->assertTrue(Hash::check(self::CHOSEN_PASSWORD, $employee->password));
        $this->assertSame($others, $this->everyAccountBut(self::INTEGRATIONS_EMPLOYEE));
    }

    public function test_staff_management_still_refuses_to_hand_out_the_master_role(): void
    {
        $before = $this->everyAccount();

        $this->signedInAs(self::PEOPLE_EMPLOYEE)
            ->post('/admin/employee/update/' . self::INTEGRATIONS_EMPLOYEE, ['role_id' => self::MASTER_ROLE] + $this->anEmployeeForm())
            ->assertRedirect();

        $this->assertSame($before, $this->everyAccount());
    }

    /**
     * @return array<string, array{mixed}> spellings of a role that PHP does not read as 1 and that MySQL, which the application
     *         runs without strict mode, stores as 1
     */
    public static function aMasterRoleInDisguise(): array
    {
        return [
            'a number followed by letters' => ['1abc'],
            'a fraction that rounds up to it' => ['0.6'],
            'a fraction that rounds down to it' => ['1.4'],
            'a half' => ['0.5'],
            'a list holding it' => [[1]],
        ];
    }

    #[DataProvider('aMasterRoleInDisguise')]
    public function test_the_master_role_is_not_handed_out_under_another_spelling_to_an_existing_employee(mixed $spelling): void
    {
        $before = $this->everyAccount();

        $this->signedInAs(self::PEOPLE_EMPLOYEE)
            ->post('/admin/employee/update/' . self::INTEGRATIONS_EMPLOYEE, ['role_id' => $spelling] + $this->anEmployeeForm())
            ->assertRedirect();

        $this->assertSame($before, $this->everyAccount(), 'a role that is not a plain role id must change no account');
    }

    #[DataProvider('aMasterRoleInDisguise')]
    public function test_the_master_role_is_not_handed_out_under_another_spelling_to_oneself(mixed $spelling): void
    {
        $before = $this->everyAccount();

        $this->signedInAs(self::PEOPLE_EMPLOYEE)
            ->post('/admin/employee/update/' . self::PEOPLE_EMPLOYEE, ['role_id' => $spelling] + $this->anEmployeeForm())
            ->assertRedirect();

        $this->assertSame($before, $this->everyAccount());
    }

    #[DataProvider('aMasterRoleInDisguise')]
    public function test_no_new_employee_is_created_with_the_master_role_under_another_spelling(mixed $spelling): void
    {
        $before = $this->everyAccount();

        $this->signedInAs(self::PEOPLE_EMPLOYEE)
            ->post('/admin/employee/add', ['role_id' => $spelling, 'email' => 'new@example.test', 'image' => UploadedFile::fake()->image('face.png')] + $this->anEmployeeForm())
            ->assertRedirect();

        $this->assertSame($before, $this->everyAccount(), 'no account may be created by a request whose role is not a plain role id');
    }

    public function test_an_ordinary_role_is_stored_as_the_number_it_is(): void
    {
        $this->signedInAs(self::PEOPLE_EMPLOYEE)
            ->post('/admin/employee/update/' . self::INTEGRATIONS_EMPLOYEE, ['role_id' => (string) self::PEOPLE_ROLE] + $this->anEmployeeForm())
            ->assertRedirect();

        $this->assertSame(self::PEOPLE_ROLE, (int) $this->account(self::INTEGRATIONS_EMPLOYEE)->admin_role_id);
    }

    // ---- the master role is not a role to manage ---------------------------------------------------------------------------

    public function test_the_master_role_is_not_rewritten_switched_off_or_deleted(): void
    {
        $before = $this->everyRole();
        $asked = $this->signedInAs(self::PEOPLE_EMPLOYEE);

        $asked->post('/admin/custom-role/update/' . self::MASTER_ROLE, ['name' => 'Renamed', 'modules' => ['people']])->assertRedirect();
        $asked->post('/admin/custom-role/employee-role-status', ['id' => self::MASTER_ROLE, 'status' => 0])->assertForbidden();
        $asked->post('/admin/custom-role/delete', ['id' => self::MASTER_ROLE])->assertForbidden();
        $asked->get('/admin/custom-role/update/' . self::MASTER_ROLE)->assertRedirect();

        $this->assertSame($before, $this->everyRole());
    }

    /** The role is the one the request will write to: an id in the body is read before the one in the address. */
    public function test_the_master_role_is_not_rewritten_through_the_address_of_another_role(): void
    {
        $before = $this->everyRole();

        $this->signedInAs(self::PEOPLE_EMPLOYEE)
            ->post('/admin/custom-role/update/' . self::PEOPLE_ROLE, ['id' => self::MASTER_ROLE, 'name' => 'Renamed', 'modules' => ['people']])
            ->assertRedirect();

        $this->assertSame($before, $this->everyRole());
    }

    #[DataProvider('aMasterRoleInDisguise')]
    public function test_the_master_role_is_not_switched_off_or_deleted_under_another_spelling(mixed $spelling): void
    {
        $before = $this->everyRole();
        $asked = $this->signedInAs(self::PEOPLE_EMPLOYEE);

        $asked->post('/admin/custom-role/employee-role-status', ['id' => $spelling, 'status' => 0]);
        $asked->post('/admin/custom-role/delete', ['id' => $spelling]);

        $this->assertSame($before, $this->everyRole(), 'no spelling of a role id may reach the master role');
    }

    public function test_an_ordinary_role_is_still_switched_off_and_deleted(): void
    {
        $asked = $this->signedInAs(self::PEOPLE_EMPLOYEE);

        $asked->post('/admin/custom-role/employee-role-status', ['id' => 3, 'status' => 0])->assertOk();
        $this->assertSame(0, (int) DB::table('admin_roles')->where('id', 3)->value('status'));

        $asked->post('/admin/custom-role/delete', ['id' => 3])->assertOk();
        $this->assertFalse(DB::table('admin_roles')->where('id', 3)->exists());
    }

    // ---- what the tests read and send ------------------------------------------------------------------------------------

    private function signedInAs(int $id): static
    {
        return $this->actingAs(Admin::findOrFail($id), 'admin');
    }

    private function account(int $id): object
    {
        return DB::table('admins')->where('id', $id)->first();
    }

    /** @return array<int, array<string, mixed>> every admin row but its timestamps, by id, so that a change to any account is seen */
    private function everyAccount(): array
    {
        return DB::table('admins')->orderBy('id')->get()
            ->mapWithKeys(static fn (object $row): array => [$row->id => array_diff_key((array) $row, ['created_at' => true, 'updated_at' => true])])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function everyAccountBut(int $id): array
    {
        return array_diff_key($this->everyAccount(), [$id => true]);
    }

    /** @return array<int, array<string, mixed>> */
    private function everyRole(): array
    {
        return DB::table('admin_roles')->orderBy('id')->get()
            ->mapWithKeys(static fn (object $row): array => [$row->id => array_diff_key((array) $row, ['created_at' => true, 'updated_at' => true])])
            ->all();
    }

    /** @return array{password: string, confirm_password: string} */
    private function aNewPassword(): array
    {
        return ['password' => self::CHOSEN_PASSWORD, 'confirm_password' => self::CHOSEN_PASSWORD];
    }

    /** @return array<string, mixed> what the employee form posts, with a password and a role that staff management may hand out */
    private function anEmployeeForm(): array
    {
        return [
            'name' => 'Renamed', 'phone' => '0599999999', 'email' => 'moved@example.test', 'role_id' => self::PEOPLE_ROLE,
            'identify_type' => 'nid', 'identify_number' => '1',
        ] + $this->aNewPassword();
    }
}
