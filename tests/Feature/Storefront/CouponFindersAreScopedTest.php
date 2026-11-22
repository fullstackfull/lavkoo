<?php

namespace Tests\Feature\Storefront;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GCC-05, slice 5: every place a customer meets a coupon asks for the coupons of the storefront it is served from. Source-level, like the guard that
 * every admin product route carries the money middleware: a new finder that forgets the scope would offer a Saudi coupon, typed in riyals, on a Kuwaiti
 * storefront, and nothing else would say so.
 */
class CouponFindersAreScopedTest extends TestCase
{
    /** @return array<string, array{0: string, 1: int}> */
    public static function scopedFinders(): array
    {
        return [
            'the eligibility of a coupon at checkout (two lookups)' => ['app/Utils/OrderManager.php', 2],
            'the REST coupon list, the applicable list and the seller-wise list' => ['app/Http/Controllers/RestAPI/v1/CouponController.php', 3],
            'the coupons of a seller on a shop page' => ['app/Models/Seller.php', 1],
            'the coupon strip of the home page' => ['app/Services/Theme/SectionDataResolver.php', 1],
            'my coupons on the website' => ['app/Http/Controllers/Web/UserProfileController.php', 1],
            'the in-house coupons of the top vendors' => ['app/Traits/CacheManagerTrait.php', 1],
        ];
    }

    #[DataProvider('scopedFinders')]
    public function test_the_finder_narrows_to_the_storefront(string $file, int $times): void
    {
        $this->assertSame($times, substr_count((string) file_get_contents(base_path($file)), '->forStorefront()'), $file);
    }

    public function test_the_finders_that_are_not_scoped_are_scoped_on_purpose(): void
    {
        $orderManager = (string) file_get_contents(base_path('app/Utils/OrderManager.php'));

        $this->assertStringContainsString("Coupon::where(['code' => \$coupon_code])->where('status', 1)->first()", $orderManager, 'the preview reads the type only');
        $this->assertStringContainsString("Coupon::where('code', \$couponCode)->where('status', 1)->first()", $orderManager, 'the reward snapshot is read by code after the decision');
        $this->assertStringNotContainsString('forStorefront', (string) file_get_contents(base_path('app/Traits/OrderEditManager.php')), 'an order edit finds the order\'s own coupon by its unique code');
    }
}
