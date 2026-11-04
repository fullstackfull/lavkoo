<?php

namespace Modules\TaxModule\app\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderTax extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'tax_percentage' => 'float',
        'tax_amount' => 'float',
        'before_tax_amount' => 'float',
        'after_tax_amount' => 'float',
        'order_id' => 'integer',
        'tax_id' => 'integer',
        'store_id' => 'integer',
        'system_tax_setup_id' => 'integer',
    ];


    public function Orders(): HasMany
    {
        return $this->hasMany(Order::class, 'order_id');
    }

    public function store(): MorphTo
    {
        return $this->morphTo();
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }
}
