<?php

namespace App\Models;

use App\Traits\CacheManagerTrait;
use App\Traits\StorageTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class SeoMetaInfo
 *
 * @property int $id
 * @property string $seoable_type
 * @property int $seoable_id
 * @property int|null $product_id
 * @property string|null $title
 * @property string|null $description
 * @property string|null $index
 * @property string|null $no_follow
 * @property string|null $no_image_index
 * @property string|null $no_archive
 * @property string|null $no_snippet
 * @property string|null $max_snippet
 * @property string|null $max_snippet_value
 * @property string|null $max_video_preview
 * @property string|null $max_video_preview_value
 * @property string|null $max_image_preview
 * @property string|null $max_image_preview_value
 * @property string|null $image
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 */
class SeoMetaInfo extends Model
{
    use StorageTrait, CacheManagerTrait;

    protected $table = 'seo_meta_info';

    protected $fillable = [
        'seoable_type',
        'seoable_id',
        'product_id',
        'title',
        'description',
        'index',
        'no_follow',
        'no_image_index',
        'no_archive',
        'no_snippet',
        'max_snippet',
        'max_snippet_value',
        'max_video_preview',
        'max_video_preview_value',
        'max_image_preview',
        'max_image_preview_value',
        'image',
    ];

    protected $casts = [
        'seoable_type' => 'string',
        'seoable_id' => 'integer',
        'product_id' => 'integer',
        'title' => 'string',
        'description' => 'string',
        'index' => 'string',
        'no_follow' => 'string',
        'no_image_index' => 'string',
        'no_archive' => 'string',
        'no_snippet' => 'string',
        'max_snippet' => 'string',
        'max_snippet_value' => 'string',
        'max_video_preview' => 'string',
        'max_video_preview_value' => 'string',
        'max_image_preview' => 'string',
        'max_image_preview_value' => 'string',
        'image' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['image_full_url'];

    public function getImageFullUrlAttribute(): array
    {
        $value = $this->image;
        if (count($this->storage) > 0 ) {
            $storage = $this->storage->where('key','image')->first();
        }
        return $this->storageLink('seo-meta-info', $value, $storage['value'] ?? 'public');
    }

    protected static function boot(): void
    {
        parent::boot();
    }
}
