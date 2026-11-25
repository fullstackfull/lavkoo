<?php

namespace App\Services\Staff;

use App\Http\Controllers\Admin\Promotion\BannerController;
use App\Http\Controllers\Admin\Promotion\ClearanceSaleController;
use App\Http\Controllers\Admin\Promotion\DealOfTheDayController;
use App\Http\Controllers\Admin\Promotion\FlashDealController;
use App\Http\Controllers\Admin\Promotion\MostDemandedController;
use App\Http\Controllers\Admin\Commerce\CollectionController;
use App\Http\Controllers\Admin\Settings\InhouseShopController;
use App\Http\Controllers\Admin\Settings\SeoHealthController;
use App\Http\Controllers\Admin\Settings\SeoTranslationController;
use App\Http\Controllers\Admin\Settings\SiteMapController;
use App\Http\Controllers\Admin\Settings\ThemeBuilderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The promotion and storefront-content screens of the admin panel that a staff country scope covers (GCC-14c, slice 4), and what each of
 * their actions names or lists: the flash deals, the deal of the day, the clearance sale, the most-demanded entries, the banners, the
 * in-house shop page, the theme builder, the two SEO screens, the site map and the collection preview.
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
 *   link_form                the product a composed link points at, which is a product only when `kind` says `product`
 *   seo_entity:{t},{i}       the polymorphic row an SEO screen is about: the id in `{i}` is a PRODUCT id only when `{t}` says `product`
 *   theme_version.input:{n}, theme_section.input:{n}, theme_block.input:{n}, theme_asset.input:{n}
 *                            a theme version, section, block or asset in the request: the MARKET of the theme it belongs to must be one the
 *                            employee holds. A theme has a market of its own (`themes.storefront_country`, F-01), so unlike a promotion it
 *                            is judged directly; a theme that names none is the default storefront's
 *   unavailable              it reads or writes a platform-wide artefact that no market owns a part of (the site-map files): a restricted
 *                            employee is answered like for something that does not exist, rather than being shown or allowed to overwrite
 *                            every market's at once
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
        ThemeBuilderController::class => [
            // A theme belongs to a storefront (F-01), so the builder is judged by the market of the theme the version, section, block or
            // asset in hand belongs to -- not by the products it can pick, which the product scope narrows on top of that.
            'index' => ['theme_version.input:version?'],
            'resources' => ['list'],
            'resourceLabels' => ['list'],
            'sectionSchema' => ['theme_section.input:section_id?'],
            'addSection' => ['theme_version.input:version_id'],
            'updateSection' => ['theme_section.input:section_id'],
            'reorderSections' => ['theme_version.input:version_id'],
            'toggleSection' => ['theme_section.input:section_id'],
            'duplicateSection' => ['theme_section.input:section_id'],
            'deleteSection' => ['theme_section.input:section_id'],
            'updateDeliveryRules' => ['theme_section.input:section_id'],
            'blockSchema' => ['theme_block.input:block_id'],
            'addBlock' => ['theme_section.input:section_id'],
            'updateBlock' => ['theme_block.input:block_id'],
            'toggleBlock' => ['theme_block.input:block_id'],
            'reorderBlocks' => ['theme_section.input:section_id'],
            'duplicateBlock' => ['theme_block.input:block_id'],
            'deleteBlock' => ['theme_block.input:block_id'],
            'uploadMedia' => ['theme_version.input:version_id'],
            'mediaLibrary' => ['theme_version.input:version_id'],
            'deleteMedia' => ['theme_asset.input:id'],
            'composeLink' => ['link_form'],
            'startPreview' => ['theme_version.input:version_id'],
            'previewLink' => ['theme_version.input:version_id'],
            // Stops previewing whatever this employee's own session was previewing. It names nothing and reads nothing of anybody else's.
            'stopPreview' => ['none'],
        ],
        SeoTranslationController::class => [
            // The screen is polymorphic: `entity_type` says what kind of row `entity_id` is, and only `product` makes it a product id. The
            // list of the twenty most recent translations is narrowed by `StaffCountryProductRowScope` on the model, which leaves the rows
            // of the other kinds alone.
            'index' => ['seo_entity:entity_type,entity_id', 'list'],
            'save' => ['seo_entity:entity_type,entity_id'],
            'delete' => ['seo_entity:entity_type,entity_id'],
        ],
        SeoHealthController::class => [
            'index' => ['list'],
        ],
        SiteMapController::class => [
            // A site map is a platform-wide artefact: the bare file lists the DEFAULT storefront's product addresses and the rest is one
            // file per live market. There is no part of it a restricted employee owns -- generating it rewrites every market's file, and
            // downloading it hands over another market's product addresses -- so the whole screen is unavailable to them. Scoping it by
            // market is the storefront lane's to build (one file per storefront, generated for the markets the employee holds).
            'index' => ['unavailable'],
            'getGenerateAndDownload' => ['unavailable'],
            'getGenerateAndUpload' => ['unavailable'],
            'getDownload' => ['unavailable'],
            'getUpload' => ['unavailable'],
            'getDelete' => ['unavailable'],
        ],
        CollectionController::class => [
            // The scan does not see this one: the preview resolves a collection's products through CollectionResolver, which reads them
            // with the model, so the product scope narrows the preview as soon as the request is bound. A collection itself has no market.
            'index' => ['none'],
            'store' => ['none'],
            'update' => ['none'],
            'delete' => ['none'],
            'preview' => ['list'],
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

    /**
     * How a theme row reaches its theme. Each step is a table and the column of it that points at the next one, ending at `themes.id`: a
     * block hangs off a section, a section off a version, a version and an asset off the theme itself.
     *
     * @var array<string, list<array{table: string, column: string}>>
     */
    private const THEME_CHAINS = [
        'theme_version' => [['table' => 'theme_versions', 'column' => 'theme_id']],
        'theme_section' => [['table' => 'theme_sections', 'column' => 'theme_version_id'], ['table' => 'theme_versions', 'column' => 'theme_id']],
        'theme_block' => [['table' => 'theme_blocks', 'column' => 'theme_section_id'], ['table' => 'theme_sections', 'column' => 'theme_version_id'], ['table' => 'theme_versions', 'column' => 'theme_id']],
        'theme_asset' => [['table' => 'theme_assets', 'column' => 'theme_id']],
    ];

    /** The entity the SEO screens call a product; every other kind of row they handle names no product. */
    private const SEO_PRODUCT_ENTITY = 'product';

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
            $named = self::whatAPartNames(request: $request, part: $part);

            if ($named === null) {
                return false;
            }

            if (!$scope->coversEveryProduct(productIds: $named['products']) || !$scope->coversEveryTheme(themeIds: $named['themes'])) {
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
     * What one part of a rule says this request names, by subject.
     *
     * @return array{products: list<int>, themes: list<int>}|null null when what the part asks for is missing, is not an id at all, names a
     *                      row that does not exist, is a part nobody understands, or is `unavailable` -- each of which is answered like
     *                      something that does not exist
     */
    public static function whatAPartNames(Request $request, string $part): ?array
    {
        if ($part === 'none' || $part === 'list' || $part === 'list:orders') {
            return self::nothing();
        }

        if ($part === 'unavailable') {
            return null;
        }

        if ($part === 'banner_form') {
            return self::named(products: self::productOfAFormThatNamesItsKind(request: $request, kindField: 'resource_type', idField: 'product_id'));
        }

        if ($part === 'link_form') {
            return self::named(products: self::productOfAFormThatNamesItsKind(request: $request, kindField: 'kind', idField: 'reference'));
        }

        if (str_starts_with($part, 'seo_entity:')) {
            [$kindField, $idField] = array_pad(explode(',', substr($part, strlen('seo_entity:')), 2), 2, '');

            return $kindField === '' || $idField === ''
                ? null
                : self::named(products: self::productOfAFormThatNamesItsKind(request: $request, kindField: $kindField, idField: $idField));
        }

        [$subject, $where] = array_pad(explode('.', $part, 2), 2, '');
        [$kind, $name] = array_pad(explode(':', $where, 2), 2, '');
        $optional = str_ends_with($name, '?');
        $name = rtrim($name, '?');

        if ($name === '' || !in_array($kind, ['route', 'input', 'inputs'], true)) {
            return null;
        }

        $value = $kind === 'route' ? $request->route($name) : $request->input($name);

        if ($subject === 'product') {
            return self::named(products: match (true) {
                $kind === 'inputs' => StaffRouteIds::many(value: $value),
                $optional && StaffRouteIds::blank(value: $value) => [],
                default => StaffRouteIds::one(value: $value),
            });
        }

        if ($kind === 'inputs') {
            return null;
        }

        if (isset(self::ROW_TABLES[$subject])) {
            return self::named(products: self::productOfARow(subject: $subject, rowId: $value));
        }

        if (isset(self::THEME_CHAINS[$subject])) {
            if ($optional && StaffRouteIds::blank(value: $value)) {
                return self::nothing();
            }

            return self::named(themes: self::themeOfARow(subject: $subject, rowId: $value));
        }

        return null;
    }

    /** @return array{products: list<int>, themes: list<int>} */
    private static function nothing(): array
    {
        return ['products' => [], 'themes' => []];
    }

    /**
     * @param list<int>|null $products
     * @param list<int>|null $themes
     * @return array{products: list<int>, themes: list<int>}|null
     */
    private static function named(?array $products = [], ?array $themes = []): ?array
    {
        return $products === null || $themes === null ? null : ['products' => $products, 'themes' => $themes];
    }

    /**
     * The product a form points at when the form says what KIND of thing it points at. A banner posts `resource_type` and one id per kind of
     * resource, a composed link posts `kind` and a `reference`, and the SEO screens post `entity_type` and an `entity_id`: in all three the
     * id is a PRODUCT id only when the kind says so, and a form that heads a category page names a category there and no product at all.
     *
     * @return list<int>|null
     */
    private static function productOfAFormThatNamesItsKind(Request $request, string $kindField, string $idField): ?array
    {
        if ((string) $request->input($kindField) !== self::SEO_PRODUCT_ENTITY) {
            return [];
        }

        return StaffRouteIds::one(value: $request->input($idField));
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

    /**
     * The theme a theme row belongs to, followed one table at a time. A step that finds nothing ends the walk: a row that is gone, or hangs
     * off one that is, belongs to no theme and so to no market, and is answered like something that does not exist.
     *
     * @return list<int>|null
     */
    private static function themeOfARow(string $subject, mixed $rowId): ?array
    {
        $row = StaffRouteIds::one(value: $rowId);
        if ($row === null) {
            return null;
        }

        $id = $row[0];
        foreach (self::THEME_CHAINS[$subject] as $step) {
            $id = DB::table($step['table'])->where('id', $id)->value($step['column']);

            if ($id === null) {
                return null;
            }
        }

        return [(int) $id];
    }
}
