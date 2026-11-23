<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The countries an employee works with (GCC-14c). One row per employee and country, created and removed only by `gcc:staff-country:assign` and
 * `gcc:staff-country:revoke` on the server: no route can create or widen a scope. An employee with no row is not restricted, so the table is additive: nothing
 * reads it until the staff country scopes are switched on, and nothing changes for anyone until a country is assigned.
 *
 * The country is two characters compared byte for byte, as `products.storefront_country` is, so 'sa' is not 'SA' in either place.
 *
 * A foreign key from `admin_id` to `admins.id`, which removes the countries with their admin, is added only when the real column can be referenced: BIGINT
 * UNSIGNED on an engine that has foreign keys (InnoDB on MySQL and MariaDB; SQLite does not tell the types apart). The dump that creates `admins` is not in this
 * repository, so the migration looks at the column instead of assuming it. A table it cannot reference, or a database that refuses the key for any other
 * reason, is no reason to fail the deploy of the whole application for a feature that ships switched off: the table is created without the key and one warning
 * says why. Without the key a deleted admin leaves its rows behind, and a new admin that is given the same id would inherit them, which can only narrow that
 * employee, never widen them; revoke the countries before deleting an admin to leave nothing behind.
 */
return new class extends Migration {
    private const TABLE = 'admin_country_scopes';

    private const ADMIN_FOREIGN_KEY = 'admin_country_scopes_admin_fk';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $column = $table->char('country', 2);
            $table->timestamps();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $column->charset('ascii')->collation('ascii_bin');
            }

            $table->unique(['admin_id', 'country'], 'admin_country_scopes_admin_country_unique');
        });

        $this->tieRowsToAdmins();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function tieRowsToAdmins(): void
    {
        if (Schema::getConnection()->pretending()) {
            return;
        }

        $obstacle = $this->whyAdminsCannotBeReferenced() ?? $this->tryToAddTheForeignKey();

        if ($obstacle !== null) {
            Log::warning('admin_country_scopes was created without a foreign key to admins: ' . $obstacle . '. Deleting an admin leaves its countries behind, and a new admin given the same id would inherit them, which can only narrow that employee. Run gcc:staff-country:revoke --all for the admin before deleting it, to leave nothing behind.');
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
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->foreign('admin_id', self::ADMIN_FOREIGN_KEY)->references('id')->on('admins')->cascadeOnDelete());
        } catch (QueryException $refusal) {
            return 'the database refused it: ' . ($refusal->getPrevious()?->getMessage() ?? $refusal->getMessage());
        }

        return null;
    }
};
