<?php

namespace App\Services\Accounting\Odoo;

use Illuminate\Support\Facades\Schema;

/**
 * The Odoo accounting code can be deployed before its migration has run, and until it has, its screens must say so rather than
 * fail on a missing table.
 *
 * Its own check rather than a line in `SalesChannelSchema`: the three Odoo tables are deliberately NOT required by the
 * sales-channel module, because nothing in that module needs them and a shop that never connects an accountant should not be
 * told its channels are unmigrated.
 */
final class OdooSchema
{
    /** The one migration these screens need, named so the page can tell a person exactly what to run. */
    public const MIGRATION = '2026_11_15_100000_create_odoo_accounting_tables';

    private const TABLES = ['odoo_account_mappings', 'odoo_posting_queue', 'odoo_reconciliations'];

    public static function ready(): bool
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
