<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What one of our facts means in the customer's chart of accounts.
 *
 * **It holds no cost.** `OperationalCosts` computes every figure; this row only says which Odoo account, journal, tax and cost
 * centre a figure of that kind belongs to in THIS customer's books. Every id in it was READ from Odoo, never invented.
 *
 * "Any channel" and "any currency" are the empty string rather than NULL on purpose: a unique index does not constrain NULLs on
 * any engine this runs on, so a nullable column would have let the general mapping -- the one that applies to everything, and so
 * the one most worth protecting -- be inserted any number of times.
 *
 * @property int $id
 * @property int $odoo_company_id
 * @property string $fact_kind
 * @property string $fact_code
 * @property string $sales_channel
 * @property string $currency
 * @property int $odoo_account_id
 * @property int $odoo_journal_id
 * @property int|null $odoo_tax_id
 * @property int|null $odoo_analytic_account_id
 */
class OdooAccountMapping extends Model
{
    /** The expense, income or balance account one of our cost components posts to. */
    public const KIND_COST_COMPONENT = 'cost_component';

    /**
     * The OTHER side of the entry: what the expense is owed to, or accrued against.
     *
     * Its own kind rather than a second column on the component row, because a customer may want every marketplace fee accrued
     * to one account while each fee has its own expense account -- and because "which account balances this" is a different
     * decision from "which account is this", made by the same person at a different moment.
     */
    public const KIND_COUNTERPART = 'counterpart';

    /** The `fact_code` that stands for "whatever has no mapping of its own". Spelled out so a general row is deliberate. */
    public const CODE_DEFAULT = 'default';

    /** Spelled the same way in the column and in a query: "every channel" and "every currency". */
    public const ANY = '';

    protected $table = 'odoo_account_mappings';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'odoo_company_id' => 'integer',
        'odoo_account_id' => 'integer',
        'odoo_journal_id' => 'integer',
        'odoo_tax_id' => 'integer',
        'odoo_analytic_account_id' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /** An unapproved mapping is never posted through: somebody has to have looked at which account this writes to. */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * How specific this row is, for choosing between rows that all match.
     *
     * A row naming both the channel and the currency outranks one naming only the channel, which outranks the general row. The
     * alternative -- "whichever the database returned first" -- would make the account an entry posts to depend on row order.
     */
    public function specificity(): int
    {
        return ($this->sales_channel === self::ANY ? 0 : 2) + ($this->currency === self::ANY ? 0 : 1);
    }
}
