<?php

namespace App\Traits;

use App\Contracts\Repositories\AdminRepositoryInterface;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\Shop;

trait InHouseTrait
{
    public function __construct(
        private readonly AdminRepositoryInterface $adminRepo,
    )
    {
    }

    public function getInHouseShopObject(): Shop
    {
        $inhouseProducts = Product::active()->with(['reviews', 'rating'])->withCount('reviews')->where(['added_by' => 'admin'])->get();
        $inHouseVacation = getWebConfig(name: 'vacation_add');
        $inHouseShop = new Shop([
            'seller_id' => 0,
            'name' => getInHouseShopConfig(key: 'name'),
            'slug' => 'inhouse',
            'address' => getWebConfig(name: 'shop_address'),
            'contact' => getWebConfig(name: 'company_phone'),
            'image' => getWebConfig(name: 'company_fav_icon')['key'] ?? null,
            'bottom_banner' => getWebConfig(name: 'bottom_banner')['key'] ?? null,
            'offer_banner' => getWebConfig(name: 'offer_banner')['key'] ?? null,
            'vacation_start_date' => $inHouseVacation['vacation_start_date'] ?? null,
            'vacation_end_date' => $inHouseVacation['vacation_end_date'] ?? null,
            'is_vacation_mode_now' => checkVendorAbility(type: 'inhouse', status: 'vacation_status') ? 1 : 0,
            'vacation_note' => $inHouseVacation['vacation_note'],
            'vacation_status' => $inHouseVacation['status'] ?? false,
            'temporary_close' => getWebConfig(name: 'temporary_close') ? getWebConfig(name: 'temporary_close')['status'] : 0,
            'banner' => getWebConfig(name: 'shop_banner')['key'] ?? null,
            'created_at' => isset(Admin::where(['id' => 1])->first()->created_at) ? Admin::where(['id' => 1])->first()->created_at : null,
        ]);
        $inHouseShop->id = 0;
        $inHouseShop->average_rating = Review::active()->whereIn('product_id', $inhouseProducts->pluck('id'))->avg('rating');
        $inHouseShop->review_count = Review::active()->whereIn('product_id', $inhouseProducts->pluck('id'))->count();
        $inHouseShop->total_rating = Review::active()->whereIn('product_id', $inhouseProducts->pluck('id'))->count();
        return $inHouseShop;
    }

    public function getInHouseSellerObject(): Seller
    {
        $inHouseSeller = new Seller([
            "f_name" => getInHouseShopConfig(key: 'name'),
            "l_name" => getInHouseShopConfig(key: 'name'),
            "phone" => getInHouseShopConfig(key: 'contact'),
            "image" => getInHouseShopConfig(key: 'image'),
            "email" => getWebConfig(name: 'company_email'),
            "status" => "approved",
            "pos_status" => 1,
            "minimum_order_amount" => (int)getWebConfig(name: 'minimum_order_amount'),
            "free_delivery_status" => (int)getWebConfig(name: 'free_delivery_status'),
            "free_delivery_over_amount" => getWebConfig(name: 'free_delivery_over_amount'),
            "app_language" => getDefaultLanguage(),
            'created_at' => Admin::where(['id' => 1])->first()->created_at,
            'updated_at' => Admin::where(['id' => 1])->first()->created_at,
            "bank_name" => "",
            "branch" => "",
            "account_no" => "",
            "holder_name" => "",
        ]);
        $inHouseSeller->id = 0;
        return $inHouseSeller;
    }
}
