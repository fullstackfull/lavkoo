<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\DigitalProductVariationRepositoryInterface;
use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\StorageRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use App\Traits\API\v3\VendorPOSManagement;
use App\Traits\CustomerTrait;
use App\Utils\CartManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class POSCartController extends Controller
{

    use CustomerTrait;
    use VatTaxManagement;
    use VendorPOSManagement;

    /**
     * @param PasswordResetRepositoryInterface $passwordResetRepo
     * @param DigitalProductVariationRepositoryInterface $digitalProductVariationRepo
     * @param ProductRepositoryInterface $productRepo
     * @param StorageRepositoryInterface $storageRepo
     * @param PasswordResetService $passwordResetService
     * @param CustomerRepositoryInterface $customerRepo
     */
    public function __construct(

        private readonly PasswordResetRepositoryInterface           $passwordResetRepo,
        private readonly DigitalProductVariationRepositoryInterface $digitalProductVariationRepo,
        private readonly ProductRepositoryInterface                 $productRepo,
        private readonly StorageRepositoryInterface                 $storageRepo,
        private readonly PasswordResetService                       $passwordResetService,
        private readonly CustomerRepositoryInterface                $customerRepo,
    )
    {
    }

    public function getTaxAmountCart(Request $request):JsonResponse
    {
        if ($request['cart']) {
            $productIds = collect($request['cart'])->pluck('id')->toArray();
            $products = \App\Models\Product::whereIn('id', $productIds)
                ->with(['digitalVariation', 'category' => function ($query) {
                    return $query->with(['taxVats' => function ($query) {
                        return $query->with(['tax'])->wherehas('tax', function ($query) {
                            return $query->where('is_active', 1);
                        });
                    }]);
                }, 'clearanceSale' => function ($query) {
                    return $query->active();
                }, 'taxVats' => function ($query) {
                    return $query->with(['tax'])->wherehas('tax', function ($query) {
                        return $query->where('is_active', 1);
                    });
                }])
                ->get();

            $modifiedCart = [];

            $taxConfig = self::getTaxSystemType();

            foreach ($request['cart'] as $cartItemKey => $cartItem) {
                $product = $products->where('id', $cartItem['id'])->first();
                $getProductArray = self::getOrderDetailsAddData(cartItem: $cartItem, product: $product);
                $result = [
                    'id' => $cartItem['id'],
                    'price' => $getProductArray['price'],
                    'discounted_price' => $getProductArray['price'] - $getProductArray['productDiscount'],
                    'discount' => $getProductArray['productDiscount'],
                    'discount_type' => $product['discount_type'],
                    'quantity' => $cartItem['quantity'],
                    'variant' => $cartItem['variant'],
                    'variant_key' => $cartItem['variant_key'],
                    'digital_variation_price' => $cartItem['digital_variation_price'],
                    'variation' => $cartItem['variation'],
                    'taxVats' => $product['taxVats'],
                    'category' => $product['category'],
                ];

                $modifiedCart[] = $result;
            }

            $totalDiscountedPrice = 0;
            foreach ($modifiedCart as $cartItemKey => $cartItem) {
                $totalDiscountedPrice += $cartItem['discounted_price'] * $cartItem['quantity'];
            }

            $modifiedCart = collect($modifiedCart)->map(function ($product) use ($request, $taxConfig, $totalDiscountedPrice) {
                $productDiscountedPrice = $product['discounted_price'] * $product['quantity'];
                $couponDiscount = ($request['coupon_discount_amount'] * $productDiscountedPrice) / $totalDiscountedPrice;
                if ($request['extra_discount_type'] == 'percent') {
                    $extraDiscount = (($productDiscountedPrice - $couponDiscount) * $request['extra_discount']) / 100;
                } else {
                    $extraDiscount = ($request['extra_discount'] * $productDiscountedPrice) / $totalDiscountedPrice;
                }

                $product['coupon_discount'] = $couponDiscount;
                $product['extra_discount'] = $extraDiscount;
                $appliedTaxAmount = CartManager::getAppliedTaxAmount(
                    product: $product,
                    taxConfig: $taxConfig,
                    totalDiscountedPrice: $productDiscountedPrice,
                    appliedDiscountedAmount: $couponDiscount + $extraDiscount,
                );
                $product['applied_tax_amount'] = $appliedTaxAmount;
                return $product;
            });

            $request['total_tax_amount'] = collect($modifiedCart)->sum('applied_tax_amount');
            $request['modified_cart'] = $modifiedCart;
        }

        unset($request['seller']);
        return response()->json($request->all());
    }
}
