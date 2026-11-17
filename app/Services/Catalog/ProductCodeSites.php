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

    /**
     * A person picks a product from a list that can hold the products of two countries, and while the switch is on every row names its country
     * (ProductCodeScope::countryTag). Nothing is narrowed: what is picked (a promotion, a cost layer, a listing link) has no country, and it is
     * saved by the picked id.
     */
    public const LABELLED = 'labelled';

    /** Nothing resolves a product from the code there: it is keyed by an id, or it is a list a person reads. */
    public const ID_KEYED = 'id_keyed';

    /** The site still treats a code as one product. */
    public const NOT_COVERED = 'not_covered';

    /** The site is safe as it is, and a feature that would change it waits for the owner's approval: the note says what it needs. */
    public const DEFERRED = 'deferred';

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

    /** @return list<array{id: string, where: string, status: string, note: string}> the sites that are still open: a code is read as one product there, wholly or in part, or a feature waits for the owner */
    public static function notCovered(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $site): bool => in_array($site['status'], [self::NOT_COVERED, self::PARTIAL, self::DEFERRED], true),
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
        ['C1', 'RestAPI v3 seller POSController::get_product_by_barcode', self::COVERED, 'Looks at the code and the barcode of the seller\'s own products, narrowed to the country of the request (`country`, else the default storefront); more than one match answers 409 ambiguous_code. Switch off: the first product with the code, as before.'],
        ['C2', 'ProductRepository::getListWithScope (search_from=pos)', self::LABELLED, 'The admin POS search rows name the country at the end of the SKU line. The vendor POS search rows list the seller\'s own products, which are all in the table default country (no seller path writes another), so they stay as they are; a test pins it.'],
        ['C3', 'ProductRepository::getWebListWithScope', self::LABELLED, 'The admin POS grid cards, the admin product gallery cards and their details panels name the country after the name, and the admin product export has a country column after its last one (the admin product list has had its country column since GCC-14a). The vendor POS grid, export and gallery list the seller\'s own products, all in the table default country, and stay as they are; a test pins it.'],
        ['C4', 'ProductRepository::getListWithScope (searchValue)', self::LABELLED, 'The admin and vendor order-edit pickers offer the products of the order\'s country (it follows from the order lines), and the admin picker also names the country in each row, for an order whose lines do not name one. The admin clearance-sale picker offers every country (a promotion has no country) and names it in each row and chip. The vendor pickers list the seller\'s own products, all in the table default country.'],
        ['C5', 'ProductRepository::getListWhere (code filter)', self::LABELLED, 'The code filter of the admin product searches (the reviews filter and the promotion pickers behind them) offers every country and names it in each row and chip. The vendor search lists the seller\'s own products.'],
        ['C6', 'Flash deal and deal of the day pickers', self::LABELLED, 'The promotion pickers offer every country (a promotion has no country to narrow to) and name it in each row and chip; the discount is written by the picked id.'],
        ['C7', 'ProductRepository stock-limit list', self::ID_KEYED, 'A list a person reads (code LIKE); every action on a row is keyed by its id. The rows do not name the country.'],
        ['C8', 'RestockProductRepository', self::ID_KEYED, 'A list a person reads (code LIKE); every action on a row is keyed by its id. The rows do not name the country.'],
        ['C9', 'SellerCenter ProductList', self::ID_KEYED, 'A list a person reads (code LIKE); every action on a row is keyed by its id.'],
        ['C10', 'SellerCenter InventoryList', self::ID_KEYED, 'A list a person reads (code LIKE); every action on a row is keyed by its id.'],
        ['C11', 'SellerCenter Search', self::ID_KEYED, 'A list a person reads (code LIKE); every action on a row is keyed by its id.'],
        ['D1', 'SalesChannels ProductMatcher', self::PARTIAL, 'A listing is proposed only the products of the country its account sells in: a Trendyol storefront, a noon country, or an Amazon account whose marketplaces are all the Saudi (A17E79C6D8DWNP) or all the UAE (A2VIGQ35RCS4UG) one. An Amazon account of any other marketplace or of marketplaces of two countries, the Turkish Trendyol marketplace and any account of no configured country are not narrowed: a code two countries share stays a conflict and nothing links on a guess.'],
        ['D2', 'ChannelOrderIngestor', self::ID_KEYED, 'The mapping is found by the account and the channel\'s own listing key; a code never picks it.'],
        ['D3', 'ListingDraftService / ListingPublisher', self::COVERED, 'The default outbound key is the product code; a key a listing of the account already holds for another product refuses the second publish. Pinned across two countries, switch on and off.'],
        ['D4', 'ChannelInventoryWriter', self::ID_KEYED, 'Keyed by the account\'s listing key.'],
        ['D5', 'StockPricePublisher', self::ID_KEYED, 'Keyed by the account\'s listing key.'],
        ['D6', 'RoutingRuleMatcher', self::COVERED, 'A SKU condition also needs the line\'s product to be in the country the rule names, or in the default storefront\'s when it names none; an unknown product country matches no SKU rule. A rule keyed by a product id is untouched.'],
        ['D7', 'Admin SalesChannels ProductDirectory', self::LABELLED, 'The listing-link picker (and the routing rule\'s product picker, which shares it) offers every country and names the country at the end of each row; the link is saved by the picked id.'],
        ['D8', 'LocationStockPage', self::ID_KEYED, 'A stock page filter (code LIKE); a list a person reads.'],
        ['E1', 'ProviderMappingService::suggestionsFor', self::COVERED, 'Suggests only the products of the provider\'s country: the default storefront\'s, until provider connections have a country of their own.'],
        ['E2', 'TorodMatchingController::productSearch', self::COVERED, 'Offers only the products of the provider\'s country (the default storefront\'s).'],
        ['E3', 'TorodChecks', self::ID_KEYED, 'A diagnostic that reads warehouse items, not store products.'],
        ['F1', 'Admin ProductCostController picker', self::LABELLED, 'The cost-layer picker offers every country and names the country at the end of each row and of the chosen product; the layer is written by the picked product id.'],
        ['G1', 'Printed labels and seller PDFs', self::ID_KEYED, 'Display only: the label prints the code of a product chosen by id.'],
        ['G2', 'Product screens and exports', self::ID_KEYED, 'Display only (the admin list has a country column since GCC-14a).'],
        ['G3', 'Storefront structured data (sku, gtin)', self::ID_KEYED, 'Display only, and a storefront shows its own country\'s products.'],
        ['G4', 'Order-line snapshots', self::ID_KEYED, 'A copy of the code beside the product id.'],
        ['G5', 'Purchase orders and cost ledgers', self::ID_KEYED, 'A SKU label beside product_id; pool keys are location, product id and variant.'],
        ['G6', 'Stock rows', self::ID_KEYED, 'Display only.'],
        ['G7', 'Outbound attributes (Torod externalId, M5AZN)', self::ID_KEYED, 'The product id is the external id; SKU and barcode are only attributes.'],
        ['G8', 'CatalogIntegrityProducer', self::ID_KEYED, 'A diagnostic that flags the same barcode inside one seller: a false positive for twins once sellers have markets.'],
        ['G9', 'Product gallery copy', self::NOT_COVERED, 'The copy blanks the code and barcode so it passes the old global rule; a copy-to-country flow keeps them.'],
    ];
}
