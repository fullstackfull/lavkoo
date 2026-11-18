<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Services\Storefront\StorefrontPermissionService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * The world of the storefront screens of the admin panel: the real routes, controllers, requests and templates, the real role check (ModulePermissionMiddleware stays on), a stand-in
 * admin shell (the real layout needs the installation database), and one admin for each way a role can hold the storefront permissions. Every test ends by checking that rendering wrote
 * no key into a language file: translate() appends a key that no file defines, so a page that shows an untranslated phrase would otherwise pass and leave the repository dirty.
 */
abstract class AdminStorefrontScreenTestCase extends TestCase
{
    use ApprovesStorefrontTax;
    use BuildsStorefrontLegalDocuments;

    /** The admins of the world, by what their role holds. */
    protected const MASTER = 1;

    protected const LIFECYCLE_ADMIN = 21;

    protected const LEGAL_WRITER = 22;

    protected const LEGAL_APPROVER = 23;

    protected const CATALOG_ADMIN = 24;

    protected const ADMIN_OF_A_SWITCHED_OFF_ROLE = 25;

    protected const OTHER_LEGAL_WRITER = 26;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstWrittenLanguageFiles();
        $this->buildTheAdmins();
        $this->buildStorefrontLegalDocumentsTable();
        $this->buildTheTablesOfAStorefrontDecision(settingPrefix: 'storefront_');
        DB::table('audit_logs')->delete();
        DB::table('business_settings')->where('type', 'like', 'storefront_%')->delete();
        clearWebConfigCacheKeys();

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        ToastMagic::clear();

        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        $this->signInAs(self::MASTER);
    }

    /** The language files are digested now and compared again before the application is destroyed: a key that nobody defined fails the test that reached it instead of being written quietly. */
    private function guardAgainstWrittenLanguageFiles(): void
    {
        $digestsBefore = $this->digestsOfLanguageFiles();

        $this->beforeApplicationDestroyed(function () use ($digestsBefore): void {
            $this->assertSame($digestsBefore, $this->digestsOfLanguageFiles(), 'a file under resources/lang changed during the test: translate() wrote a key that no language file defines');
        });
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

    protected function tearDown(): void
    {
        Schema::dropIfExists('admins');
        Schema::dropIfExists('admin_roles');

        parent::tearDown();
    }

    private function buildTheAdmins(): void
    {
        Schema::dropIfExists('admins');
        Schema::dropIfExists('admin_roles');
        Schema::create('admin_roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        Schema::create('admins', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        $all = [StorefrontPermissionService::LIFECYCLE, StorefrontPermissionService::LEGAL_WRITE, StorefrontPermissionService::LEGAL_APPROVE];
        DB::table('admin_roles')->insert([
            ['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1],
            ['id' => 11, 'name' => 'Lifecycle', 'module_access' => json_encode([StorefrontPermissionService::LIFECYCLE]), 'status' => 1],
            ['id' => 12, 'name' => 'Legal writer', 'module_access' => json_encode([StorefrontPermissionService::LEGAL_WRITE]), 'status' => 1],
            ['id' => 13, 'name' => 'Legal approver', 'module_access' => json_encode([StorefrontPermissionService::LEGAL_APPROVE]), 'status' => 1],
            ['id' => 14, 'name' => 'Catalog only', 'module_access' => json_encode(['catalog']), 'status' => 1],
            ['id' => 15, 'name' => 'Switched off', 'module_access' => json_encode($all), 'status' => 0],
        ]);
        DB::table('admins')->insert([
            ['id' => self::MASTER, 'name' => 'Master admin', 'email' => 'master@example.test', 'admin_role_id' => 1],
            ['id' => self::LIFECYCLE_ADMIN, 'name' => 'Lifecycle admin', 'email' => 'lifecycle@example.test', 'admin_role_id' => 11],
            ['id' => self::LEGAL_WRITER, 'name' => 'Legal writer', 'email' => 'writer@example.test', 'admin_role_id' => 12],
            ['id' => self::LEGAL_APPROVER, 'name' => 'Legal approver', 'email' => 'approver@example.test', 'admin_role_id' => 13],
            ['id' => self::CATALOG_ADMIN, 'name' => 'Catalog admin', 'email' => 'catalog@example.test', 'admin_role_id' => 14],
            ['id' => self::ADMIN_OF_A_SWITCHED_OFF_ROLE, 'name' => 'Switched off admin', 'email' => 'off@example.test', 'admin_role_id' => 15],
            ['id' => self::OTHER_LEGAL_WRITER, 'name' => 'Other legal writer', 'email' => 'writer2@example.test', 'admin_role_id' => 12],
        ]);
    }

    protected function signInAs(int $admin): void
    {
        $this->actingAs(Admin::query()->findOrFail($admin), 'admin');
    }

    /** What the toasts of the last request said. */
    protected function toasts(): string
    {
        return implode(' | ', array_map(static fn (array $toast): string => (string) ($toast['message'] ?? ''), array_values((array) session('laravel-toaster-magic::messages', []))));
    }

    protected function assertToastContains(string $needle): void
    {
        $this->assertTrue(str_contains($this->toasts(), $needle) || str_contains($this->toasts(), e($needle)), "no toast says '{$needle}'; the toasts were: {$this->toasts()}");
    }

    /** The audit lines of an action, oldest first. */
    protected function auditLines(string $action): array
    {
        return DB::table('audit_logs')->where('action', $action)->orderBy('id')->get()->all();
    }

    /** The country has what it needs before the lifecycle: its texts and their review, and a tax decision. */
    protected function meetThePrerequisitesOf(string $country = 'KW'): void
    {
        $this->publishRequiredLegalDocuments(country: $country, activated: false);
        $this->approveNoTaxFor($country);
    }
}
