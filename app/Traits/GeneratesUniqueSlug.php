<?php

namespace App\Traits;

use App\Contracts\Repositories\AdminRepositoryInterface;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\Shop;
use Illuminate\Support\Str;

trait GeneratesUniqueSlug
{
    /**
     * Generate a unique slug for a given model type.
     *
     * @param string|null $name
     * @param string|null $type (e.g. 'brand', 'category', 'product')
     * @param int|null $id The record ID to exclude (for updates)
     * @return string
     */
    public function generateModelUniqueSlug(?string $name = null, ?string $type = null, ?int $id = null): string
    {
        if (is_null($name) || is_null($type)) {
            return Str::slug(Str::random(10) . '-' . time());
        }

        $modelClass = $this->resolveModelClass($type);

        $slug = Str::slug($name);

        if (empty($slug)) {
            $slug = Str::random(12);
        }

        $originalSlug = $slug;
        $count = 2;

        while (
        $modelClass::where('slug', $slug)
            ->when($id, fn($q) => $q->where('id', '!=', $id))
            ->exists()
        ) {
            $slug = $originalSlug . '-' . str_pad($count, 2, '0', STR_PAD_LEFT);
            $count++;
        }

        return $slug;
    }

    /**
     * Resolve the model class based on the given type.
     *
     * @param string $type
     * @return string
     */
    private function resolveModelClass(string $type): string
    {
        return match (strtolower($type)) {
            'brand' => \App\Models\Brand::class,
            'category' => \App\Models\Category::class,
            // add more types here if needed
            default => throw new \InvalidArgumentException("Unknown type: {$type}"),
        };
    }
}
