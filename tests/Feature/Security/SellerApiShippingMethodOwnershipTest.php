<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * A shipping method has a creator, and the creator is a pair: a creator_type ('admin' or 'seller') and the creator's id. The in-house shop's
 * methods are created by the admin, whose id is 1, which is also the id of seller 1. A seller's handlers for reading, switching, rewriting
 * and deleting a method must therefore match the creator's type as well as the id: seller 1 changes only the methods created by a seller
 * with id 1, never the in-house shop's, and nobody changes another seller's.
 */
class SellerApiShippingMethodOwnershipTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const IN_HOUSE_METHOD = 1;
    private const OWN_METHOD = 10;
    private const OTHER_SELLERS_METHOD = 20;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createSellerApiTable('shipping_methods', function (Blueprint $table) {
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_type')->nullable();
            $table->string('title')->nullable();
            $table->string('duration')->nullable();
            $table->decimal('cost', 16, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $method = fn (int $id, int $creatorId, string $creatorType, string $title) => DB::table('shipping_methods')->insert([
            'id' => $id, 'creator_id' => $creatorId, 'creator_type' => $creatorType, 'title' => $title, 'duration' => '2 days', 'cost' => 15, 'status' => 1,
        ]);
        $method(self::IN_HOUSE_METHOD, self::IN_HOUSE_ID, 'admin', 'In-house express');
        $method(self::OWN_METHOD, self::SELLER, 'seller', 'Own method');
        $method(self::OTHER_SELLERS_METHOD, self::OTHER_SELLER, 'seller', 'Other sellers method');
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function methodsThatAreNotTheSellers(): array
    {
        return [
            'v3, the in-house shop\'s method' => ['v3', self::IN_HOUSE_METHOD],
            'v2, the in-house shop\'s method' => ['v2', self::IN_HOUSE_METHOD],
            'v3, another seller\'s method' => ['v3', self::OTHER_SELLERS_METHOD],
            'v2, another seller\'s method' => ['v2', self::OTHER_SELLERS_METHOD],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function apiVersions(): array
    {
        return ['v3' => ['v3'], 'v2' => ['v2']];
    }

    #[DataProvider('methodsThatAreNotTheSellers')]
    public function test_a_method_that_is_not_the_sellers_is_not_returned_for_editing(string $version, int $methodId): void
    {
        $response = $this->shippingMethodApi($version, 'GET', 'edit/' . $methodId);

        $this->assertStringNotContainsString('express', strtolower((string) $response->getContent()));
        $this->assertStringNotContainsString('Other sellers method', (string) $response->getContent());
        $this->assertNull($response->json('id'));
    }

    #[DataProvider('methodsThatAreNotTheSellers')]
    public function test_a_method_that_is_not_the_sellers_cannot_be_switched_off(string $version, int $methodId): void
    {
        $before = $this->allMethods();

        $this->shippingMethodApi($version, 'PUT', 'status', ['id' => $methodId, 'status' => 0]);

        $this->assertSame($before, $this->allMethods());
    }

    #[DataProvider('methodsThatAreNotTheSellers')]
    public function test_a_method_that_is_not_the_sellers_cannot_be_rewritten(string $version, int $methodId): void
    {
        $before = $this->allMethods();

        $this->shippingMethodApi($version, 'PUT', 'update/' . $methodId, ['title' => 'Hijacked', 'duration' => '99 days', 'cost' => 999]);

        $this->assertSame($before, $this->allMethods());
    }

    #[DataProvider('methodsThatAreNotTheSellers')]
    public function test_a_method_that_is_not_the_sellers_cannot_be_deleted(string $version, int $methodId): void
    {
        $before = $this->allMethods();

        $this->shippingMethodApi($version, 'DELETE', 'delete/' . $methodId, ['id' => $methodId]);

        $this->assertSame($before, $this->allMethods());
    }

    #[DataProvider('apiVersions')]
    public function test_the_seller_can_still_read_their_own_method(string $version): void
    {
        $response = $this->shippingMethodApi($version, 'GET', 'edit/' . self::OWN_METHOD);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_METHOD, 'seller', self::SELLER, 'Own method'], [$response->json('id'), $response->json('creator_type'), $response->json('creator_id'), $response->json('title')]);
    }

    #[DataProvider('apiVersions')]
    public function test_the_seller_can_still_switch_their_own_method_off(string $version): void
    {
        $response = $this->shippingMethodApi($version, 'PUT', 'status', ['id' => self::OWN_METHOD, 'status' => 0]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) DB::table('shipping_methods')->where('id', self::OWN_METHOD)->value('status'));
        $this->assertSame(1, (int) DB::table('shipping_methods')->where('id', self::IN_HOUSE_METHOD)->value('status'));
    }

    #[DataProvider('apiVersions')]
    public function test_the_seller_can_still_rewrite_their_own_method(string $version): void
    {
        $response = $this->shippingMethodApi($version, 'PUT', 'update/' . self::OWN_METHOD, ['title' => 'Own method renamed', 'duration' => '4 days', 'cost' => 30]);

        $this->assertSame(200, $response->getStatusCode());
        $method = DB::table('shipping_methods')->where('id', self::OWN_METHOD)->first();
        $this->assertSame(['Own method renamed', '4 days', 30.0, 'seller', self::SELLER], [$method->title, $method->duration, (float) $method->cost, $method->creator_type, (int) $method->creator_id]);
    }

    #[DataProvider('apiVersions')]
    public function test_the_seller_can_still_delete_their_own_method(string $version): void
    {
        $response = $this->shippingMethodApi($version, 'DELETE', 'delete/' . self::OWN_METHOD, ['id' => self::OWN_METHOD]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::IN_HOUSE_METHOD, self::OTHER_SELLERS_METHOD], DB::table('shipping_methods')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    // ---- helpers ----

    /** @param array<string, mixed> $data */
    private function shippingMethodApi(string $version, string $method, string $uri, array $data = []): TestResponse
    {
        $uri = 'shipping-method/' . $uri;

        return $version === 'v3' ? $this->sellerApi($method, $uri, $data) : $this->sellerApiV2($method, $uri, $data);
    }

    /** @return list<array<string, mixed>> */
    private function allMethods(): array
    {
        return DB::table('shipping_methods')->orderBy('id')->get()->map(fn ($method) => (array) $method)->all();
    }
}
