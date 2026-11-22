<?php

namespace Tests\Feature\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\AI\app\Http\Controllers\Vendor\AIProductController;
use Modules\TaxModule\app\Http\Controllers\Vendor\Reports\TaxReportController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The vendor routes of the tax and AI modules are registered with the `seller` middleware alone, so SellerStaffAccessMiddleware, which asks a staff
 * member for the permission of the area a vendor URL belongs to, never ran on them: any staff member, with no role at all, read the shop's VAT report
 * (the report area asks for finance.view) and used the AI auto-fill of the product form (products.view to read, products.manage to post), which
 * the product pages themselves refuse them.
 *
 * The handlers are stood in for by an answer, so that what is under test is who reaches them.
 */
class VendorModuleRoutesStaffAccessTest extends VendorPanelTestCase
{
    private const STAFF_WITH_NO_ROLE = 5;
    private const STAFF_WHO_ONLY_VIEWS_ORDERS = 6;
    private const STAFF_WHO_VIEWS_FINANCE = 7;
    private const STAFF_WHO_VIEWS_PRODUCTS = 8;
    private const STAFF_WHO_MANAGES_PRODUCTS = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffTables();
        $this->insertRole(roleId: 1, permissions: ['orders.view']);
        $this->insertRole(roleId: 2, permissions: ['finance.view']);
        $this->insertRole(roleId: 3, permissions: ['products.view']);
        $this->insertRole(roleId: 4, permissions: ['products.manage']);
        $this->insertStaff(staffId: self::STAFF_WITH_NO_ROLE, roleId: null);
        $this->insertStaff(staffId: self::STAFF_WHO_ONLY_VIEWS_ORDERS, roleId: 1);
        $this->insertStaff(staffId: self::STAFF_WHO_VIEWS_FINANCE, roleId: 2);
        $this->insertStaff(staffId: self::STAFF_WHO_VIEWS_PRODUCTS, roleId: 3);
        $this->insertStaff(staffId: self::STAFF_WHO_MANAGES_PRODUCTS, roleId: 4);
        $this->standInForTheHandlers();
    }

    /** @return array<string, array{string, string, int}> a route, and the staff member whose role grants exactly what its area asks for */
    public static function routesAndTheStaffMemberWhoMayUseThem(): array
    {
        return [
            'the VAT report' => ['GET', '/vendor/report/get-vat-report', self::STAFF_WHO_VIEWS_FINANCE],
            'the VAT report export' => ['GET', '/vendor/report/get-vat-report-export', self::STAFF_WHO_VIEWS_FINANCE],
            'the AI title' => ['GET', '/vendor/product/title-auto-fill', self::STAFF_WHO_VIEWS_PRODUCTS],
            'the AI description' => ['GET', '/vendor/product/description-auto-fill', self::STAFF_WHO_VIEWS_PRODUCTS],
            'the AI image analysis' => ['POST', '/vendor/product/analyze-image-auto-fill', self::STAFF_WHO_MANAGES_PRODUCTS],
            'the AI title suggestions' => ['POST', '/vendor/product/generate-title-suggestions', self::STAFF_WHO_MANAGES_PRODUCTS],
        ];
    }

    #[DataProvider('routesAndTheStaffMemberWhoMayUseThem')]
    public function test_a_staff_member_whose_role_does_not_grant_the_area_is_refused(string $method, string $uri): void
    {
        foreach ([self::STAFF_WITH_NO_ROLE, self::STAFF_WHO_ONLY_VIEWS_ORDERS] as $staffId) {
            $this->signInAsStaff($staffId)->call($method, $uri, $this->formFor($uri))->assertForbidden();
        }
    }

    #[DataProvider('routesAndTheStaffMemberWhoMayUseThem')]
    public function test_a_staff_member_whose_role_grants_the_area_and_the_owner_still_reach_it(string $method, string $uri, int $staffId): void
    {
        $this->signInAsStaff($staffId)->call($method, $uri, $this->formFor($uri))->assertOk()->assertJson(['handled' => true]);
        $this->signInAs(self::SELLER)->call($method, $uri, $this->formFor($uri))->assertOk()->assertJson(['handled' => true]);
    }

    public function test_a_staff_member_who_may_only_view_products_cannot_post_to_the_ai_routes(): void
    {
        $uri = '/vendor/product/generate-title-suggestions';

        $this->signInAsStaff(self::STAFF_WHO_VIEWS_PRODUCTS)->call('POST', $uri, $this->formFor($uri))->assertForbidden();
    }

    /** @return array<string, mixed> what the route's form request accepts, so that a request that is let through reaches its handler */
    private function formFor(string $uri): array
    {
        return match (true) {
            str_ends_with($uri, 'analyze-image-auto-fill') => ['image' => UploadedFile::fake()->image('photo.png')],
            str_ends_with($uri, 'generate-title-suggestions') => ['keywords' => ['aspirin']],
            str_contains($uri, 'auto-fill') => ['name' => 'Aspirin'],
            default => [],
        };
    }

    private function standInForTheHandlers(): void
    {
        $answer = new JsonResponse(['handled' => true]);
        $this->partialMock(TaxReportController::class, static function ($mock) use ($answer): void {
            $mock->shouldReceive('vendorTaxReportList', 'vendorTaxExport')->andReturn($answer);
        });
        $this->partialMock(AIProductController::class, static function ($mock) use ($answer): void {
            $mock->shouldReceive('titleAutoFill', 'descriptionAutoFill', 'generateTitleFromImages', 'generateProductTitleSuggestion')->andReturn($answer);
        });
    }

    private function insertRole(int $roleId, array $permissions): void
    {
        DB::table('seller_roles')->insert([
            'id' => $roleId, 'seller_id' => self::SELLER, 'name' => 'Role ' . $roleId, 'permissions' => json_encode($permissions), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertStaff(int $staffId, ?int $roleId): void
    {
        DB::table('seller_staff')->insert([
            'id' => $staffId, 'seller_id' => self::SELLER, 'seller_role_id' => $roleId, 'name' => 'Staff ' . $staffId, 'email' => "staff{$staffId}@alpha.test", 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
