<?php

namespace App\Models;

use App\Traits\StorageTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Class YourModel
 *
 * @property int $id Primary
 * @property string $photo
 * @property string $banner_type
 * @property string $theme
 * @property int $published
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $url
 * @property string $resource_type
 * @property int $resource_id
 * @property string $title
 * @property string $sub_title
 * @property string $button_text
 * @property string $background_color
 *
 * @package App\Models
 */
class Banner extends Model
{
    use StorageTrait;

    protected $casts = [
        'id' => 'integer',
        'published' => 'integer',
        'resource_id' => 'integer',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'photo',
        'mobile_photo',
        'banner_type',
        'layout',
        'priority',
        'theme',
        'published',
        'url',
        'resource_type',
        'resource_id',
        'title',
        'sub_title',
        'button_text',
        'background_color',
    ];

    protected $appends = ['photo_full_url', 'mobile_photo_full_url'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'resource_id');
    }

    public function getPhotoFullUrlAttribute(): string|null|array
    {
        $value = $this->photo;
        if (count($this->storage) > 0) {
            $storage = $this->storage->where('key', 'photo')->first();
        }
        return $this->storageLink('banner', $value, $storage['value'] ?? 'public');
    }

    /**
     * The app-facing image. Banners without one fall back to the web image, so
     * an app always has something to render.
     */
    public function getMobilePhotoFullUrlAttribute(): string|null|array
    {
        if (empty($this->mobile_photo)) {
            return $this->photo_full_url;
        }

        if (count($this->storage) > 0) {
            $storage = $this->storage->where('key', 'mobile_photo')->first();
        }
        return $this->storageLink('banner', $this->mobile_photo, $storage['value'] ?? 'public');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::saved(function ($model) {
            cacheRemoveByType(type: 'banners');

            $storage = config('filesystems.disks.default') ?? 'public';
            foreach (['photo', 'mobile_photo'] as $file) {
                if ($model->isDirty($file) && !empty($model->{$file})) {
                    $value = $storage;
                    DB::table('storages')->updateOrInsert([
                        'data_type' => get_class($model),
                        'data_id' => $model->id,
                        'key' => $file,
                    ], [
                        'value' => $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        static::deleted(function ($model) {
            cacheRemoveByType(type: 'banners');
        });
    }
}
