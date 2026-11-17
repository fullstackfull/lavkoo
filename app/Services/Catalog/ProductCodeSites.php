<?php

namespace App\Services\Catalog;

/**
 * Every place the platform finds a product from its SKU or barcode, and what the country-scoped codes switch does about it
 * (docs/gcc/GCC-14B-COUNTRY-SCOPED-CODES.md). The ids are those of the lookup survey of 2026-11-16.
 *
 * What the owner has to read before turning the switch on is the list of sites that are not covered: each one still treats a code as if it
 * named one product.
 */
final class ProductCodeSites
{
    /** The site is behind ProductCodeScope: it asks the country, or it answers an ambiguity. */
    public const COVERED = 'covered';

    /** Part of the site is covered and part is not: the note says which. */
    public const PARTIAL = 'partial';

    /** Nothing resolves a product from the code there: it is keyed by an id, or it is a list a person reads. */
    public const ID_KEYED = 'id_keyed';

    /** The site still treats a code as one product. */
    public const NOT_COVERED = 'not_covered';

    /**
     * @return list<array{id: string, where: string, status: string, note: string}>
     */
    public static function all(): array
    {
        return array_map(
            static fn (array $site): array => ['id' => $site[0], 'where' => $site[1], 'status' => $site[2], 'note' => $site[3]],
            self::SITES,
        );
    }

    /** @return list<array{id: string, where: string, status: string, note: string}> the sites a code still identifies one product at, wholly or in part */
    public static function notCovered(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $site): bool => in_array($site['status'], [self::NOT_COVERED, self::PARTIAL], true),
        ));
    }

    private const SITES = [
        ['A1', 'ProductAddRequest: code', self::COVERED, 'Unique inside the country the add action saves the product in (the admin\'s choice, the table default for a vendor).'],
        ['A2', 'ProductAddRequest: barcode', self::COVERED, 'Unique inside the same country.'],
        ['A3', 'ProductUpdateRequest: code', self::COVERED, 'Unique inside the product\'s own country; the product itself is ignored.'],
        ['A4', 'ProductUpdateRequest: barcode', self::COVERED, 'Unique inside the product\'s own country; the product itself is ignored.'],
        ['A5', 'RestAPI v2 seller ProductController::add_new', self::COVERED, 'Unique inside the table default country, where a seller product is saved.'],
        ['A6', 'RestAPI v2 seller ProductController::update', self::COVERED, 'Unique inside the product\'s own country; the product itself is ignored.'],
        ['A7', 'RestAPI v3 seller ProductController::add_new', self::COVERED, 'Unique inside the table default country, where a seller product is saved.'],
        ['A8', 'RestAPI v3 seller ProductController::updateProduct', self::COVERED, 'Unique inside the product\'s own country; the product itself is ignored.'],
        ['A9', 'ProductService::getUniqueProductSKUCode', self::COVERED, 'Free in the country the imported products take (the table default: the sheet has no country column).'],
        ['A10', 'M5aznProductImporter::clashesWithExistingProduct', self::COVERED, 'Parks an item only when its SKU or barcode is used in the country it is imported into (the table default, written explicitly while the switch is on).'],
        ['B1', 'ProductService::getImportBulkProductData ($productsTax)', self::COVERED, 'The tax ids are listed in the order of the sheet rows, not keyed by code.'],
        ['B2', 'Admin ProductController::importBulkProduct', self::COVERED, 'The tax rows go to the ids of the rows the import just created; no product is found by its code.'],
        ['C1', 'RestAPI v3 seller POSController::get_product_by_barcode', self::NOT_COVERED, 'The seller POS scan takes the first product with the code, by code only.'],
        ['C2', 'ProductRepository::getListWithScope (search_from=pos)', self::NOT_COVERED, 'The admin and vendor POS search rows show no country.'],
        ['C3', 'ProductRepository::getWebListWithScope', self::NOT_COVERED, 'The POS grid, product lists, export and gallery rows show no country.'],
        ['C4', 'ProductRepository::getListWithScope (searchValue)', self::NOT_COVERED, 'The order-edit and clearance-sale pickers offer every country.'],
        ['C5', 'ProductRepository::getListWhere (code filter)', self::NOT_COVERED, 'The exact code or barcode filter of a product list.'],
        ['C6', 'Flash deal and deal of the day pickers', self::NOT_COVERED, 'The promotion pickers offer every country.'],
        ['C7', 'ProductRepository stock-limit list', self::NOT_COVERED, 'A list a person reads (code LIKE).'],
        ['C8', 'RestockProductRepository', self::NOT_COVERED, 'A list a person reads (code LIKE).'],
        ['C9', 'SellerCenter ProductList', self::NOT_COVERED, 'A list a person reads (code LIKE).'],
        ['C10', 'SellerCenter InventoryList', self::NOT_COVERED, 'A list a person reads (code LIKE).'],
        ['C11', 'SellerCenter Search', self::NOT_COVERED, 'A list a person reads (code LIKE).'],
        ['D1', 'SalesChannels ProductMatcher', self::NOT_COVERED, 'A channel listing proposes every product with its SKU or barcode, whatever its country.'],
        ['D2', 'ChannelOrderIngestor', self::NOT_COVERED, 'The mapping is found by the account and the channel listing key.'],
        ['D3', 'ListingDraftService / ListingPublisher', self::NOT_COVERED, 'The default outbound listing key is the product code.'],
        ['D4', 'ChannelInventoryWriter', self::NOT_COVERED, 'Keyed by the account listing key.'],
        ['D5', 'StockPricePublisher', self::NOT_COVERED, 'Keyed by the account listing key.'],
        ['D6', 'RoutingRuleMatcher', self::NOT_COVERED, 'A routing rule on a SKU matches the SKU of the order line, in every country.'],
        ['D7', 'Admin SalesChannels ProductDirectory', self::NOT_COVERED, 'The listing-link picker shows no country.'],
        ['D8', 'LocationStockPage', self::NOT_COVERED, 'The stock page filter (code LIKE).'],
        ['E1', 'ProviderMappingService::suggestionsFor', self::NOT_COVERED, 'Warehouse items are suggested every product with their SKU or barcode.'],
        ['E2', 'TorodMatchingController::productSearch', self::NOT_COVERED, 'The matching picker offers every country.'],
        ['E3', 'TorodChecks', self::NOT_COVERED, 'A diagnostic over the warehouse items.'],
        ['F1', 'Admin ProductCostController picker', self::NOT_COVERED, 'The cost-layer picker shows no country.'],
        ['G9', 'Product gallery copy', self::NOT_COVERED, 'The copy blanks the code and barcode so it passes the old global rule.'],
    ];
}
