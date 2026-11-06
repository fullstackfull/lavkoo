# M5AZN import workflow — "select on M5AZN, appear in Pharmacy KSA"

## Verdict

**Supported, by polling, with a one-time setup and a bounded delay.** M5AZN's official documentation describes no
webhook or push of any kind (product, stock or order), so the store cannot be *told* that you pressed Supply/Import. It
*asks*, on a schedule, for the products on your seller list and creates the missing ones. Nothing about this is
instant, and some of it cannot work until a person has verified a response format once.

## What happens, step by step

```
you: select / Supply a product inside your M5AZN seller account
        │   (no notification to us exists)
        ▼
scheduler (cron → schedule:run, every 5 min) ──► fulfillment:sync --due
        │   asks each provider "is your interval up?" (default 30 min, minimum 5)
        ▼
GET /api/v1/seller/myproductlist   once per configured product type (local, global, …), page after page
        ▼
staging table (provider_catalog_items): one row per M5AZN product id, de-duplicated by payload hash
        ▼
import (only if the catalogue field map is VERIFIED and "import selected products" is on):
   new products first, then imported ones whose M5AZN data changed
        ▼
store product: created UNPUBLISHED, assigned to M5AZN, approved mapping keyed on M5AZN's own id,
supplier cost kept on the mapping (never in purchase_price), stock and price set per the price policy
```

## How long until it appears

| Part | Time |
|---|---|
| Wait for the next scheduled check | at most the interval — **30 minutes by default**, **5 minutes at best** (Settings → *Product sync interval*) |
| Run itself | seconds for a normal list; image downloads dominate on large imports |
| Then | the product exists in the admin, **unpublished**. It is live on the storefront only after a person publishes it, or if *Publish imported products* is on |
| Impatient? | **Sync products now** (M5AZN page / settings) runs the same code immediately |

Worst case for a first-time pull of a very large list is several runs (the batch is 500 items per run, newly
selected ones first). The scheduler must really be running (`* * * * * php artisan schedule:run`); the Integration
Center shows the last attempt/success so a stalled scheduler is visible.

## Preconditions (one-time) — without them products are staged but NOT imported

1. M5AZN credentials saved and **Test connection** passes.
2. Environment switch `FULFILLMENT_INTEGRATIONS_ENABLED=true`, provider **enabled**, **Pull the products selected on
   M5AZN** on, **Import selected products into the store automatically** on, a **store category for imports** chosen.
3. **The catalogue field map is verified.** M5AZN publishes no response schema, so a person must fetch a real sample,
   point each logical field (id, name, SKU, price, stock…) at the right JSON path and verify it **once**. Until then
   every pull is staged and reported as *blocked* — nothing is lost, it imports by itself once the map is verified.
4. **Product types.** M5AZN's "my products" call returns the `local` type unless told otherwise. If you also supply
   `global`, `ali_express` or `digital` products, list them (comma-separated) in *M5AZN product type*, or those
   products never appear.

## Guarantees the tests pin down (`M5aznImportWorkflowTest`)

* Selected products become store products after one sync; a product selected later is added **without duplicating or
  touching** the earlier ones; running the sync again and again changes nothing.
* A change on M5AZN (stock, price, data) reaches the store on the next sync; unchanged products are skipped.
* **Every page is read.** The documentation lists no pagination parameter, so the sync asks for page 2, 3, … and stops
  when a page adds nothing new (an API that ignores `page` simply repeats page 1). Capped at 50 pages per type; a cap
  hit is reported as *truncated*, a failed later page as *partial*. A failing first page changes nothing and records
  the error.
* **Fairness.** New products are processed before changed ones and unchanged ones are not processed at all, so a long
  list of old products can never crowd out a newly selected one.
* A SKU or barcode that already exists in the store is **never adopted**: the item waits for a person (*Needs review*).

## Limitations (documented, not hidden)

| # | Limitation | Class |
|---|---|---|
| 1 | Not instant: delay up to the interval (≥ 5 min). No webhook exists to remove it | by design |
| 2 | The response format is unknown until a person verifies a real sample; until then no import | **setup step** |
| 3 | Pagination and product-type handling rest on the documented query parameters plus the safe probing above; **verify with a real account that every selected product shows up** | verify live |
| 4 | **Products removed from the M5AZN list are not detected**: the store product stays, with its last stock. Remove or unpublish it by hand | non-blocker |
| 5 | New products start unpublished (deliberate: a human reviews price, text, images) | by design |
| 6 | Price policy decides prices; the admin page shows supplier cost and margin only as *selling price − supplier cost* (M5AZN documents no margin API) | by design |
| 7 | Stock follows M5AZN only if the stock-change feed's field map is also verified and *Follow M5AZN stock levels* is on | setup step |
| 8 | A variant without a SKU cannot be ordered from M5AZN, so that product waits for review | by design |

## Live verification (needs a real M5AZN seller account — not performed)

See [`live-verification/m5azn.md`](live-verification/m5azn.md): select a test product, record the time, run **Sync now**
and then wait one interval, and confirm (a) the product appears once, (b) its SKU/price/stock match M5AZN, (c) a second
sync creates nothing, (d) a product of every type you sell appears, (e) the number of pages the real API returns.
