# B-11, B-12, B-13, B-15, B-16: one mechanism makes a catalogue entity visible or hidden per storefront

Status: coded and tested on SQLite and MariaDB 10.11.14 (branch `gcc/p1-catalogue-visible-per-storefront`). Dormant by construction — see below. Nothing here has been run on the pilot server or on production, and nothing is MET until CI has run on a pushed head.

## The rule

A storefront shows or does not show a category, a brand, a banner, a coupon, a deal, a clearance sale, a campaign or a variation attribute **without the central row ever being touched**. Hiding is a mapping, not a flag on the entity, so removing the mapping restores the entity exactly. Brands carry no country at all (B-11): one brand table serves every market, and each storefront counts that brand's products for itself.

## One table, one service, one scope

- **One table.** `storefront_entity_visibility` holds `(entity_type, entity_id, storefront_country, mode)`, unique on the first three. Nothing else is stored, and no entity table gains a column.
- **One service.** `App\Services\Storefront\StorefrontVisibility` answers "does this storefront show this entity" (`isVisible`), hands every reader the same predicate (`restrict`), and is the only writer (`set`, `storefrontsOf`).
- **One scope.** `App\Traits\HasStorefrontVisibility` adds the global scope `storefront_visibility` to the nine models. Every reader **in this repository** that goes through the model — the category tree, the home page, the filters and facets, the category and brand pages, the brand list, the API v1 attribute, category, brand and coupon lists, the app lists, the deals, the clearance sales and the campaign overlay — is narrowed by that one rule, and a reader added tomorrow is narrowed too. A reader that reaches the table with `DB::table()` instead of the model is not, and a reader in an add-on this repository does not vendor was not audited at all. Three repository methods drop *every* global scope (`CategoryRepository::getFirstWhere`, `FlashDealRepository` and `DealOfTheDayRepository::getFirstWhereWithoutGlobalScope`); they put this one back by hand.

A further entity type costs one line of `StorefrontVisibility::ENTITY_MODES` and the trait on its model — that is what the attribute type cost on the storefront side, and the bill is itemised in `B-12-LOCAL-ATTRIBUTES-PER-COUNTRY.md`. A **panel** screen that works on one country's catalogue while belonging to no storefront is the one thing this does not cover; it names the country through `StorefrontVisibility::offeredIn()`, which is the same table, the same two modes and the same predicate.

## Two defaults, and they are not the same rule

| Mode | Entity types | No mapping row | A row naming KW |
|---|---|---|---|
| `hidden` | category, brand | shown in every storefront (today's behaviour) | hidden in KW, shown everywhere else |
| `assigned` | banner, coupon, flash deal, deal of the day, clearance sale, experience campaign, attribute | shown in every storefront | shown **only** in KW |

They meet at the same safe place — with no rows at all, every storefront shows exactly what it shows today — but they are opposite rules, and `StorefrontVisibility` expresses both without conflating them (`test_the_two_defaults_are_not_the_same_rule`).

## Dormant by construction

Nothing is narrowed:

- while `GCC_STOREFRONTS_ENABLED` is off;
- for the admin and vendor panels, the seller APIs (`api/v2`, `api/v3`), a queue or a console command — they belong to no storefront, so every central row stays reachable there;
- before the migration has run;
- **while the entity type has no mapping row at all.** The service asks once per request which entity types are mapped (`SELECT DISTINCT entity_type`, remembered for 30 seconds) and adds no predicate to the types that are not. So a storefront with an empty mapping table runs the statement it runs today, byte for byte — `test_while_no_mapping_row_exists_the_storefront_runs_the_query_it_runs_today` compares the SQL.

`test_an_empty_mapping_table_changes_nothing_with_the_storefront_switch_on` and its switched-off twin compare the tree, the API category list, the brand list, the banners, the coupon list API and the campaign overlay, for SA and KW, against the answers the platform gives today.

## The caches

Three of the four criteria are about caches, because a key that does not carry the storefront serves one market's answer to another.

Already carrying the storefront (from the product and theme work): the main category list, the home category list, the category filter list, the priority-wise brand list, the category and brand page sub-lists, the flash deal and featured deal lists, the clearance product count.

Given the storefront here: `cache_banner_table`, the five `cache_banner_type_*_{theme}` keys, `BannerPlacementService`'s placement keys, `cache_clearance_sale_setup_table` and `commerce_campaign_overrides_{page}`. They use the same identity the theme work established (`StorefrontCatalog::cacheKey` / `storefrontCacheKey`), so the Saudi storefront keeps the plain key it writes under today and writes no second copy.

Invalidation reaches every market: `cacheRemoveByType('banners')` forgets `cache_banner_table` in every storefront as well as the whole banner key group, and `CampaignResolver::forget()` forgets each page's overlay in every storefront (it reads the campaign rows without the visibility scope, so a campaign limited to one storefront still clears the others' keys).

`StorefrontCatalogueVisibilityCacheTest` warms each cached reader as one storefront and reads it as the other, **both ways round**, and fails if either is served the other's answer.

## Before turning it on

1. Run `2026_12_08_100000_create_storefront_entity_visibility_table`. It creates one table and changes nothing else; it can be rolled back at any time, and the code goes dormant again the moment the table is gone. Both directions are idempotent, because this platform's deployment imports `installation/backup/database.sql` before it migrates (`test_the_migration_can_run_twice_and_be_rolled_back_twice`).
2. Write the mappings you want with `php artisan storefront:visibility {entity-type} {entity-id} [countries...]` (the entity types are `category`, `brand`, `banner`, `coupon`, `flash_deal`, `deal_of_the_day`, `clearance_sale`, `experience_campaign` and `attribute`) (no countries removes the mapping and restores the entity everywhere; `--show` only prints). The command is a thin shell over `StorefrontVisibility::set` / `storefrontsOf`, which is the whole write surface; a country that is not configured is dropped rather than stored.
3. Clear the application cache once, or wait for the entries to expire.

Rollback: remove the mapping rows (or drop the table). Nothing else has changed, because nothing else was written.

## What is not done

- **No admin screen writes the mapping yet.** `php artisan storefront:visibility` is the only door. A Banner Setup / Category / Brand / Coupon form that calls the same service is not built, in either theme — it is the obvious next slice, and it changes nothing about the mechanism.
- **`Modules/Blog` is not mapped.** Blog categories are a separate model and carry no mapping.
- **The auction add-on was not audited, because it is not vendored in this repository.** `git ls-tree -d HEAD Modules/` returns `AI`, `Blog` and `TaxModule` only. `App\Models\Category` and `App\Models\Brand` do declare an `auctionProduct()` relation to `Modules\Auction\app\Models\AuctionProduct`, so the add-on exists on a deployed server and reads this catalogue — but nothing in this tree can show how. Where it reads `App\Models\Category` or `App\Models\Brand` it is narrowed by the same global scope as every other reader; where it reads `DB::table('categories')` or its own category model, it is not, and nobody working in this tree can see which it does. Audit it on a checkout that has the add-on before the mechanism is turned on in a market that sells auctions.
- The coupon's own `coupons.storefront_country` (GCC-05 slice 5) is untouched: it says which currency a coupon's amounts were typed in, and still narrows the coupon finders on its own. The mapping is a second, independent narrowing, and a coupon may be assigned to several storefronts where the column names one.
