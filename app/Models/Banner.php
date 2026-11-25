<?php

namespace App\Models;

use App\Services\Storefront\StorefrontVisibility;
use App\Traits\HasStorefrontVisibility;
use App\Traits\StorageTrait;
use App\Utils\InstallationDisk;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    use StorageTrait, HasStorefrontVisibility;

    public const STOREFRONT_ENTITY_TYPE = StorefrontVisibility::BANNER;

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

            // This block ANNOTATES which disk a banner's picture is on. It must never be able to fail the write it annotates, and it
            // could twice over:
            //
            //  - it asked `config('filesystems.disks.default')`, a key this application never sets, so on an s3 installation it recorded
            //    `public` for a file that is on s3 and `storageLink()` then built an address nothing serves (InstallationDisk);
            //  - and `storages` is a table of the installation dump that no migration creates, so where it is absent the `updateOrInsert`
            //    THREW -- inside a `saved` hook, i.e. AFTER the insert. `Banner::create()` raised an exception having already written the
            //    row, and a caller with its own try/catch swallowed it and silently skipped everything after the create. That is how an
            //    orphan banner row got past a test: the row existed and nothing downstream had run.
            //
            // So the disk comes from the one place that knows it, and a missing table means the annotation is simply not written.
            if (!Schema::hasTable('storages')) {
                return;
            }

            $storage = InstallationDisk::name();
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
