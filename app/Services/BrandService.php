<?php

namespace App\Services;

use App\Traits\GeneratesUniqueSlug;
use App\Traits\ManagesOptionalImage;

class BrandService
{
    use ManagesOptionalImage, GeneratesUniqueSlug;

    public function getAddData(object $request): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $name = $request['name'][array_search('en', $request['lang'])];
        return [
            'name' => $name,
            'slug' => $this->generateModelUniqueSlug(name: $name, type: 'brand'),
            'image' => $this->upload('brand/', 'webp', $request->file('image')),
            'mobile_image' => $request->file('mobile_image')
                ? $this->upload('brand/', 'webp', $request->file('mobile_image'))
                : null,
            'image_storage_type' => $request->has('image') ? $storage : null,
            'image_alt_text' => $request['image_alt_text'] ?? null,
            'status' => $request['status'] ?? 0,
        ];
    }

    public function getUpdateData(object $request, object $data): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $image = $request->file('image') ? $this->update('brand/', $data['image'],'webp', $request->file('image')) : $data['image'];
        $name = $request->name[array_search('en', $request['lang'])];
        return  [
            'name' => $name,
            'slug' => $this->generateModelUniqueSlug(name: $name, type: 'brand', id: $data['id']),
            // The full edit page carries no status field; writing the absent value deactivated the
            // brand on every save (its page then redirects home and its banner "never shows").
            'status' => $request->has('status') ? $request['status'] : $data['status'],
            'image' => $image,
            'mobile_image' => $this->getProcessedMobileImage(
                request: $request,
                directory: 'brand/',
                storedImage: $data['mobile_image'],
            ),
            'image_storage_type' => $request->file('image') ? $storage : $data['image_storage_type'],
            'image_alt_text' => $request['image_alt_text']?? $data['image_alt_text' ],
        ];
    }

    public function deleteImage(object $data): bool
    {
        // Brand images live under brand/, not profile/ — deleting a brand used to leave its file
        // behind and try to remove a file in someone else's directory.
        if ($data['image']) {
            $this->delete('brand/' . $data['image']);
        }
        if ($data['mobile_image']) {
            $this->delete('brand/' . $data['mobile_image']);
        }
        return true;
    }

}
