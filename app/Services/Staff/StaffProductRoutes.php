<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Marketplace\ProductModerationController;
use App\Http\Controllers\Admin\Shipping\ProductDestinationPermissionController;
use App\Http\Controllers\Admin\Product\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The product screens of the admin panel that a staff country scope covers (GCC-14c, slice 1), and what each of their actions names.
 *
 * Every action of these controllers has a rule; the middleware of the controllers (ScopeProductsToStaffCountries) reads it for an employee who is restricted to some
 * countries, and a route without a rule is refused to that employee instead of being let through. A test walks the route table and fails for an action that has none.
 * What a rule can say:
 *
 *   list           the action returns product rows (a list, a counter, a picker, an export, or the ids of a picker): the query scope narrows them
 *   none           it names no existing product (a blank form, the categories)
 *   new            it makes new products: the form request and the importer check their country
 *   route:{name}   one product id in the path
 *   input:{name}   one product id in the request (`name?` when it may be missing)
 *   inputs:{name}  a list of product ids in the request: all of them must be in scope, or none is touched
 *   restock:{name} one restock request id in the path: the product it asks for must be in scope
 *   variation:{name} one digital product variation id in the path: the product it belongs to must be in scope
 *
 * A product out of scope is answered like a product that does not exist (a 404), for the id of a route and for an id that is not a product id at all, so that
 * belonging to a country is not something an employee can find out by asking.
 */
final class StaffProductRoutes
{
    private const RULES = [
        ProductController::class => [
            'index' => 'list',
            'getAddView' => 'none',
            'add' => 'new',
            'getView' => 'route:id',
            'getSkuCombinationView' => 'input:product_id?',
            'getDigitalVariationCombinationView' => 'input:product_id?',
            'deleteDigitalVariationFile' => 'input:product_id',
            'downloadDigitalFile' => 'route:id',
            'downloadDigitalVariationFile' => 'variation:id',
            'updateFeaturedStatus' => 'input:id',
            'getCategories' => 'none',
            'updateStatus' => 'input:id',
            'bulkUpdateStatus' => 'inputs:ids',
            'getBarcodeView' => 'route:id',
            'exportList' => 'list',
            'getStockLimitListView' => 'list',
            'delete' => 'route:id',
            'getUpdateView' => 'route:id',
            'update' => 'route:id',
            'updateProductImages' => 'route:id',
            'deleteImage' => 'input:id',
            'getVariations' => 'input:id',
            'updateQuantity' => 'input:product_id',
            'getBulkImportView' => 'none',
            'importBulkProduct' => 'new',
            'updatedProductList' => 'list',
            'updatedShipping' => 'input:id',
            'deny' => 'input:id',
            'approveStatus' => 'input:id',
            'getProductGalleryView' => 'list',
            // B-04..B-07: copying a product into another market's catalogue names the product it copies by its route id, so a restricted
            // employee may copy only a product of a country they hold. Whether holding the TARGET country should be required as well is the
            // product-identity lane's decision; until it is taken, the product scope is what applies, as it does to every other action on a product.
            'copyToCountry' => 'route:id',
            'getStockLimitStatus' => 'list',
            'deletePreviewFile' => 'input:product_id',
            'getRequestRestockListView' => 'list',
            'exportRestockList' => 'list',
            'deleteRestock' => 'restock:id',
            'getMultipleProductDetailsView' => 'list',
            'getSearchedProductsView' => 'list',
            'getSearchedAllProductsView' => 'list',
            'getProductPickerOptions' => 'list',
            'getProductPickerLabels' => 'list',
        ],
        ProductModerationController::class => [
            'index' => 'none',
            'history' => 'route:productId',
            'moderate' => 'input:product_id',
            'bulk' => 'inputs:product_ids',
        ],
        ProductDestinationPermissionController::class => [
            // Which countries a product may be shipped to, and on whose authority (H1-03, H1-04). An employee restricted to some
            // countries has no business granting another country's product permission to be exported: the id arrives in the request on
            // both actions, so both are judged by it. The screen with no product chosen names none, which is the empty form.
            'index' => 'input:product_id?',
            'record' => 'input:product_id',
        ],
    ];

    /** @return list<class-string> the controllers whose actions a staff country scope covers */
    public static function controllers(): array
    {
        return array_keys(self::RULES);
    }

    /** @return array<string, string> the rule of every action of a covered controller, by the name of the action */
    public static function rulesOf(string $controller): array
    {
        return self::RULES[ltrim($controller, '\\')] ?? [];
    }

    public static function ruleOf(string $controller, string $method): ?string
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    /**
     * The product ids a request names under a rule.
     *
     * @return list<int>|null null when what the rule asks for is missing or cannot be a product id, which is answered like an unknown product
     */
    public static function productIdsOf(Request $request, string $rule): ?array
    {
        [$kind, $name] = array_pad(explode(':', $rule, 2), 2, '');
        $optional = str_ends_with($name, '?');
        $name = rtrim($name, '?');

        return match ($kind) {
            'list', 'none', 'new' => [],
            'route' => StaffRouteIds::one(value: $request->route($name)),
            'input' => $optional && StaffRouteIds::blank(value: $request->input($name)) ? [] : StaffRouteIds::one(value: $request->input($name)),
            'inputs' => StaffRouteIds::many(value: $request->input($name)),
            'restock' => self::productIdsOfRestockRequest(restockRequestId: $request->route($name)),
            'variation' => self::productIdsOfDigitalVariation(variationId: $request->route($name)),
            default => null,
        };
    }

    /** @return list<int>|null */
    private static function productIdsOfRestockRequest(mixed $restockRequestId): ?array
    {
        $restockRequest = StaffRouteIds::one(value: $restockRequestId);
        if ($restockRequest === null) {
            return null;
        }

        $productId = DB::table('restock_products')->where('id', $restockRequest[0])->value('product_id');

        return $productId === null ? null : [(int) $productId];
    }

    /** @return list<int>|null */
    private static function productIdsOfDigitalVariation(mixed $variationId): ?array
    {
        $variation = StaffRouteIds::one(value: $variationId);
        if ($variation === null) {
            return null;
        }

        $productId = DB::table('digital_product_variations')->where('id', $variation[0])->value('product_id');

        return $productId === null ? null : [(int) $productId];
    }
}
