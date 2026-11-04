<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplateMapping extends Model
{
    protected $table = 'notification_template_mappings';

    protected $fillable = [
        'provider',
        'template_name',
        'template_language',
        'template_category',
        'event',
        'recipient_field',
        'variable_mapping',
        'enabled',
        'event_condition',
    ];

    protected $casts = [
        'variable_mapping' => 'array',
        'enabled' => 'boolean',
    ];
}