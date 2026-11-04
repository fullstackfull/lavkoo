<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Traits\FileManagerTrait;
use App\Utils\ProductManager;
use Illuminate\Support\Str;

class ShopService
{
    use FileManagerTrait;

    /**
     * @param object $vendor
     * @return array
     */
    public function getShopDataForAdd(object $vendor): array
    {
        return [
            'seller_id' => $vendor['id'],
            'name' => $vendor['f_name'],
            'address' => '',
            'contact' => $vendor['phone'],
            'image' => 'def.png',
            'created_at' => now(),
            'updated_at' => now()
        ];
    }

    /**
     * @return array[name: mixed, address: mixed, contact: mixed, image: bool|mixed, banner: bool|mixed, bottomBanner: bool|mixed, offerBanner: bool|mixed]
     */
    public function getShopDataForUpdate(object $request, object $shop): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $image = $request['image'] ? $this->update(dir: 'shop/', oldImage: $shop['image'], format: 'webp', image: $request->file('image')) : $shop['image'];
        $banner = $request['banner'] ? $this->update(dir: 'shop/banner/', oldImage: $shop['banner'], format: 'webp', image: $request->file('banner')) : $shop['banner'];
        $bottomBanner = $request['bottom_banner'] ? $this->update(dir: 'shop/banner/', oldImage: $shop['bottom_banner'], format: 'webp', image: $request->file('bottom_banner')) : $shop['bottom_banner'];
        $offerBanner = $request['offer_banner'] ? $this->update(dir: 'shop/banner/', oldImage: $shop['offer_banner'], format: 'webp', image: $request->file('offer_banner')) : $shop['offer_banner'];
        return [
            'name' => $request['name'],
            'address' => $request['address'],
            'contact' => $request['company_phone'],
            'image' => $image,
            'image_storage_type' => $request->has('image') ? $storage : $shop['image_storage_type'],
            'banner' => $banner,
            'banner_storage_type' => $request->has('banner') ? $storage : $shop['banner_storage_type'],
            'bottom_banner' => $bottomBanner,
            'bottom_banner_storage_type' => $request->has('bottom_banner') ? $storage : $shop['bottom_banner_storage_type'],
            'offer_banner' => $offerBanner,
            'offer_banner_storage_type' => $request->has('offer_banner') ? $storage : $shop['offer_banner_storage_type'],
        ];
    }

    /**
     * @return array[vacation_status: int, vacation_start_date: mixed, vacation_end_date: mixed, vacation_note: mixed]
     */
    public function getVacationData(object $request): array
    {
        return [
            'vacation_status' => $request['vacation_status'] == 1 ? 1 : 0,
            'vacation_duration_type' => $request['vacation_duration_type'],
            'vacation_start_date' => $request['vacation_start_date'],
            'vacation_end_date' => $request['vacation_end_date'],
            'vacation_note' => $request['vacation_note'],
        ];
    }

    public function getAddShopDataForRegistration(object $request, int $vendorId): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        return [
            'seller_id' => $vendorId,
            'name' => $request['shop_name'],
            'slug' => Str::slug($request['shop_name'], '-') . '-' . Str::random(6),
            'address' => $request['shop_address'],
            'contact' => $request['phone'],
            'image' => $this->upload(dir: 'shop/', format: 'webp', image: $request->file('logo')),
            'image_storage_type' => $request->has('logo') ? $storage : null,
            'banner' => $this->upload(dir: 'shop/banner/', format: 'webp', image: $request->file('banner')),
            'banner_storage_type' => $request->has('banner') ? $storage : null,
            'bottom_banner' => $this->upload(dir: 'shop/banner/', format: 'webp', image: $request->file('bottom_banner')),
            'bottom_banner_storage_type' => $request->has('banner') ? $storage : null,
            'tax_identification_number' => $request['tax_identification_number'],
            'tin_expire_date' => $request['tin_expire_date'] ?? null,
            'tin_certificate' => $request->file('tin_certificate') ? $this->fileUpload(dir: 'shop/documents/', format: $request->file('tin_certificate')->getClientOriginalExtension(), file: $request->file('tin_certificate')) : null,
            'tin_certificate_storage_type' => $request->has('tin_certificate') ? $storage : null,
        ];
    }


    public function updateInHouseShopData(object|array $request, object|array $shop): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $image = $request['image'] ? $this->update(dir: 'shop/', oldImage: $shop['image'], format: 'webp', image: $request->file('image')) : $shop['image'];
        $banner = $request['shop_banner'] ? $this->update(dir: 'shop/banner/', oldImage: $shop['banner'], format: 'webp', image: $request->file('shop_banner')) : $shop['banner'];
        $bottomBanner = $request['bottom_banner'] ? $this->update(dir: 'shop/banner/', oldImage: $shop['bottom_banner'], format: 'webp', image: $request->file('bottom_banner')) : $shop['bottom_banner'];
        $offerBanner = $request['offer_banner'] ? $this->update(dir: 'shop/banner/', oldImage: $shop['offer_banner'], format: 'webp', image: $request->file('offer_banner')) : $shop['offer_banner'];

        return [
            'name' => $request['name'],
            'address' => $request['address'],
            'contact' => $request['contact'],
            'image' => $image,
            'image_storage_type' => $request->has('image') ? $storage : $shop['image_storage_type'],
            'banner' => $banner,
            'banner_storage_type' => $request->has('banner') ? $storage : $shop['banner_storage_type'],
            'bottom_banner' => $bottomBanner,
            'bottom_banner_storage_type' => $request->has('bottom_banner') ? $storage : $shop['bottom_banner_storage_type'],
            'offer_banner' => $offerBanner,
            'offer_banner_storage_type' => $request->has('offer_banner') ? $storage : $shop['offer_banner_storage_type'],
        ];
    }

    public function getInhouseShopData($request): object|null
    {
        $inhouseProductsQuery = Product::active()
            ->with(['reviews', 'rating'])
            ->withCount('reviews')
            ->where('added_by', 'admin');

        $inhouseProductsQuery = ProductManager::applySellerFilters($inhouseProductsQuery, $request);

        $inhouseProducts = $inhouseProductsQuery->get();
        if ($inhouseProducts->isEmpty()) {
            return null;
        }

        // is_string, not truthiness: `?shop_name[]=x` is truthy and strtolower() rejects an array.
        // A name nobody can spell does not exclude the in-house shop, it simply does not filter.
        if (is_string($request->shop_name) && $request->shop_name !== ''
            && !str_contains(strtolower(getInHouseShopConfig('name')), strtolower($request->shop_name))) {
            return null;
        }

        $reviewData = Review::active()->whereIn('product_id', $inhouseProducts->pluck('id'));
        $reviewCount = $reviewData->count();
        $positive = $reviewData->pluck('rating')->filter(fn($r) => $r >= 4)->count();

        $shop = getInHouseShopConfig();
        $shop->products_count  = $inhouseProducts->count();
        $shop->review_count    = $reviewCount;
        $shop->average_rating  = $reviewData->avg('rating') ?? 0;
        $shop->positive_review = $reviewCount ? ($positive * 100) / $reviewCount : 0;
        $shop->orders_count    = Order::where('seller_is', 'admin')->count();
        $shop->is_vacation_mode_now = checkVendorAbility('inhouse', 'vacation_status');

        return $shop;
    }

    public function applyOrdering($vendors, $request)
    {
        return match ($request->order_by) {
            'asc'               => $vendors->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE),
            'desc'              => $vendors->sortByDesc('name', SORT_NATURAL | SORT_FLAG_CASE),
            'highest-products'  => $vendors->sortByDesc('products_count'),
            'lowest-products'   => $vendors->sortBy('products_count'),
            'rating-high-to-low'=> $vendors->sortByDesc('average_rating'),
            'rating-low-to-high'=> $vendors->sortBy('average_rating'),
            default             => $vendors,
        };
    }

    public static function calculateReviews($shop)
    {
        $shop->orders_count = $shop->seller->orders_count ?? 0;

        $reviews = $shop->seller->product->flatMap->reviews->where('status', 1);
        $shop->average_rating = $reviews->avg('rating') ?? 0;
        $shop->review_count = $reviews->count();
        $shop->total_rating = $reviews->sum('rating');
        $shop->positive_review = $shop->review_count
            ? ($reviews->where('rating', '>=', 4)->count() * 100) / $shop->review_count
            : 0;

        $today = now()->toDateString();
        $shop->is_vacation_mode_now = $shop->vacation_status &&
            $today >= $shop->vacation_start_date &&
            $today <= $shop->vacation_end_date;

        return $shop;
    }


}
