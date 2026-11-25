<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which market a person has opened selling into which country, and who opened it (H1-01).
 *
 * The owner's declared list lives in `gcc.shipping.cross_border.pairs`, which is where a release says what it ships with. This table is
 * for the pairs a person changes afterwards: it wins over the list for that one pair, and it keeps the name of whoever changed it, because
 * opening a border is a commercial and legal act and not a configuration tidy-up. A pair with no row here falls back to the list, and a
 * list with no entry refuses -- so the platform ships selling inside each storefront's own country and nothing else.
 *
 * `is_open` is a column rather than the row's existence, so CLOSING a pair the declared list opens is recordable: deleting the row would
 * silently restore the list's answer and lose the fact that somebody closed it.
 *
 * Unique on (source, destination), because one pair has one answer. Both codes are compared BYTE for byte on MySQL and MariaDB
 * (ascii_bin), as `orders.storefront_country` and `products.storefront_country` are and as PHP compares them: a `char(2)` left to the
 * server's own collation matches `kw` with `KW`, which passed on SQLite and failed on MariaDB the last time a market column was built by
 * hand. The index name is spelt short on purpose -- Laravel's own would be 63 characters against a 64-character limit, and that limit has
 * already cost one red run here.
 *
 * `recorded_at` is a DATETIME and not a TIMESTAMP: MySQL's TIMESTAMP range ends in 2038 and silently stored a far-future date as
 * `0000-00-00`.
 */
return new class extends Migration {
    private const TABLE = 'storefront_destination_pairs';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $source = $table->char('source_country', 2);
            $destination = $table->char('destination_country', 2);

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $source->charset('ascii')->collation('ascii_bin');
                $destination->charset('ascii')->collation('ascii_bin');
            }

            $table->boolean('is_open')->default(false);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->dateTime('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['source_country', 'destination_country'], 'sdp_source_destination_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
