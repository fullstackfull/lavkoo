# Where the platform finds a product by its SKU or barcode (2026-11-16)

Read-only static survey of the stack tip (`gcc/p1-payment-callback-storefront`, #53, `a76d3254`). Nothing was run. Four of the sites (C1, A9, A10, B2) were re-read by hand and confirmed; the rest are the survey's own reading, with file and line, and should be re-read before anyone changes them.

**Why it was made.** `products.code` (the SKU) and `products.barcode` are unique across the whole table today, by validation only (there is no unique index). The decided design (GCC-02/03, GCC-14) gives every country its own product row. Letting two countries share a SKU or a barcode (the same physical box carries the same EAN in Saudi Arabia and Kuwait) is only safe when every place that resolves a product from that text knows the country first. This is the map of those places. The first admin slice (GCC-14a, draft PR on #53) therefore keeps both unique across all countries and says so.

## Facts that shape the answer

- No customer or anonymous request resolves a product by code or barcode. The storefront searches names, tags and details, and its pages use slugs and ids. Every site below is an admin, vendor, seller-app, system-job or channel-sync path.
- Those actors are never narrowed to a country (`StorefrontCatalog::PANEL_PATHS`: `admin`, `vendor`, `seller`, `api/v2`, `api/v3`, console and queues), so each lookup below sees every country's rows.
- Nothing in `app/` wrote `products.storefront_country` before GCC-14a: every create path (web forms, v2 and v3 seller APIs, bulk import, the M5azn import) relied on the table's default `SA`.
- No database uniqueness: only the plain `idx_products_code`. No migration of this tree creates `products.barcode` (it is in the baseline schema; two services guard it with `hasColumn`).
- The Flutter apps are not in the repository: any client-side matching on `code`, `barcode` or `scan_code` (appended to every product payload by `Product.php:188`) cannot be audited here.
- Found nothing keyed by product code in: a bulk update spreadsheet (none exists: the import only creates, and has no code or barcode column), Odoo (it posts journal entries only), seeders, data migrations, `config/`, `Modules/` (AI, Blog, TaxModule), JS or Blade matching, observers, listeners, jobs, the seller bulk jobs (they carry product ids), purchase orders, costs and stock pools (all by `product_id`).

## A. The uniqueness gates (validation refuses a shared code today)

| # | Where | Rule | Who |
|---|---|---|---|
| A1 | `app/Http/Requests/ProductAddRequest.php:49` | `unique:products,code`, whole table | admin and vendor web add |
| A2 | same file `:52` | `unique:products,barcode`; its comment says "so two products can never answer the same scan" | same |
| A3 | `app/Http/Requests/ProductUpdateRequest.php:65` | `Rule::unique('products','code')->ignore(self)` | admin and vendor web update |
| A4 | same file `:73` | same for barcode | same |
| A5 | `RestAPI/v2/seller/ProductController.php:153` | inline `required\|unique:products` (code only) in `add_new` | seller app v2 |
| A6 | same file `:394` | `unique:products,code,{id}` in `update` | seller app v2 |
| A7 | `RestAPI/v3/seller/ProductController.php:798` | inline `unique:products` in `add_new` | seller app v3 |
| A8 | same file `:1233` | `unique:products,code,{id}` in `updateProduct` | seller app v3 |
| A9 | `app/Services/ProductService.php:765-772` | `getUniqueProductSKUCode`: `Product::where('code',$code)->exists()`, recursive, global | bulk import only |
| A10 | `Providers/M5azn/M5aznProductImporter.php:145` (check at `:487-502`) | `products WHERE code = sku OR barcode = barcode ->exists()` over all countries | admin button or provider sync job |

Dead code, not counted: `Requests/API/v3/ProductAddRequest.php:42` and `ProductUpdateRequest.php:46` repeat the rule but are imported and never type-hinted.

A1 to A8 are loud and safe (a validation error), and are the eight independent entry points to change: one missed keeps refusing a shared code on that path. A10 is fail-closed: an M5azn item whose SKU or barcode exists in the Saudi catalogue is parked `needs_review: sku_or_barcode_already_used_by_another_product` and never auto-imports as a Kuwaiti product.

## B. Bulk import

- **B1** `ProductService.php:836-838`: `$productsTax[$productCode] = tax ids`, keyed by the freshly generated code (the sheet has no code or barcode column). Consumed by B2. Collides if a code may repeat.
- **B2** `Admin/Product/ProductController.php:874-885` (confirmed): after the insert, `Product::whereIn('code', array_keys($productsTax))->get()`, then the tax rows of every product found are deleted and rewritten from the imported row. Safe only while A9 is global: a generated code that also exists on another country's product would delete and rewrite that product's tax rows (wrong-country VAT). The vendor import does not re-query.

## C. POS, lists and pickers

- **C1** `RestAPI/v3/seller/POSController.php:204-221` (confirmed), route `GET pos/products?code=`: `Product::where([added_by seller, user_id seller, 'code' => request code])->first()`. Code only (never the barcode), no status, active or country filter, no ordering. A seller with a Saudi and a Kuwaiti copy always gets the lowest id, so the sale is at the wrong country's price, stock and tax, silently. The only unattended `first()` on a sale path.
- **C2** `ProductRepository.php:259-266` (`search_from=pos`: `code LIKE OR barcode LIKE OR name LIKE`): the admin and vendor POS search, fired on every keystroke. The rows show name, category, SKU, brand and shop, no country; a wrong pick sells the other country's product.
- **C3** `ProductRepository.php:376-386` (`getWebListWithScope`: name, code, barcode, id): the POS grid, and the admin and vendor product lists, export and gallery.
- **C4** `ProductRepository.php:250-258` (`getListWithScope`): the order-edit "add product" search (admin and vendor) and the clearance-sale pickers. Not narrowed to the order's country, no country in the rows: an admin can add another country's product to an existing order or promotion.
- **C5, C6** `ProductRepository.php:170-176`, `:211-217`: exact `code = x OR barcode = x` for the reviews filter and the flash-deal and deal-of-the-day pickers (which write discounts by the picked id).
- **C7 to C11** stock-limit and restock lists (`ProductRepository.php:608-618`, `RestockProductRepository.php:125`), the seller centre lists, inventory list and global search (`SellerCenter/Lists/ProductList.php:44`, `InventoryList.php:51,63`, `Search.php:139,153`): `code LIKE x%`, lists a person reads.

## D. Sales channels

- **D1** `SalesChannels/Sync/ProductMatcher.php:24-55`: admin-owned physical products where code, barcode or the variation's sku equals the channel's, limit 50, one candidate suggests or auto-links, two or more become CONFLICT. Every shared code becomes a conflict and auto-link stops; an unlinked listing publishes no stock or price (a silent loss, not wrong data). No account-country filter.
- **D2** `Sync/ChannelOrderIngestor.php:272-280`: the mapping is found by `(account, listing_key)` where the key is the channel's own string, unique per account, so duplicate products add no rows; harmless unless one account's listing is linked to the other country's twin.
- **D3** `Listings/ListingDraftService.php:346-364`: the default outbound listing key is `products.code` (Noon partner_sku, Amazon SellerSKU, Trendyol barcode fallback). Guarded: a key held by another product is refused (`ListingPublisher.php:188-191`, `:203-257`, `:386-410`). Two countries publishing into one channel account is refused, fail-closed; different accounts are fine.
- **D4, D5** `Inventory/ChannelInventoryWriter.php:34-52`, `Publishing/StockPricePublisher.php:371`: keyed by the account's own listing key; harmless per account.
- **D6** `Routing/RoutingRuleMatcher.php:35`: a routing rule on a SKU matches the channel line's SKU (our code), case-insensitive, first rule wins. A rule written for the Saudi SKU also fires for the Kuwaiti line (any account when the rule has none, any destination when it names no country), choosing the provider, location and execution policy of the wrong order. A product-id key and a country condition exist as alternatives.
- **D7, D8** the admin listing-link picker (`Support/ProductDirectory.php:31-39`, limit 20, no country in the rows; the link is saved by id) and the stock page filters.

## E. Fulfilment

- **E1** `Fulfillment/ProviderMappingService.php:146-196`: the Torod matching suggestions (barcode, sku, id), limit 10; approval is by id and then overwrites `products.current_stock` from the warehouse figure. Two suggestions with the same reasons, and a wrong approval binds the warehouse's stock to the other country's product.
- **E2** `Admin/Fulfillment/TorodMatchingController.php:297-306`: the picker, same hazard.
- **E3** `Fulfillment/Verification/TorodChecks.php:143-160`: a diagnostic that takes the first warehouse item with the typed SKU.

## F. Costs

- **F1** `Admin/OperationalCosts/ProductCostController.php:128-136`: the picker that records a purchase cost layer by product id; a wrong pick books cost of goods on the other country's product.

## G. Display only (id-keyed, a label)

G1 printed labels and the seller PDFs (labels carry no country; they are what C1 and C2 later read); G2 product screens and exports; G3 structured data (`sku`, `gtin`) on the storefront; G4 order-line snapshots; G5 SKU labels beside `product_id` in purchase orders and cost ledgers (pool keys are location, product id and variant); G6 stock rows; G7 outbound attributes (Torod `externalId` is the product id; SKU and barcode are only attributes); G8 diagnostics (`CatalogIntegrityProducer` flags the same barcode inside one seller, a false positive for twins); **G9** the product gallery's copy blanks the code and barcode (`update/_general-setup.blade.php:205,222`, `vendor-views/.../update/_general-setup.blade.php:178,193`) so the copy passes A1 to A4: the admin types a new SKU for a copy today, and a copy-to-country flow has to change these.

## Totals

35 real sites (19 on a money, stock or order path, 16 on an admin convenience path): 10 uniqueness gates, 2 keyed maps (B1, D5), 3 update-or-create by code (B2, D3, D4), 4 ambiguous `first()` (C1, D2, D6, E3), 16 lists a person picks from, and 9 display-only groups.

## The ten most dangerous, silent first

1. C1 the v3 seller POS scan. 2. D6 the routing rule by SKU. 3. C2 and C3 the POS search and grid (rows cannot be told apart). 4. E1 and E2 the Torod matching. 5. C4 and C6 the order-edit and promotion pickers. 6. B2 (with B1 and A9) the bulk-import tax rewrite. 7. D1 the channel matcher (auto-link dies). 8. A10 the M5azn clash check. 9. D2 the channel order line. 10. D3 the outbound listing key.

## Channel and fulfilment identifiers: id or code?

Sales channels keep the channel's own string on `sales_channel_product_mappings` (unique per account), and the link to a product is `product_id` plus the variant; orders, reservations, stock pools, stock and price publishing and cost layers follow `product_id`. A product's code enters in two places only: proposing a link (D1, a list, two or more is a conflict) and the default outbound key (D3, guarded). One rule is keyed by a string (D6). Two countries survive in the channel layer when each publishes through its own channel account; in one account the second publish is refused and auto-link is off for shared codes, never silently overwritten.

Fulfilment keeps `provider_product_mappings` unique by `(provider, external_id, external_variant_id)` and `(provider, product_id, variant_key)` (the migration says "a barcode is NOT unique"); stock sync resolves warehouse figure to mapping to `product_id`; the Torod push sends the product id. A code or barcode appears only in a human's suggestion (E1, E2) and in the creation blocker (A10). One warehouse item can map to one store product, so a Saudi and a Kuwaiti row cannot share one Torod or M5azn item.

## What this means for GCC-14

- GCC-14a keeps SKU and barcode unique across all countries. The admin picks the country of a new product (a copy for another country is the product gallery with a new SKU, which A1 to A4 already force).
- Per-country SKU and barcode is GCC-14b and needs, in this order: the eight gates A1 to A8 scoped by country; C1 narrowed by country (and to look the barcode up too); C2, C3, C4, C6, D7, E2 and F1 showing the country and, where the context has one, narrowing to it; D6 keyed by product id or given a country; B2 narrowed to the imported ids; A10 told the country; and the Flutter apps checked for matching on `scan_code`.
- Until then, a Kuwaiti copy of a Saudi product needs its own SKU, and its barcode is left empty or made unique.
