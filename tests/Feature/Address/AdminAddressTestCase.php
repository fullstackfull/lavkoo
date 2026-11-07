<?php

namespace Tests\Feature\Address;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\AddressCity;
use App\Services\Address\AddressSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsAddressSchema;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * Base for the address administration tests: the store's core tables, the fulfillment tables (the provider city list lives there), the
 * old address book and the four REAL address migrations, an audit table, a stand-in admin shell, and the real admin guard chain
 * (sign-in and module permission) left switched on. Only the heavy request middleware that needs the installation database is off.
 *
 * Administrators: 1 master, 2 catalog only, 3 third-party setup only, 4 business settings (the module the address pages sit under).
 */
abstract class AdminAddressTestCase extends TestCase
{
    use BuildsAddressSchema;
    use BuildsFulfillmentSchema;

    protected const BUSINESS_SETTINGS_ADMIN = 4;

    /** @var array<string, int> the catalog's ids by handle, once seedAddressCatalog() has run */
    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        $this->runFulfillmentMigrations();
        $this->createLegacyAddressTables();
        $this->runAddressMigrations();
        $this->createAuditLogTable();
        $this->addBusinessSettingsAdmin();
        Cache::flush();
        AddressSchema::flush();

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class,
            NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        Http::preventStrayRequests();
    }

    protected function signIn(int $adminId = 1): static
    {
        return $this->actingAs(Admin::findOrFail($adminId), 'admin');
    }

    protected function signInWithTheCatalog(): static
    {
        $this->ids = $this->seedAddressCatalog();

        return $this->signIn();
    }

    protected function createAuditLogTable(): void
    {
        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    private function addBusinessSettingsAdmin(): void
    {
        DB::table('admin_roles')->insert(['id' => 4, 'name' => 'Business settings', 'module_access' => json_encode(['business_settings']), 'status' => 1]);
        DB::table('admins')->insert(['id' => self::BUSINESS_SETTINGS_ADMIN, 'name' => 'Settings admin', 'email' => 'settings@example.test', 'admin_role_id' => 4]);
    }

    /** One saved address. Defaults to a registered customer's Saudi address typed the old way: a city, a ZIP, no region, city id or National Address. */
    protected function address(array $attributes = []): int
    {
        return (int) DB::table('shipping_addresses')->insertGetId($attributes + [
            'customer_id' => '7', 'is_guest' => 0, 'contact_person_name' => 'Zeta Quillfeather', 'phone' => '0551234567', 'address_type' => 'home',
            'address' => '12 Quillfeather Lane', 'city' => 'الرياض', 'zip' => '12345', 'state' => null, 'country' => 'Saudi Arabia', 'latitude' => '24.7', 'longitude' => '46.7',
            'is_billing' => 0, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00',
        ]);
    }

    /** Lets the owner of this address retype it at the moment the next UPDATE of the address book is about to run: the race a batch write must survive. */
    protected function retypeBeforeTheNextAddressUpdate(int $addressId, array $changes): void
    {
        $done = false;
        DB::beforeExecuting(static function (string $query) use (&$done, $addressId, $changes): void {
            if ($done || preg_match('/^update\s+[`"]shipping_addresses[`"]/i', $query) !== 1) {
                return;
            }

            $done = true;
            DB::table('shipping_addresses')->where('id', $addressId)->update($changes);
        });
    }

    /** @return array<string, mixed> the stored row, for comparing before and after */
    protected function addressRow(int $id): array
    {
        return (array) DB::table('shipping_addresses')->where('id', $id)->first();
    }

    protected function city(string $handle): AddressCity
    {
        return AddressCity::query()->with('region')->findOrFail($this->ids[$handle]);
    }

    /** @return list<string> the toasts the last request queued, as text */
    protected function toasts(): array
    {
        $messages = session('laravel-toaster-magic::messages') ?? [];

        return array_values(array_map(static fn ($message): string => is_array($message) ? (string) ($message['message'] ?? '') : (string) $message, $messages));
    }

    protected function auditActions(): array
    {
        return DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
    }
}
