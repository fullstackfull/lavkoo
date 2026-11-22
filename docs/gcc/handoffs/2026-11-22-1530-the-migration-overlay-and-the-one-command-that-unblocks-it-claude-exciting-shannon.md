# The migration set is an overlay on a dump, and one command settles what is waiting on it

**Date:** 2026-11-22 15:30 UTC · **Branch:** `claude/exciting-shannon-4mh2ok` · **Repository:** `fullstackfull/lavkoo`

This handoff exists to make one outstanding owner question answerable in a single copy-paste, and to correct, in writing, a framing of my own that was more alarming than the facts support.

## What I measured

On this branch: **336 migrations**. **112 tables are guarded by `hasTable()`** somewhere in `app/` or `Modules/`. Of those 112, **31 are created by a migration here and 81 are not.**

The 81 include `products` (31 guard sites), `orders` (19), `order_details` (19), `business_settings` (9), `sellers` (7), `currencies` (5), `categories`, `admins` — and `migrations` itself. No installation runs without those, so **"no migration in this repository" does not mean "no table."**

`CLAUDE.md` says why, and I should have weighed it before reading anything into the absence:

> Never run `php artisan migrate` on an empty database. Import `installation/backup/database.sql` first, then migrate.

So `database/migrations` is a **partial overlay on a SQL dump that is not in the repository** — `.gitignore` line 53 excludes `*.sql`, so `installation/backup/database.sql` is not in any checkout. Everything older than the overlay comes from the dump, and nothing here can say what a given dump contains.

**My correction.** Earlier today, having found that no migration creates the commission and vendor-ledger tables, I put that to a working lane as though the code therefore could not run anywhere. That was wrong, and I sent the lane the correction before it had written anything down. Those four tables arrived in the repository's initial commit `34dce658` ("Initial commit: pharmacyksa.com") together with the classes that read them, so they belong to the dump-provided set exactly as `orders` and `products` do, and they very probably do exist live. What survives the correction, unchanged, is the part that actually matters: that writer is on the checkout path of every order and freezes the commission figure every settlement and seller statement later reads, **and it has no test at all.** The table is absent from a test database either way, so the guard makes it a silent no-op in every existing test and a test has to build the schema itself. A lane is doing that now.

## The one command

On the live host, in the project directory:

```bash
grep -o -E "CREATE TABLE \`?[a-z0-9_]+\`?" installation/backup/database.sql | sed -E 's/CREATE TABLE \`?//; s/\`?$//' | sort -u
```

**Table names only. No rows, no data, no dump contents.** Paste the output back and it settles every question of this shape at once.

## What is waiting on it

Each of these is a tracker ruling that cannot be made honestly without knowing whether the table is there:

- **D1-12 criterion 4** — the commission snapshot and vendor-ledger rows per sub-order line. `order_item_commissions` (5 guard sites), `vendor_ledger_entries` (9), `commission_rules` (2), `vendor_settlements` (4), `category_governance` (3). This is money on the live checkout path.
- **D1-02 criterion 4** — `legal_entity_id`, which has no migration either, so every test of it builds its own schema.
- Thirteen further requirements whose code sits behind one of the 81 guards, where the criterion reads "is written" or "is recorded" and the answer is "only where the table exists."

## The 80 tables guarded here and created by no migration here

Ordered by how many files guard each, so the ones worth checking first come first.

products, orders, order_details, seller_insights, vendor_ledger_entries, business_settings, sellers, stock_movements, seller_webhooks, seller_staff, return_shipments, order_item_commissions, experience_campaigns, currencies, audit_logs, warehouses, vendor_settlements, vendor_payout_requests, suppliers, seller_webhook_deliveries, seller_api_keys, product_moderation_events, product_batches, experience_experiments, customer_segments, brand_claims, analytics_events, theme_versions, theme_assets, seller_verification_documents, seller_sla_breaches, seller_bulk_jobs, seller_automation_rules, seller_automation_actions, order_fulfillments, category_governance, approval_requests, analytics_daily, addon_settings, themes, theme_sections, shipping_zones, shipping_addresses, reviews, redirects, purchase_orders, product_search_index, product_price_changes, product_metrics, product_collections, payment_routing_rules, payment_requests, migrations, experience_pages, commission_rules, admins, abandoned_cart_reminders, withdraw_requests, warehouse_stock, vendor_bank_change_logs, shops, seo_templates, seo_meta_translations, seller_roles, seller_pricing_policies, seller_notification_deliveries, monitoring_scheduled_runs, monitoring_request_buckets, failed_jobs, exchange_rate_logs, delivery_syria_parcels, categories, brands, auction_products, auction_participants, api_snapshots, api_response_shapes, api_changes, analytics_campaigns, admin_roles

(`migrations` is the 81st and needs no checking.)

---

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_018Q6HuHNDa9TWpNZBsZLmVX
