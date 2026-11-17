<?php

namespace App\Services;

use App\Models\Product;
use Barryvdh\DomPDF\PDF;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

/**
 * The barcode sheet of a product, as the seller apps print it.
 *
 * Nothing is stored. The app is given a link that is signed for one product of one seller and valid for a few minutes, and the sheet is made when
 * the link is opened, in the language the app asked for. The link is opened in the phone's browser, which holds no token: the signature is what
 * says which seller's product it is for, so it cannot be turned into the sheet of anybody else's. The signature covers the path and the query but not
 * the host or the scheme, which a proxy in front of the application may change.
 */
class ProductBarcodeSheetService
{
    private const LINK_LIFETIME_IN_MINUTES = 10;

    public function getLink(Product $product, int|string $quantity): string
    {
        return url(URL::temporarySignedRoute(
            name: 'seller-barcode-sheet',
            expiration: now()->addMinutes(self::LINK_LIFETIME_IN_MINUTES),
            parameters: ['seller' => $product['user_id'], 'product' => $product['id'], 'quantity' => (int)$quantity, 'lang' => App::getLocale()],
            absolute: false,
        ));
    }

    public function streamSheet(Product $product, int $quantity): Response
    {
        return app()->make(PDF::class)
            ->loadView('vendor-views.product.barcode-pdf', compact('product', 'quantity'))
            ->stream('barcode.pdf');
    }
}
