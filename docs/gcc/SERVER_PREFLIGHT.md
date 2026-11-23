# Server preflight list (read-only)

For the authorized administrator of the live server. It collects the facts about the live system that no repository can show and that decide several choices of the release plan, of the merge order and of the GCC switches. **Everything here only reads.** Nothing changes a file, a setting or a row, and nothing prints a secret. Written 2026-11-18; it changes nothing about the owner's decision to defer the rotation of keys and credentials (`DEFERRED_RISKS.md`).

## Rules

1. Run it as the user the application runs as, on the live server or on a read-only copy of it. Do not run `migrate`, `optimize`, `config:cache`, `queue:restart`, `deploy.php apply` or anything that is not on this list.
2. Report only what each check asks for: a version, a count, a yes or no, a name. **Never paste a key, a token, a password, the contents of `.env`, or a row of `addon_settings`, `integration_settings`, `sales_channel_accounts.credentials`, `payment_requests` or `users`.** The queries below select named columns for that reason; do not widen them to `*`.
3. **The three repositories are public.** Do not write the answers into any repository, pull request or issue. Send them privately to the engineer who asked; the answers that matter for a decision are summarized in the table at the end, without the raw values.
4. If a query fails, report the first line of the error and go on.
5. Run the database queries off-peak on a large database; they are all primary-key or small-table reads except the counts of section D, which scan a table once each.

## A. The code on the server

| # | Check | Command | Report |
|---|---|---|---|
| A1 | Whether the files the next release would replace are the ones the release was built against | `php scripts/deploy/deploy.php verify --app=/path/to/app --package=/path/to/the/stage/package --json` (it changes nothing and never prints file contents) | the list of files marked `DRIFTED`, `CONFLICT`, `MISSING`, `UNWRITABLE`, `UNPARSEABLE` or `UNREADABLE` (names only), and the count of `CLEAN` |
| A2 | The commit the code was deployed from, if the server is a git checkout | `git -C /path/to/app rev-parse --short HEAD` | the short hash, or "not a git checkout" (the baseline of the release plan is `698aa1e`) |
| A3 | Who owns and may write what the application writes | `stat -c '%U:%G %a %n' storage bootstrap/cache public/storage .env` and `ps -o user= -C php-fpm \| sort -u` (or the web server's process) | owner, group and mode of each, and the user the web server runs as |
| A4 | The files a release deploys by hand | `ls -la .htaccess public/assets/new/back-end/js/translate.js public/assets/front-end/js/custom.js Modules/AI/routes Modules/Blog/routes Modules/TaxModule/routes` (from the application root) | present or absent (these are the paths named in section 5 of `MERGE_ORDER.md`) |

## B. The runtime and the database engine

| # | Check | Command | Report |
|---|---|---|---|
| B1 | PHP and its extensions | `php -v` and `php -m \| grep -E '^(bcmath\|curl\|fileinfo\|gd\|intl\|mbstring\|openssl\|pdo_mysql\|zip\|exif)$'` | the version and which of the ten are missing (CI runs PHP 8.2 and 8.3) |
| B2 | Database engine, version and modes | `SELECT VERSION(), @@sql_mode, @@innodb_lock_wait_timeout, @@transaction_isolation;` | the four values (CI runs MySQL 8.0 and MariaDB 11.4.2; several lock and migration choices depend on which one this is) |
| B3 | Collation of the tables the plan touches | `SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('orders','products','users','wallet_transactions','business_settings','currencies');` | the six collations |
| B4 | Type of the money columns | `SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'orders' AND COLUMN_NAME IN ('order_amount','paid_amount','discount_amount')) OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'wallet_balance') OR (TABLE_NAME = 'wallet_transactions' AND COLUMN_NAME IN ('credit','debit','balance')));` | the type of each (the code expects `decimal(40,20)`; the migration `2026_05_07_000001` makes it so) |
| B5 | Where the migrations stand | `SELECT COUNT(*) FROM migrations;` then `SELECT migration, batch FROM migrations ORDER BY id DESC LIMIT 15;` then `SELECT migration FROM migrations WHERE migration >= '2026_11_20';` | the count, the last fifteen names, and any name from the third query (the open pull requests add 20 migrations, all dated from `2026_11_23`; none should exist on the server yet) |

## C. Live settings that decide the plan

All of these select named setting keys. They decide the rules marked in the last column.

| # | Check | Query | Decides |
|---|---|---|---|
| C1 | The money model | `SELECT type, value FROM business_settings WHERE type IN ('currency_model','system_default_currency','decimal_point_settings','currency_symbol_position');` and `SELECT id, code, exchange_rate, status FROM currencies ORDER BY id;` | GCC-05 (is the platform single- or multi-currency, which currency is the base, how many decimals), the whole-cent wallet debit of PR 36, and the backfill of the currency of old orders |
| C2 | Delivery restrictions | `SELECT type, value FROM business_settings WHERE type IN ('delivery_country_restriction','delivery_zip_code_area_restriction','shipping_method','free_delivery_status','minimum_order_amount_status');` and `SELECT COUNT(*) FROM delivery_country_codes;` | the cross-border default and the shipping rules per storefront (the plan cannot tell whether the country restriction is on today) |
| C3 | Wallet, loyalty, cash on delivery, POS | `SELECT type, value FROM business_settings WHERE type IN ('wallet_status','loyalty_point_status','add_funds_to_wallet','cash_on_delivery','order_verification','seller_pos','minimum_add_fund_amount','maximum_add_fund_amount');` | which of the money PRs change behaviour on this server at all |
| C4 | The structured address | `SELECT type, value FROM business_settings WHERE type = 'unified_address_mode';` | whether the address work is dormant (`off`) or live |
| C5 | The tax module | `SELECT id, tax_type, country_code, is_default, is_active, is_included FROM system_tax_setups;` and `SELECT COUNT(*), SUM(is_active) FROM taxes;` | the tax snapshot per country and whether prices include tax |
| C6 | Payment gateways that are on (names only) | `SELECT key_name, settings_type, mode, is_active FROM addon_settings WHERE settings_type = 'payment_config' ORDER BY key_name;` (never `live_values` or `test_values`) | the gateway currency rules of GCC-05 and the Saudi continuity pack |
| C7 | GCC switches in the environment | `grep -E '^(GCC_\|DIGITAL_FILES_PRIVATE)' .env` (these are booleans and a country code; if anything else is on those lines, report the names only) | whether any dormant GCC code is already on |

## D. Data facts that decide the rehearsal of the migrations

| # | Check | Query | Decides |
|---|---|---|---|
| D1 | Sizes | `SELECT 'orders', COUNT(*) FROM orders UNION ALL SELECT 'order_details', COUNT(*) FROM order_details UNION ALL SELECT 'products', COUNT(*) FROM products UNION ALL SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'wallet_transactions', COUNT(*) FROM wallet_transactions UNION ALL SELECT 'loyalty_point_transactions', COUNT(*) FROM loyalty_point_transactions;` | how long the two ALTERs that touch `products` and `orders` take, and whether a copy is needed to rehearse them |
| D2 | Duplicate product codes | `SELECT COUNT(*) FROM (SELECT code FROM products WHERE code IS NOT NULL AND code <> '' GROUP BY code HAVING COUNT(*) > 1) d;` and the same with `barcode` | whether the country-scoped unique rule (GCC-14b) finds duplicates inside the Saudi catalogue that someone has to settle first |
| D3 | Wallet balances that should not exist | `SELECT COUNT(*) FROM users WHERE wallet_balance < 0;` and `SELECT COUNT(*) FROM users WHERE wallet_balance IS NULL;` | the roll-out of the overdraft guard and the reconciliation of the old balances |
| D4 | Seller withdrawals in flight | `SELECT COUNT(*) FROM withdraw_requests WHERE approved = 0;` | the moment to deploy the withdraw changes (a pending request is read by both the old and the new code) |
| D5 | One-time codes and reset proofs that stage 1 deletes | `SELECT COUNT(*) FROM phone_or_email_verifications;` and `SELECT user_type, COUNT(*) FROM password_resets GROUP BY user_type;` and `SELECT COUNT(*) FROM sellers WHERE remember_token IS NOT NULL;` | the migrations of the security set delete all of these on purpose (a customer in the middle of a reset asks again, a seller with a remembered session signs in again); this is how many people that touches |

## E. The application key (DR-2, read-only)

This is the comparison that `DEFERRED_RISKS.md` keeps in this list. It prints a yes or no and never the key.

```bash
live=$(sed -n 's/^APP_KEY=//p' .env | head -1 | tr -d "\"'" | sha256sum | cut -d' ' -f1)
example=$(sed -n 's/^APP_KEY=//p' .env.example | head -1 | tr -d "\"'" | sha256sum | cut -d' ' -f1)
[ "$live" = "$example" ] && echo "the live key IS the example key" || echo "the live key is different from the example key"
grep -E '^(APP_ENV|APP_DEBUG)=' .env
```

Report the verdict line and the two values of the last line (`APP_ENV`, `APP_DEBUG`). Nothing in this repository changes the key, and the staged hotfix does not. The credentials of the sales-channel accounts are encrypted with the application key, so any later change of the key is a data migration of its own and needs the owner's separate authorization.

## F. Integrations and automation

| # | Check | Command | Report |
|---|---|---|---|
| F1 | Sales-channel accounts, by state (counts only) | `SELECT channel, environment, enabled, write_enabled, dry_run, connection_status, COUNT(*) FROM sales_channel_accounts GROUP BY channel, environment, enabled, write_enabled, dry_run, connection_status;` | the table; it shows whether anything is switched on and in what mode |
| F2 | Provider integration settings (names only) | `SELECT provider, name, enabled FROM integration_settings ORDER BY provider, name;` (never `credentials` or `settings`) | which providers have a row and whether it is enabled |
| F3 | The scheduler | `crontab -l \| grep -c 'schedule:run'` (run as the application user) | the number of lines that run `schedule:run`, normally 1 |
| F4 | Queue, cache and session drivers | `grep -E '^(QUEUE_CONNECTION\|CACHE_STORE\|CACHE_DRIVER\|SESSION_DRIVER)=' .env` and `ps aux \| grep -c '[q]ueue:work'` | the four driver names and how many queue workers run |
| F5 | Integration switches | `grep -E '^(ODOO\|AMAZON\|NOON\|TRENDYOL\|TOROD\|M5AZN)[A-Z_]*ENABLED=' .env` | the names and the true or false (these are booleans) |

## G. Backups and the way back

| # | Check | Command | Report |
|---|---|---|---|
| G1 | The latest backup is complete | `bash scripts/deploy/verify-backup.sh --backup=/path/to/the/backup/directory --app=/path/to/app` (without `--restore-test`: it only reads the backup; **do not use `--restore-test` on the live server for this list**) | the verdict line and the date of the backup |
| G2 | Free disk for a backup and for the files an apply replaces | `df -h /path/to/app /path/to/the/backup/directory` | free space of each (the deploy tool keeps its backup outside the application tree) |
| G3 | Who can roll back, and when | none | the name of the person who can restore the database and run `deploy.php rollback`, and the maintenance window |

## What each answer decides

| Answer | Decides | Where it is used |
|---|---|---|
| C1 single or multi currency, base currency, decimals | the rule of GCC-05 for the default storefront, and whether PR 36 changes any Saudi debit | owner decisions 1 to 4 of draft PR 87; finance review of PR 36 |
| C2 and C5 country restriction and tax set-up | whether cross-border selling can ever be offered, and the tax snapshot per country | tracker gaps 1 (H1-01, D1-07) |
| B2 engine and version | which of the two engines the migrations are rehearsed on first | `MERGE_ORDER.md`, the migration notes of each pull request |
| D1 sizes and D2 duplicates | the ALTER windows and whether a clean-up of codes comes before GCC-14b | the owner guide, section 7 |
| D3 and D4 balances and pending withdrawals | the day the money fixes are deployed | tracker gap 2 (E-06, E-07) |
| D5 reset proofs | what the security migrations take from users | the release plan, stage 1 |
| E the key | nothing in the code; a decision of the owner (DR-2) | `DEFERRED_RISKS.md` |
| F1 to F5 integrations and automation | that nothing is switched on by a deployment; the Saudi continuity pack | tracker axis H, `SECURITY_RELEASE_PLAN.md` |
| G backups | go or no-go for any stage | `SECURITY_RELEASE_PLAN.md` |
