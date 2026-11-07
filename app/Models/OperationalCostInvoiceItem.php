<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalCostInvoiceItem extends Model
{
    public const UNMATCHED = 'unmatched';
    public const MATCHED = 'matched';
    public const PARTIAL = 'partial';
    public const DISPUTED = 'disputed';
    public const IGNORED = 'ignored';

    protected $table = 'operational_cost_invoice_items';

    protected $guarded = [];

    protected $casts = [
        'invoice_id' => 'integer',
        'line_no' => 'integer',
        'quantity' => 'decimal:6',
        'unit_price' => 'decimal:6',
        'amount' => 'decimal:6',
        'tax_amount' => 'decimal:6',
        'product_id' => 'integer',
        'order_id' => 'integer',
        'provider_fulfillment_id' => 'integer',
        'external_order_mapping_id' => 'integer',
        'purchase_order_item_id' => 'integer',
        'resembles_purchase_order_item_id' => 'integer',
        'service_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'entry_id' => 'integer',
        'raw' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(OperationalCostInvoice::class, 'invoice_id');
    }
}
