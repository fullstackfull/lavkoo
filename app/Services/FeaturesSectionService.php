<?php

namespace App\Services;

use App\Traits\FileManagerTrait;

class FeaturesSectionService
{
    use FileManagerTrait;

    public function getBottomSectionData(object $request, object|null $featuresBottomSection = null): array
    {
        $bottomSectionData = [];
        if($featuresBottomSection) {
            $bottomSectionData = json_decode($featuresBottomSection['value']);
        }
        foreach($request['features_section_bottom']['title'] as $key => $value) {
            $iconArray = null;
            if (!empty($request['features_section_bottom_icon']) && isset($request['features_section_bottom_icon'][$key])) {
                $image = $this->upload(dir: 'banner/', format: 'webp', image: $request['features_section_bottom_icon'][$key]);
                $iconArray = [
                    'image_name' =>  $image,
                    'storage' => config('filesystems.disks.default') ?? 'public'
                ];
            }
            $bottomSectionData[] = [
                'title' => $request['features_section_bottom']['title'][$key],
                'subtitle' => $request['features_section_bottom']['subtitle'][$key],
                'icon' => $iconArray,
            ];
        }
        return $bottomSectionData;
    }

    public function getDeleteData(object $request, object $data): array
    {
        $newArray = [];
        foreach(json_decode($data->value) as $item) {
            if($request->title != $item->title && $request->subtitle != $item->subtitle){
                $newArray[] = $item;
            }else{
                $this->delete(filePath: "/banner/" . ($item?->icon?->image_name ?? $item?->icon));
            }
        }
        return $newArray;
    }

    public function getReliabilityUpdateData(object $request, object $data): array
    {
        $items = [];
        $storage = config('filesystems.disks.default', 'public');

        $decodedData = json_decode($data['value'], true);

        foreach ($decodedData as $key => $itemData) {
            $index = $key + 1;

            $itemKey = 'item_' . $index;
            $titleKey = 'title_' . $index;
            $statusKey = 'status_' . $index;
            $imageKey = 'image_' . $index;

            $imageArray = null;

            if ($request->hasFile($imageKey) && $itemData['item'] == $request->input($itemKey)) {
                $imageArray = [
                    'image_name' => $this->update(
                        dir: 'company-reliability/',
                        oldImage: is_array($itemData['image']) ? $itemData['image']['image_name'] : $itemData['image'],
                        format: 'webp',
                        image: $request->file($imageKey)
                    ),
                    'storage' => $storage
                ];
            }

            if ($itemData['item'] == $request->input($itemKey)) {
                $updatedItem = [
                    'item' => $request->input($itemKey),
                    'title' => $request->input($titleKey) ?? '',
                    'image' => $imageArray ?? $itemData['image'],
                    'status' => $request->input($statusKey) ?? 0,
                ];
            } else {
                $updatedItem = [
                    'item' => $itemData['item'],
                    'title' => $itemData['title'],
                    'image' => $itemData['image'],
                    'status' => $itemData['status'] ?? 0,
                ];
            }

            $items[] = $updatedItem;
        }

        return $items;
    }

}
