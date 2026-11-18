<?php

namespace Tests\Feature\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Modules\AI\app\Http\Controllers\Vendor\AIAuctionProductController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The eight AI helpers of the auction product form (vendor/auction/product/*) were registered with the `seller` middleware alone, so the staff
 * middleware never ran on them: any staff member, with no role, with an empty role or with a role that has nothing to do with products, used them,
 * and a staff member spends the shop's AI quota. The helpers of the product form ask products.view for the ones that read and products.manage for the
 * ones that post, and an auction product is a product of the shop, so the auction helpers ask the same.
 *
 * The handlers are stood in for by an answer that counts how often it was reached, so that what is under test is who reaches them. Every request
 * carries the form its handler validates: a refused form answers 403 as well, and a 403 must only ever come from the permission.
 */
class VendorAuctionAiHelpersStaffAccessTest extends VendorPanelTestCase
{
    private const HANDLERS = [
        'titleAutoFill', 'descriptionAutoFill', 'generalSetupAutoFill', 'shippingPolicyAutoFill', 'auctionInfoAutoFill', 'seoSectionAutoFill',
        'generateTitleFromImages', 'generateProductTitleSuggestion',
    ];

    private const READING_HELPERS = [
        'title' => 'title-auto-fill',
        'description' => 'description-auto-fill',
        'general setup' => 'general-setup-auto-fill',
        'shipping policy' => 'shipping-policy-auto-fill',
        'auction info' => 'auction-info-auto-fill',
        'seo section' => 'seo-section-auto-fill',
    ];

    private const POSTING_HELPERS = [
        'image analysis' => 'analyze-image-auto-fill',
        'title suggestions' => 'generate-title-suggestions',
    ];

    private const STAFF_WITH_NO_ROLE = 'no role';
    private const STAFF_WITH_AN_EMPTY_ROLE = 'an empty role';
    private const STAFF_WHO_MANAGE_ORDERS = 'orders.manage';
    private const STAFF_WHO_ONLY_VIEW_PRODUCTS = 'products.view';
    private const STAFF_WHO_ONLY_MANAGE_PRODUCTS = 'products.manage';
    private const STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS = 'products.view and products.manage';

    /** @var array<string, int> */
    private array $staff;

    private int $helperCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffTables();
        $this->staff = [
            self::STAFF_WITH_NO_ROLE => $this->insertStaffMember(name: 'norole', permissions: null),
            self::STAFF_WITH_AN_EMPTY_ROLE => $this->insertStaffMember(name: 'emptyrole', permissions: []),
            self::STAFF_WHO_MANAGE_ORDERS => $this->insertStaffMember(name: 'orders', permissions: ['orders.manage']),
            self::STAFF_WHO_ONLY_VIEW_PRODUCTS => $this->insertStaffMember(name: 'productsview', permissions: ['products.view']),
            self::STAFF_WHO_ONLY_MANAGE_PRODUCTS => $this->insertStaffMember(name: 'productsmanage', permissions: ['products.manage']),
            self::STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS => $this->insertStaffMember(name: 'productsboth', permissions: ['products.view', 'products.manage']),
        ];
        $this->standInForTheHandlers();
    }

    /** @return iterable<string, array{string, string, string}> a helper that reads, and a staff member whose role does not grant products.view */
    public static function readingHelpersAndStaffWhoMayNotViewProducts(): iterable
    {
        $withoutTheViewPermission = [
            self::STAFF_WITH_NO_ROLE, self::STAFF_WITH_AN_EMPTY_ROLE, self::STAFF_WHO_MANAGE_ORDERS, self::STAFF_WHO_ONLY_MANAGE_PRODUCTS,
        ];

        foreach (self::READING_HELPERS as $helper => $segment) {
            foreach ($withoutTheViewPermission as $staff) {
                yield "the {$helper} helper, staff with {$staff}" => ['GET', $segment, $staff];
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> a helper that posts, and a staff member whose role does not grant products.manage */
    public static function postingHelpersAndStaffWhoMayNotManageProducts(): iterable
    {
        $withoutTheManagePermission = [
            self::STAFF_WITH_NO_ROLE, self::STAFF_WITH_AN_EMPTY_ROLE, self::STAFF_WHO_MANAGE_ORDERS, self::STAFF_WHO_ONLY_VIEW_PRODUCTS,
        ];

        foreach (self::POSTING_HELPERS as $helper => $segment) {
            foreach ($withoutTheManagePermission as $staff) {
                yield "the {$helper} helper, staff with {$staff}" => ['POST', $segment, $staff];
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> a helper, and a staff member whose role grants what the helper asks */
    public static function helpersAndStaffWhoMayUseThem(): iterable
    {
        foreach (self::READING_HELPERS as $helper => $segment) {
            yield "the {$helper} helper, staff who view products" => ['GET', $segment, self::STAFF_WHO_ONLY_VIEW_PRODUCTS];
            yield "the {$helper} helper, staff who view and manage products" => ['GET', $segment, self::STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS];
        }
        foreach (self::POSTING_HELPERS as $helper => $segment) {
            yield "the {$helper} helper, staff who manage products" => ['POST', $segment, self::STAFF_WHO_ONLY_MANAGE_PRODUCTS];
            yield "the {$helper} helper, staff who view and manage products" => ['POST', $segment, self::STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function everyHelper(): iterable
    {
        foreach (self::READING_HELPERS as $helper => $segment) {
            yield "the {$helper} helper" => ['GET', $segment];
        }
        foreach (self::POSTING_HELPERS as $helper => $segment) {
            yield "the {$helper} helper" => ['POST', $segment];
        }
    }

    #[DataProvider('readingHelpersAndStaffWhoMayNotViewProducts')]
    #[DataProvider('postingHelpersAndStaffWhoMayNotManageProducts')]
    public function test_a_staff_member_whose_role_does_not_grant_what_the_helper_asks_is_refused_before_it_runs(string $method, string $segment, string $staff): void
    {
        $this->signInAsStaff($this->staff[$staff]);

        $this->requestTheHelper(method: $method, segment: $segment)
            ->assertForbidden()
            ->assertJsonPath('message', translate('you_do_not_have_permission_for_this_action'));

        $this->assertSame(0, $this->helperCalls);
    }

    #[DataProvider('helpersAndStaffWhoMayUseThem')]
    public function test_a_staff_member_whose_role_grants_what_the_helper_asks_still_reaches_it(string $method, string $segment, string $staff): void
    {
        $this->signInAsStaff($this->staff[$staff]);

        $this->requestTheHelper(method: $method, segment: $segment)->assertOk()->assertJson(['handled' => true]);

        $this->assertSame(1, $this->helperCalls);
    }

    #[DataProvider('everyHelper')]
    public function test_the_owner_still_reaches_every_helper(string $method, string $segment): void
    {
        $this->signInAs(self::SELLER);

        $this->requestTheHelper(method: $method, segment: $segment)->assertOk()->assertJson(['handled' => true]);

        $this->assertSame(1, $this->helperCalls);
    }

    public function test_a_staff_member_with_every_product_permission_is_still_refused_on_any_other_page_under_the_auction_prefix(): void
    {
        Route::middleware(['web', 'seller', 'seller_staff_access'])->get('/vendor/auction/bids/list', static fn (): JsonResponse => new JsonResponse(['handled' => true]));
        $this->signInAsStaff($this->staff[self::STAFF_WHO_VIEW_AND_MANAGE_PRODUCTS]);

        $this->call(method: 'GET', uri: '/vendor/auction/bids/list', server: ['HTTP_ACCEPT' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('message', translate('you_do_not_have_permission_for_this_action'));
    }

    private function requestTheHelper(string $method, string $segment): TestResponse
    {
        return $this->call(
            method: $method,
            uri: '/vendor/auction/product/' . $segment,
            parameters: $this->formFor($segment),
            server: ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    /** @return array<string, mixed> what the helper's form request accepts, so that a request that is let through reaches its handler */
    private function formFor(string $segment): array
    {
        return match ($segment) {
            'analyze-image-auto-fill' => ['image' => UploadedFile::fake()->image('photo.png')],
            'generate-title-suggestions' => ['keywords' => ['aspirin']],
            'title-auto-fill', 'description-auto-fill' => ['name' => 'Aspirin', 'langCode' => 'en'],
            default => ['name' => 'Aspirin', 'description' => 'Pain relief'],
        };
    }

    private function standInForTheHandlers(): void
    {
        $this->partialMock(AIAuctionProductController::class, function (MockInterface $mock): void {
            $mock->shouldReceive(...self::HANDLERS)->andReturnUsing(function (): JsonResponse {
                $this->helperCalls++;

                return new JsonResponse(['handled' => true]);
            });
        });
    }
}
