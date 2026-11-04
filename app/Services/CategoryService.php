<?php

namespace App\Services;

use App\Traits\GeneratesUniqueSlug;
use App\Traits\ManagesOptionalImage;

class CategoryService
{
    use ManagesOptionalImage, GeneratesUniqueSlug;

    public function getAddData(object $request): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $name = $request['name'][array_search('en', $request['lang'])];

        return [
            'name' => $name,
            'slug' => $this->generateModelUniqueSlug(name: $name, type: 'category'),
            'icon' => $this->upload('category/', 'webp', $request->file('image')),
            'mobile_icon' => $request->file('mobile_image')
                ? $this->upload('category/', 'webp', $request->file('mobile_image'))
                : null,
            'icon_storage_type' => $request->has('image') ? $storage : null,
            'parent_id' => $request->get('parent_id', 0),
            'position' => $request['position'] ?? 0,
            'priority' => $request['priority'] ?? 0,
            'home_status' => 1,
        ];
    }

    public function getUpdateData(object $request, object $data): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        // The old file name is `icon`; passing `image` (an attribute categories do not have) meant
        // every replacement orphaned the file it replaced instead of deleting it.
        $image = $request->file('image') ? $this->update('category/', $data['icon'], 'webp', $request->file('image')) : $data['icon'];
        $name = $request['name'][array_search('en', $request['lang'])];

        $result = [
            'name' => $name,
            'slug' => $this->generateModelUniqueSlug(name: $name, type: 'category', id: $data['id']),
            'icon' => $image,
            'mobile_icon' => $this->getProcessedMobileImage(
                request: $request,
                directory: 'category/',
                storedImage: $data['mobile_icon'],
            ),
            'icon_storage_type' => $request->has('image') ? $storage : $data['icon_storage_type'],
            'priority' => $request['priority'],
        ];

        if ($request['parent_id']) {
            $result['parent_id'] = $request['parent_id'];
        }
        if ($data['position'] == 0) {
            $result['home_status'] = $request['home_status'] ?? 0;
        }
        return $result;
    }

    /**
     * Every category, at every level, labelled with its ancestry.
     *
     * A banner belongs to the page a shopper is standing on, and that page can be
     * a main category, a sub category or a sub-sub category. A flat list of names
     * cannot say which "Cleansers" is meant, so each entry carries its parents:
     * "Skin Care › Cleansers". Ordered by the tree, so the select reads as the
     * catalogue does.
     *
     * @return array<int, array{id:int, label:string, position:int}>
     */
    public function getHierarchicalOptions(object $categories): array
    {
        $byParent = [];
        foreach ($categories as $category) {
            $byParent[(int)$category['parent_id']][] = $category;
        }

        $options = [];
        $walk = function (int $parentId, string $prefix) use (&$walk, &$options, $byParent) {
            foreach ($byParent[$parentId] ?? [] as $category) {
                $label = $prefix === '' ? $category['name'] : $prefix . ' › ' . $category['name'];
                $options[] = [
                    'id' => (int)$category['id'],
                    'label' => $label,
                    'position' => (int)$category['position'],
                ];
                $walk((int)$category['id'], $label);
            }
        };
        $walk(0, '');

        return $options;
    }

    public function getSelectOptionHtml(object $data): string
    {
        $output = '<option value="" disabled selected>' . (translate('select_sub_category')) . '</option>';
        foreach ($data as $row) {
            $output .= '<option value="' . $row->id . '">' . $row->defaultName . '</option>';
        }
        return $output;
    }

    public function deleteImages(object $data): bool
    {
        if ($data->childes) {
            foreach ($data->childes as $child) {
                if ($child->childes) {
                    foreach ($child->childes as $item) {
                        if ($item['icon']) {
                            $this->delete('category/' . $item['icon']);
                        }
                    }
                }
                if ($child['icon']) {
                    $this->delete('category/' . $child['icon']);
                }
            }
        }
        if ($data['icon']) {
            $this->delete('category/' . $data['icon']);
        }
        if ($data['mobile_icon']) {
            $this->delete('category/' . $data['mobile_icon']);
        }
        return true;
    }
}
