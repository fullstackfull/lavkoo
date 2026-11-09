<?php

namespace App\Models;

use App\Services\OperationalCosts\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Three figures for one period and one fact, each from its own place, and the differences BETWEEN them.
 *
 * **A NULL IS NOT A ZERO HERE, AND THE DIFFERENCE IS THE WHOLE POINT.** `source_amount` is null when the channel publishes no
 * statement at all — noon has no settlement endpoint anywhere in its reference — and `odoo_amount` is null when Odoo could not be
 * read. Reporting either as `0.00` would turn "nobody said" into "it was nothing", which is the one mistake a reconciliation
 * cannot make: it would show a difference where there is no claim, or hide one where there is.
 *
 * Nothing here closes a difference. A row that resolved one automatically would be worse than no row, because the figure it
 * agreed with would be whichever this code happened to trust.
 *
 * @property int $odoo_company_id
 * @property string $sales_channel
 * @property string $currency
 * @property string $fact_kind
 * @property string|null $source_amount
 * @property string|null $source_basis
 * @property string|null $ledger_amount
 * @property string|null $odoo_amount
 * @property int $entries_counted
 * @property int $entries_not_posted
 */
class OdooReconciliation extends Model
{
    protected $table = 'odoo_reconciliations';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'odoo_company_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'source_amount' => 'decimal:6',
        'ledger_amount' => 'decimal:6',
        'odoo_amount' => 'decimal:6',
        'entries_counted' => 'integer',
        'entries_not_posted' => 'integer',
        'odoo_read_at' => 'datetime',
    ];

    /**
     * The ledger against the source, or null when one of them has nothing to say.
     *
     * Subtracting from a null would produce a figure that looks like a finding, so a missing leg yields no difference at all —
     * the screen says which leg is missing instead.
     */
    public function sourceDifference(): ?string
    {
        return $this->difference($this->source_amount, $this->ledger_amount);
    }

    /** The ledger against Odoo. Null when Odoo could not be read, which is not the same as Odoo holding nothing. */
    public function odooDifference(): ?string
    {
        return $this->difference($this->ledger_amount, $this->odoo_amount);
    }

    /** True when every leg that HAS a figure agrees. A leg with none is not agreement and not disagreement. */
    public function agrees(): bool
    {
        foreach ([$this->sourceDifference(), $this->odooDifference()] as $difference) {
            if ($difference !== null && (float) $difference !== 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which legs had nothing to say, as stable codes.
     *
     * @return list<string>
     */
    public function missingLegs(): array
    {
        $missing = [];
        if ($this->source_amount === null) {
            $missing[] = 'source';
        }
        if ($this->ledger_amount === null) {
            $missing[] = 'ledger';
        }
        if ($this->odoo_amount === null) {
            $missing[] = 'odoo';
        }

        return $missing;
    }

    /**
     * The difference between two legs, as an exact decimal.
     *
     * Through `Money` rather than arithmetic on floats, for the reason the rest of the cost module uses it: a reconciliation
     * that reports a difference of 0.000001 because of binary rounding sends somebody looking for a discrepancy that does not
     * exist. (`bcsub` is not available on every machine this runs on either.)
     */
    private function difference(?string $left, ?string $right): ?string
    {
        return $left === null || $right === null ? null : (string) Money::of($left)->minus(Money::of($right));
    }
}
