# Staging plan, the six-country scenarios and the manual device checks

Requirements M1-14 (a staging environment with fake data) and M2-15 (a six-country journey matrix) of the master tracker. **Nothing here has been run.** No staging exists: `docs/integrations/pilot-deployment.md` says there is "no staging server and no second copy", and building one reverses that text, which is the owner's decision. What this file does is fix, before anyone builds it, what the environment must be, what its data is, in what order the switches are turned on, which scenarios prove the six countries, which of them a test already proves, and what a person with a phone has to check.

## 1. What staging must be

| Need | Rule |
|---|---|
| Stack | the engines the released tree runs on: MariaDB 11.4.2 (the pilot server's) and a MySQL 8 rehearsal, PHP 8.2 and 8.3. Read `@@version` first |
| Access | behind authentication; its own `APP_KEY`, its own database, `APP_ENV=staging` |
| Data | **invented only** (charter section 4: no production copy until origin, isolation and sanitization are authorized). Every name, phone, e-mail and address is an obvious fixture (`Example`, a reserved e-mail domain, a phone range nobody owns) |
| Outbound | every channel at a sink: mail, SMS and OTP, push, payment gateways (their sandboxes), couriers, Odoo, sales channels. Proven by `php artisan fulfillment:outbound-audit` exiting 0 on the staging database, not by looking |
| Seeding | a seeder that **refuses to run unless `APP_ENV=staging`**. It is not written: a seeder cannot be validated without the real schema, which this repository does not ship (`installation/backup/database.sql` is not in it), so it is built on the staging host from the world builders of the tests and checked there |
| Rollback | the same rehearsal as production: switch off, clear the config cache, restore the database snapshot taken before the rehearsal |

## 2. The invented data

| Thing | Per country (SA, AE, KW, QA, BH, OM) |
|---|---|
| Storefront | active, with its currency: SAR, AED, KWD, QAR, BHD, OMR (three decimals for KWD, BHD, OMR) in `currencies` |
| Selling company | one `legal_entities` row, an "Example" name and invented numbers (GCC-13) |
| Sellers | one approved, one pending, one rejected; one in-house (the admin's own shop) |
| Customers | five: a normal one, one with a wallet balance and an add-fund bonus, one with loyalty points, one who accepted every legal text and one who did not, plus one guest |
| Products | twelve: physical, digital (with a file), a variant product, one at zero stock, one hidden, one inactive seller's, one priced to the third decimal |
| Coupons | a fixed one, a percent one, an expired one, one with a minimum; later one per storefront (slice 5) |
| Orders | one in each status (pending, confirmed, processing, out for delivery, delivered, returned, failed, canceled), cash on delivery, wallet-paid, gateway-paid in the sandbox, a POS order |
| Refunds | one requested, one approved, one refunded to the wallet |
| Staff | an employee restricted to one country, one to two, one unrestricted; a platform admin with a second factor |

A second dataset, for the reconciliation: a wallet ledger with one customer whose balance differs from the ledger by a thousandth, one by a unit, one with rows of a deleted user, one with a NULL balance, so that `php artisan wallet:reconcile` is seen to say what the plan says.

## 3. The order the switches are turned on

Each step is rehearsed alone, checked, and switched off again to see that the rollback leaves the previous step working. Every flip is followed by `php artisan config:clear` and `php artisan cache:clear`.

| Step | Switch | What to look at | Going back |
|---|---|---|---|
| 0 | none: the released tree with every switch off | the Saudi golden pages and the smoke tests of the release plan | n/a |
| 1 | `GCC_STOREFRONTS_ENABLED` (with `GCC_DEFAULT_COUNTRY=SA`) | the six paths `/sa` to `/om` answer; a country that is not active answers 404; the legal texts of each country; the sale routes refuse a storefront whose texts are not approved | off; Saudi is the only storefront again |
| 2 | `GCC_STOREFRONT_CURRENCY` | `php artisan gcc:storefront:currency-readiness` exits 0; prices of a Kuwaiti product with three decimals and its own symbol; an order records its storefront, currency and rate; a KWD payment by a gateway not verified for three decimals is refused with `storefront_payment_not_supported` | off; stamped orders keep their record |
| 3 | `GCC_LEGAL_ENTITIES` (then `GCC_LEGAL_ENTITIES_REQUIRED`) | `php artisan gcc:legal-entity:list` exits 0; each order carries its country's company and a frozen copy | off; stamped orders keep their record |
| 4 | `GCC_STAFF_COUNTRY_SCOPES` | an employee restricted to one country sees and changes only that country's products, orders and people | off; every employee keeps the access the role gives |
| 5 | `GCC_PLATFORM_ADMIN_ENABLED` (second factor required) | the Super Admin API answers only with a second factor | off; every route answers 404 |
| 6 | `DIGITAL_FILES_PRIVATE` | only after `php artisan digital-files:privatize --execute` and after both apps call the authenticated routes | off |

The wallet reconciliation (`wallet:reconcile`, E-06) is run once, on the restored invented copy, before any step that touches balances; a migration of balances is not rehearsed until it exits 0.

## 4. The six-country journey matrix

A cell is one scenario in one country. "Auto" is a test that exists today (on the heads named in the master tracker; none of them is merged); "manual" is a person with a browser or a phone. The matrix is generated from the configured countries, so a seventh country is a new column.

| Id | Scenario | Auto today | Manual |
|---|---|---|---|
| S1 | The storefront answers at its path with its legal texts; one that is off answers 404 | storefront resolver, legal readiness and kill-switch tests | look at the six home pages |
| S2 | A product of one country is not seen, searched or carted from another | storefront catalog, cart isolation and search-index tests, the six-country catalogue matrices | try the URL of a Kuwaiti product on `/sa` |
| S3 | Price, symbol and decimals are the storefront's own; nothing is converted | `StorefrontCurrencyTest`, `StorefrontCheckoutSnapshotTest` (a cash-on-delivery checkout from each of the six storefronts) | read a price in each |
| S4 | Cash on delivery checkout places one order that records its storefront, currency and company | `StorefrontCheckoutSnapshotTest`, `OrderStorefrontSnapshotTest`, `OrderLegalEntitySnapshotTest` | place one in each country |
| S5 | A gateway that cannot take the currency (or three decimals) is refused before it starts | `GatewayCurrencyPolicyTest` | start a payment in KWD |
| S6 | Wallet payment: the balance is locked, never overdrawn, the debit is whole cents | wallet and overdraft tests, the concurrency group | pay with a balance equal to the price, and with less |
| S7 | A coupon of one storefront does not apply on another | not yet (slice 5 of GCC-05) | try the same code in two countries |
| S8 | Order status changes and the customer's notification | order and fulfillment tests | follow one order to delivered |
| S9 | Refund and return credit the wallet once | refund tests; four concurrency cases are marked pending an owner decision | refund one to the wallet |
| S10 | A storefront switched off stops selling and keeps its orders readable | kill-switch and payment-callback tests | switch one off in the middle of a checkout |
| S11 | A restricted employee sees only their country | the staff scope matrices | sign in as one |
| S12 | The Saudi pages are unchanged with every switch off | not yet (the golden pack, M1-17) | compare the Saudi pages before and after each step |

## 5. Manual checks on a device

Run on a phone against the staging host, with the build made for each state of the flag (`--dart-define=GCC_STOREFRONTS_ENABLED=true` and without).

**Customer app.** (1) Fresh install, the flag off: nothing changes from the store version. (2) The flag on: the storefront chooser lists the storefronts the server lists; choosing one reloads the catalogue, the cart and the theme and asks the server whether it may sell. (3) A browse-only storefront: a product opens, the cart and the checkout are not offered. (4) Prices on a Kuwaiti, an Emirati and the Saudi storefront: decimals, mark, and no exchange-rate choice in the settings on the first two. (5) Add to cart, change storefront, come back: the carts are separate. (6) Sign in, OTP, sign out: the same account on every storefront, the wallet and points shown as they are. (7) Wallet top-up by the gateway sandbox, and a wallet payment. (8) Order, track, refund request. (9) Push: a notification arrives for an order of the storefront the order was placed in. (10) A digital product: the file opens through the authenticated route once the private mode is on. (11) A deep link into a product of another country opens that country's storefront or is refused, never the wrong price. (12) Airplane mode in the middle of a checkout: no duplicate order after reconnecting.

**Seller app.** (1) Sign in with the second factor. (2) The market scope shows the seller's own markets and no figure is summed across currencies. (3) A product add and edit with a price to the third decimal. (4) An order of the seller: the status change, the digital file after sale. (5) The payout request and the withdraw with the market's decimals. (6) A rejected seller's old token is refused. (7) The finance statement of one market.

**Admin and vendor panels.** An order of each country in the order list; the product list with each row in its own currency; the legal entity on an order; the invoice (still the live settings until the invoice slice of GCC-13 lands).

## 6. The log to keep

For every step: the tree (the commit), the engine and its version, the switches, the date, who, the commands run and their exit codes, the scenario ids and the result of each, a screenshot of anything that looked wrong, and what was done to go back. A rehearsal with no log did not happen.

## 7. When staging is done

All of: a host exists behind authentication with the engines above; the seeder ran there and `fulfillment:outbound-audit` exits 0; steps 0 to 5 of section 3 were rehearsed with the rollback of each; S1 to S11 passed in all six countries on the tree that will be merged (the all-in-one integration is the proof of the tree, not of the host); the device checks of section 5 were done on both apps; and the log exists. Until then nothing is called verified.

## 8. Owner decisions open

1. Whether to build a staging at all, where, and who may reach it.
2. That only invented data is used.
3. Which gateway sandboxes are used, and who holds their credentials (never in the repository).
