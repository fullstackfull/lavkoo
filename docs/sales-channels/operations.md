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
4. Link listings to products (*Products & Channels*): nothing is linked automatically unless the account's explicit *auto-link exact SKU/barcode* setting is on; names are never matched.
5. Add **routing rules** (*Dynamic Routing*). With no matching rule the account's default decides: *manual review* (every order waits for a person — the standard) or *managed by someone else* (the order is only followed, for an account whose shipments another integration creates). Set it on the account's page.
6. Writes (**Inventory Publishing**, **Price Publishing**, **Fulfillment Dispatch**) additionally need: the environment switch on, *write enabled* on the account, a recorded approval with a reason, and dry run turned off. Start with dry run on: it logs the exact request and sends nothing.

## Running things by hand

```bash
php artisan channels:status                         # switches, accounts, capabilities, last sync
php artisan channels:test-connection {account}      # harmless reads; prints granted / missing
php artisan channels:sync {account} --only=orders   # orders | listings | inventory | finances
php artisan channels:publish {account} --only=stock,price,settle,shipments
php artisan channels:import-statement {account} statement.csv   # a statement exported from the seller portal (columns in the command's class docblock)
php artisan costs:refresh --days=7                  # bring cost entries of recent sales up to date
php artisan costs:accrue-storage --date=2026-11-05  # storage cost of one day
```

Each prints `ok`, `skipped` (switch/capability off), `busy` (another run holds the lease or the resource is backing off), `rate_limited` or `failed`. A failed resource backs off (5 minutes per consecutive failure, capped) and never advances its watermark; the next successful run re-reads from the watermark less 10 minutes and records nothing twice.

## Webhooks (Trendyol)

Registering is a write (needs *Order Import*, the *webhook management* approval, write enabled, dry run off). The address is `https://<your host>/api/sales-channel-webhooks/{account id}` (it deliberately does not contain the word "trendyol": Trendyol refuses addresses that do). Trendyol authenticates with an API key the application generates and stores encrypted; it arrives in `x-api-key` and is compared in constant time; any authentication failure is the same flat 401. Events are stored once and applied through the same idempotent path the poller uses; polling stays the source of truth because Trendyol documents webhook delivery as not guaranteed.

## When something looks wrong

| Symptom | Look at |
|---|---|
| nothing syncs | `channels:status`: global switch, account enabled, capability, backing-off resource, connection `auth_expired`/`revoked` |
| an order is "waiting" | the order page → latest decision and its reason code (`no_rule_matched`, `cod_requires_review`, `unmapped_lines`, `managed_dispatch_not_available`, …) |
| costs show "Missing" | the reason on the entry (`no_contract`, `awaiting_channel_statement`, …): set up the contract or wait for the statement |
| a publish keeps failing | the listing's publish state; after three identical failures it stops until a person changes the value |
| a Trendyol tracking push fails | the order's `channel_notify_error` (usually a carrier with no Trendyol code in the account's *carrier codes*) |
