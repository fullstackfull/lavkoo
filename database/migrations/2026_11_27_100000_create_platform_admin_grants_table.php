<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Who may use the platform admin API (GCC-20, level 1). One row per admin, created and revoked only by `gcc:platform-admin:grant` and
 * `gcc:platform-admin:revoke` on the server: no route can create or widen a grant. A null `countries` is the platform owner, who sees every
 * market; a list of country codes is a viewer restricted to those markets. Additive: the table is new and nothing reads it until the API is
 * switched on.
 *
 * `admin_fingerprint` is what the grant remembers of the admin row it was made for: the id and the creation time of the row, hashed. A row that
 * merely comes to carry the same id later (a deleted admin whose id is reused, a restored dump) is not that admin, and the API treats a grant
 * whose fingerprint is not the row's as no grant.
 *
 * A foreign key from `admin_id` to `admins.id`, which removes a grant with its admin, is added only when the real column can be referenced:
 * BIGINT UNSIGNED on an engine that has foreign keys (InnoDB on MySQL and MariaDB; SQLite does not tell the types apart). The dump that
 * creates `admins` is not in this repository, so the migration looks at the column instead of assuming it. A table it cannot reference, or a
 * database that refuses the key for any other reason, is no reason to fail the deploy of the whole application for a feature that ships
 * switched off: the table is created without the key and one warning says why. Without the key a deleted admin leaves its grant and sessions
 * behind, unusable by any other row; revoke the grant before deleting the admin to leave nothing behind.
 */
return new class extends Migration {
    private const ADMIN_FOREIGN_KEY = 'platform_admin_grants_admin_fk';

    public function up(): void
    {
        Schema::create('platform_admin_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->unique();
            $table->char('admin_fingerprint', 64);
            $table->unsignedTinyInteger('level')->default(1);
            $table->json('countries')->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        $this->tieGrantsToAdmins();
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_grants');
    }

    private function tieGrantsToAdmins(): void
    {
        if (Schema::getConnection()->pretending()) {
            return;
        }

        $obstacle = $this->whyAdminsCannotBeReferenced() ?? $this->tryToAddTheForeignKey();

        if ($obstacle !== null) {
            Log::warning("platform_admin_grants was created without a foreign key to admins: {$obstacle}. A grant is tied to the admin row it was made for by its fingerprint instead, so another row that takes the same id inherits nothing. Deleting an admin leaves its grant and sessions behind: run gcc:platform-admin:revoke for the admin before deleting it, so that they are ended.");
        }
    }

    /** @return string|null what stands in the way of a foreign key to admins.id, or null when it can be made */
    private function whyAdminsCannotBeReferenced(): ?string
    {
        return match (true) {
            !Schema::hasTable('admins') => 'there is no admins table',
            Schema::getConnection()->getDriverName() === 'sqlite' => null,
            default => $this->whyAdminsOfMySqlCannotBeReferenced(),
        };
    }

    private function whyAdminsOfMySqlCannotBeReferenced(): ?string
    {
        // Without a schema, getTables() lists the tables of every database on the server, and another database's admins table would be taken for this one.
        $tables = Schema::getTables(schema: Schema::getConnection()->getDatabaseName());
        $engine = collect($tables)->firstWhere('name', 'admins')['engine'] ?? null;
        $idType = collect(Schema::getColumns('admins'))->firstWhere('name', 'id')['type'] ?? null;

        return match (true) {
            strcasecmp((string) $engine, 'InnoDB') !== 0 => 'the admins table is ' . ($engine ?? 'of an unknown engine') . ' and only InnoDB tables can be referenced',
            $idType === null => 'admins has no id column',
            preg_match('/^bigint(\(\d+\))? unsigned$/i', $idType) !== 1 => "admins.id is {$idType}, not BIGINT UNSIGNED",
            default => null,
        };
    }

    /** @return string|null why the database refused the key, or null when it is there */
    private function tryToAddTheForeignKey(): ?string
    {
        try {
            Schema::table('platform_admin_grants', fn (Blueprint $table) => $table->foreign('admin_id', self::ADMIN_FOREIGN_KEY)->references('id')->on('admins')->cascadeOnDelete());
        } catch (QueryException $refusal) {
            return 'the database refused it: ' . ($refusal->getPrevious()?->getMessage() ?? $refusal->getMessage());
        }

        return null;
    }
};
