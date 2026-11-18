<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductBarcodeSheetService;
use App\Traits\SellerOwnedRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

class ProductBarcodeSheetController extends Controller
{
    use SellerOwnedRecords;

    /** The product is looked up again for the seller the signed link names, so a product that has been deleted or is no longer theirs gives no sheet. */
    public function show(Request $request, ProductBarcodeSheetService $barcodeSheetService): Response|JsonResponse
    {
        App::setLocale($request->string('lang', App::getLocale())->toString());

        $product = Product::ofSeller(sellerId: $request->integer('seller'))->find($request->integer('product'));
        if (!$product) {
            return $this->recordNotFound(code: 'product', message: 'product_not_found');
        }

        return $barcodeSheetService->streamSheet(product: $product, quantity: $request->integer('quantity'));
    }
}
