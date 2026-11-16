<?php

namespace Tests\Feature\Security;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only the panels, and the seller APIs they use, see reviews that have not been approved. A storefront page whose address merely starts
 * with the same letters (the shop pages of a vendor, the vendor list, a seller profile) shows approved reviews only.
 */
class ReviewVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('reviews');
        Schema::create('reviews', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
        });
        DB::table('reviews')->insert([['status' => 1], ['status' => 1], ['status' => 0]]);
    }

    private function visitAs(string $uri): int
    {
        app()->instance('request', Request::create($uri));

        return Review::query()->count();
    }

    public static function panelAddresses(): array
    {
        return [
            'admin panel' => ['/admin/reviews'],
            'admin root' => ['/admin'],
            'vendor panel' => ['/vendor/products/list'],
            'seller area' => ['/seller/dashboard'],
            'seller API v2' => ['/api/v2/seller/products/list'],
            'seller API v3' => ['/api/v3/seller/products/review-list/5'],
        ];
    }

    public static function storefrontAddresses(): array
    {
        return [
            'the shop page of a vendor' => ['/vendor-shop/acme'],
            'the vendor list' => ['/vendors'],
            'a seller profile' => ['/seller-profile/3'],
            'the home page' => ['/'],
            'the customer API' => ['/api/v1/products/reviews/acme'],
            'an address that only looks like the admin one' => ['/administrators'],
        ];
    }

    #[DataProvider('panelAddresses')]
    public function test_a_panel_sees_every_review(string $uri): void
    {
        $this->assertSame(3, $this->visitAs($uri));
    }

    #[DataProvider('storefrontAddresses')]
    public function test_a_storefront_page_sees_approved_reviews_only(string $uri): void
    {
        $this->assertSame(2, $this->visitAs($uri));
    }

    public function test_a_command_line_run_sees_approved_reviews_only(): void
    {
        $this->assertSame(2, Review::query()->count());
    }
}
