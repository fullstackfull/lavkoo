<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MtnCashTransaction extends Model
{
    protected $table = 'mtn_cash_transactions';

    protected $fillable = [
        'payment_id',
        'invoice',
        'guid',
        'phone',
        'operation_number',
        'transaction_number',
        'status',
        'mtn_response',
    ];

    protected $casts = [
        'mtn_response' => 'array',
    ];
}