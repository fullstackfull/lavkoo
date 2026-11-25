<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The account a market's messages leave through (I1-01, I1-02).
 *
 * One mail host, one SMS gateway chain, one WhatsApp inbox and one push credential served the whole installation: a Kuwaiti customer's
 * one-time code left through the Saudi gateway, and a Kuwaiti order's e-mail left through the Saudi company's mail host and from address --
 * which, once each market is a separate company (GCC-13), is a message signed by a company that did not sell the order and sent from a
 * domain that does not belong to it.
 *
 * **Empty string, not NULL, for the platform-wide row**, exactly as `integration_settings.country` does it (H1-11): MySQL and MariaDB count
 * two NULLs as different in a unique key, so `(channel, NULL)` could be stored twice and the key that stops a channel having two
 * platform-wide accounts would quietly stop biting. The country is `ascii_bin` on a real engine so that a stored `kw` is never read as `KW`,
 * which is how every other market column of this platform is compared, and how run 664's one failure was found.
 *
 * **`credentials` is encrypted and `settings` is not.** The split is the one `integration_settings` already uses for Chatwoot and for the
 * carriers: a password, an API token or a service-account private key goes through `Crypt::encryptString()` into `credentials`, and
 * everything an admin screen may show -- a host, a port, a from address, an inbox id -- is plain JSON in `settings`. Nothing reads
 * `credentials` but the sender that authenticates with it.
 *
 * The table ships EMPTY and the readers are off unless `GCC_NOTIFICATION_PROFILES` is on, so running this migration changes nothing: every
 * message leaves exactly as it does today until an operator writes a row AND the switch is turned on.
 *
 * Rolling back drops the table, which is the state before it: the rows are accounts an operator entered and cannot be derived from anything
 * else, so a rollback that would discard a configured account refuses, for the same reason every other market column's does.
 */
return new class extends Migration {
    private const TABLE = 'notification_channel_profiles';

    private const UNIQUE = 'notification_profiles_channel_country_unique';

    /** The row that belongs to no country in particular: the shared account every installation has. */
    private const PLATFORM_WIDE = '';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $this->addCountryTo($table);
            $table->string('label')->nullable();
            $table->text('settings')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
            $table->unique(['channel', 'country'], self::UNIQUE);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('notification_channel_profiles holds accounts an operator entered. Dropping it would discard credentials that exist nowhere else.');
        }

        Schema::drop(self::TABLE);
    }

    /** The market whose account this row is. Compared byte for byte on a real engine, as every other market column of this platform is. */
    private function addCountryTo(Blueprint $table): void
    {
        $column = $table->char('country', 2)->default(self::PLATFORM_WIDE);

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $column->charset('ascii')->collation('ascii_bin');
        }
    }
};
