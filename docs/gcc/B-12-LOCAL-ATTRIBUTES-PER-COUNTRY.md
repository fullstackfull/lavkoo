# B-12: a variation attribute defined for one country is offered only there

Status: coded and tested on SQLite and MariaDB 10.11.14 (branch `gcc/p1-catalogue-visible-per-storefront`). Dormant by construction. Nothing here has been run on the pilot server or on production, and nothing is MET until CI has run on a pushed head.

Two of the three plan criteria are closed. The third is not built, and the end of this page says exactly what it needs and why it was not bolted on here.

**"that country's product forms" is still only partly closed, and should stay at the lower state.** Five forms read the attribute list, not four: the four web/panel forms and the seller app's Add Product screen. All five can now ask the right question — the endpoint the fifth one reads takes the country — but the fifth one does not yet *send* it, and this slice does not touch either Flutter app. In a single-market deployment it answers the same thing either way, so nothing is wrong today; the criterion is one app change away from being closed, and the change is named below.

## The claim this slice was the test of

B-13 shipped one mapping table for the catalogue and claimed that **a further entity type costs one line of `StorefrontVisibility::ENTITY_MODES` and the trait on its model — no new table, no new scope.** B-12 is the first type added after that claim was written. What it actually cost:

| What B-13 claimed | What B-12 needed |
|---|---|
| one line of `ENTITY_MODES` | one line: `self::ATTRIBUTE => self::MODE_ASSIGNED` |
| the trait on the model | `use HasStorefrontVisibility` + `STOREFRONT_ENTITY_TYPE` on `App\Models\Attribute` |
| no new table | no new table |
| no new scope | no new scope |
| — | **one thing the claim did not cover: `StorefrontVisibility::offeredIn()`** |

The claim holds **for the storefront side** and cost exactly what it said. It did not cover the panel side, and that is worth writing down rather than papering over.

### What the mechanism was missing

`StorefrontVisibility::restrict()` narrows a query to **the storefront the request is served from** (`StorefrontCatalog::country()`). For a category, a brand, a banner or a campaign that is the whole story, because those are read on the storefront and authored on a screen that does not need to be narrowed — an admin editing the category tree should see the whole tree.

An attribute is different. Its most important reader is the **product form**, which lives in the admin and vendor panels — and the panels belong to **no** storefront, on purpose: that is exactly what keeps every central row reachable there and is what the dormancy guarantee rests on. So the question a product form asks is not "what does my storefront show?" but "what does *the country this product belongs to* show?", and `restrict()` had no way to express it. `isVisible()` already took an explicit `?string $country`; `restrict()` did not. That asymmetry was the gap.

The fix is a second public door on the same service, not a second mechanism:

```php
StorefrontVisibility::offeredIn(query: …, entityType: …, country: 'KW', alwaysOffered: [7]);
```

Same mapping table, same two modes, same `whereNotExists`/`orWhereExists` predicate builders — `restrict()` and `offeredIn()` both go through one private `narrowFor()`. The only new behaviour is *where the country comes from* and the `alwaysOffered` escape hatch below.

## Which default an attribute wants: `assigned`, not `hidden`

An attribute **defined for** one country must appear only there. That is the `assigned` rule:

| | no mapping row | a row naming KW |
|---|---|---|
| `assigned` (attribute) | offered in every storefront | offered **only** in KW |

With no rows at all every storefront offers every attribute, which is byte-for-byte what the platform does today and what the live Saudi storefront keeps doing. `hidden` would have been wrong: it would have made "defined for KW" mean "hidden in the other five", which an operator would have to write five rows to express and would get wrong the moment a seventh market is configured.

`test_an_attribute_with_no_mapping_is_offered_everywhere_with_the_switch_on` and its switched-off twin pin the no-row behaviour; `test_an_attribute_assigned_to_kuwait_is_offered_on_kuwaits_product_form_and_not_on_saudis` pins the row behaviour.

## The three surfaces

**The storefront.** `GET api/v1/attributes` with no country named (`RestAPI\v1\AttributeController::get_attributes`) is narrowed by the global scope, like every other storefront read. `api/v1` is a storefront path — it carries no `storefront` middleware and is not in `StorefrontCatalog::PANEL_PATHS`, so a real request falls through to `contextOfUnboundRequest()` and is bound to the storefront it asks for — so the scope has a country to narrow to. This is what the **customer app's compare screen** reads (`lavkooapp/lib/features/compare/domain/repositories/compare_repository.dart:59` → `app_constants.dart:249`), and it is the right answer for it: the compare screen compares products of the storefront the customer is already shopping, so the attribute vocabulary it labels them with is that storefront's. It sends no country and needs no change.

**The panels' product forms.** Four call sites, all narrowed by the country the form is filled in for, resolved by `ProductCountry::countryOfForm()`:

| Form | Country it is narrowed to | Why |
|---|---|---|
| admin add | the country the form carries (`old('storefront_country')`), else the default storefront | the operator picks it on the form (GCC-14) |
| admin update | the product's own `storefront_country` | a product's country never changes |
| vendor add | the default storefront | `ProductService::getAddProductData` writes no country for a seller product, so it takes the table default |
| vendor update | the product's own `storefront_country` | as above |

The list is fetched through one repository method, `AttributeRepository::getListOfferedIn()`. No blade changed: the `<option>` loop and every class stay exactly as they were — only the collection handed to it is narrower.

**The fifth product form, in the seller app.** The first revision of this slice wired the four web forms and never grepped the apps. There is a fifth form, and it asked the wrong question:

```
lavkooappseller/lib/utill/app_constants.dart:41                                    attributeUri = '/api/v1/attributes'
lavkooappseller/lib/features/addProduct/domain/repository/add_product_repository.dart:30   the ADD PRODUCT screen fetches it
```

Because `api/v1` is a storefront path, that screen's list was narrowed by the storefront of the **request** rather than by the country of the **product being authored** — exactly the asymmetry `offeredIn()` exists to remove. Measured out of console mode (which is why no test over the controller reached this path), with attribute 2 assigned to KW:

```
GET api/v1/attributes                -> [1]
GET api/v1/attributes?storefront=KW  -> [1,2]
GET api/v1/attributes?storefront=SA  -> [1]
GET api/v2/attributes                -> [1,2]   (a panel path, for contrast)
```

So the same endpoint now answers **either** question, and the caller says which:

| the request | the question | narrowed by |
|---|---|---|
| `GET api/v1/attributes` | what does **this storefront** offer? | the global scope, as today — byte for byte |
| `GET api/v1/attributes?storefront_country=KW` | what does **Kuwait's catalogue** offer? | `getListOfferedIn()`, the same door the four forms use |

`storefront_country` is the spelling the admin form posts (`old('storefront_country')`) and the product row carries (`StorefrontCatalog::COLUMN`), so the app, the form and the column name the country the same way. The named country is the one answer; the request's own storefront is **not** ANDed on to it.

**Only a storefront the caller can already be served from may be named.** `?storefront=AE` cannot choose a market that is configured but not active (`StorefrontResolver::countryOfChoice()` requires `active`), and this route carries no `auth:api`, so letting `?storefront_country=AE` through would have let an unauthenticated caller read the catalogue a market is preparing *before it opens*. `StorefrontResolver::reachableCountries()` is that predicate — the active storefronts, or the default one alone while storefronts are off — extracted from `activeStorefronts()`, which now reads it instead of repeating the filter. A named answer is therefore exactly the answer the same caller gets when served from that storefront: naming one discloses nothing new. Anything else (an unopened market, an unconfigured code, a blank) answers the unnamed question, so a stale code never empties the list rather than narrowing it to nothing.

**The seller app itself is not changed in this slice, on purpose.** What it would have to send is one query parameter on the call at `add_product_repository.dart:30`: `GET /api/v1/attributes?storefront_country=<CC>`, where `<CC>` is the country the add-product form is authoring for. Today a seller product takes the table default (`ProductService::getAddProductData` writes no country for `addedBy: 'seller'`, and `api/v3/seller/products/add` is where it posts), so that code would equal the default storefront's and the answer is the same as sending nothing — which is why the backend half forces no app release. The parameter becomes load-bearing when sellers get markets; until then the screen is correct by accident in a single-market deployment and correct by construction once it sends the country.

### `alwaysOffered`: a mapping added later never strips a merchant's work

The two update forms pass the attribute ids the product **already carries** (`ProductService::getProductAttributeIds`). Those rows are offered whatever the mapping says. Without this, assigning an attribute to KW would quietly drop it from every SA product the next time somebody pressed Save on that product, because the multi-select would no longer contain the option and the save writes what the select posts. `test_the_form_keeps_offering_an_attribute_the_product_already_carries` pins it.

## Not a leak: the product page does not read this table

A product's `choice_options` column stores the attribute's **title and its option values copied in at save time**; `products.attributes` stores only ids and `Helpers::product_data_formatting` casts them to integers without joining `attributes`. So an attribute id from another country sitting on an older product's row shows nothing to a storefront — the leak surface is the **lists that read the table**, which is the API list (named and unnamed) and the four form lists, and those are the three surfaces above. That is why this slice adds no save-time refusal: it would guard a path that cannot leak, across six writers (`ProductService` ×2 and the v2/v3 seller product controllers ×4), for no gain in what a customer can see.

## Dormant by construction

Nothing is narrowed while `GCC_STOREFRONTS_ENABLED` is off, for the admin and vendor panels' *other* reads, for the seller APIs, a queue or a console command, before the `storefront_entity_visibility` migration has run, or while no attribute has a mapping row. `offeredIn()` additionally narrows nothing for a country that is not configured, so a stale or mistyped code shows the whole list rather than an empty one (`test_a_country_that_is_not_configured_narrows_nothing`), and the endpoint narrows nothing for a country it will not let a caller name (`test_a_value_that_names_no_reachable_storefront_answers_the_unnamed_question`).

With the switch off the endpoint runs `select * from "attributes"` with no bindings and returns the same status and the same JSON body whether or not a country is named: base and head are byte-identical over nine scenarios — an empty mapping table, a KW mapping row written before launch, and no mapping table at all, each with no country named, with `?storefront_country=KW`, with `?storefront_country=SA` and with `?storefront=KW`, on the real-request path with console mode forced off (`test_naming_a_country_changes_nothing_while_the_mechanism_is_dormant` keeps the committed half of that).

One write path is exempted by hand: `M5aznProductImporter::variationData()` does `Attribute::firstOrCreate(['name' => …])`. A lookup that may write must see the whole table — narrowed, it would miss the central row and add a second attribute under the same name — so it drops the `storefront_visibility` scope explicitly.

## Caches

There is no cached reader of attributes. `CacheManagerTrait` has no attribute key, the v1 attribute endpoint caches nothing in either of its two answers, and the four form lists are per-request. The per-process memos inside `StorefrontVisibility` (`$tableAnswer`, `$mappedTypesAnswer`) decide only **whether** to narrow, never **to what** — the country is read at query time — so they cannot answer for the wrong market. `test_a_storefront_is_never_served_the_other_storefronts_attribute_list` warms the list as one storefront and reads it as the other, **both ways round**, with the memos warm and then with the cache flushed, and fails if either is served the other's answer.

## Before turning it on

1. Run `2026_12_08_100000_create_storefront_entity_visibility_table` (B-13's table; this slice adds none).
2. `php artisan storefront:visibility attribute {id} KW` assigns an attribute to a storefront; with no countries it removes the mapping and restores the attribute everywhere; `--show` only prints.

Rollback: remove the mapping rows. The `attributes` rows were never touched (`test_the_central_row_is_untouched_and_removing_the_mapping_restores_the_attribute` compares the whole central row before and after).

## What is not done

- **No admin screen writes the mapping.** As for B-13, `php artisan storefront:visibility` is the only door. An Attribute Setup form calling the same service is the next slice and changes nothing about the mechanism.
- **`Modules/AI` suggests attributes from every country.** `Modules\AI\app\Services\ProductResourceService::getVariationData()` reads `$this->attribute->get(['id','name'])` to build the AI prompt's attribute vocabulary. It runs in the panel, so it is not narrowed, and it has no product country to name. The consequence is degraded, not leaking: `setAttributeForAI` (`public/assets/backend/admin/js/AI/products/product-variation-setup.js`) selects by matching `<option>` values, and an attribute the narrowed form does not offer has no option to match, so it is simply not selected. Threading the form's country through `ProductResourceService` → the prompt templates is a `Modules/AI` change and was not made here.
- **No admin form re-fetches the list when the country select changes, and the copy-from-gallery path starts on the wrong country's list.** The list rendered is always exactly one country's, but it is the country the form was *opened* for, and the select can be moved afterwards.
  - On the **admin add** form, choosing a different country does not reload the list without a round-trip.
  - On the **copy-from-gallery** path it is worse by one step: the gallery's copy link is `route('admin.products.update', ['id' => ..., 'product-gallery' => 1])` (`resources/views/admin-views/product/product-gallery.blade.php:162`), so `getUpdateView()` renders `edit.blade.php` for the **source** product — the attribute list is `countryOfForm(product: $sourceProduct)`, the source product's country — while `_storefront-country.blade.php` sees `request('product-gallery')` and therefore renders a *select* rather than the fixed label, and the form posts to `admin.products.add`. The new product is created in whatever country that select names, which need not be the country whose attribute list is on the screen.
  - Both have the same cause and the same fix: a `<select>` that re-fetches needs JS and an endpoint. The endpoint now exists — `GET api/v1/attributes?storefront_country=<CC>` — so what is left is the JS, and it was not added here because the rule in `.claude/rules/auction-ui.md` is to inject logic, not to rework a form widget. The consequence is degraded, not leaking: the operator sees one real country's attributes, saves, and the product's country is fixed from then on; reopening the product for edit shows that country's list. Nothing of another country's is written to the product by this (`products.attributes` holds ids and `choice_options` holds the titles and values copied in at save time, so a variation the operator did not pick is not created).

### The third criterion is not built: "reusable option values can be picked from a central list instead of free text"

**It is not a slice of this mechanism; it is a new entity.** Today there is no such thing as an attribute value anywhere in this platform:

- `App\Models\Attribute` is `id` + `name` + translations. There is no `attribute_values` table, no model, no repository, no admin screen.
- An attribute's option values are typed **per product, as free text**, into a `data-role="tagsinput"` field (`resources/views/{admin,vendor}-views/product/partials/_choices.blade.php`), split on commas by `ProductService::getChoiceOptions()`, and stored inside the product's own `choice_options` JSON. Two products with "Red" each hold their own copy of the string.

A central list a form offers instead of free text therefore needs, at minimum: a table and model for the values (with translations, since attribute names are translated); admin CRUD for them, including the per-country mapping this slice establishes; the tagsinput in four blades (admin/vendor × add/update) replaced by a picker that still accepts a free-typed value, because every existing product's values were typed; `ProductService::getChoiceOptions()`/`getOptions()` taught to resolve a picked value to its central row while still accepting a typed one; the same in the two v2 and two v3 seller product writers, so web and API stay in sync; and a read path so `api/v1/attributes` can return an attribute's values.

That is a table, a model, a repository, two admin screens, six blades, four API writers and a migration — a slice of its own. Half of it (a table nothing offers, or a picker over a table nobody can fill) would be worse than none. **It is left open and named, not quietly bolted on.**
