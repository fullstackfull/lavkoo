<?php

namespace Modules\Blog\app\Models;

use App\Utils\RequestArea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\App;

class BlogCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'created_at',
        'updated_at',
    ];

    public function blogs(): HasMany
    {
        return $this->hasMany(Blog::class, 'category_id', 'id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function translations(): MorphMany
    {
        return $this->morphMany('Modules\Blog\app\Models\BlogTranslation', 'translation');
    }

    public function getNameAttribute($name): string|null
    {
        if (RequestArea::isPanel()) {
            return $name;
        }
        $translation = $this->translations()->where(['key' => 'name'])
            ->when(RequestArea::isApi(), function ($query) {
                return $query->where('locale', App::getLocale());
            })
            ->when(!RequestArea::isApi(), function ($query) {
                return $query->where('locale', getDefaultLanguage());
            })
            ->first();

        return $translation?->value ?? $name;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('translate', function (Builder $builder) {
            $builder->with(['translations' => function ($query) {
                if (RequestArea::isApi()) {
                    return $query->where('locale', App::getLocale());
                } else {
                    return $query->where('locale', getDefaultLanguage());
                }
            }]);
        });
    }
}
