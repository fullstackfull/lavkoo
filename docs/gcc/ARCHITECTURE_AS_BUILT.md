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
- **A market dimension in the analytics rollup, the search index and the monitoring module.** Not switched off: absent.
  A figure read from them is a platform total.
- **A market of its own on a queued job.** None of the 27 `ShouldQueue` classes names one; a queued message is correct
  because the order it reads carries a market.
- **Country paths.** `gcc.storefronts.paths.enabled` ships off and no route group is mounted under a country prefix, so
  no old link has moved.

## 5. What this document does not decide

The legal-entity model is the owner's (Q-10), and whether the country column plus the configuration registry STAYS
instead of `storefronts` and `countries` tables is the owner's too. This file records what was built and why, so that
the owner is deciding about something that exists rather than about a proposal.
