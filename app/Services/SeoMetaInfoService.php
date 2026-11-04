<?php

namespace App\Services;

use App\Models\Color;
use App\Traits\FileManagerTrait;

class SeoMetaInfoService
{
    use FileManagerTrait;

    public function __construct(private readonly Color $color)
    {
    }

    public function getModelSEOData(object $request, object|null $seoMetaInfo = null, string $type = '', int $modelId = null, ?string $action = null): array
    {
        if ($seoMetaInfo) {
            if ($request->file('meta_image')) {
                $metaImage = $this->update(dir: 'seo-meta-info/', oldImage: $seoMetaInfo['image'], format: 'png', image: $request['meta_image']);
            } elseif (!$request->file('meta_image') && $request->file('image') && $action == 'add') {
                $metaImage = $this->upload(dir: 'seo-meta-info/', format: 'webp', image: $request['image']);
            } else {
                $metaImage = $seoMetaInfo?->image ?? $seoMetaInfo['image'];
            }
        } else {
            if ($request->file('meta_image')) {
                $metaImage = $this->upload(dir: 'seo-meta-info/', format: 'webp', image: $request['meta_image']);
            } elseif (!$request->file('meta_image') && $request->file('image') && $action == 'add') {
                $metaImage = $this->upload(dir: 'seo-meta-info/', format: 'webp', image: $request['image']);
            }
        }
        return [
            "seoable_type" => $type,
            "seoable_id" => $modelId,
            "title" => $request['meta_title'] ?? ($seoMetaInfo ? $seoMetaInfo['meta_title'] : null),
            "description" => $request['meta_description'] ?? ($seoMetaInfo ? $seoMetaInfo['meta_description'] : null),
            "index" => $request['meta_index'] == 'index' ? '' : 'noindex',
            "no_follow" => $request['meta_no_follow'] ? 'nofollow' : '',
            "no_image_index" => $request['meta_no_image_index'] ? 'noimageindex' : '',
            "no_archive" => $request['meta_no_archive'] ? 'noarchive' : '',
            "no_snippet" => $request['meta_no_snippet'] ?? 0,
            "max_snippet" => $request['meta_max_snippet'] ?? 0,
            "max_snippet_value" => $request['meta_max_snippet_value'] ?? 0,
            "max_video_preview" => $request['meta_max_video_preview'] ?? 0,
            "max_video_preview_value" => $request['meta_max_video_preview_value'] ?? 0,
            "max_image_preview" => $request['meta_max_image_preview'] ?? 0,
            "max_image_preview_value" => $request['meta_max_image_preview_value'] ?? 0,
            "image" => $metaImage ?? ($seoMetaInfo ? $seoMetaInfo['image'] : null),
            "created_at" => now(),
            "updated_at" => now(),
        ];
    }
}
