<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZenderSetting extends Model
{
    protected $fillable = [
        'enabled',
        'base_url',
        'secret',
        'type',
        'message',
        'expire',
        'priority',
        'account',
        'mode',
        'device',
        'gateway',
        'sim',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'expire' => 'integer',
        'priority' => 'integer',
        'sim' => 'integer',
        'secret' => 'encrypted',
    ];
}