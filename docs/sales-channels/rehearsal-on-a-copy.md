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

Run the test suite itself against a **different** empty database, never against the copy: several tests create and drop tables. Step 6 is the only step that writes platform tables, and only in the copy.

## What to bring back

The output of steps 1 to 6 (including 5b), `SELECT VERSION();`, `SHOW CREATE TABLE orders` and `order_details` (structure only), and anything that failed. Until then the statement in the final report stays true: *nothing was tested against the real schema*.
