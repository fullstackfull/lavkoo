<?php

namespace App\Models;

use App\Models\Builders\AuditedBuilder;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;
    use HasUuid;

    protected $table = 'addon_settings';

    protected $casts = [
        'live_values' => 'array',
        'test_values' => 'array',
        'is_active' => 'integer',
    ];

    protected $fillable = ['id', 'key_name', 'live_values', 'test_values', 'settings_type', 'mode', 'is_active', 'additional_data', 'created_at', 'updated_at'];

    /**
     * Payment, SMS and mail credentials live in this table and are written with mass updates. Same
     * reasoning as BusinessSetting: the builder records the change, the redactor drops the value.
     */
    public function newEloquentBuilder($query): AuditedBuilder
    {
        return new AuditedBuilder($query);
    }
}
