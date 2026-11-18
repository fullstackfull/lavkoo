# E-05: a ledger row says what stands behind it

Master tracker requirement E-05, criteria 1 (a row names the order, payment or refund behind it, not a literal), 2 (a row records the actor of a manual admin top-up) and 5 (a row carries storefront, legal entity and currency). Additive and recording only: **no balance, amount, rounding, `balance` column or `reference` value changes**, and nothing is written until the migration has run. The three criteria are CODED, not MET: tests on SQLite and MySQL 8, CI on a pushed head still to be read.

## What is recorded

Seven nullable columns on `wallet_transactions` and six on `loyalty_point_transactions` (a point has no currency), migration `2026_12_05_100000_add_attribution_to_wallet_and_loyalty_ledgers.php`:

| Column | Meaning |
|---|---|
| `source_type`, `source_id` | what the movement came from: `order` (`orders.id`), `order_group` (`orders.order_group_id`: one wallet debit pays every order of a checkout), `refund` (`refund_requests.id`) or `payment` (`payment_requests.id`, a gateway top-up) |
| `actor_type`, `actor_id` | the staff member who made a manual movement: `admin` or `seller` and the id of whoever is signed in under that guard, never a value of the request |
| `storefront_country`, `currency_code`, `legal_entity_id` | the market of the movement. `currency_code` is the currency of the market, not a statement of the unit of `credit`, `debit` and `balance` (E04-F02 is open) |

`reference` is untouched: reports translate it and the REST filters read `add_funds_to_wallet` and `earned_by_referral`. Old rows keep the columns empty (no backfill); the rollback is refused while any row carries a record. Both models hide the columns, so the REST statements keep the keys they had.

## Where it is written

`App\Services\Storefront\LedgerAttribution::of($table, $origin)` returns the columns for the row about to be saved. The six writers call it once each: `CustomerManager::create_wallet_transaction` and `create_loyalty_point_transaction`, `OrderManager::createWalletTransaction`, `CustomerTrait::createWalletTransaction`, `WalletTransactionRepository::addWalletTransaction`, `LoyaltyPointTransactionRepository::addLoyaltyPointTransaction`. Each takes an optional `?LedgerOrigin` (`order`, `checkout`, `refund`, `payment`, `by` a staff member), a plain value that reads nothing. Before the migration has run the recorder finds no column, remembers it (asked again after 30 seconds) and reads nothing else: the writers save the row as before.

| Caller | Source | Actor |
|---|---|---|
| Checkout paid from the wallet (web, REST v1) | order group | none |
| Gateway top-up (`add_fund_to_wallet_success`) | payment | none |
| Refund paid into the wallet (`RefundStatusService`) | refund | administrator |
| Manual top-up (`CustomerWalletController::addFund`) | none | administrator |
| Order edit paid back, cancelled point-of-sale order paid back (admin, vendor) | order | administrator or vendor |
| Edited-order due, referral rewards, points earned on delivery | order | none |
| Points taken back with a refund (admin, vendor) | refund | administrator or vendor |
| Point-of-sale debit (admin, vendor panel): the order is saved after the debit | none | administrator or vendor |

The market is the order's own record (through the refund's order for a refund); for a gateway top-up the storefront the payment was started in (`PaymentStorefront`) and the currency it was taken in, never the request, because the gateway calls back with no storefront; otherwise the storefront of the request while storefronts are on (with the currency and the company if those switches are on), nothing for a panel, a queue or a console command. With every switch off a Saudi row keeps the three market columns empty.

## What it does not do

- No backfill, rename or drop.
- E-05.3 replay safety: `source_id` makes a unique key possible, none is added; a replayed credit still writes twice.
- E-05.4: the ledger `balance` column still leaves out the add-fund bonus.
- A movement made by a customer or by the system has no actor; the seller app's point of sale has no staff guard to read; a points conversion names nothing (the loyalty row's `reference` already is the wallet row's id).
- No screen, report or export shows the new columns.
- Run the migration on a rehearsal copy first: appended nullable columns are an instant add on MySQL 8 and MariaDB 11.4, but the production table size is UNKNOWN.

## Tests

`tests/Feature/Storefront`, 158 tests in ten classes: the migration, the recorder, each of the four wallet writers and the two loyalty writers (a Saudi write with every switch off and a write before the migration are compared with a golden row), a source scan that fails when a writer stops asking, a new writer appears or a caller drops its origin, and through the real code the admin top-up route (with a forged actor in the request), the API wallet checkout of each of the six storefronts, the gateway success hook, the refund credit and the customer statements. The web wallet checkout, the vendor panel, the seller apps and the referral rewards are not run end to end: they share the writers and the source scan pins their origins.

## Open owner decisions

1. Whether customer-made and system-made movements record an actor.
2. The unit of the ledger amounts against `currency_code` (E04-F02), and whether points should carry a currency.
3. Whether a point-of-sale debit should name its order (the debit would have to follow the order, or the row be completed after it).
4. The idempotency key (E-05.3), and whether `source_type` plus `source_id` is it.
5. Which reports and screens show the columns, and whether a customer may see who credited their wallet.
