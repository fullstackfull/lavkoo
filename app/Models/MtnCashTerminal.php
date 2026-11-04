<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MtnCashTerminal extends Model
{
    protected $table = 'mtn_cash_terminals';

    protected $fillable = [
        'terminal_id',
        'private_key',
        'public_key',
        'is_activated',
        'activated_at',
        'terminal_settings',
    ];

    protected $casts = [
        'is_activated' => 'boolean',
        'activated_at' => 'datetime',
        'terminal_settings' => 'array',
    ];
}