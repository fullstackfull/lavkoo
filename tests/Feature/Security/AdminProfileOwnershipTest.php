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
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The admin profile routes act on the signed-in admin, never on the {id} in the URL.
 *
 * Admin\ProfileController used to load and update whichever admin the URL named, and its routes carry only the `admin` sign-in guard,
 * no module permission. An employee holding a single module could PATCH /admin/profile/update/1 with a password of their choosing, or
 * POST it with an e-mail address of their choosing, and then sign in as the master admin. Vendor\ProfileController was fixed the same
 * way earlier; this pins the admin twin.
 *
 * Administrators (BuildsFulfillmentSchema): 1 master admin, 2 employee holding only the `catalog` module, 3 another employee.
 *
 * translate() writes a key no language file defines into a tracked file, so tearDown() puts the language files back whatever a test
 * did; test_no_profile_flow_writes_a_language_file() is the one that fails when a flow still depends on such a write.
 */
class AdminProfileOwnershipTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const MASTER = 1;
    private const EMPLOYEE = 2;
    private const COLLEAGUE = 3;

    private const EMAILS = [
        self::MASTER => 'master@example.test',
        self::EMPLOYEE => 'employee@example.test',
        self::COLLEAGUE => 'colleague@example.test',
    ];

    private const PASSWORDS = [
        self::MASTER => 'Master-original1!',
        self::EMPLOYEE => 'Clerk-original1!',
        self::COLLEAGUE => 'Colleague-original1!',
    ];

    private const CHOSEN_PASSWORD = 'Owned-by-clerk1!';
    private const ATTACKER_EMAIL = 'attacker-controlled@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();

        $this->buildCoreSchema();
        Schema::table(table: 'admins', callback: static function (Blueprint $table): void {
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
        });
        foreach (self::PASSWORDS as $adminId => $password) {
            DB::table('admins')->where('id', $adminId)->update(['email' => self::EMAILS[$adminId], 'phone' => '050000000' . $adminId, 'password' => Hash::make($password)]);
        }
        Cache::flush();
        Storage::fake('public');

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class,
            NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function signInAs(int $adminId): static
    {
        return $this->actingAs(Admin::findOrFail($adminId), 'admin');
    }

    /** @return array<int, array<string, mixed>> every admin row by id, to prove what a request did and did not touch */
    private function adminRows(): array
    {
        return DB::table('admins')->orderBy('id')->get()->keyBy('id')->map(static fn (object $row): array => (array) $row)->all();
    }

    private function assertNoRowChangedExcept(int $adminId, array $before): void
    {
        $after = $this->adminRows();
        unset($before[$adminId], $after[$adminId]);

        $this->assertSame($before, $after, 'a request from admin #' . $adminId . ' changed another admin');
    }

    /** @return string the avatar's file name, stored under admin/ on the public disk and recorded on the admin's row */
    private function giveAvatar(int $adminId): string
    {
        $fileName = 'avatar-' . $adminId . '.png';
        Storage::disk('public')->put('admin/' . $fileName, 'avatar');
        DB::table('admins')->where('id', $adminId)->update(['image' => $fileName]);

        return $fileName;
    }

    private function passwordIs(int $adminId, string $password): bool
    {
        return Hash::check($password, (string) DB::table('admins')->where('id', $adminId)->value('password'));
    }

    /** @return list<string> the kinds of toast (success, error...) the last response asked the next page to show */
    private function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }

    /** @return array<string, string> */
    private function digestsOfLanguageFiles(): array
    {
        $digests = [];
        foreach (glob(base_path('resources/lang/*/*.php')) ?: [] as $file) {
            $digests[$file] = md5_file($file);
        }

        return $digests;
    }

    public function test_an_employee_cannot_set_the_master_admins_password(): void
    {
        $before = $this->adminRows();
        $this->signInAs(self::EMPLOYEE);

        $this->patch('/admin/profile/update/' . self::MASTER, ['password' => self::CHOSEN_PASSWORD, 'confirm_password' => self::CHOSEN_PASSWORD]);

        $this->assertFalse($this->passwordIs(self::MASTER, self::CHOSEN_PASSWORD), "the employee's password now opens the master admin's account");
        $this->assertTrue($this->passwordIs(self::MASTER, self::PASSWORDS[self::MASTER]));
        $this->assertSame($before[self::MASTER], $this->adminRows()[self::MASTER]);
    }

    public function test_an_employee_cannot_change_the_master_admins_name_email_or_phone(): void
    {
        $before = $this->adminRows();
        $this->signInAs(self::EMPLOYEE);

        $this->post('/admin/profile/update/' . self::MASTER, ['name' => 'Taken over', 'email' => self::ATTACKER_EMAIL, 'phone' => '0500000009']);

        $master = $this->adminRows()[self::MASTER];
        $this->assertSame(self::EMAILS[self::MASTER], $master['email'], 'the master admin signs in with this address');
        $this->assertSame($before[self::MASTER], $master);
    }

    public function test_an_employee_cannot_replace_the_master_admins_avatar(): void
    {
        $masterAvatar = $this->giveAvatar(self::MASTER);
        $before = $this->adminRows();
        $this->signInAs(self::EMPLOYEE);

        $this->post('/admin/profile/update/' . self::MASTER, [
            'name' => 'Taken over', 'email' => self::ATTACKER_EMAIL, 'phone' => '0500000009', 'image' => UploadedFile::fake()->image('attacker.png'),
        ]);

        Storage::disk('public')->assertExists('admin/' . $masterAvatar);
        $this->assertSame($before[self::MASTER], $this->adminRows()[self::MASTER]);
    }

    public function test_a_request_naming_another_admin_changes_no_row_but_the_signed_in_admins(): void
    {
        foreach ([self::EMPLOYEE => [self::MASTER, self::COLLEAGUE], self::MASTER => [self::EMPLOYEE, self::COLLEAGUE]] as $signedInId => $targetIds) {
            $this->signInAs($signedInId);

            foreach ($targetIds as $targetId) {
                $before = $this->adminRows();

                $this->post('/admin/profile/update/' . $targetId, ['name' => 'Renamed', 'email' => self::ATTACKER_EMAIL, 'phone' => '0500000009']);
                $this->patch('/admin/profile/update/' . $targetId, ['password' => self::CHOSEN_PASSWORD, 'confirm_password' => self::CHOSEN_PASSWORD]);

                $this->assertNoRowChangedExcept(adminId: $signedInId, before: $before);
            }
        }
    }

    public function test_an_admin_is_not_shown_another_admins_profile(): void
    {
        foreach ([self::EMPLOYEE => self::MASTER, self::MASTER => self::EMPLOYEE] as $signedInId => $targetId) {
            $this->signInAs($signedInId);

            foreach (['', '?tab=password'] as $query) {
                ToastMagic::clear();

                $response = $this->get('/admin/profile/update/' . $targetId . $query);

                $response->assertRedirect(route('admin.dashboard.index'));
                $response->assertDontSee(self::EMAILS[$targetId]);
                $this->assertSame(['error'], $this->toastTypes(), "admin #{$signedInId} asking for admin #{$targetId}{$query}");
            }
        }
    }

    public function test_an_admin_updates_their_own_profile(): void
    {
        foreach ([self::EMPLOYEE, self::MASTER] as $adminId) {
            $before = $this->adminRows();
            $this->signInAs($adminId);

            $this->post('/admin/profile/update/' . $adminId, ['name' => 'Renamed ' . $adminId, 'email' => 'renamed' . $adminId . '@example.test', 'phone' => '055000000' . $adminId])
                ->assertRedirect();

            $after = $this->adminRows();
            $this->assertSame(
                ['name' => 'Renamed ' . $adminId, 'email' => 'renamed' . $adminId . '@example.test', 'phone' => '055000000' . $adminId],
                ['name' => $after[$adminId]['name'], 'email' => $after[$adminId]['email'], 'phone' => $after[$adminId]['phone']],
            );
            $this->assertSame($before[$adminId]['password'], $after[$adminId]['password'], 'a profile update must not touch the password');
            $this->assertNoRowChangedExcept(adminId: $adminId, before: $before);
        }
    }

    public function test_an_admin_replaces_their_own_avatar(): void
    {
        $masterAvatar = $this->giveAvatar(self::MASTER);
        $ownAvatar = $this->giveAvatar(self::EMPLOYEE);
        $this->signInAs(self::EMPLOYEE);

        $this->post('/admin/profile/update/' . self::EMPLOYEE, [
            'name' => 'Renamed', 'email' => 'renamed@example.test', 'phone' => '0550000002', 'image' => UploadedFile::fake()->image('new-avatar.png'),
        ])->assertRedirect();

        $newAvatar = (string) $this->adminRows()[self::EMPLOYEE]['image'];
        $this->assertNotSame($ownAvatar, $newAvatar);
        Storage::disk('public')->assertExists('admin/' . $newAvatar);
        Storage::disk('public')->assertMissing('admin/' . $ownAvatar);
        Storage::disk('public')->assertExists('admin/' . $masterAvatar);
    }

    public function test_an_admin_changes_their_own_password(): void
    {
        foreach ([self::EMPLOYEE, self::MASTER] as $adminId) {
            $before = $this->adminRows();
            $this->signInAs($adminId);

            $this->patch('/admin/profile/update/' . $adminId, ['password' => self::CHOSEN_PASSWORD, 'confirm_password' => self::CHOSEN_PASSWORD])
                ->assertRedirect();

            $this->assertTrue($this->passwordIs($adminId, self::CHOSEN_PASSWORD));
            $this->assertFalse($this->passwordIs($adminId, self::PASSWORDS[$adminId]));
            $this->assertSame($before[$adminId]['email'], $this->adminRows()[$adminId]['email'], 'a password change must not touch the e-mail address');
            $this->assertNoRowChangedExcept(adminId: $adminId, before: $before);
        }
    }

    public function test_the_profile_page_shows_the_signed_in_admin_and_posts_back_to_the_same_account(): void
    {
        foreach ([self::EMPLOYEE, self::MASTER] as $adminId) {
            $this->signInAs($adminId);
            $action = route('admin.profile.update', ['id' => $adminId]);

            $this->get('/admin/profile/update/' . $adminId)
                ->assertOk()
                ->assertSee(self::EMAILS[$adminId], false)
                ->assertSee('action="' . $action . '"', false);

            $this->get('/admin/profile/update/' . $adminId . '?tab=password')
                ->assertOk()
                ->assertSee('name="confirm_password"', false)
                ->assertSee('action="' . $action . '"', false);
        }
    }

    public function test_no_profile_flow_writes_a_language_file(): void
    {
        $digests = $this->digestsOfLanguageFiles();
        $this->signInAs(self::EMPLOYEE);

        $this->get('/admin/profile/update/' . self::EMPLOYEE)->assertOk();
        $this->get('/admin/profile/update/' . self::MASTER);
        $this->post('/admin/profile/update/' . self::EMPLOYEE, ['name' => 'Renamed', 'email' => 'renamed@example.test', 'phone' => '0550000002'])->assertRedirect();
        $this->patch('/admin/profile/update/' . self::EMPLOYEE, ['password' => self::CHOSEN_PASSWORD, 'confirm_password' => self::CHOSEN_PASSWORD])->assertRedirect();

        $this->assertSame([], array_keys(array_diff_assoc($this->digestsOfLanguageFiles(), $digests)), 'a profile flow made translate() write these files under resources/lang: define the key it asked for');
    }
}
