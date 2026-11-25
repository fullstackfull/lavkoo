<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Promotion\BannerController;
use App\Http\Controllers\Admin\Promotion\ClearanceSaleController;
use App\Http\Controllers\Admin\Promotion\DealOfTheDayController;
use App\Http\Controllers\Admin\Promotion\FlashDealController;
use App\Http\Controllers\Admin\Promotion\MostDemandedController;
use App\Http\Controllers\Admin\Settings\InhouseShopController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The promotion screens of the admin panel that a staff country scope covers (GCC-14c, slice 4), and what each of their actions names or
 * lists: the flash deals, the deal of the day, the clearance sale, the most-demanded entries, the banners and the in-house shop page.
 *
 * Every action of these controllers has a rule: a list of parts, as the order screens' rules are. The middleware of the controllers
 * (`ScopePromotionsToStaffCountries`) reads it for an employee who is restricted to some countries, and a route without a rule is REFUSED to
 * that employee instead of being let through. A test walks the route table and fails for an action that has none.
 *
 * What a part can say:
 *
 *   none                     the action names no product and lists none: a deal row, a banner row, a setting, a screen that redirects
 *   list                     it returns products, or rows that belong to a product (a list, a counter, a picker, a search): the product scope
 *                            and `StaffCountryProductRowScope` narrow them
 *   list:orders              it also counts or lists ORDERS, which are a different subject and are narrowed through their lines' products
 *                            (`StaffCountryScope::SUBJECT_ORDERS`), the way the order screens narrow them
 *   product.route:{n}        one product id in the path
 *   product.input:{n}        one product id in the request (`{n}?` when the form may leave it out)
 *   product.inputs:{n}       a list of product ids in the request: all of them must be in scope, or none is touched
 *   deal_of_the_day.input:{n}, most_demanded.input:{n}
 *                            one row id of a table that belongs to a product, in the request: the product the row names must be in scope
 *   most_demanded.route:{n}  the same, in the path
 *   banner_form              the product a banner form posts, which is a product only when `resource_type` says `product`
 *
 * What is NOT narrowed, and why: the promotion itself has no market. A flash deal, a banner, a clearance setup and the most-demanded section
 * are platform rows (GCC-17 has to give promotions a market before that can change), so editing a deal's name or its dates, or publishing a
 * banner, is judged by the module permission alone, exactly as it was. What this covers is the only thing on these screens that CAN be told
 * apart by country: the products they pick, list, count and write.
 *
 * Something out of the employee's countries is answered like something that does not exist (a 404), for an id of the path, of the request and
 * of a list, so that belonging to a country is not something an employee can find out by asking.
 */
final class StaffPromotionRoutes
{
    /** @var array<class-string, array<string, list<string>>> */
    private const RULES = [
        FlashDealController::class => [
            'index' => ['list'],
            'getAddView' => ['none'],
            'add' => ['none'],
            'getUpdateView' => ['none'],
            'update' => ['none'],
            'updateStatus' => ['none'],
            'getAddProductView' => ['list'],
            'addProduct' => ['product.inputs:product_id'],
            // `delete` removes a PRODUCT from the deals, not a deal: the id it carries is a product id, which is why it reads as
            // `product.input` and not as a row of its own.
            'delete' => ['product.input:id'],
            'search' => ['list'],
        ],
        DealOfTheDayController::class => [
            'index' => ['list'],
            'add' => ['product.input:product_id'],
            // Both ends of an edit: the deal row the employee is changing must name a product of theirs, and so must the product they
            // are changing it to. Either one alone would let a restricted employee move a foreign product in, or a product of theirs out.
            'getUpdateView' => ['deal_of_the_day.route:id'],
            'update' => ['deal_of_the_day.route:id', 'product.input:product_id'],
            'updateStatus' => ['deal_of_the_day.input:id'],
            'delete' => ['deal_of_the_day.input:id'],
        ],
        MostDemandedController::class => [
            // The screens of this controller were retired with the fashion theme and redirect to the dashboard; the writes still work and
            // still name a product, so they are judged by it. `index` has no route of its own -- the list path reaches `getListView` -- so
            // it has no rule: a rule for an action nothing reaches fails the guard that keeps the two in step.
            'getListView' => ['none'],
            'add' => ['product.input:product_id'],
            'getUpdateView' => ['most_demanded.route:id'],
            'update' => ['most_demanded.route:id', 'product.input:product_id'],
            'updateStatus' => ['most_demanded.input:id'],
            'delete' => ['most_demanded.input:id'],
        ],
        ClearanceSaleController::class => [
            'index' => ['list'],
            'updateStatus' => ['none'],
            'updateClearanceConfig' => ['none'],
            'updateClearanceSeoConfig' => ['none'],
            'getSearchedProductsView' => ['list'],
            'getMultipleProductDetailsView' => ['list'],
            'addClearanceProduct' => ['product.inputs:productIds'],
            'updateDiscountAmount' => ['product.input:product_id'],
            'updateProductStatus' => ['product.input:product_id'],
            'deleteClearanceProduct' => ['product.route:product_id'],
            // Every admin clearance offer at once. The action names no id at all, so the rule cannot check one: what keeps it inside the
            // employee's countries is `StaffCountryProductRowScope` on the offers, which is why it reads as a list and not as `none`.
            'deleteClearanceAllProduct' => ['list'],
        ],
        BannerController::class => [
            'index' => ['list'],
            'getPlacementGuide' => ['none'],
            'getThemeLayoutView' => ['none'],
            'add' => ['banner_form'],
            'getUpdateView' => ['list'],
            'update' => ['banner_form'],
            'updateStatus' => ['none'],
            'delete' => ['none'],
        ],
        InhouseShopController::class => [
            // The page counts the in-house products and the reviews of those products, and it counts ORDERS -- a different subject, which
            // is why the rule names it: a Kuwait employee was shown the number of every market's in-house orders.
            'index' => ['list', 'list:orders'],
            'update' => ['none'],
            'getTemporaryClose' => ['none'],
            'updateVacation' => ['none'],
            'getSetupView' => ['none'],
            'updateSetup' => ['none'],
        ],
    ];

    /** The tables a row part may name, and the column of each that points at a product. */
    private const ROW_TABLES = [
        'deal_of_the_day' => ['table' => 'deal_of_the_days', 'column' => 'product_id'],
        'most_demanded' => ['table' => 'most_demandeds', 'column' => 'product_id'],
    ];

    /** @return list<class-string> the controllers whose actions a staff country scope covers in slice 4 */
    public static function controllers(): array
    {
        return array_keys(self::RULES);
    }

    /** @return array<string, list<string>> the rule of every action of a covered controller, by the name of the action */
    public static function rulesOf(string $controller): array
    {
        return self::RULES[ltrim($controller, '\\')] ?? [];
    }

    /** @return list<string>|null */
    public static function ruleOf(string $controller, string $method): ?array
    {
        return self::rulesOf(controller: $controller)[$method] ?? null;
    }

    /**
     * Whether every product the rule says this request names is one the employee may work with. A part that names nothing that can be
     * checked answers false, so a rule nobody understands refuses the request rather than letting it through.
     *
     * @param list<string> $rule
     */
    public static function isCovered(Request $request, array $rule, StaffCountryScope $scope): bool
    {
        if ($rule === []) {
            return false;
        }

        foreach ($rule as $part) {
            $productIds = self::productIdsOf(request: $request, part: $part);

            if ($productIds === null || !$scope->coversEveryProduct(productIds: $productIds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The subjects of `StaffCountryScope` a rule narrows for the rest of the request. Products need none -- they are narrowed as soon as a
     * request is bound -- so only a part that names another subject answers here.
     *
     * @param list<string> $rule
     * @return list<string>
     */
    public static function subjectsOf(array $rule): array
    {
        return in_array('list:orders', $rule, true) ? [StaffCountryScope::SUBJECT_ORDERS] : [];
    }

    /**
     * The product ids one part of a rule says a request names.
     *
     * @return list<int>|null null when what the part asks for is missing, is not an id at all, or names a row that does not exist, which is
     *                        answered like an unknown product
     */
    public static function productIdsOf(Request $request, string $part): ?array
    {
        if ($part === 'none' || $part === 'list' || $part === 'list:orders') {
            return [];
        }

        if ($part === 'banner_form') {
            return self::productOfABannerForm(request: $request);
        }

        [$subject, $where] = array_pad(explode('.', $part, 2), 2, '');
        [$kind, $name] = array_pad(explode(':', $where, 2), 2, '');
        $optional = str_ends_with($name, '?');
        $name = rtrim($name, '?');

        $value = match ($kind) {
            'route' => $request->route($name),
            'input' => $request->input($name),
            'inputs' => $request->input($name),
            default => null,
        };

        if ($kind === '' || $name === '') {
            return null;
        }

        if ($subject === 'product') {
            return match (true) {
                $kind === 'inputs' => StaffRouteIds::many(value: $value),
                $optional && StaffRouteIds::blank(value: $value) => [],
                default => StaffRouteIds::one(value: $value),
            };
        }

        return isset(self::ROW_TABLES[$subject]) && in_array($kind, ['route', 'input'], true)
            ? self::productOfARow(subject: $subject, rowId: $value)
            : null;
    }

    /**
     * The product a banner points at. A banner form posts `resource_type` and one id per kind of resource, so `product_id` is a product id
     * only when the type says `product`: a banner that heads a category page names a category there and no product at all.
     *
     * @return list<int>|null
     */
    private static function productOfABannerForm(Request $request): ?array
    {
        if ((string) $request->input('resource_type') !== 'product') {
            return [];
        }

        return StaffRouteIds::one(value: $request->input('product_id'));
    }

    /** @return list<int>|null */
    private static function productOfARow(string $subject, mixed $rowId): ?array
    {
        $row = StaffRouteIds::one(value: $rowId);
        if ($row === null) {
            return null;
        }

        $productId = DB::table(self::ROW_TABLES[$subject]['table'])->where('id', $row[0])->value(self::ROW_TABLES[$subject]['column']);

        return $productId === null ? null : [(int) $productId];
    }
}
