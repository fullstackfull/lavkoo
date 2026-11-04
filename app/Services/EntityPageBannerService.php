<?php

namespace App\Services;

use App\Models\Banner;
use App\Traits\FileManagerTrait;
use Illuminate\Http\UploadedFile;

/**
 * The page banner that heads a category's or a brand's landing page, managed from the place a
 * merchant actually thinks of it: the category / brand form itself.
 *
 * It stays a plain row in Promotion -> Banners ('Category Banner' / 'Brand Banner', resource-linked
 * and published), so nothing about the storefront or the apps changes — this is only a second,
 * structure-anchored door to the same record. Banner Setup keeps listing and editing it.
 */
class EntityPageBannerService
{
    use FileManagerTrait;

    private const TYPES = [
        'category' => 'Category Banner',
        'brand'    => 'Brand Banner',
    ];

    /** The entity's current page banner (newest wins — same rule the storefront resolves by). */
    public function current(string $entity, int $resourceId): ?Banner
    {
        $type = self::TYPES[$entity] ?? null;
        if (!$type || $resourceId <= 0) {
            return null;
        }

        try {
            return Banner::query()
                ->where('banner_type', $type)
                ->where('resource_type', $entity)
                ->where('resource_id', $resourceId)
                ->where('theme', theme_root_path())
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Store an uploaded page banner for the entity: replaces the image of the existing linked
     * banner row, or creates a fresh published one. A null file is a no-op, so callers can pass
     * the request file through unconditionally.
     */
    public function sync(string $entity, int $resourceId, ?UploadedFile $image): void
    {
        $type = self::TYPES[$entity] ?? null;
        if (!$type || $resourceId <= 0 || !$image || !$image->isValid()) {
            return;
        }

        try {
            $fileName = $this->upload(dir: 'banner/', format: 'webp', image: $image);
            if (!$fileName) {
                return;
            }

            $banner = $this->current($entity, $resourceId);
            if ($banner) {
                $banner->photo = $fileName;
                $banner->save();
                return;
            }

            Banner::create([
                'photo'         => $fileName,
                'banner_type'   => $type,
                'theme'         => theme_root_path(),
                'published'     => 1,
                'resource_type' => $entity,
                'resource_id'   => $resourceId,
                'url'           => null,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
