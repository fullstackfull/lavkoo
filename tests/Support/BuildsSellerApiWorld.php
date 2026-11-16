<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * Two sellers behind the real seller API (route, token middleware and permission middleware), on an INFERRED minimal schema: the
 * installation dump that owns the store tables is not in the repository, so each test creates only the tables its handler reads.
 *
 * Seller 1 is the caller, and its id is also the id the in-house shop's rows carry (added_by 'admin', user_id 1), so a handler that
 * scopes on the id alone shows up here as reaching the in-house shop. Seller 2 is the other seller.
 *
 * The drop that starts every test is guarded the way the other schema helpers guard theirs (RefusesNonTestDatabases). translate() writes a
 * placeholder for a key it does not know into the tracked language files; they are put back after every test.
 */
trait BuildsSellerApiWorld
{
    use RefusesNonTestDatabases, RestoresLanguageFiles;

    protected const SELLER = 1;
    protected const OTHER_SELLER = 2;
    protected const IN_HOUSE_ID = 1;

    private const SELLER_TOKENS = [
        self::SELLER => 'seller-a-token-that-is-longer-than-thirty-characters',
        self::OTHER_SELLER => 'seller-b-token-that-is-longer-than-thirty-characters',
    ];

    protected function buildSellerApiWorld(): void
    {
        $this->rememberLanguageFiles();
        $this->startFromAnEmptySellerApiDatabase();

        $this->createSellerApiTable('business_settings', function (Blueprint $table) {
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('sellers', function (Blueprint $table) {
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (self::SELLER_TOKENS as $sellerId => $token) {
            DB::table('sellers')->insert(['id' => $sellerId, 'f_name' => 'Seller ' . $sellerId, 'l_name' => 'Test', 'status' => 'approved', 'auth_token' => $token]);
        }
    }

    protected function tearDownSellerApiWorld(): void
    {
        $this->restoreLanguageFiles();
    }

    protected function createSellerApiTable(string $name, Closure $columns): void
    {
        $this->refuseUnlessDisposableTestDatabase();

        Schema::dropIfExists($name);
        Schema::create($name, function (Blueprint $table) use ($columns) {
            $table->id();
            $columns($table);
        });
    }

    /** @param array<string, mixed> $data */
    protected function sellerApi(string $method, string $uri, array $data = [], int $as = self::SELLER): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . self::SELLER_TOKENS[$as], 'Accept' => 'application/json'])
            ->json($method, '/api/v3/seller/' . $uri, $data);
    }

    private function startFromAnEmptySellerApiDatabase(): void
    {
        $this->refuseUnlessDisposableTestDatabase();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::dropAllTables();
        }
    }
}
