<?php

namespace Tests\Feature\Storefront;

use App\Models\ProductDestinationPermission as PermissionRow;
use App\Services\Shipping\ProductDestinationPermission;
use App\Services\Shipping\RegulatedShippingGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * H1-04: a regulated product does not cross a border because a courier flies there.
 *
 * The requirement is a sentence about the world rather than about code: a medicine, a supplement or a medical device needs one authority's
 * licence to leave a country and another's to enter the next, and a carrier's route map says nothing about either. So the first test here
 * is the dormancy -- `off` is how the gate ships and `off` stops nothing, because a platform selling inside one country holds no export
 * licence and needs none -- and the test that carries the requirement is the one where the carrier covers the route, the product has all
 * three destination permissions, and the shipment is still refused.
 *
 * The three ways it fails closed are each proven on their own: a product nobody classified is refused ("we could not tell what this is" is
 * not "it is fine to export"), a product the owner's lists name needs a reference on file, and a reference nobody answerable recorded is
 * not a clearance. An admin id on the row is not one either.
 */
class RegulatedShippingGateTest extends TestCase
{
    private const HOME = 'SA';

    private const ABROAD = 'KW';

    private const MEDICINES = '7';

    private int $authorisedAdmin;

    private int $unauthorisedAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTheWorld();
        (require database_path('migrations/2026_12_20_100000_create_product_destination_permissions_table.php'))->up();
        (require database_path('migrations/2026_12_20_100300_add_clearance_reference_to_product_destination_permissions.php'))->up();

        $this->authorisedAdmin = $this->employeeHolding(['catalog', 'orders']);
        $this->unauthorisedAdmin = $this->employeeHolding(['reports']);

        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();
    }

    protected function tearDown(): void
    {
        foreach (['product_destination_permissions', 'products', 'admins', 'admin_roles'] as $table) {
            Schema::dropIfExists($table);
        }
        ProductDestinationPermission::forget();
        RegulatedShippingGate::forget();

        parent::tearDown();
    }

    private function buildTheWorld(): void
    {
        foreach (['products', 'admins', 'admin_roles'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('brand_id')->nullable();
            $table->text('category_ids')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        // Role 1 is the platform's master account, and it holds every module by being what it is. It is created FIRST and deliberately, so
        // that no employee role built after it lands on id 1 and passes this gate for a reason the test did not intend.
        DB::table('admin_roles')->insert(['id' => 1, 'name' => 'Master', 'module_access' => json_encode([]), 'status' => 1]);
    }

    /** @param list<string> $modules */
    private function employeeHolding(array $modules, int $status = 1): int
    {
        $roleId = DB::table('admin_roles')->insertGetId([
            'name' => 'Role ' . implode('-', $modules),
            'module_access' => json_encode($modules),
            'status' => $status,
        ]);

        return DB::table('admins')->insertGetId([
            'name' => 'Employee',
            'email' => 'employee' . $roleId . '@example.test',
            'admin_role_id' => $roleId,
            'status' => 1,
        ]);
    }

    private function aProduct(?string $categoryIds = null, string $name = 'Vitamin C tablets', ?string $brand = null, int $id = 1): int
    {
        DB::table('products')->insert([
            'id' => $id,
            'name' => $name,
            'brand_id' => $brand,
            'category_ids' => $categoryIds,
        ]);

        return $id;
    }

    private function inTheMedicinesCategory(): string
    {
        return json_encode([['id' => self::MEDICINES, 'position' => 1]]);
    }

    private function strictWithMedicinesRegulated(): void
    {
        config([
            'gcc.shipping.regulated.mode' => RegulatedShippingGate::MODE_STRICT,
            'gcc.shipping.regulated.category_ids' => [self::MEDICINES],
        ]);
    }

    private function clearance(?string $reference, ?int $recordedBy, string $destination = self::ABROAD, int $productId = 1): void
    {
        PermissionRow::create([
            'product_id' => $productId,
            'destination_country' => $destination,
            'may_ship' => true,
            'may_export' => true,
            'may_import' => true,
            'clearance_reference' => $reference,
            'recorded_by' => $recordedBy,
            'recorded_at' => now(),
        ]);
        RegulatedShippingGate::forget();
    }

    private function gate(): RegulatedShippingGate
    {
        return new RegulatedShippingGate();
    }

    private function refusalFor(int $productId = 1, string $destination = self::ABROAD): ?string
    {
        return $this->gate()->refusalFor(productId: $productId, source: self::HOME, destination: $destination);
    }

    /** The dormancy, and the reason the Saudi platform is untouched: off stops nothing, whatever the lists say. */
    public function test_with_the_mode_off_the_gate_stops_nothing(): void
    {
        config(['gcc.shipping.regulated.category_ids' => [self::MEDICINES]]);
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());

        $this->assertSame(RegulatedShippingGate::MODE_OFF, $this->gate()->modeFor(self::ABROAD));
        $this->assertNull($this->refusalFor());
        $this->assertFalse($this->gate()->needsClearance(productId: 1, source: self::HOME, destination: self::ABROAD));
    }

    /** A sale delivered in its own country asks no customs officer, so the gate has no business in it even in strict mode. */
    public function test_a_domestic_destination_is_never_this_gates_business(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());

        $this->assertNull($this->refusalFor(destination: self::HOME));
        $this->assertFalse($this->gate()->needsClearance(productId: 1, source: self::HOME, destination: self::HOME));
    }

    /** "We could not tell what this is" is not "it is fine to export". */
    public function test_a_product_nobody_classified_is_refused_abroad(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: null);

        $this->assertSame(RegulatedShippingGate::CLASSIFICATION_MISSING, $this->refusalFor());
        $this->assertTrue($this->gate()->needsClearance(productId: 1, source: self::HOME, destination: self::ABROAD));
    }

    public function test_a_product_that_does_not_exist_is_refused_abroad(): void
    {
        $this->strictWithMedicinesRegulated();

        $this->assertSame(RegulatedShippingGate::CLASSIFICATION_MISSING, $this->refusalFor(productId: 404));
    }

    /** An ordinary product in a category nobody regulated is not this gate's business either. */
    public function test_a_classified_product_outside_the_owners_lists_passes(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: json_encode([['id' => '99', 'position' => 1]]), name: 'Hairbrush');

        $this->assertNull($this->refusalFor());
        $this->assertFalse($this->gate()->needsClearance(productId: 1, source: self::HOME, destination: self::ABROAD));
    }

    /**
     * The requirement itself: every destination permission granted, so H1-03 is satisfied and a carrier covering the route changes
     * nothing -- and the shipment is still refused, because no licence is on file.
     */
    public function test_a_regulated_product_with_every_permission_is_still_refused_without_a_clearance(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());
        $this->clearance(reference: null, recordedBy: $this->authorisedAdmin);

        $this->assertTrue(ProductDestinationPermission::allows(1, self::HOME, self::ABROAD), 'H1-03 is satisfied');
        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor());
    }

    public function test_a_recorded_clearance_lets_the_product_through(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());
        $this->clearance(reference: 'SFDA-EXP-2026-114', recordedBy: $this->authorisedAdmin);

        $this->assertNull($this->refusalFor());
    }

    /** An admin id on the row is not a clearance: somebody has to have pointed at a licence. */
    public function test_an_admin_id_alone_is_not_a_clearance(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());
        $this->clearance(reference: '   ', recordedBy: $this->authorisedAdmin);

        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor());
    }

    /** And a reference nobody answerable recorded is not one either. */
    public function test_a_reference_recorded_by_somebody_without_the_module_is_not_a_clearance(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());
        $this->clearance(reference: 'SFDA-EXP-2026-114', recordedBy: $this->unauthorisedAdmin);

        $this->assertSame(RegulatedShippingGate::CLEARANCE_NOT_PERMITTED, $this->refusalFor());
    }

    public function test_a_reference_recorded_by_nobody_at_all_is_not_a_clearance(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());
        $this->clearance(reference: 'SFDA-EXP-2026-114', recordedBy: null);

        $this->assertSame(RegulatedShippingGate::CLEARANCE_NOT_PERMITTED, $this->refusalFor());
    }

    /** A clearance is for one destination. The licence that lets a medicine into Kuwait says nothing about Bahrain. */
    public function test_a_clearance_is_for_the_destination_it_names(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());
        $this->clearance(reference: 'SFDA-EXP-2026-114', recordedBy: $this->authorisedAdmin, destination: self::ABROAD);

        $this->assertNull($this->refusalFor(destination: self::ABROAD));
        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor(destination: 'BH'));
    }

    /** Withdrawing the permission withdraws the clearance with it, which is what an expiring licence needs. */
    public function test_withdrawing_the_row_withdraws_the_clearance(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());
        $this->clearance(reference: 'SFDA-EXP-2026-114', recordedBy: $this->authorisedAdmin);
        $this->assertNull($this->refusalFor());

        PermissionRow::query()->where('product_id', 1)->delete();
        RegulatedShippingGate::forget();

        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor());
    }

    public function test_a_name_pattern_marks_a_product_regulated(): void
    {
        config([
            'gcc.shipping.regulated.mode' => RegulatedShippingGate::MODE_STRICT,
            'gcc.shipping.regulated.name_patterns' => ['insulin'],
        ]);
        $this->aProduct(categoryIds: json_encode([['id' => '99', 'position' => 1]]), name: 'Insulin pen 100IU');

        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor());
    }

    public function test_a_brand_marks_a_product_regulated(): void
    {
        config([
            'gcc.shipping.regulated.mode' => RegulatedShippingGate::MODE_STRICT,
            'gcc.shipping.regulated.brand_ids' => ['41'],
        ]);
        $this->aProduct(categoryIds: json_encode([['id' => '99', 'position' => 1]]), name: 'Hairbrush', brand: '41');

        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor());
    }

    /** What one country closes at its border is not what the next one closes, so a destination may set its own mode. */
    public function test_a_destination_may_be_strict_while_the_platform_is_off(): void
    {
        config([
            'gcc.shipping.regulated.category_ids' => [self::MEDICINES],
            'gcc.shipping.regulated.destinations' => [self::ABROAD => ['mode' => RegulatedShippingGate::MODE_STRICT]],
        ]);
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());

        $this->assertSame(RegulatedShippingGate::MODE_STRICT, $this->gate()->modeFor(self::ABROAD));
        $this->assertSame(RegulatedShippingGate::MODE_OFF, $this->gate()->modeFor('BH'));
        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor(destination: self::ABROAD));
        $this->assertNull($this->refusalFor(destination: 'BH'));
    }

    /** A global rule is never lost by naming a country. */
    public function test_a_destinations_own_list_is_added_to_the_global_one(): void
    {
        config([
            'gcc.shipping.regulated.mode' => RegulatedShippingGate::MODE_STRICT,
            'gcc.shipping.regulated.category_ids' => [self::MEDICINES],
            'gcc.shipping.regulated.destinations' => [self::ABROAD => ['category_ids' => ['99']]],
        ]);
        $this->aProduct(categoryIds: json_encode([['id' => self::MEDICINES, 'position' => 1]]));
        $this->aProduct(categoryIds: json_encode([['id' => '99', 'position' => 1]]), name: 'Thermometer', id: 2);

        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor(productId: 1));
        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor(productId: 2));
    }

    /** Role 1 is the master account, which holds every module by being what it is. */
    public function test_the_master_role_may_record_a_clearance(): void
    {
        $master = DB::table('admins')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.test', 'admin_role_id' => 1, 'status' => 1]);

        $this->assertTrue($this->gate()->mayRecordClearance($master));
        $this->assertFalse($this->gate()->mayRecordClearance($this->unauthorisedAdmin));
        $this->assertFalse($this->gate()->mayRecordClearance(null));
        $this->assertFalse($this->gate()->mayRecordClearance(99_999), 'an id that belongs to no admin never may');
    }

    /** The guard on every other test in this class: the employees it builds pass or fail on their module, not by being the master. */
    public function test_the_employees_in_this_fixture_are_not_the_master(): void
    {
        foreach ([$this->authorisedAdmin, $this->unauthorisedAdmin] as $adminId) {
            $this->assertNotSame(1, (int) DB::table('admins')->where('id', $adminId)->value('admin_role_id'));
        }
    }

    /** A suspended role is not a role. */
    public function test_a_suspended_role_may_not_record_a_clearance(): void
    {
        $suspended = $this->employeeHolding(['catalog'], status: 0);

        $this->assertFalse($this->gate()->mayRecordClearance($suspended));
    }

    /** Which module clears a regulated export is the owner's Q-13, so it is a setting rather than a constant. */
    public function test_the_module_a_clearance_needs_is_configurable(): void
    {
        config(['gcc.shipping.regulated.clearance_module' => 'reports']);
        RegulatedShippingGate::forget();

        $this->assertTrue($this->gate()->mayRecordClearance($this->unauthorisedAdmin));
        $this->assertFalse($this->gate()->mayRecordClearance($this->authorisedAdmin));
    }

    /** Before the migration runs there is nowhere to record a clearance, so a regulated product stays where it is. */
    public function test_without_the_reference_column_a_regulated_product_is_refused(): void
    {
        $this->strictWithMedicinesRegulated();
        $this->aProduct(categoryIds: $this->inTheMedicinesCategory());
        Schema::dropIfExists('product_destination_permissions');
        RegulatedShippingGate::forget();

        $this->assertSame(RegulatedShippingGate::NEEDS_CLEARANCE, $this->refusalFor());
    }
}
