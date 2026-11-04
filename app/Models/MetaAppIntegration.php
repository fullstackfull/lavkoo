<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetaAppIntegration extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_type',
        'platform',
        'meta_app_id',
        'client_token',
        'package_name',
        'bundle_id',
        'is_active',
        'events',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'events' => 'array',
    ];
}