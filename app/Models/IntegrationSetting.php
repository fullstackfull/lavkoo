<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $table = 'integration_settings';

    protected $fillable = [
        'provider',
        'name',
        'enabled',
        'base_url',
        'credentials',
        'settings',
        // The country whose account this row is (H1-11). '' is the platform-wide row, which is the only row a one-account platform has.
        'country',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
    ];
}