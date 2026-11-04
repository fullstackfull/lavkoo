<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\RecentSearch
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $user_type
 * @property string|null $title
 * @property string|null $route_uri
 * @property string|null $route_full_url
 * @property string|null $keyword
 * @property array|null $response
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecentSearch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_type',
        'title',
        'route_uri',
        'route_full_url',
        'keyword',
        'response',
    ];

    protected $casts = [
        'response' => 'json',
    ];
}
