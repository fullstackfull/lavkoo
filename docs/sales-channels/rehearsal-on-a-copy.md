# Rehearsing on an isolated copy of the real database

Everything in this repository was tested on throwaway databases whose platform tables (`orders`, `order_details`, `products`, `suppliers`, `purchase_orders`, `stock_movements`, `warehouse_stock`, …) were **rebuilt from the code**, because the real schema and data come from a dump the repository does not hold (the project rule is "import `installation/backup/database.sql` first, then migrate"; that file is not in this checkout). The tests prove the code against that rebuilt schema, on SQLite, MySQL 8.0, MariaDB 10.11 and MariaDB 11.4.2 (the version the pilot server reports), in strict mode and with real concurrent processes. They cannot prove it against the real tables. This is the rehearsal that does, to be run by the owner (or with the owner's dump) before anything is switched on. Nothing here touches production.

## Set-up (an isolated copy)

1. Take a dump of the real database with `mysqldump --single-transaction --routines` and restore it into a **new MariaDB 11.4.2** server or container that nothing else uses (check `SELECT VERSION();` equals the production server's). Personal data in the dump: use a protected machine and delete the copy afterwards.
2. Check out this branch on a machine that cannot reach production, `cp .env.example .env`, point `DB_*` at the copy, and leave every `SALES_CHANNELS_*` switch unset (off). Put **no** real Amazon, Trendyol, Torod or M5AZN credential anywhere. Never run `config:cache`.
3. `php artisan migrate:status` must list the platform's own migrations as run (the dump carries them). If it does not, the dump is the wrong one.

## What to run, in this order, and what each must show

| Step | Command | Must show |
|---|---|---|
| 1 | `php artisan channels:check-platform-schema` | `ok` for every table; any `columns missing` / `insists on` line is a real mismatch between this code and the platform's tables (fix or report it before going on). Lines saying the dispatcher "will not fill" a column are informational. |
| 2 | the module's migrations, each first with `--pretend` to read the SQL, then for real, in the order of the deploy list in [operations.md](operations.md) | each runs without error on the copy; `php artisan channels:status` then says the module is migrated and every switch is off |
| 3 | `php artisan channels:check-platform-schema` again | still `ok` (the module's own tables are not on its list; a migration must not have changed the platform's) |
| 4 | `php artisan migrate:rollback --path=<each migration, newest first>` then migrate again | the `down()` of each migration leaves the platform's tables as they were (compare `SHOW CREATE TABLE` of the platform's tables before and after) |
| 5 | the read-only reports of the cost module (`costs:refresh --days=7` on the copy, the profit screens) | numbers that look like the shop's own; entries marked "missing" instead of invented costs |
| 5b | on the copy only, with `OPERATIONAL_COSTS_ENABLED=true` and `OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS=shadow`: `php artisan costs:reconcile-platform` and `php artisan costs:layers-from-purchase-orders --dry-run --since=YYYY-MM-DD` ([costs.md](costs.md), *Sources of truth*) | read-only reports (the row count of every table is the same before and after): the purchase-order lines it could layer and every line it refuses, with its reason; compare a few lines with the platform's own purchase-order and stock screens (cost per unit, currency, quantity received, whether `unit_cost` includes VAT, the dates of the receipts). A column or table the code expects and the copy lacks is named, not guessed |
| 6 | with **dry run on** and a **fake** provider only: dispatch one hand-made channel order (see [dispatch.md](dispatch.md)) | one internal order in `orders` with customer 0, `is_stock_decreased = 0` lines, no stock change in `products.current_stock`; a second attempt finds the first |
| 7 | the shared provider shelf, on the copy only and with no provider credential: pick one product the store sells and a provider holds (`provider_product_mappings` has an approved figure for it), note its `products.current_stock`, make one channel order of 2 units of it by hand at that provider's location (an `external_order_mappings` row with a line and a RESERVED `inventory_reservations` row, as [operations.md](operations.md) *The storefront's side of a provider's shelf* describes), then apply the provider's figure once from `tinker` (`app(\App\Services\Fulfillment\InventorySyncService::class)->applyToAssignedProducts(...)` with the figure the mapping already holds) | `current_stock` is lower by exactly those 2 units and never negative, one `stock_movements` row with reason `provider_sync`, and nothing else in `products` changed. Release the hold and apply the same figure again: the 2 units come back. With `SALES_CHANNELS_HOLD_CHANNEL_UNITS_FROM_STOREFRONT=false` the figure is written as it was before this change |

Run the test suite itself against a **different** empty database, never against the copy: several tests create and drop tables. Steps 6 and 7 are the only steps that write platform tables, and only in the copy (step 7 writes `products.current_stock` and one stock movement: it is the one step whose effect the shop would see, so it belongs on the copy and nowhere else).

## What to bring back

The output of steps 1 to 7 (including 5b), `SELECT VERSION();`, `SHOW CREATE TABLE orders` and `order_details` (structure only), and anything that failed. Until then the statement in the final report stays true: *nothing was tested against the real schema*.


---

## Scrubbing the copy before anybody works on it

The owner asked for the rehearsal to run on an isolated copy of the real schema **and data, with the
sensitive data scrubbed**. The dump carries patients' names, addresses, telephone numbers and what
they ordered, so the scrub is part of making the copy, not a step afterwards.

Run these on the COPY, never on production, and before any of steps 1 to 7:

```sql
-- customers and the orders' own copies of their details
UPDATE customers SET f_name = CONCAT('Test', id), l_name = 'Customer', email = CONCAT('c', id, '@example.invalid'),
       phone = CONCAT('+9665', LPAD(id, 8, '0')), street_address = NULL, city = city, zip = NULL;
UPDATE orders SET shipping_address_data = JSON_OBJECT('city', JSON_UNQUOTE(JSON_EXTRACT(shipping_address_data, '$.city')), 'redacted', true),
       billing_address_data = NULL;
UPDATE shipping_addresses SET contact_person_name = CONCAT('Test', id), phone = CONCAT('+9665', LPAD(id, 8, '0')),
       address = NULL, latitude = NULL, longitude = NULL;
-- anything the channels stored about a buyer
UPDATE external_order_mappings SET buyer_name = NULL, buyer_email = NULL, buyer_phone = NULL;
DELETE FROM sales_channel_api_logs;
```

Then check the scrub actually bit, because a scrub that silently matched nothing is worse than none:

```sql
SELECT COUNT(*) FROM customers WHERE email NOT LIKE '%@example.invalid';
SELECT COUNT(*) FROM external_order_mappings WHERE buyer_phone IS NOT NULL;
```

Both must be zero. If a column above does not exist on the dump, that is a schema difference worth
reporting rather than skipping: step 1 (`channels:check-platform-schema`) is the place it shows up.

**And the credentials.** Point `DB_*` at the copy, leave every `SALES_CHANNELS_*`, `ODOO_*` and
`AMAZON_*` switch unset, and put no real Amazon, Trendyol, noon, Torod, M5AZN or MyFatoorah
credential anywhere on that machine. The switches ship off and the rehearsal does not need them: no
step here sends anything to a channel.


## One check that decides whether a data migration is needed

The channel's fee net is now filed under `channel-net:<CUR>` in every currency. Versions before
2026-11-11 filed it under the empty service reference whenever the fee's currency matched the order's.
If any such row exists, a new fee for the same order is written in the NEW slot while the old row
stays effective, and both are counted: measured on a copy, two 12.00 SAR nets gave an actual cost of
24.00 and the estimate beside them was already gone.

Run this on the copy BEFORE anything else, and bring the number back:

```sql
SELECT COUNT(*) AS legacy_channel_nets
FROM operational_cost_entries
WHERE source = 'channel_api' AND is_effective = 1 AND state IN ('actual','reconciled')
  AND slot_key = SHA2(CONCAT_WS('|', 'slot', seller_id, subject_type, subject_id, cost_component, ''), 256);
```

**Zero** means this does not apply and nothing needs migrating: the module's own migrations have never
run on production, so that is the expected answer. **Anything above zero needs a decision before the
branch is used against that database**, because re-slotting those rows means recomputing `slot_key`
and `dedupe_key` and deciding what happens where a `channel-net:` row already exists for the same
service. The migration is writable and testable; it is not written yet, because whether it is needed
at all is what this query answers.
