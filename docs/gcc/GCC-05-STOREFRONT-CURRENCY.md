# GCC-05: the currency of a storefront

Status: slice 1 of 4, in a draft pull request, behind a switch that is off by default (`GCC_STOREFRONT_CURRENCY`). Nothing here has been run on the pilot server or on production.

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

## What slice 1 does not do (the next slices)

Do not turn the switch on with a priced non-Saudi catalog before slice 2.

1. **Entering and reading a price in the product's own context (slice 2).** The admin product screens, the seller APIs (v2 and v3), the importers and the bulk price tools convert an entered price with the platform's settings (`ProductService`, `Convert::usd`, `currencyConverter`: 121 call sites). They must run in the context of the product's country so an amount entered for Kuwait is stored as dinars. Until then a price entered under a multi-currency platform is converted from the default currency into the base currency, which is the wrong unit for another storefront.
2. **The order records what it was sold in (slice 3).** An order has no storefront, currency or rate of its own. Invoices, the order e-mails, the exports and the reports re-compute totals in the default currency; queue and console contexts bind no storefront. A migration adds the columns (additive, nullable, guarded like the other GCC columns) and the documents read the order's own snapshot.
3. **Fixed decimals and gateway amounts (slice 4).** A few formatters round to two decimals whatever the currency (`BackEndHelper::usd_to_currency`, `getFormatCurrency`), the payment controllers convert the amount to the gateway currency with the platform's rates, and wallet and loyalty balances have no currency (GCC-15). Each is listed with its call sites in the pull request that fixes it.
4. **The apps.** `GET /api/v1/config` does not resolve a storefront, so an app learns the currency of a storefront from the storefront list; making the config answer for the storefront an app asks for is part of slice 2 with the customer and seller app pull requests.

## Rollback

Switch `GCC_STOREFRONT_CURRENCY` off (and clear the config cache). No data was written by this slice, so nothing has to be repaired: every storefront reads the platform settings again at once.

## Tests

`tests/Feature/Storefront/StorefrontCurrencyTest.php`: the default storefront and the switched-off cases read the platform settings; each of the five other storefronts answers its own id, model and decimals; amounts are shown as stored in each symbol with the right decimals and no rate; a session that moves between storefronts keeps no symbol of the previous one; the platform settings and their cache are not touched; a missing or switched-off currency refuses the sale and shows `sellable: false`; the readiness command.
