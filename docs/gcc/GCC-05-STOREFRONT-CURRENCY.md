# GCC-05: the currency of a storefront

Status: slices 1 to 4 of 5, in draft pull requests, behind one switch that is off by default (`GCC_STOREFRONT_CURRENCY`). Nothing here has been run on the pilot server or on production.

## The rule

A storefront other than the default one trades in its own currency (`gcc.storefronts.countries.XX.currency`). The prices of its catalog are entered and shown in that currency, and nothing is converted from another storefront's amounts. This is the owner's "local pricing, no automatic conversion" decision (`DECISIONS.md`) applied to money display. Before this slice the platform had one currency model for everything: prices were stored in a base currency (USD) and converted for display with the visitor's currency, so a Kuwaiti product row showed the Saudi default currency's symbol and rate (`docs/gcc/audits/2026-11-16-price-stock-map/`, finding 1).

## What slice 1 does

While `GCC_STOREFRONT_CURRENCY` and `GCC_STOREFRONTS_ENABLED` are both on, and a request is served from a storefront other than the default one, `getWebConfig()` answers three business settings for that storefront alone (`App\Services\Storefront\StorefrontCurrency`):

| Setting | Platform value (unchanged for the default storefront) | Value for the other storefronts |
|---|---|---|
| `system_default_currency` | the platform's default currency | the id of the storefront's currency in the `currencies` table |
| `currency_model` | `single_currency` or `multi_currency` | `single_currency`: an amount is shown as it is stored, with no rate |
| `decimal_point_settings` | the platform's decimals | the decimals of the currency: three for KWD, BHD and OMR, two for the rest (`gcc.storefronts.currency.decimals`) |

Every converter, symbol and formatter that already asks `getWebConfig()` follows (`webCurrencyConverter`, `usdToDefaultCurrency`, `currencyConverter`, `setCurrencySymbol`, `loadCurrency`, the payment, order-edit and fulfillment readers), without a change of its own. The values are computed per call and never written to the settings table or to the settings cache, so the platform's own values stay what they were for every other request.

What does not change: the default storefront (Saudi Arabia unless the admin changes it), every request while either switch is off, and every process with no storefront bound (queue workers, console commands), which keep reading the platform settings.

### A storefront may sell only while its currency exists

A storefront whose currency is missing from the `currencies` table, or switched off there, keeps the platform settings (it never shows another storefront's rate) and **may not sell**: `StorefrontSellingGate` refuses a new order or payment with the same answer as for missing legal texts, and `GET /api/v1/storefronts` lists it with `sellable: false`. The default storefront needs no currency of its own.

### Readiness check

```bash
php artisan gcc:storefront:currency-readiness
```

Read-only. Lists every configured storefront, its currency, decimals, whether it is switched on, and whether its currency is ready, missing or inactive. Exits 1 when a storefront that is switched on lacks its currency.

## What slice 2 does: the order records what it was sold in

An order has no currency of its own: invoices, e-mails and exports recompute its totals with whatever the platform settings are when they are read, so a Kuwaiti order cannot be shown later in the currency it was sold in. Slice 2 adds the record (`App\Services\Storefront\OrderStorefrontSnapshot`, migration `2026_11_26_100000_add_storefront_snapshot_to_orders_table.php`).

| Column of `orders` | Meaning |
|---|---|
| `storefront_country` | the one country the products of the order's lines belong to (null for an order whose lines belong to two) |
| `currency_code` | the currency the amounts of the order are in |
| `currency_rate` | the rate that turns a stored amount into that currency: 1 for a storefront with its own currency, because its amounts are stored as they are |

It is written right after the order's lines are saved, from those lines and not from the request, so the web and API checkout (`OrderManager::generateOrder`, which both POS screens use too) and the seller app's POS record the same thing. A record is written once and never rewritten. The default storefront on a multi-currency platform records its storefront only: the currency an order is shown in there is the visitor's choice, which an order does not know yet. An order saved before the migration, or while the switch is off, carries no record and is read as before (default storefront, platform settings). The migration is additive (three nullable columns appended without a position), and its rollback is refused while any order carries a record.

## What slice 3 does: a payment is refused before it starts when its gateway cannot take the storefront's currency

The amount of a storefront with its own currency is in that currency, so a gateway has to take that very currency: it is never converted to dollars, and never goes to a gateway whose list of currencies (`App\Traits\PaymentGatewayTrait`) does not have it. A currency with three decimals (KWD, BHD, OMR) goes only to a gateway somebody has verified for it, because most gateways multiply the amount by one hundred and would ask for a tenth of the price (`gcc.storefronts.currency.verified_three_decimal_gateways`, empty at first: a payment in such a currency is refused). `App\Services\Storefront\GatewayCurrencyPolicy` asks in `Payment::generate_link()`, which every payment starts in (the checkout, the wallet top-up and the payment of an edited order's due), so a refused payment leaves no `payment_requests` row. The answer is 422 with the code `storefront_payment_not_supported` for an app, and a message for a browser (a new text in `en` and `sa`, to be read by a native Arabic reader). Cash on delivery, the wallet, offline payments, callbacks, refunds and the payments that were already started are not affected, and nothing is refused while the switch is off, for the default storefront, or outside a storefront request.

## What slice 4 does: the admin product screens work in the money of the product's country

`StorefrontCurrency::within($country, $callback)` puts one market's money in force for the duration of a callback, whatever the request is narrowed to (the panels see every country, so nothing narrowed them before). The middleware `money.market` (`ScopeMoneyToMarket`) is on the admin product routes of the catalog group: the market is the country the form names (`storefront_country`, a new product) or else the stored country of the product the route names (`{id}`: view, edit, update, delete, barcode). Inside, the conversions of the product screens (`currencyConverter`, `usdToDefaultCurrency`, the symbol and the decimals) are those of that country's currency, so an amount typed for Kuwait is saved as typed (3.505 dinars, not 3.505 divided by the rate of the riyal) and read back as dinars with three decimals. The admin product list shows each row's price in its own country's currency, so a list that mixes countries never prints a dinar amount with the riyal's symbol. The five picker and search routes that span every country are outside the group and name no one market. Nothing changes for a product of the default storefront, for an unknown country, or while the switch is off; the market in force before is restored even when a save throws.

## What is not done yet (slice 5, and what slice 4 leaves)

Do not turn the switch on with a non-Saudi catalog before the bulk tools below know a market.

Left by slice 4: the bulk import (no country on the import form: it always lands in the default storefront), the bulk price operations and the other product lists (stock limit, restock requests, updated products, the gallery offcanvas), and every other screen that types or shows an amount of one country: coupons, shipping costs, the minimum order and free-delivery thresholds, promotions (GCC-17), and the vendor and seller tools (all Saudi until sellers have markets).

5. **Wallets, loyalty, fixed decimals, documents and the apps.** Wallet and loyalty balances have no currency (GCC-15) and two wallet paths feed a base-unit amount into a function that treats it as default-currency (an existing bug of the wallet stack, independent of the switch); a few formatters round to two decimals whatever the currency (`BackEndHelper::usd_to_currency`, `getFormatCurrency`); the MyFatoorah money class is two-decimal by definition; invoices, order e-mails, exports and reports read the order's own record; queue jobs carry the storefront they work for; `GET /api/v1/config` answers for the storefront an app asks for; the customer app applies the currency and decimals of the storefront.

## Rollback

Switch `GCC_STOREFRONT_CURRENCY` off (and clear the config cache). No data was written by this slice, so nothing has to be repaired: every storefront reads the platform settings again at once.

## Tests

`tests/Feature/Storefront/StorefrontCurrencyTest.php` (slice 1): the default storefront and the switched-off cases read the platform settings; each of the five other storefronts answers its own id, model and decimals; amounts are shown as stored in each symbol with the right decimals and no rate; a session that moves between storefronts keeps no symbol of the previous one; the platform settings and their cache are not touched; a missing or switched-off currency refuses the sale and shows `sellable: false`; the readiness command.

`tests/Feature/Storefront/OrderStorefrontSnapshotTest.php` and `StorefrontCheckoutSnapshotTest.php` (slice 2): each of the five other storefronts records its country and currency at rate one; the default storefront records its currency only on a single-currency platform; nothing is recorded while the switch or the storefronts are off, before the migration, for an order of two countries or without lines; a record is written once; a request served from another storefront never records a currency it cannot vouch for; the migration is idempotent and its rollback is refused while a record exists; a real cash-on-delivery checkout of the API from each of the six storefronts (SA, AE, KW, QA, BH, OM, each with its own product priced in its own currency) saves one order with the record and the price of its own catalog, converted by no rate, and none while the switch is off.

`tests/Feature/Storefront/GatewayCurrencyPolicyTest.php` (slice 3): a gateway takes a storefront's currency only when it is that currency, in the gateway's list and with two decimals; a three-decimal currency only after the gateway is verified (and the verification is per gateway, with the second name of MyFatoorah judged like the first); nothing is refused while the switches are off, for the default storefront or outside a storefront request; a refused payment through `generate_link()` leaves no row and an accepted one does; the 422 and the browser answers.

`tests/Feature/Storefront/AdminProductMoneyTest.php` (slice 4): an amount typed inside the market of Kuwait is stored as typed and one typed for the default storefront is converted as before; the market in force before is restored (nested, and when a save throws); the default market, an unknown country, no country, and both switches change nothing; a market without its currency keeps the platform settings; a route that names a Kuwaiti product or a form that names Kuwait runs in dinars, the next request never inherits it; every route of the admin catalog group carries the middleware; what the admin add form saves for Kuwait and for the default storefront; the admin list prints each product's price in its own currency.
