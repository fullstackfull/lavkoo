<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
/**
 * Class Currency
 *
 * @property int $id Primary
 * @property string $name
 * @property string $symbol
 * @property string $code
 * @property string $exchange_rate
 * @property bool $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package App\Models
 */
class Currency extends Model
{

    protected $casts = [
        'id' => 'integer',
        'status' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'name',
        'symbol',
        'code',
        'exchange_rate',
        'status',
    ];

    protected $table = 'currencies';

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            cacheRemoveByType(type: 'currencies');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'currencies');
        });
    }
}
