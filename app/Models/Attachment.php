<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Class App\Models\Attachment
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string|null $file_type
 * @property string|null $file_name
 * @property string $storage_disk
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'file_type',
        'file_name',
        'storage_disk',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'attachable_type' => 'string',
        'attachable_id' => 'integer',
        'file_type' => 'string',
        'file_name' => 'string',
        'storage_disk' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(function ($model) {
            cacheRemoveByType(type: 'business_pages');
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'business_pages');
        });
    }
}
