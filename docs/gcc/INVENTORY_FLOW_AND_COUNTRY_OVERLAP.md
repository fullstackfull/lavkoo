# Inventory and fulfilment: the flow, and where six countries can overlap

**What this is.** The eight hops a unit of stock passes through, and at each hop the question this project exists to answer: *can one
country's stock be read, taken, held, restocked or shown to another?* For every risk there is either the test that pins it or the owner
decision it waits on — never a reassurance (C-17).

**How it was made.** Re-derived from the code on branch `wt/acgh` at the commit this file was added in, hop by hop, rather than copied from
the E11 discovery report: that report is on `claude/exciting-shannon-4mh2ok`, which is not in this tree, and it predates the row-per-country
design (`products.storefront_country`) that changed the answer at four of the eight hops. Where a finding of E11 is named below, the name is
kept so the two can be read together; the state beside it is this tree's.

## The design in one line

**A product row belongs to one country, and its stock and price are that row's.** That is why roughly 400 stock and price call sites were
left exactly as they were: they are already per country, because the row is. Everything below is about the places that key on something
OTHER than the product row — a SKU, a shelf, a pool, a provider account, a global switch — because those are the only places two countries
can meet.

## The eight hops

| # | Hop | What it keys on | Can two countries overlap? | Pinned by, or waiting on |
|---|---|---|---|---|
| 1 | **Provider sync** (a carrier tells us what it holds) | `provider_product_mappings.product_id` | No — a mapping names one product row. The provider's own catalogue is matched to OUR products through `ProviderStorefrontCountry`, so a Kuwaiti warehouse's SKU is never suggested as a Saudi product | `M5aznImporterCodeScopeTest`; `StorefrontInventoryIsolationTest::test_a_shared_sku_proposes_only_the_products_of_the_channels_own_country` |
| 2 | **Inventory location** (the shelf) | `inventory_locations.id` | No longer: the shelf carries its own market and selling company (`2026_12_27_100000`, read by `InventoryLocationMarket` on `StorefrontMarketRows`' rule). **E11-F19 is closed.** The marketplace's own `warehouses` table still carries no column; its market is DERIVED from the stock on its shelves (`WarehouseMarkets`) | `StaffMarketplaceStockScreensTest::test_a_warehouses_market_is_read_off_what_is_on_it` and its unrestricted twin |
| 3 | **Pool** (what a channel may be offered) | `inventory_pools(account_id, product_id, variant_key)` | No — the pool row names the product, and a share is refused unless the location admits the channel (`StockListingGate`: `no_stock_source`, `location_not_for_this_channel`) | `ChannelPoolMarketTest`; `NoonPoolMembershipTest`; `StorefrontInventoryIsolationTest::test_each_countrys_pool_nets_its_own_figure` |
| 4 | **Reservation** (a channel order holds units) | `inventory_reservations.reservation_key` (unique) + `product_id` | No — the key is unique, so a replayed webhook cannot hold the same units twice, and the row names the product of its own market. **E11-F07's duplicate-hold half is closed by the key** | `ChannelOrderReservationsTest`; `StorefrontInventoryIsolationTest::test_a_reservation_belongs_to_the_product_of_its_own_market` |
| 5 | **Checkout** (a customer takes units) | the cart's rows, narrowed by `StorefrontCatalog` | No — a cart holds one storefront's rows and a checkout in one storefront leaves the other's alone | `StorefrontCartIsolationTest`; `StorefrontCartGuardTest` |
| 6 | **Order** (what was sold, frozen) | `order_details.product_id`; `provider_fulfillment_items` | No — and from `2027_01_02_100000` the ownership snapshot also records the MARKET and the carrier ACCOUNT the line was placed under, so a figure can be attributed afterwards without re-reading today's product | `OwnershipSnapshotSourceTest`; `OwnershipHistory` is the written rule for a row that names neither |
| 7 | **Fulfilment** (a carrier is told to ship) | `provider_fulfillments.provider` + `integration_settings(provider, country)` | No longer: one carrier code can hold one account per country (`2026_12_23_100000`), and the four places that tell a carrier where a parcel goes ask the DELIVERY country rather than the market that sold it (H1-13). **E11-F08's "one provider account" half is closed** | `ThirdProviderTest`; `WhereTheParcelIsGoingTest`; `ShippingScenarioMatrixTest` |
| 8 | **Channel updates** (we tell a marketplace a figure) | `channel_stock_events.product_id`; the account's own market | No — the outbox row names the product, and a product of one market is never listed, drafted or priced on another market's account | `ChannelMarketPushGuardTest`; `StorefrontInventoryIsolationTest::test_an_outbox_row_names_only_the_product_of_its_own_market` |

## The risks that are NOT closed, and what each waits on

| Risk | What it is | Waiting on |
|---|---|---|
| **E11-F15 — global switches** | `SALES_CHANNELS_ENABLED`, `FULFILLMENT_INTEGRATIONS_ENABLED` and the four `ODOO_*` switches are platform-wide: there is no per-market switch for an integration, so switching a family on switches it on for every market | Partly closed: a market that is paused or not open for sale makes NO channel calls (`ChannelAccountMarketStop`, H2-12), and its tills have the same gate written behind an off switch (`PointOfSaleSaleRule`, C-16). What is still platform-wide is turning a FAMILY on, which is an environment decision rather than a market one. The owner's remaining question is what a paused market does to the MATERIALIZER and the webhooks, which receive rather than call out (`ChannelOrderSaleGateTest` pins today's behaviour under a name that says it is not a decision) |
| **E11-F08 — three location concepts** | `warehouses`, `inventory_locations` and a provider code are three notions of "where the goods are", and only two of them carry a market | The owner's answer to whether one own warehouse may hold two markets' stock (A1-18). Until then the market of a `warehouses` row is DERIVED from what is on it, which presupposes neither answer |
| **Oversell across twins** | Two countries' copies of one product are two rows, so the arithmetic cannot cross — but no test takes units of both copies CONCURRENTLY on a real engine | A concurrency test across twins (C-16's own remaining work). The single-row concurrency is pinned: `WarehouseService::place()` locks the catalogue row before measuring the unallocated remainder, and `InventoryService::lockProductsForSale()` locks in ascending id order |
| **An unknown execution source** | `products.fulfillment_provider` is free text; a value the registry cannot resolve used to be packed in-house silently | Closed for a DECLARED carrier (it is snapshotted and routed). For an unresolvable value the owner chooses between refusing the sale and holding the line: `WhatAnUnknownSourceDoes`, default today's behaviour (C-03) |
| **A per-country default source** | No country has an explicit default source or location for its in-house products | C-05's location-ownership decision |

## What this file is not

It is not a reading of the live server, and it is not a promise that the tests named above ran on the engine the pilot runs
(10.11.14 is available in the development container; the pilot runs 11.4.2). Each row names its test so that the claim can be re-run rather
than believed.

Linked from [OWNER_PR_GUIDE.md](OWNER_PR_GUIDE.md). Refresh it when a hop changes — the hop, the key and the test, in that order.
