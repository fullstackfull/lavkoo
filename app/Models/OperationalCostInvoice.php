<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice or statement from a company (a fulfilment provider, a marketplace, a payment gateway, a supplier of goods).
 * `kind` is `purchase` for goods we bought, `service` for what a provider charged, `marketplace_statement` for an imported statement.
 */
class OperationalCostInvoice extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_VOID = 'void';

    protected $table = 'operational_cost_invoices';

    protected $guarded = [];

    protected $casts = [
        'seller_id' => 'integer',
        'invoice_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'subtotal' => 'decimal:6',
        'tax_total' => 'decimal:6',
        'total' => 'decimal:6',
        'inventory_location_id' => 'integer',
        'supplier_id' => 'integer',
        'account_id' => 'integer',
        'duplicate_of_id' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OperationalCostInvoiceItem::class, 'invoice_id')->orderBy('line_no');
    }
}
