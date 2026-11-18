# D1-10: a refund names the money and the company of its order

Master tracker requirement D1-10, first criterion ("a refund request or transaction stores the currency and the company of the original payment"). Additive and recording only: **the amount of a refund, the way it is computed and the account it goes back to are not touched.**

## The rule

When a refund request or a refund transaction is made, it copies from its order's own record the storefront, the currency, the rate that turns a stored amount into that currency, and the selling company (`orders.storefront_country`, `currency_code`, `currency_rate`, `legal_entity_id`: GCC-05 and GCC-13). A refund read later then says what it gave back in and on behalf of whom, whatever the rates and the settings are by then.

- **Four nullable columns** on `refund_requests` and `refund_transactions` (migration `2026_12_03_100000`, appended without a position, no index, the country `ascii_bin` on MySQL and MariaDB like the order's). A refund made before the migration keeps all four empty. A rollback is refused while any refund carries a record, because what a refund gave back cannot be worked out again.
- **`App\Services\Storefront\RefundOrderMoney::of($order, $table)`** returns a column only when the order has a value and the table has the column, so an order with no record (placed before the storefront currency or the selling company was switched on) and a database that has not run the migration both give nothing: the row is made exactly as it always was.
- **The three places that make a row** ask it: the customer API (`POST /api/v1/customer/order/refund-store`), the customer web (`refund-store`) and `RefundTransactionService::getData` (the administrator's refund payout). `RefundTransaction::$fillable` names the four columns, because the repository mass-assigns and would drop them silently. A test reads `app/` and fails for a place that makes a refund row and does not ask.

## What it does not do

- The amount: still the order line's total plus tax minus discount, coupon and referral share, in the order's stored amounts. A refund "computed in the order's own currency and not re-converted at the refund-time rate" (criterion 2), one that "goes back through the merchant account and the company that took the payment" (criterion 3) and "returns exactly the amount paid after a rate change" (criterion 4) are not done: they need the owner's rule on refunds across currency movements, credit notes and whether a refund may only return to the original merchant account (open decisions below), and the gateway accounts of each company (D2).
- The seller's and vendor's refund screens, the exports and the refund report do not show the new columns yet.
- A wallet refund credit still converts the stored amount at the current rate; the wallet is closed on a storefront with its own currency until the wallets are per market (GCC-15).

## Tests

`RefundOrderMoneyTest` (12: what the service copies for each table and for an order object, an order with no record, a table that has not run the migration or does not exist; the transaction the administrator makes carries the record and keeps its own fields, and is what it always was for an order with no record; the model stores it; the source guard; the migration is idempotent, skips a missing table and refuses a rollback while a refund carries a record) and `StorefrontRefundRecordTest` (4, through the real API: a Kuwaiti refund request carries `KW`, `KWD`, rate one and the company with the order line's amount unchanged; each of the other four storefronts names its own currency; an order that recorded nothing is refunded as before; a refund made before the migration is made without the columns). Mutations of the API writer, the transaction service and the fillable list each fail the matching test.

## Open owner decisions

1. The refund policy across currency movements: the original amount, or the amount at the current rate.
2. Credit notes, and whether a refund may return only to the original merchant account.
3. Whether the refund reports and exports show the currency and the company.
