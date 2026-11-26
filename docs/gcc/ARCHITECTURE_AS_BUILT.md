# Six markets: what was proposed, what was built, and why the difference

Discovery-01 report 03 section 4 proposed ten minimal additions, five of them new tables: `countries`, `storefronts`,
`legal_entities`, `customer_balance_accounts` and `staff_scopes`. Everything in it was marked PROPOSED, deliberately: it
was a concept and not a design to impose.

What was built is not that list, and the difference has never been written down in one place, so a reader of report 03
would reasonably conclude that five tables are still owed. **Three of the five exist under other names, and two were
deliberately not built at all.** This file reconciles the two, states the alternative that was chosen at each point and
the reason, and lists what is still not built.

`tests/Unit/Architecture/ArchitectureAsBuiltTest` reads this file: every table it says is built must be in
`docs/gcc/table-ownership.json`, every column must be added by a migration in the tree, every `gcc.*` configuration key
must be in `config/gcc.php`, and every table it says is NOT built must really be absent. A reconciliation that drifts
from the schema is worse than none, because it is the document a reader would trust.

---

## 1. The five proposed tables

| proposed | state | what exists instead | why |
|---|---|---|---|
| `countries` | **not built, by choice** | `gcc.storefronts.countries` in `config/gcc.php`: the six markets with their path, currency, display name and `active` flag | A market is not a row an operator edits; it is a deploy-time fact, and the set of six is fixed by the business. A table would make "which markets exist" a question with two answers -- the table's and the configuration's -- and the one that decides whether a storefront resolves is read at boot on every request. A1-03 records that the default storefront is a recorded DECISION read at boot rather than a deploy. |
| `storefronts` | **not built, by choice** | the ISO country code, used as the storefront key everywhere | A1-09 records it in as many words: a storefront has no identity of its own yet. One market is one country, so a second key would be a synonym for the first, and every column, cache key and index would carry the longer of the two. If a market ever needs two storefronts, this is the decision that has to be reopened -- and `table-ownership.json` keeps `storefront` and `country` as separate SCOPES so that the reopening is a migration and not a re-reading of every table. |
| `legal_entities` | **built** | `legal_entities`, one row per country (unique on `country`), with `orders.legal_entity_id` and `orders.entity_snapshot`, `products.legal_entity_id` and `vendor_ledger_entries.legal_entity_id` | A selling company is not derivable from a country once a market has more than one, and an order must keep the company that sold it even if the registry changes afterwards -- hence the snapshot beside the key. This is the one proposed table whose argument survived contact with the work. |
| `customer_balance_accounts` | **built, split in two** | `customer_market_wallet_accounts` and `customer_market_loyalty_accounts`, both keyed `customer_id` with the market | One table for two ledgers would have made every read say which kind it meant, and the two have different authorities: the wallet is money and the points are a programme the owner has not adopted per market (E-02). Both ship dormant: `users.wallet_balance` and `users.loyalty_point` remain the authority until the owner adopts the accounts. |
| `staff_scopes` | **built, split by whose staff** | `admin_country_scopes` for the platform's employees and `seller_market_scopes` for a seller's | An employee of the platform is restricted to markets; a seller's staff member is restricted within one seller's markets. One table would have carried a nullable `seller_id` whose null meant "platform", which is the shape that makes every query ask a question it should not have to. |

## 2. The columns and the registry that carry the markets

What a `country_id` on every table would have done, done instead by a column on the few tables that own a market and by a
derivation for the rest. DECISIONS guardrail 3 forbids the blanket column; `table-ownership.json` is what makes the
derivation checkable: 155 tables, each with one of eleven scopes, and `derived` (38 tables) names the parent the market is
read through.

| what | where | note |
|---|---|---|
| the market of a product | `products.storefront_country` | `char(2)`, default `SA`, `ascii_bin` on a real engine |
| the market of an order and the money it was taken in | `orders.storefront_country`, `orders.currency_code`, `orders.currency_rate` | nullable: an order from before this says nothing rather than guessing Saudi Arabia |
| the market of a coupon, a theme, a redirect, a shipping method, a category shipping cost | `coupons.storefront_country`, `themes.storefront_country`, `redirects.storefront_country`, `shipping_methods.storefront_country`, `category_shipping_costs.storefront_country` | each nullable or defaulted, each added by its own expand-only migration |
| which markets an employee may act in | `admin_country_scopes.country` | with a grant trail (`granted_by`, `granted_at`) |
| which markets a seller sells in | `seller_market_scopes.market` | |
| the company a payment account belongs to | `payment_gateway_accounts.storefront_country` with `legal_entity_id` | |
| which markets an entity is visible in | `storefront_entity_visibility.storefront_country` | |
| the market a carrier account serves | `provider_cities.country`, and `integration_settings.country` | `''` is the platform-wide row, never NULL |
| the six markets themselves | `gcc.storefronts.countries`, `gcc.storefronts.default_country` | SA active, the other five `active => false` |

## 3. The alternatives, and the reason for each choice

**A country column on every table, or a derived scope?** Derived, for all but the tables that own a market. The blanket
column is forbidden by DECISIONS guardrail 3 and would have been 150-odd migrations against live tables; the derivation
is free at write time and costs a join at read time. What makes it safe is that the derivation is WRITTEN DOWN and
checked: `TableOwnershipTest` fails when a migration creates a table the register does not classify, when the register
names a table no migration creates, and when a NEW table is filed in the frozen `inherited` bucket.

**NULL or the empty string for "the platform-wide row"?** The empty string, every time, and this is the subtlest choice
here. NULL is the natural spelling, but MySQL and MariaDB count two NULLs as DIFFERENT in a unique key, so
`(channel, NULL)` could be stored twice and the key that stops a channel having two platform-wide accounts would quietly
stop biting. `integration_settings.country`, `address_city_aliases.country_code` and `notification_channel_profiles.country`
all use `''`.

**Case-sensitive or case-insensitive market codes?** Byte for byte: `char(2)` with `ascii` and `ascii_bin` on MySQL and
MariaDB, so a stored `kw` is never read as equal to `KW`. A hand-built `char(2)` takes the engine's own
case-insensitive collation, which is how a `kw` that matched `KW` on MariaDB and not on SQLite reached CI once.

**Expand or rewrite?** Expand, with one recorded exception: the decimal columns of provider money were widened in place
from scale 2 to scale 3, because a fils amount is rounded away on the way IN and a new column beside them would not be
written by the existing writers. Widening a decimal is lossless and the migration re-runs.

**A switch per capability, or one GCC switch?** One per capability, all shipping off, and the first test of each asserts
that with it off nothing changed. The cost is about forty switches to keep track of; the benefit is that turning on
per-market themes does not also turn on per-market wallets.

**A storefronts table now, or the country code?** The country code (see section 1), and this is the alternative most
likely to be reopened. The reopening is bounded: the register keeps `storefront` and `country` as separate scopes, and the
six tables scoped `storefront` are the ones a second storefront per country would touch.

## 4. What is still not built

- **The two tables above**, by choice, with the reasons given.
- **Entity-bound wallets.** The per-market accounts exist and are dormant; `users.wallet_balance` is still the authority,
  and the drift between it and the ledger is pinned by a test rather than fixed (M2-17).
- **A market dimension in the analytics rollup and the monitoring module.** Not switched off: absent. A figure read from
  either is a platform total. The analytics rollup is the one that misleads, because `analytics_daily` *does* carry a
  `country` column and it is the **visitor's geography** (`VisitorContext::country`, read off the request), not the
  storefront the visit was served from: a shopper in Kuwait browsing the Saudi storefront is counted under KW. So a
  figure an operator reads while arranging one market is that market's by appearance only, and
  `commerce:metrics-refresh` carries the same figures into the ranking of dynamic collections. The monitoring module's
  series are measurements of one server, which serves every market, so they have nothing per market to lose; the
  per-market alerts an operator wants (`stock_zero`, `orders_pending_too_long`, `legal_not_ready`) are a separate chain
  that does exist per market in the platform-admin overview. Both are declared and counted as gaps in
  `tests/Feature/Queue/EveryScheduledTaskDeclaresItsMarketTest.php`.
- **The search index is NOT in that list, and the line that used to put it there was wrong.** The index table carries no
  market column — its rows are one per product per locale — and it needs none, because a **search** is narrowed to the
  storefront at read time: `ProductSearchService::baseQuery` passes the query through
  `StorefrontCatalog::restrictProductIds` (against `products.storefront_country`) *before* the 500-id cap, so the oldest
  and largest catalogue cannot fill the pool and leave a later storefront a fraction of its own matches.
  `StorefrontSearchIndexTest` pins it (4 tests), and removing that one call makes a Kuwaiti search for `vitamin` return
  the two Saudi products instead of the two Kuwaiti ones. `unmatchedTerms` is narrowed twice over, by the same call and
  by `Product::active()`'s own scope. What a search result is *not* is a platform total.
- **A market of its own on a queued job — built since this line was written.** Of the 27 files naming `ShouldQueue`, 26
  classes implement it; 22 carry or honour a market and 4 are declared `no market of its own` with reasons, enforced in
  both directions by `tests/Feature/Queue/EveryQueuedClassDeclaresItsMarketTest.php` (a class claiming a market is built
  and asked for its queue middleware; a class claiming none is refused one). A job records the dispatching market in its
  constructor and it rides in the serialised payload; a queued listener records it in the middleware the dispatcher asks
  for, because Laravel builds a listener without its constructor. The 45 **scheduled** entries are classified too (42 on
  a default install, 45 with three switched-off features' entries), and four of them have no market — see the gaps
  above and `seller:run-stuck-bulk-jobs`.
- **Country paths.** `gcc.storefronts.paths.enabled` ships off and no route group is mounted under a country prefix, so
  no old link has moved.

## 5. Where the data lives, and which of it can be told apart by market

Report 02 maps the system as it is; this is the storage leg of that map, as built. Three kinds of storage carry this
platform's data, and only one of them can be narrowed by market today.

**The database is the source, and it is the only one of the three a market scope reaches.** 155 tables, each with one of
eleven scopes in `table-ownership.json` (section 2), so the question "which market does this row belong to" has a written
answer for every table -- either a column of its own or the parent it is derived through. The columns are listed in
section 2; what matters here is that nothing else on this list has that property.

**Uploaded files live on four disks, and a file's market is knowable only through the row that owns it.** The disks are
declared in `config/filesystems.php`: `local` (`storage/app`), `public` (`storage/app/public`, served under
`/storage`), `digital_private` (`storage/app/private/digital`, visibility `private`, the disk
`DigitalProductFileService::PRIVATE_DISK` names) and `s3`. Which disk a particular file went to is recorded per file in
`storages`, a polymorphic table `App\Traits\StorageTrait` morphs to the row that owns the file -- so the same product
image can be on the public disk on one installation and on S3 on another, and the row says which.

What follows from that, and it is the finding of this leg: **a stored file has no market, and its PATH cannot be given
one.** A product image belongs to a product, which has a market; the file is a flat name on a shared disk. Every
narrowing of a file therefore has to go through the owning row, which is why the staff export survey (G-10) ends with
exactly one entry it cannot classify by country -- the file manager, which serves a file of the storage by its NAME,
with no row behind it at all. The digital files of a sale are the counter-example and the shape to copy: they are on a
private disk, served only by a route that names the order line (`line.route:id`), so the scope of the orders decides who
may download one. The upload surface itself -- 146 suspects over seven areas, and what each one stores -- is mapped in
`docs/gcc/audits/2026-11-16-upload-surface-audit.md` rather than repeated here.

**Client-local storage is not in this repository, and the backend's only lever on it is a sync contract.** Both Flutter
apps and every browser keep their own copy of what they were served, and nothing here can reach into them. What is here
is the contract they refresh against, and it is per market:

- `/api/v1/theme/version` answers `revision` (monotonic, one step per publish), `checksum` (the ETag), `schema_version`
  and `engine_version`. A client sends `If-None-Match` with the checksum it holds and is answered 304 with no body, so a
  resume costs a header rather than a home page.
- The answer is per storefront twice over: the server-side cache key is scoped (`StorefrontContext::scopedCacheKey`),
  and the response carries `Vary` naming the UI headers the payload varies by, with `X-Storefront` appended by the
  `VaryByStorefront` middleware (the alias storefront.vary) while `gcc.storefronts.enabled` is on -- `ResolveStorefront::varyByHeader()` adds it
  without replacing what the controller already set. A shared HTTP cache in front of the API therefore keys on the
  market, which is what stops one market's theme reaching another market's app. The probe for the case where a
  controller sets its own `Vary` first is in `ResolveStorefrontMiddlewareTest`.
- A draft theme shares no cache and no validator with the published one: a preview answer says `preview: true` and is
  never cached, so a 304 against a shopper's checksum cannot hand them a draft.

**What this leg does NOT cover, and nobody should read it as covering.** No test uses a shared cache store or a real
queue worker, which is the only arrangement in which a cache leak between markets can be observed at all (THREAT_MODEL
class 7 says the same); the apps' own on-device caches are verified by their repositories' CI and by a device run, and
neither has happened for the market chooser (M2-10); and a file already on a disk cannot be moved behind a market
without a migration of the paths, which nothing proposes.

## 6. What this document does not decide

The legal-entity model is the owner's (Q-10), and whether the country column plus the configuration registry STAYS
instead of `storefronts` and `countries` tables is the owner's too. This file records what was built and why, so that
the owner is deciding about something that exists rather than about a proposal.
