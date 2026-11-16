<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Staff members of seller 1 who hold a bearer token of their own, behind the real seller API of BuildsSellerApiWorld: the staff middleware resolves
 * the token into a principal that names the shop and the person, and what that person may do is what their role grants.
 */
trait BuildsSellerApiStaff
{
    use BuildsSellerApiWorld;

    protected function createSellerApiStaffTables(): void
    {
        $this->createSellerApiTable('seller_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id');
            $table->string('name')->nullable();
            $table->text('permissions')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('seller_staff', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('status')->default('active');
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    /** @param list<string> $permissions */
    protected function insertSellerApiRole(string $name, array $permissions): int
    {
        return DB::table('seller_roles')->insertGetId([
            'seller_id' => self::SELLER, 'name' => $name, 'permissions' => json_encode($permissions), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A staff member of seller 1 whose role grants exactly $permissions (null: no role at all).
     *
     * @param list<string>|null $permissions
     * @return array{id: int, token: string}
     */
    protected function insertSellerApiStaffMember(string $name, ?array $permissions): array
    {
        $token = 'staff-' . $name . '-token-that-is-longer-than-thirty-characters';
        $id = DB::table('seller_staff')->insertGetId([
            'seller_id' => self::SELLER, 'seller_role_id' => $permissions === null ? null : $this->insertSellerApiRole(name: 'Role of ' . $name, permissions: $permissions),
            'name' => $name, 'email' => $name . '@alpha.test', 'auth_token' => $token, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /** @param array<string, mixed> $data */
    protected function sellerApiAsStaff(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token])->json($method, '/api/v3/seller/' . $uri, $data);
    }
}
