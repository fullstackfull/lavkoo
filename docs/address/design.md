# Unified Saudi address — design

**One central address system, extended — not a second one.** The store already had an address book (`shipping_addresses`), an immutable per-order copy
(`orders.shipping_address_data` / `billing_address_data`), a checkout, an account book, panel editors, a REST API and a Torod Point integration. This feature adds structure to
*those*, plus the one thing that was missing: a list of regions and cities with stable ids. Couriers, warehouses and marketplaces are mapped to that list; none of them owns a city list of its own.

## What was found before changing anything

* No region or city list existed anywhere: `shipping_addresses.city` was whatever the customer typed; every integration guessed from that text.
* 14 writers of an address and 6 writers of the order snapshot; invoices and order pages read the snapshot, some pages joined the live address instead.
* The ZIP is a free-text legacy column read by three restriction settings (default off) and by invoices/order pages only.
* Torod's resolver matched the typed text against a city list cached from one API call that returned 10 cities, and refused (hold) anything ambiguous — which is why order `100002` is held
  (`hold_city_unmapped`) and why Torod's two "Riyadh" rows (36189 / region 1204 and 230194 / region 1265) matched nothing.

## Tables (all additive)

| Table | Role |
|---|---|
| `address_regions` | the 13 regions (ids, names ar/en, ISO code, active, source + date) |
| `address_cities` | places (id, region, names ar/en, centre, rank, active, source + source id + date). **Names are never unique keys** |
| `address_city_aliases` | an administrator-confirmed spelling → exactly one city |
| `shipping_addresses` (+4 nullable columns) | `region_id`, `city_id`, `district`, `national_address`; `city`, `country`, `zip`, `address` stay and are still written for old readers; `state` is never written by the structured address (the shipping zones read it, and what a customer pays must not depend on which form created the address) |
| `provider_city_mappings` | provider-agnostic link *internal city → provider city id + region id*, with status, method, evidence, approver, date; **the database allows one approved mapping per provider and city** |
| `provider_city_sync_runs` | every refresh of a provider's city list and whether it was complete |
| `provider_cities` (+6 nullable columns) | what the provider says about each city (region names, prefix, selected flag, first/last seen) |

No foreign keys to store tables, nothing renamed or dropped, no backfill in a migration. Rolling back drops only what this feature added.

## Services

`ShippingAddressService` (single write path) → `AddressCatalog` (regions/cities) · `NationalAddress` · `AddressSnapshot` (order copy and its readers) · `CheckoutAddressGuard` · `LegacyCityMatcher` ·
`AddressSchema` (dormant-until-migrated, mode). Providers consume the central data through `ProviderCityMappingService`.

## Mode (the switch an administrator controls)

`off` (default) — the old forms, validation and data model everywhere, plus the mode-independent fixes listed in the README · `soft` — the website and the panels must use the structured address for Saudi addresses, the mobile app and older API clients may still send the old fields
(those orders are accepted; at fulfilment their typed city is used only when it is certain, otherwise a person looks at the order first) · `strict` — every client. The mode in force is `off` when the migrations have not run, when no city exists (an empty list can
never lock customers out) and while the ZIP allow-list restriction is on (the chosen mode is kept and comes back when the restriction is turned off).

## The flows

* **Checkout / account / panels / API** — region (searchable) → city (filtered by region, cleared when the region changes, Arabic or English search, only ids from the store's own list) →
  district → National Address; the legacy city and ZIP rows appear only for other countries. Server-side: region exists and is active, city belongs to that region and is active, National Address shape, Saudi
  mobile, lengths, no provider ids accepted from clients, every address id scoped to its owner. In the list a place whose name, or whose curated spelling, is exactly what was typed comes first (the larger place before a village of the same
  name), then the names that start with it, then the rest.
* **Country** — a country is read as Saudi Arabia in any of its common spellings (the code, the English or Arabic name, with or without dots, hyphens or spaces: `SA`, `K.S.A.`, `Saudi-Arabia`, `Saudia`, `السعودية`). Any other text is another country and keeps the
  old rules, so the server cannot tell a mistyped Saudi Arabia from a country it does not know; the pages offer a closed list, so only another client can send one, and such an order reaches fulfilment with a typed city that is used only when it is certain.
* **Order** — the snapshot is built once from the address at that moment (all old keys, `zip` included, plus ids, National Address and the *names* of region and city then). Editing or renaming
  afterwards never changes a past order.
* **Old addresses** — kept as they are. A saved Saudi address without a city id is matched **only** when an administrator alias or exactly one registered city agrees (a small place that merely shares the typed name, as a district typed in the city box does, waits for a person); ambiguity, a region that points elsewhere, or no
  candidate are never guessed. Reusing such an address at checkout requires completing it first (the app is exempt in `soft` mode).
* **Shipping** — the provider adapter receives the order snapshot, finds the approved mapping for that internal city and sends the provider's own ids. No mapping, a pending one or several candidates ⇒ the
  fulfilment is **held** with a reviewable reason; nothing is sent, nothing is guessed, no "nearest city". Preflight and submit read only the local database.

## Security

Server-side validation of country, region, city/region consistency, active flags, National Address, phone, lengths and Unicode; plain-integer ids; provider ids from clients ignored; owner-scoped lookups
(fixing the existing IDOR fall-throughs in the checkout handlers); no addresses, phone numbers or National Addresses in logs or hold messages (city name only, with anything that looks like a phone number or e-mail address masked); staging scrubbing and log redaction cover the new keys.

**What "owner" means for a guest.** A registered customer is proved by the sign-in. The website's guest is proved by the server-side session (the guest id never leaves it). A guest of the **mobile API** is only the `guest_id` the app sends:
`GET /api/v1/get-guest-id` hands out a sequential number and no secret, so the platform cannot tell the guest from anyone who guesses the number. Address lists, edits, deletes and a signed-in customer's "carry my guest address over" all trust that
number. That predates this work and cannot be closed on the server alone: it needs a per-guest secret issued by `get-guest-id`, sent by the app and required on every guest route (decision 9 in the README).

## Future integrations

M5AZN/Amazon/Trendyol/Odoo reuse `provider_city_mappings` (a new `provider` value) and the snapshot; nothing in inventory, orders or shipping pricing changes for them. **Only the shared structure and the integration points exist; none of the integrations below is built.**

| Platform | What the shared structure already gives it | What it still needs (not built) |
|---|---|---|
| M5AZN | the order's copy (street plus district, the store's own Arabic city name) is what its payload builder reads | nothing for the address; its stock and order work are separate |
| Amazon (FBA, FBM) | the immutable order copy, internal city ids, `provider_city_mappings` rows under another `provider` value | an importer that keeps Amazon's own ids and address as received; FBA orders are never auto-sent, FBM orders are converted for the courier the store chooses |
| Trendyol | the same | an importer that keeps Trendyol's original order id and address next to our copy |
| Odoo | the structured fields (region, city, district, National Address) are plain columns of the copy | a converter from the copy to Odoo contacts; nothing in it needs a free-text city for a Saudi address |

What a new provider implements to plug into the one address system (nothing here is Torod-specific by design, though only Torod is wired today):

1. **A directory reader**: fetch the provider's cities and write them to `provider_cities` (`provider`, `external_id`, names, `region_external_id`, `raw`, `missing_since`), recording each reading in `provider_city_sync_runs` with its completeness evidence (`TorodCityDirectory` is the model; the weekly schedule and the Integration Center button call it through `ProviderSyncService`).
2. **Mappings through `ProviderCityMappingService`**: candidates by exact name (`candidatesFor`), proposals, a person's approval (`approve`), and the automatic rule only after a proven-complete reading. Never a second city list, never an id of another provider.
3. **A resolver in its order payload builder** that reads the order's copy (`AddressSnapshot`): the `city_id` it carries, or the certain match of its typed text, then `approvedFor(provider, cityId)`; anything else holds the shipment (see `TorodCityResolver`).
4. **A review screen** over the same tables (`ProviderCityReview` and the matching screen are parametrised by provider code in their queries but wired to Torod's routes and views today).

Only steps 1 and 4 are provider-specific code; the tables, the approval rules, the audit trail and the order copy are shared.
