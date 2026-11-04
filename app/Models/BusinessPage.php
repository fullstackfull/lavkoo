<?php

namespace App\Models;

use App\Traits\StorageTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * App\Models\BusinessPage
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property int $status
 * @property int $default_status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BusinessPage extends Model
{
    use StorageTrait;
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'status',
        'default_status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'id' => 'integer',
        'title' => 'string',
        'slug' => 'string',
        'description' => 'string',
        'status' => 'integer',
        'default_status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['banner_full_url'];

    public function banner(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')->where(['file_type' => 'banner']);
    }

    public function getBannerFullUrlAttribute(): string|null|array
    {
        $banner = $this->banner;
        if (strpos(url()->current(), '/api') && !$banner) {
            return [
                'key' => $banner?->file_name ?? 'business-pages',
                'path' => getStorageImages(path: $banner?->file_name, type: 'business-page'),
                'status' => 200,
            ];
        }
        return $this->storageLink('business-pages', $banner?->file_name, $banner?->storage_disk ?? 'public');
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
