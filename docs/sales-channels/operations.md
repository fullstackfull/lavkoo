# Operations

## Deploying safely

Nothing here is deployed to production by this work, and no production data is read or changed. When you deploy:

1. **Back up the database first.** The migrations are additive (new tables only; every `Schema::create` is guarded by `Schema::hasTable`; no existing table or column is altered; no foreign key points into a core table) but a backup is the rule for any release.
2. **Never run `php artisan migrate` on an empty database** (project rule): import the dump first.
3. Run exactly these four migrations, in this order (they can be run alone):
   ```bash
   php artisan migrate --path=database/migrations/2026_11_07_100000_create_operational_cost_tables.php
   php artisan migrate --path=database/migrations/2026_11_07_100100_create_sales_channel_tables.php
   php artisan migrate --path=database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php
   php artisan migrate --path=database/migrations/2026_11_08_100000_add_state_before_dispute_to_operational_cost_entries.php
   ```
   Until they have run, every admin page of the module says so, and every scheduled command prints "the sales-channel migrations have not been run yet" and exits successfully.
4. **Leave the switches off** (the default). `.env` needs nothing; the two optional values are `SALES_CHANNELS_ENABLED` and `SALES_CHANNELS_EXTERNAL_WRITES_ENABLED` (read with `FILTER_VALIDATE_BOOLEAN`: `off`/`no` mean off). `OPERATIONAL_COSTS_BASE_CURRENCY` defaults to the fulfilment currency (SAR).
5. `php artisan optimize:clear`. The scheduler (the server cron `* * * * * php artisan schedule:run`) already exists for the platform; the new entries need nothing else. There is no queue worker to change: the module runs from commands.
6. Give the roles that should see the screens the two new permissions, **Sales Channels** and **Operational Costs** (the master admin has them).

**Rollback:** switch `SALES_CHANNELS_ENABLED` off (all channel traffic stops at once); remove the scheduler lines if wanted; the tables can stay (nothing reads them with the module off) or be dropped in reverse order of the migrations' `down()`.

## Connecting a channel (step by step; each step is an admin decision)

1. *Sales Channels → Add account.* Enter the seller id, the credentials (write-only; the page only ever says set / not set), the environment (sandbox/stage or production), the market settings. The account is created **disabled**.
   * **Amazon:** a private-developer application with the roles in [api-reference.md](api-reference.md), self-authorised in Seller Central → the LWA client id/secret and the **refresh token**; the seller id (Merchant Token); the marketplace id (Saudi Arabia `A17E79C6D8DWNP`); region `eu`.
   * **Trendyol:** the Seller ID, API key and secret (Account Information → Integration Information; stage and production differ), storefront `SA`, an integration name (letters/digits, ≤ 30). For stage, ask Trendyol to allow the server's egress IP.
2. Enable the account, switch **Connection Test** on, press *Test connection*. It only reads. The result lists granted and missing permissions; fix roles/keys and test again.
3. Switch on **Read-only Sync**, then **Product Sync**, **Order Import**, **Financial Reconciliation** as needed. Each is a separate decision; a successful test never switches anything on.
4. Link listings to products (*Products & Channels*): nothing is linked automatically unless the account's explicit *auto-link exact SKU/barcode* setting is on; names are never matched. The default stock locations (Torod Point, M5AZN, the own warehouse; an Amazon FBA location per Amazon account) exist from the first time a module page is opened or a scheduled command starts after the migrations. *Stock by location* shows what each one holds, one location at a time, with how far each figure can be trusted: Amazon FBA is shown on its own and is never added to Torod Point's.
5. Add **routing rules** (*Dynamic Routing*). With no matching rule the account's default decides: *manual review* (every order waits for a person — the standard) or *managed by someone else* (the order is only followed, for an account whose shipments another integration creates). Set it on the account's page.
6. Writes (**Inventory Publishing**, **Price Publishing**, **Fulfillment Dispatch**) additionally need: the environment switch on, *write enabled* on the account, a recorded approval with a reason, and dry run turned off. Start with dry run on: it logs the exact request and sends nothing.

## Running things by hand

```bash
php artisan channels:status                         # switches, accounts, capabilities, last sync
php artisan channels:test-connection {account}      # harmless reads; prints granted / missing
php artisan channels:sync {account} --only=orders   # orders | returns | listings | inventory | finances; omit --only for every resource the account may read; --due runs only what the scheduler would
php artisan channels:publish {account} --only=stock,price,settle,shipments
php artisan channels:import-statement {account} statement.csv   # a statement exported from the seller portal (columns in the command's class docblock)
php artisan costs:refresh --days=7                  # bring cost entries of the sales placed OR changed in the last days up to date (a sale delivered, shipped or cancelled later is picked up when it changes)
php artisan costs:accrue-storage --date=2026-11-05  # storage cost of one day
php artisan channels:prune --days=60                # delete old call logs and clear the stored text of old webhook deliveries (daily on the schedule; the orders and costs they led to are kept)
```

**History:** the scheduled refresh looks at the last three days, so the sales that existed before this module was deployed have no cost rows. They are shown as costs-not-yet-determined (their profit is provisional, never a perfect margin) until `php artisan costs:refresh --days=365` (or the span wanted) has run once. Recognising the cost of goods of old delivered orders takes units from the FIFO layers, so enter the purchase invoices (or the opening stock) first; a sale whose goods have no layer to take from is reported as a shortfall, not as free.

`costs:refresh` and `costs:accrue-storage` isolate each sale (each warehouse): one that cannot be brought up to date is reported to the log and counted, the others are still looked at, and the command exits with a failure so the scheduler shows it.

Each prints `ok`, `skipped` (switch/capability off), `busy` (another run holds the lease or the resource is backing off), `rate_limited` or `failed`. A failed resource backs off (5 minutes per consecutive failure, capped) and never advances its watermark; the next successful run re-reads from the watermark less 10 minutes and records nothing twice.

A backlog larger than one allowance is read over several runs: when the channel (or our own limiter) says wait, the place reached is saved before the run backs off, and the next run goes on from it. A statement is read in windows of at most 180 days (a longer gap takes several runs; the watermark only moves as far as was read), and a run that goes on from a saved place asks for the very window it was saved with. A snapshot of stock the channel holds for us (Amazon FBA) is always read in one go: its page tokens live seconds, so a saved one is never used.

**Stopping a run that is already going:** switching the account off, forbidding writes, going back to a rehearsal or withdrawing an approval takes effect at the very next call the run makes (the transport reads those five switches from the database before every call, not from the account the run started with).

## Webhooks (Trendyol)

Registering is a write (needs *Order Import*, the *webhook management* approval, write enabled, dry run off). The address is `https://<your host>/api/sales-channel-webhooks/{account id}` (it deliberately does not contain the word "trendyol": Trendyol refuses addresses that do). Trendyol authenticates with an API key the application generates and stores encrypted; it arrives in `x-api-key` and is compared in constant time; any authentication failure is the same flat 401. Events are stored once and applied through the same idempotent path the poller uses; polling stays the source of truth because Trendyol documents webhook delivery as not guaranteed.

## When something looks wrong

| Symptom | Look at |
|---|---|
| nothing syncs | `channels:status`: global switch, account enabled, capability, backing-off resource, connection `auth_expired`/`revoked` |
| an order is "waiting" | the order page → latest decision and its reason code (`no_rule_matched`, `cod_requires_review`, `unmapped_lines`, `managed_dispatch_not_available`, …) |
| costs show "Missing" | the reason on the entry (`no_contract`, `awaiting_channel_statement`, …): set up the contract or wait for the statement |
| a publish keeps failing | the listing's publish state; a value the channel refused three times (right away, or later in the batch result, or accepted and never applied) stops until a person changes it; an outage or a dropped connection never counts against the value and is simply tried again |
| a follow-up says "could not be read" | a batch result the channel would not give right now (a switch is off, the channel is busy, the batch is gone): the change stays queued and the other batches are still followed |
| stock offered is lower than the shelf | open channel orders hold their units (*reservations*); they come back when the order is cancelled, and a shipped order counts only until the location's own count shows it |
| stored credentials "cannot be decrypted" | this server's application key differs from the one that encrypted them: restore the key (nothing was overwritten), or enter the complete set of credentials again to replace them |
| a Trendyol tracking push fails | the order's `channel_notify_error` (usually a carrier with no Trendyol code in the account's *carrier codes*); after three refusals of the same tracking number it stops until a person presses *Tell the channel about this shipment* or the tracking number changes |

## Known limits of this version

* The Amazon marketplaces of one account are read one after the other (orders take them all at once); listings, FBA stock and statements go through every marketplace id the account holds, and each has its own place in a paused sweep.
* An invoice with thousands of lines is linked to provider shipments one line at a time against the Fulfilment tables, whose secondary reference and tracking columns are not indexed (they belong to the Fulfilment workstream; this module indexes its own tables).
* Two workers racing for the same lock are retried (three tries) when MySQL reports a deadlock; the first-write races of order ingestion and of a ledger slot were reasoned about and tested on one connection, not under real concurrency.
* The instants of an order are stored to the second: of two changes to one order inside the same second the later wins only if its time is later than the stored second, so a stale update from that same second can be applied over a newer one. Nothing in the module depends on finer ordering.
* A rehearsal (dry run) does not remember what it rehearsed, so every cycle logs the same rehearsed requests again (nothing is sent: Amazon's validation preview is a PATCH with the real values and is gated like the real write).
* A publish run does not renew its lease while it works: a very long Amazon per-SKU run (thousands of listings at 5 calls a second) could outlive the 20 minutes of the lease.
* MySQL sorts the keys of a JSON column, so a stored object comes back with its keys in another order. Nothing in the module compares a stored JSON value as a whole except the fingerprint of a routing decision, which puts the keys of every line in one order first (a test stores the lines in MySQL's order and shows that no second decision is recorded).
* The application's MySQL connection uses `utf8mb4_unicode_ci`, which compares text without regard to letter case and ignores trailing spaces. Two listing keys that differ only by case would be one mapping and one unique key there, while SQLite (the daily tests) tells them apart. Nothing in the module relies on case to tell two listings apart; a seller who keeps two SKUs that differ only by case would see them merged.
* Price and quantity writes, shipment confirmation and the connection test use the first marketplace id of an Amazon account (reads go through all of them): an account that sells in several marketplaces gets its writes in the first only.
* A manual shipment link is checked against the other channel orders and against the store's own fulfilments, not against the provider: a reference that does not exist at the provider is accepted.
* The same marketplace fee imported from the API and again from a manual statement file is counted twice (and `25` and `25.00` are two rows): import a file only for what the API does not give.
* Telling a channel about a shipment has no lock and no lease: the scheduled run and the *Tell the channel* button can overlap and send the same tracking number twice. A carrier with no Trendyol code is retried every cycle with a read of the package and no write until the code is set.
* `sales_channels.managed_dispatch_enabled` (default off, not an environment value) makes the engine record rule decisions to create provider shipments that nothing executes; keep it off until the dispatcher exists.
