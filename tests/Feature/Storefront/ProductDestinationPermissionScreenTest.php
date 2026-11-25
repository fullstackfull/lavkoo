<?php

namespace Tests\Feature\Storefront;

use App\Models\ProductDestinationPermission as PermissionRow;
use App\Services\Shipping\ProductDestinationPermission;
use App\Services\Shipping\RegulatedShippingGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * H1-03's second criterion: a person grants a product's permission to reach a country, and the change is audited.
 *
 * The platform refuses a destination it has no record for, so this screen is where a refusal becomes a permission -- which is exactly why
 * every write of it is recorded with who did it and what it was before. Withdrawing is the same act in reverse, and the row's absence is
 * what makes the destination refused again.
 *
 * It is behind the module that governs the catalogue, because it is a fact about a product rather than about a provider's integration.
 */
class ProductDestinationPermissionScreenTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const ADMIN = 1;

    private const PRODUCT = 4;

    /** Holds `catalog`, so the route lets them in; the clearance module is a separate question. */
    private const CATALOGUE_EMPLOYEE = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStaffAdminTables();
        $this->buildProductsTable();
        (require database_path('migrations/2026_12_20_100000_create_product_destination_permissions_table.php'))->up();
        (require database_path('migrations/2026_12_20_100300_add_clearance_reference_to_product_destination_permissions.php'))->up();
        $this->buildAuditTable();
        $this->buildSettingsTable();
        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();
        config(['gcc.storefronts.countries.KW.active' => true]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function buildProductsTable(): void
    {
        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('products')->insert(['id' => self::PRODUCT, 'name' => 'Paracetamol']);

        // The product model loads its translations wherever it is read, so the table has to exist even when it holds nothing.
        Schema::dropIfExists('translations');
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    /** The admin shell reads the shop's settings on every page; an empty table is enough for this screen. */
    private function buildSettingsTable(): void
    {
        if (Schema::hasTable('business_settings')) {
            return;
        }

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    private function buildAuditTable(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::create('audit_logs', function (Blueprint $table): void {
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

    private function grant(array $permissions = ['may_ship' => 1, 'may_export' => 1, 'may_import' => 1], string $destination = 'KW'): \Illuminate\Testing\TestResponse
    {
        $this->withoutExceptionHandling();

        return $this->signInAsStaff(self::ADMIN)->post(route('admin.shipping.destination-permissions.record'), $permissions + [
            'product_id' => self::PRODUCT,
            'destination_country' => $destination,
        ]);
    }

    public function test_the_screen_is_routed_and_behind_the_catalogue_module(): void
    {
        $this->assertTrue(Route::has('admin.shipping.destination-permissions.index'));
        $this->assertTrue(Route::has('admin.shipping.destination-permissions.record'));

        $middleware = Route::getRoutes()->getByName('admin.shipping.destination-permissions.record')->gatherMiddleware();
        $this->assertContains('module:catalog', $middleware);
        $this->assertContains('admin', $middleware);
    }

    public function test_granting_a_permission_writes_the_row_and_who_recorded_it(): void
    {
        $this->grant();

        $row = PermissionRow::query()->where('product_id', self::PRODUCT)->where('destination_country', 'KW')->firstOrFail();
        $this->assertSame([true, true, true], [$row->may_ship, $row->may_export, $row->may_import]);
        $this->assertSame(self::ADMIN, (int) $row->recorded_by);
        $this->assertNotNull($row->recorded_at);
        $this->assertTrue(ProductDestinationPermission::allows(self::PRODUCT, 'SA', 'KW'), 'and the engine now allows it');
    }

    public function test_the_grant_is_audited_with_what_it_was_before(): void
    {
        $this->grant(['may_ship' => 1, 'may_export' => 0, 'may_import' => 0]);
        $this->grant();

        $entries = DB::table('audit_logs')->where('action', 'shipping.destination_permission_recorded')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertNull(json_decode((string) $entries[0]->before, true), 'the first grant had nothing before it');
        $this->assertSame(
            ['may_ship' => true, 'may_export' => false, 'may_import' => false, 'clearance_reference' => null],
            json_decode((string) $entries[1]->before, true),
            'the second says what it replaced, the licence it rested on included',
        );
        $this->assertSame('KW', json_decode((string) $entries[1]->context, true)['destination_country']);
    }

    public function test_withdrawing_refuses_the_destination_again_and_is_audited(): void
    {
        $this->grant();

        $this->signInAsStaff(self::ADMIN)->post(route('admin.shipping.destination-permissions.record'), [
            'product_id' => self::PRODUCT, 'destination_country' => 'KW', 'withdraw' => 1,
        ]);

        $this->assertSame(0, PermissionRow::query()->count());
        $this->assertFalse(ProductDestinationPermission::allows(self::PRODUCT, 'SA', 'KW'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'shipping.destination_permission_withdrawn')->count());
    }

    /** A permission for a country nobody sells in is a row that will be read one day and believed, so it is refused here. */
    public function test_a_country_the_platform_does_not_serve_is_refused(): void
    {
        $this->grant(destination: 'ZZ')->assertSessionHasErrors('destination_country');

        $this->assertSame(0, PermissionRow::query()->count());
    }

    public function test_an_unknown_product_is_refused(): void
    {
        $this->signInAsStaff(self::ADMIN)
            ->post(route('admin.shipping.destination-permissions.record'), ['product_id' => 999, 'destination_country' => 'KW', 'may_ship' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, PermissionRow::query()->count());
    }

    /**
     * What the screen hands its view: the product it was asked about and the destinations it may reach.
     *
     * The controller is called directly rather than through the route, because rendering the admin shell is a test of the shell -- its
     * menus, its settings, its styles -- and not of this page.
     */
    public function test_the_screen_hands_the_view_the_product_and_what_it_may_reach(): void
    {
        $this->grant();
        $this->signInAsStaff(self::ADMIN);

        $view = app(\App\Http\Controllers\Admin\Shipping\ProductDestinationPermissionController::class)
            ->index(\Illuminate\Http\Request::create('/admin/shipping/destination-permissions', 'GET', ['product_id' => self::PRODUCT]));

        $this->assertSame('admin-views.shipping.destination-permissions', $view->name());
        $data = $view->getData();
        $this->assertSame(self::PRODUCT, (int) $data['product']->id);
        $this->assertSame(['KW'], $data['permissions']->pluck('destination_country')->all());
        $this->assertContains('KW', $data['countries'], 'the destinations a permission may name are the markets the platform serves');
        $this->assertTrue($data['ready']);
    }

    /**
     * H1-04 on this screen: the licence a regulated product's permission rests on is recorded with it, and audited with it.
     *
     * Admin 1 is the master account, which holds every module by being what it is.
     */
    public function test_a_clearance_reference_is_recorded_and_audited(): void
    {
        $this->grant(['may_ship' => 1, 'may_export' => 1, 'may_import' => 1, 'clearance_reference' => ' SFDA-EXP-2026-114 ']);

        $row = PermissionRow::query()->where('product_id', self::PRODUCT)->firstOrFail();
        $this->assertSame('SFDA-EXP-2026-114', $row->clearance_reference, 'stored without the spaces somebody pasted with it');

        $audited = json_decode((string) DB::table('audit_logs')->where('action', 'shipping.destination_permission_recorded')->value('after'), true);
        $this->assertSame('SFDA-EXP-2026-114', $audited['clearance_reference']);
    }

    /**
     * Recording a regulatory clearance is not an ordinary catalogue edit: the module that governs it is the owner's Q-13, so it is a
     * setting. Here it names a module this employee does not hold, while the route still lets them in on `catalog`.
     */
    public function test_a_reference_from_somebody_not_entitled_is_refused(): void
    {
        config(['gcc.shipping.regulated.clearance_module' => 'marketing']);
        RegulatedShippingGate::forget();

        $this->signInAsStaff(self::CATALOGUE_EMPLOYEE)
            ->post(route('admin.shipping.destination-permissions.record'), [
                'product_id' => self::PRODUCT, 'destination_country' => 'KW', 'may_ship' => 1,
                'clearance_reference' => 'INVENTED-2026',
            ])
            ->assertSessionHasErrors('clearance_reference');

        $this->assertSame(0, PermissionRow::query()->count(), 'and nothing at all is written');
    }

    /**
     * The other direction, which is the one that is easy to miss: their form disables the field, so their ordinary save of the three
     * permissions arrives with an empty reference. That must not withdraw a licence somebody else put on file.
     */
    public function test_an_ordinary_save_by_somebody_not_entitled_does_not_wipe_a_clearance(): void
    {
        $this->grant(['may_ship' => 1, 'may_export' => 1, 'may_import' => 1, 'clearance_reference' => 'SFDA-EXP-2026-114']);

        config(['gcc.shipping.regulated.clearance_module' => 'marketing']);
        RegulatedShippingGate::forget();

        $this->signInAsStaff(self::CATALOGUE_EMPLOYEE)->post(route('admin.shipping.destination-permissions.record'), [
            'product_id' => self::PRODUCT, 'destination_country' => 'KW', 'may_ship' => 1, 'may_export' => 1, 'may_import' => 0,
        ]);

        $row = PermissionRow::query()->where('product_id', self::PRODUCT)->firstOrFail();
        $this->assertSame('SFDA-EXP-2026-114', $row->clearance_reference, 'the licence is still on file');
        $this->assertFalse($row->may_import, 'while the permission they were entitled to change did change');
    }

    /** And somebody entitled may withdraw it deliberately, by saving an empty field. */
    public function test_somebody_entitled_may_withdraw_a_clearance(): void
    {
        $this->grant(['may_ship' => 1, 'may_export' => 1, 'may_import' => 1, 'clearance_reference' => 'SFDA-EXP-2026-114']);
        $this->grant(['may_ship' => 1, 'may_export' => 1, 'may_import' => 1, 'clearance_reference' => '']);

        $this->assertNull(PermissionRow::query()->where('product_id', self::PRODUCT)->value('clearance_reference'));
    }

    /** A product that may reach nothing hands an empty list, which is what the platform's default is. */
    public function test_a_product_with_no_permission_hands_an_empty_list(): void
    {
        $this->signInAsStaff(self::ADMIN);

        $view = app(\App\Http\Controllers\Admin\Shipping\ProductDestinationPermissionController::class)
            ->index(\Illuminate\Http\Request::create('/admin/shipping/destination-permissions', 'GET', ['product_id' => self::PRODUCT]));

        $this->assertSame([], $view->getData()['permissions']->all());
    }
}
