<?php

namespace App\Services;

use App\Traits\FileManagerTrait;
use Illuminate\Support\Str;

class BusinessPageService
{
    use FileManagerTrait;

    public function getPageAddData(object|array $request): array
    {
        return [
            'title' => $request['title'],
            'slug' => Str::slug($request['title']),
            'description' => $request['description'],
            'status' => $request['status'] ?? 0,
        ];
    }

    public function getPageUpdateData(object|array $request): array
    {
        if (in_array(Str::slug($request['title']), ['terms-and-conditions', 'about-us', 'privacy-policy'])) {
            $request->merge(['status' => 1]);
        }

        $defaultPages = [
            'about_us' => 'about-us',
            'terms_condition' => 'terms-and-conditions',
            'privacy_policy' => 'privacy-policy',
            'refund-policy' => 'refund-policy',
            'return-policy' => 'return-policy',
            'cancellation-policy' => 'cancellation-policy',
            'shipping-policy' => 'shipping-policy',
        ];

        $slug = Str::slug($request['title']);
        if ($request->has('slug') && in_array($request['slug'], $defaultPages)) {
            $slug = Str::slug($request['slug']);
        }

        $status = $request['status'] ?? 0;
        if (in_array($request['slug'], ['terms-and-conditions', 'about-us', 'privacy-policy'])) {
            $status = 1;
        }
        return [
            'title' => $request['title'],
            'slug' => $slug,
            'description' => $request['description'],
            'status' => $status,
        ];
    }

    public function getPageAttachmentAddData(object|array $request, object|array $page): array
    {
        $banner = $this->upload(dir: 'business-pages/', format: 'webp', image: $request['banner']);
        $storage = config('filesystems.disks.default') ?? 'public';
        return [
            'attachable_type' => 'App\Models\BusinessPage',
            'attachable_id' => $page->id,
            'file_type' => 'banner',
            'file_name' => $banner,
            'storage_disk' => $storage,
        ];
    }

}
