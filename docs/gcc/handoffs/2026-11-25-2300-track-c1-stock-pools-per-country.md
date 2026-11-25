# Track C1 — stock pools, publications and channel accounts are per country

**Worktree** `/home/user/wt/acgh`, branch `wt/acgh`, cut from `30a2283e`. Nothing pushed.
**Database** `pharmacy_testing_acgh`. Engine MariaDB via `phpunit.mysql.xml`.

Three recorded MISSING criteria, one gap: `PoolKey` — the identity of a stock pool — named no country.

---

## The gap, and what it actually was

A pool is one physical stock as the channels are offered it, keyed by `(location_id, product_id, variant_key)`. The product row
names a market and the shelf names a market, but the POOL named neither. Three consequences, and only one of them is the oversell
the tracker's wording suggests:

1. **The identity could not be attributed.** The row the single writer takes `FOR UPDATE` for every division, the division written
   onto each listing (`allocation.pool`) and the figure an operator reads said nothing about whose stock had been shared out.
2. **A nameless account could take another market's units.** `ChannelMarketMatch`'s recorded rule is that an account whose settings
   name NO country is never narrowed — right for the pools that exist today, all of which are the default market's, and wrong the
   moment a Kuwaiti product has a pool: such an account was admitted to it and given a share of Kuwait's stock.
3. **A market's own stock was divided with foreign claimants.** Where the markets are NOT read at all, two countries' accounts on one
   pool each take a share, so Saudi Arabia is bounded below its own stock while Kuwait is offered Saudi units — Kuwait publishing
   more than Kuwait holds. Measured: with the market rule blinded, Saudi Arabia may show 25 of its 40 and Kuwait 25 of its 10.

What was ALREADY closed before this track, and is not re-done here: `ChannelMarketMatch` refuses a listing whose product or whose shelf
belongs to another market than the account's (E11-F19), and `ChannelAccountMarketStop` makes no channel WRITE call for a market that is
paused or not open for sale (H2-12). Both are asserted in the new tests so that the three protections are not mistaken for one another.

---

## Criterion by criterion

### M-656 — axis-m line 656

> `- [MISSING] Channel and provider stock pools and publications are per country (PoolKey carries no country)`

**Target state.** A pool's identity names the market it belongs to, so a pool, the division written from it and the publication made
from it can be attributed to one country.

**Done.** `PoolKey` carries a `market`. `''` is the default storefront's pool — every pool this platform has today — and
`PoolMarket::ofProduct()` answers `''` for every product until storefronts are on, the products carry a market and the pool table is
keyed by one. The market reaches every place a key is built from a row or a product: `DirtyPools::pending()` (from the row's own
market), `StockEventDrain::poolsOf()`, `ProviderFigureNotes`, `ChannelOrderReservations::sync()`, `LocationStockPage::withPools()`,
and `PoolKey::ofListing()`, which is what `StockPlanner`, `StockPricePublisher`, `PoolWarnings` and the admin listing screen use.

**State: MET for pools and for the publications made from them.** The outbox row and the reservation row still carry no market column
of their own — the product they name answers for them — so narrowing those two tables by market in SQL needs a join. Deliberate; see
*Not closed* below.

### M-745 — axis-m line 745

> `- [MISSING] Stock pools, publications and the own-warehouse authority are per country; two countries' accounts publishing one SKU never exceed each stock (PoolKey carries no country; no test)`

**Target state.** Three things per country, and a test that two markets' accounts on one SKU are each bounded by their own stock.

**Done.** The pool and the publication, as above. `PoolMembers` now asks `PoolMarket::admits()`: a pool of a non-default market admits
only an account that names that market. The test exists — `test_two_countries_accounts_publishing_one_sku_never_exceed_each_stock`
measures the entitlement the single writer worked out, per market, and `test_on_the_wire_each_account_is_sent_its_own_markets_figure`
measures what left the host.

**State: PARTLY MET. The own-warehouse authority is NOT per country and is not closed here.**
`sales_channels.inventory.own_warehouse_source` is one environment value for the whole platform, and
`LocationStockReader::readsPlatform()` needs it AND the location's own `source_of_truth`. The per-location half is already per market
(`inventory_locations.storefront_country`); the global half would need a key in `config/sales_channels.php`, which is outside this
track's paths, and the tracker records the authority itself as waiting on the owner (A1-18 / "per-country pools need the owner decision
on the own-warehouse authority"). Unchanged and named rather than guessed at.

### M-744 — axis-m line 744

> `- [MISSING] A channel account holds an explicit storefront country and refuses a marketplace of another country (Country is derived from settings; no validation)`

**Target state.** The account holds the country explicitly, and a marketplace of another country is refused.

**Done.** `ChannelSettings::MARKET` (`storefront_country`) is a setting of every channel that has settings, present only while
`gcc.storefronts.enabled` is on, and validated where every account setting is validated:

- empty declares nothing and changes nothing — the derived answer stands, which is every account that exists today;
- a country the platform has no storefront for is refused, naming the configured list;
- a declaration that contradicts the account's own marketplace is refused, naming both: an Amazon account of the Emirati marketplace
  cannot declare the Saudi storefront, a noon account whose prices are Emirati cannot, a Trendyol account of the Saudi storefront
  cannot declare Kuwait, and a Trendyol account with an EMPTY storefront code sells on the Turkish marketplace, so it cannot declare a
  Gulf one either;
- a marketplace whose country this platform does not list is not a contradiction — a new Amazon marketplace id is a fact about
  Amazon's catalogue, and refusing it would stop an account the day Amazon opens one we have not listed.

`ChannelAccountCountry::of()` prefers the declaration to the derived answer, so the pool, the market stop and the product matcher all
read one answer. The marketplace→country map is not copied: `ChannelAccountCountry::countryOfMarketplace()` is now public and is the
one answer both the derivation and the validation use.

**State: MET in the service funnel.** `ChannelAccountService::create()`/`update()` is the only writer of account settings, and it goes
through `ChannelSettings::validate()`. The admin FORM has no row for the field (`AccountForm` is outside this track's paths), so the
declaration is settable through the service, the console and seeding but not yet on the screen.

---

## Tests

| file | tests | what |
|---|---|---|
| `tests/Feature/SalesChannels/PoolCountryIdentityTest.php` | 14 | M-656, M-745: the key's shape with the switch off, the market in the key and in the row, an existing pool is the default market's, the dirty-mark round trip, two markets bounded by their own stock, who may take from a pool, the two markets' rows one shelf can hold, the migration's rollback, and the rollback switch's limit |
| `tests/Feature/SalesChannels/ChannelAccountStorefrontCountryTest.php` | 10 | M-744: dormancy, the declaration, precedence, the four refusals, the unknown marketplace, and the tie to the pool |

24 new tests, 75 assertions, all green on MariaDB. Regression runs on the files that share this code: `tests/Unit/SalesChannels`
(139), `StockAllocationTest` (31), `StockOutboxTest`/`PoolOutsidersTest`/`StockAllocationCommandsTest`/`StockShareStrictModeTest`
(62), `ChannelAccountServiceTest` + `ChannelPoolMarketTest` (30) — all green. `AdminSalesChannelsAccountsTest`,
`ChannelMarketStopTest`, `InventoryLocationMarketTest`, `RegulatedMarketRulesTest`, `AdminNoonAccountTest` and
`SaudiLiteralsAllowListTest` (61 in the file that holds the one failure) run green but for one PRE-EXISTING failure that has nothing
to do with this work — see *A defect found on the way* below.

A first attempt at that batch reported 13 errors and 2 failures; they were CONTENTION, not this code. Two of my own PHPUnit processes
were migrating `pharmacy_testing_acgh` at the same time, and the errors were "Base table ... doesn't exist" and duplicate-key
violations from one run altering the schema under the other. Re-run alone, the batch is green but for the one pre-existing failure.
Four other tracks share this box: a failure in this area is worth re-running alone before believing it.

The new migration is NOT added to `tests/Support/BuildsSalesChannelSchema.php` (outside this track's paths), so the other 120
sales-channel test files exercise the PRE-migration shape. That is real dormancy coverage and it is why none of them changed; adding it
there is a merge-time decision, not a fix.

---

## Teeth checks

Every test was checked by breaking the production code it covers, running it, and watching THAT test fail with a message naming the
fault. Each break was restored and `git status` confirmed clean afterwards.

### The pool's market

| break | tests that failed, and how |
|---|---|
| `PoolMarket::applies()` drops the `gcc.storefronts.enabled` gate | `..._the_pool_key_is_the_one_it_has_always_been`: "product 51 — expected `''`, actual `'KW'`". `..._every_account_divides_the_pools_it_always_did`: "and the second account its 10 — null is not identical to 10" |
| `PoolMarket::keyedByMarket()` returns true | `..._the_pool_table_is_not_keyed_by_a_market`: "the pool table has no market column yet — true is not false" |
| `PoolMarket::ofProduct()` always answers the default market | `..._is_a_pool_of_its_own`: "the pool of a Kuwaiti product is Kuwait's". `..._comes_back_as_that_markets_pool`. `..._takes_units_of_the_default_markets_pool_only` |
| `PoolMarket::ofProduct()` stops normalising the DEFAULT market to `''` | `..._keeps_the_pool_it_had`: "product 52 — expected `''`, actual `'SA'`". `..._is_the_default_markets_pool`: two pool rows where there must be one |
| the migration's column default `'SA'` instead of `''` | `..._is_the_default_markets_pool`: "no second pool is opened beside the one that was already there — 1 is not identical to 2" |
| the migration leaves the OLD unique key in place | `..._can_hold_two_markets_pools_of_one_shelf`: "two markets, two pool rows: the key without a market could hold only one — 1 is not identical to 2" |
| the migration's `down()` guard removed | `..._refuses_while_a_pool_names_a_market`: "a pool names a market, so the rollback must refuse rather than forget which" |
| `StorefrontMarketRows::applies()` returns false (the ONE market rule blinded) | six tests, including the criterion itself: "the Saudi account may be shown its own 40 and no more — **25** is not identical to 40". That 25/25 split of a 40/10 pair is the defect in one line |
| `ChannelAccountMarketStop::reasonFor()` returns null | `..._each_account_is_sent_its_own_markets_figure`: "Kuwait is not open for sale — null is not identical to 'market_stopped'" |

### The account's declared country

| break | tests that failed, and how |
|---|---|
| `ChannelSettings::marketMayBeDeclared()` drops the switch | `..._an_account_declares_no_country`: "amazon — true is not false" |
| the check against the configured storefronts removed | `..._has_no_storefront_for_is_refused`: `''` does not contain "The storefront country must be one of: SA, AE, KW, QA, BH, OM, or empty." |
| `marketplaceCountriesOf()` returns `[]` | `..._a_marketplace_of_another_country_is_refused`, `..._the_turkish_marketplace_declares_no_gulf_storefront`, `..._can_declare_neither` — each naming the refusal it expected |
| an unknown marketplace counted as a contradiction | `..._does_not_know_is_not_a_contradiction`: "a marketplace whose country we do not know refuses nothing — actual: *This account's marketplace A1UNLISTED99 sells in , so it cannot declare the AE storefront.*" |
| `ChannelAccountCountry::countryOf()` ignores the declaration | `..._derives_no_country_declares_one`: "and is the country the account sells in — null is not identical to 'KW'". `..._lets_an_account_join_that_markets_pool`. `..._where_a_row_holds_both`: "expected 'KW', actual 'SA'" |
| the DERIVED answer removed from `countryOf()` | `..._an_account_declares_no_country` and `..._is_still_derived`: "the Saudi marketplace still answers SA — null is not identical to 'SA'" |

### One finding from the teeth checks, recorded rather than hidden

**Three tests are insensitive to any SINGLE guard being broken.**
`..._never_exceed_each_stock`, `..._each_account_is_sent_its_own_markets_figure` and
`..._holds_only_that_markets_accounts` stayed GREEN when `ChannelMarketMatch::productRefusal()` was blinded, and green again when
`PoolMarket::ofProduct()` was blinded. The scenario is blocked independently by the product's market, by the shelf's market and by
the pool's market, so removing one leaves two. They fail only when the shared rule underneath all three
(`StorefrontMarketRows::applies()`) goes blind.

They are not INERT — they fail for a real fault, and the 25-of-40 message is the clearest statement of the defect in the whole
suite — but they measure the CRITERION (defence in depth) and not one mechanism. The tests that bite on the pool's market alone are
the three in the table above that failed to `ofProduct()`: the key's identity, the dirty-mark round trip, and the nameless account.
A reviewer who wants a single-guard test of the pool market should read those, not these.

---

## What is not closed, and why

1. **The own-warehouse authority is still platform-wide** (M-745's third clause). `sales_channels.inventory.own_warehouse_source` is
   one env value; `config/` is outside this track's paths and the authority itself is an open owner decision (A1-18). Named, not
   guessed.
2. **`StockAttention` builds a market-less key.**
   `app/Http/Controllers/Admin/SalesChannels/Support/StockAttention.php` makes a `PoolKey` from a pool ROW without its market, so with
   the switch ON the "shown on channels is more than the net" attention row and the holding-orders list miss a non-default market's
   pools. Cosmetic, switch-on only, and outside this track's paths. Two lines: read `$pool->storefront_country` into the key, exactly
   as `DirtyPools::keyOf()` does.
3. **The admin account form has no `storefront_country` row.** `AccountForm` (outside these paths) validates `settings.*` field by
   field, so the declaration cannot be typed on the screen yet. One validation rule and one field.
4. **`channel_stock_events` and `inventory_reservations` carry no market column.** The product each row names answers for it, and
   `PoolKey::columns()` deliberately stays the three columns those tables share — putting the market there would have sent an unknown
   column to both. Narrowing either by market in SQL therefore needs a join on `products`.
5. **With the division switched off the pool's market narrows nothing.**
   `sales_channels.stock.allocation.enabled` is the documented rollback switch; off, `StockPlanner::wholeNet()` offers every listing
   the pool's whole net and never asks the members, which is where the market question is answered. An account that NAMES another
   market is still refused there (the gate runs before the switch is read); an account that names NONE is not. Pinned as it is by
   `test_with_the_division_switched_off_the_pools_market_narrows_nothing_currently` rather than changed, because making the rollback
   path narrow would make it a different path from the one it rolls back to.
6. **No CONCURRENT two-country test.** M2-08's own remaining criterion ("one SKU in two countries bought and synced at once") is a
   `tests/Feature/Concurrency` class that starts several PHP processes; this track measured the division and the wire in one process.
   The pool rows are now distinct per market, which is the schema that such a test needs, so it is unblocked rather than done.

## A defect found on the way, outside this track's paths

`AdminSalesChannelsAccountsTest::test_the_create_page_offers_both_channels_first` FAILS on a pristine checkout of this branch, and of
the branch point `30a2283e`, with "rendering added an untranslated key to resources/lang/en/new-messages.php".

`resources/views/admin-views/sales-channels/account-create.blade.php` lines 19-20 build their keys from the channel:
`translate('connect_' . $choice->value)` and `translate('connect_' . $choice->value . '_help')`. When noon joined the channel list
(`7188d210`, another track) nobody added `connect_noon` and `connect_noon_help` to `resources/lang/en/new-messages.php`, so rendering
the page WRITES them — which is exactly what that suite's guard refuses.

It is invisible as long as a previous test run has left the lang file dirty with those two keys, which is how it was missed. This
track hit it because it reverted that dirty file rather than committing it. Not fixed here: `resources/lang/**` and
`resources/views/**` are outside this track's paths, and fixing it would also hide it from whoever owns the noon form. The fix is two
lines in the English lang file (and their Arabic counterparts), or a static key per channel in the blade — `.claude/rules/translate.md`
would prefer the latter.

While running the suites, `translate()` wrote into `resources/lang/en/new-messages.php` twice. Both times the file was reverted, and
`git status` was checked before each commit: no commit of this track carries it.

## Merge notes

- One new migration, `2027_01_07_100000_add_market_to_inventory_pools.php`. It replaces the unique key
  `ipool_location_product_variant_unique` with `ipool_market_location_product_variant_unique` (market first), adds the new one BEFORE
  dropping the old one, and refuses `down()` while any pool names a market — rolling back would both forget which market a pool
  belongs to and collide two markets' pools on the old key.
- The column is `char(2) NOT NULL DEFAULT ''`, not nullable like every other market column on the platform, and the reason is
  load-bearing: it is part of a UNIQUE key, and on MySQL and MariaDB two rows whose key contains NULL do not collide. A nullable
  market would let a second pool row exist for one shelf and product, and the `FOR UPDATE` on that row is the entire single-writer
  guarantee. `''` collides with `''`.
- No file outside `app/Services/SalesChannels/**`, `tests/Feature/SalesChannels/**` and the new migration was changed. `config/` was
  not touched, so there is no collision with the other tracks' `config/gcc.php` edits.
