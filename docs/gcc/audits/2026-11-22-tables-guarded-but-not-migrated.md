# Audit: 62 tables the code guards and this repository does not create

**Date:** 2026-11-22
**Measured on:** `origin/main` `c5c04b7b`, in a clean worktree.
**Status:** a finding with ONE open question that only the owner can answer. Nothing here is a defect claim.

## What was measured, and how

Every `Schema::hasTable('<name>')` guard in `app/` and `Modules/` was collected, and each table name
compared against the text of every file in `database/migrations/`.

| | |
| --- | --- |
| distinct tables behind a `hasTable()` guard in application code | **112** |
| of those, named by **no** migration in this repository | **62** |

## The question only the owner can answer

**Which of these 62 tables exist on the live platform?**

This repository cannot say, and neither can this container:

- `.gitignore` excludes `*.sql` (line 53), so `installation/backup/database.sql` is **not** in the
  repository and is not on this machine.
- `CLAUDE.md` is explicit that the dump is imported **before** `php artisan migrate` runs — so the dump,
  not `database/migrations/`, is the source of most of the schema.

So **"no migration here" does not mean "the table does not exist"**. Some of the 62 are obviously in the
dump (`business_settings`, `failed_jobs`, `themes`, `stock_movements`, `redirects`). Others may be in no
database anywhere.

**A correction this audit makes to earlier work of ours:** an earlier round read
`grep -c <table> installation/backup/database.sql` as `0` and took that for evidence that a table was
absent from the dump. A grep of a file that is not present returns 0 because there is nothing to match.
That claim appeared in one migration docblock and in one pull request description; both have been
corrected, and no other conclusion in the register rests on it.

## Why the answer changes what remains of the plan

A `hasTable()` guard is a feature that **turns itself off** when its table is missing. So for every one
of these tables that does not exist:

- the services, models, admin screens and console commands around it **run but do nothing**;
- a criterion recorded CODED on reading that code is recorded on code that **cannot execute**;
- and a test written against it would have to create the table itself — which is the owner's standing
  rule against tests that manufacture the state they are meant to find.

Two instances were met head-on while implementing this week, which is what prompted the sweep:

| table | what is shipped around it | what was found |
| --- | --- | --- |
| `payment_routing_rules` | the model, the resolver, and an admin screen that creates, updates and deletes rules | no migration here; a migration is added by PR 158 with a `hasTable()` early-out so it is safe whether or not the dump has it |
| `order_item_commissions`, `vendor_ledger_entries`, `commission_rules` | `CommissionEngine`, `VendorLedger`, `OrderManager::recordOrderItemCommission`, the settlement and payout screens | no migration here; `recordOrderItemCommission` returns at its own guard, so **D1-12's commission criterion cannot be tested until the question above is answered** |

## The 62, with how many application files guard each

A higher count means more of the application is written against it.

| table | files guarding it |
| --- | --- |
| `seller_insights` | 10 |
| `business_settings` | 9 |
| `vendor_ledger_entries` | 9 |
| `audit_logs` | 5 |
| `experience_campaigns` | 5 |
| `order_item_commissions` | 5 |
| `return_shipments` | 5 |
| `seller_staff` | 5 |
| `seller_webhooks` | 5 |
| `stock_movements` | 5 |
| `analytics_events` | 4 |
| `brand_claims` | 4 |
| `customer_segments` | 4 |
| `experience_experiments` | 4 |
| `product_batches` | 4 |
| `product_moderation_events` | 4 |
| `seller_api_keys` | 4 |
| `seller_webhook_deliveries` | 4 |
| `suppliers` | 4 |
| `vendor_payout_requests` | 4 |
| `vendor_settlements` | 4 |
| `analytics_daily` | 3 |
| `approval_requests` | 3 |
| `category_governance` | 3 |
| `seller_automation_actions` | 3 |
| `seller_automation_rules` | 3 |
| `seller_bulk_jobs` | 3 |
| `seller_sla_breaches` | 3 |
| `seller_verification_documents` | 3 |
| `theme_assets` | 3 |
| `theme_versions` | 3 |
| `abandoned_cart_reminders` | 2 |
| `commission_rules` | 2 |
| `experience_pages` | 2 |
| `payment_routing_rules` | 2 |
| `product_collections` | 2 |
| `product_metrics` | 2 |
| `product_price_changes` | 2 |
| `product_search_index` | 2 |
| `purchase_orders` | 2 |
| `redirects` | 2 |
| `shipping_zones` | 2 |
| `theme_sections` | 2 |
| `themes` | 2 |
| `analytics_campaigns` | 1 |
| `api_changes` | 1 |
| `api_response_shapes` | 1 |
| `api_snapshots` | 1 |
| `auction_participants` | 1 |
| `auction_products` | 1 |
| `delivery_syria_parcels` | 1 |
| `exchange_rate_logs` | 1 |
| `failed_jobs` | 1 |
| `monitoring_request_buckets` | 1 |
| `monitoring_scheduled_runs` | 1 |
| `seller_notification_deliveries` | 1 |
| `seller_pricing_policies` | 1 |
| `seller_roles` | 1 |
| `seo_meta_translations` | 1 |
| `seo_templates` | 1 |
| `vendor_bank_change_logs` | 1 |
| `warehouse_stock` | 1 |

## What we are doing about it without the answer

- Nothing is reclassified in the tracker on the strength of this sweep. A CODED criterion stays CODED.
- Any migration we add for one of these tables opens with a `hasTable()` early-out, so it alters nothing
  if the dump already carries the table.
- Where a criterion's test would need one of these tables, the criterion is left as it is and the
  dependency is named, rather than a test being written that builds the table for itself.

## What would settle it in one step

Either of these, from a machine that has the dump or the live database:

```bash
grep -o -E "CREATE TABLE \`?[a-z0-9_]+\`?" installation/backup/database.sql | sort -u
```

```sql
SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name;
```

The list of names is all that is needed; no data, and nothing from a production host is wanted here.
