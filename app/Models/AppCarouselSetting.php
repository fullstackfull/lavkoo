<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppCarouselSetting extends Model
{
    protected $table = 'app_carousel_settings';

    protected $fillable = [
        'images',
        'texts',
        'show_text',
        'show_carousel',
    ];

    protected $casts = [
        'images' => 'array',
        'texts' => 'array',
        'show_text' => 'boolean',
        'show_carousel' => 'boolean',
    ];
}