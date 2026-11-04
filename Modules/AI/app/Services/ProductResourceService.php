<?php

namespace Modules\AI\app\Services;

use App\Models\Attribute;
use App\Models\Author;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\PublishingHouse;

class ProductResourceService
{
    protected Category $category;
    protected Brand $brand;
    protected Attribute $attribute;
    protected Color $color;
    protected Author $author;
    protected PublishingHouse $publishingHouse;

    private array $productType = ["physical", "digital"];
    private array $deliveryTypes = ["ready_product", "ready_after_sell"];

    public function __construct()
    {
        $this->category = new Category();
        $this->brand = new Brand();
        $this->attribute = new Attribute();
        $this->color = new Color();
        $this->author = new Author();
        $this->publishingHouse = new PublishingHouse();
    }

    private function getCategoryEntitiyData($position = 0)
    {
        return $this->category
            ->where(['position' => $position])
            ->get(['id', 'name'])
            ->mapWithKeys(fn($item) => [strtolower($item->name) => $item->id])
            ->toArray();
    }

    private function getBrandData()
    {
        return $this->brand->active()
            ->get(['id', 'name'])
            ->mapWithKeys(fn($item) => [strtolower($item->name) => $item->id])
            ->toArray();
    }

    public function productGeneralSetupData(): array
    {
        return [
            'categories' => $this->getCategoryEntitiyData(0),
            'sub_categories' => $this->getCategoryEntitiyData(1),
            'sub_sub_categories' => $this->getCategoryEntitiyData(2),
            'brands' => $this->getBrandData(),
            'units' => $this->units(),
            'product_types' => $this->productType,
            'delivery_types' => $this->deliveryTypes,

        ];
    }

    public function getVariationData(): array
    {
        return [
            'attributes' => $this->attribute
                ->get(['id', 'name'])
                ->mapWithKeys(fn($item) => [strtolower($item->name) => $item->id])
                ->toArray(),
            'color' => $this->color
                ->get(['id', 'name', 'code'])
                ->mapWithKeys(fn($item) => [
                    strtolower($item->name) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'code' => $item->code
                    ]
                ])
                ->toArray(),
        ];
    }

    public function units(): array
    {
        return ['kg', 'pc', 'gms', 'ltrs'];
    }
}
