<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The comparison of one invoice line with the estimate of the same service: both amounts, the difference, and what a person decided about it. */
class OperationalCostReconciliation extends Model
{
    public const MATCHED = 'matched';
    public const VARIANCE = 'variance';
    public const UNMATCHED_INVOICE = 'unmatched_invoice';
    public const UNMATCHED_ESTIMATE = 'unmatched_estimate';
    public const DISPUTED = 'disputed';
    public const RESOLVED = 'resolved';

    /** What an invoice line was compared with: the total the channel's statements booked for the same fee and period (the default is the estimate of the same service). */
    public const BASIS_STATEMENTS = 'statements';

    protected $table = 'operational_cost_reconciliations';

    protected $guarded = [];

    protected $casts = [
        'invoice_id' => 'integer',
        'invoice_item_id' => 'integer',
        'estimated_entry_id' => 'integer',
        'actual_entry_id' => 'integer',
        'estimated_amount' => 'decimal:6',
        'actual_amount' => 'decimal:6',
        'variance_amount' => 'decimal:6',
        'variance_percent' => 'decimal:4',
        'tolerance_percent' => 'decimal:4',
        'resolved_at' => 'datetime',
    ];
}
