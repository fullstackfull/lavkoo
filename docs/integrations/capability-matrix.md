# Capability matrix — what the official documentation establishes

Legend: **Documented** = the provider's official document describes the call. **Built** = implemented.
**Gated** = built but off / held until a person verifies something. **No** = not built, because the
documentation does not provide it (nothing is guessed).

Sources: M5AZN API on `m5azn-api.stoplight.io` (retrieved 2026-11-05); LogesTechs *Lastmile API v2*,
*Fulfillment API v5 (updated version)* and *Webhook status updates* PDFs supplied with the brief.

## M5AZN

| Capability | Documented | Built | Notes |
|---|---|---|---|
| Login (seller account → bearer token) | Yes | Yes | Token cached encrypted; one re-login + one retry on 401; refreshed before expiry |
| Product catalogue / "my products" / details | Yes | Yes | Pull-based; products are staged raw, then imported idempotently by M5AZN's own id |
| Stock-change feed (`qty-changed`) | Yes | Gated | Needs a verified inventory field map |
| Categories | Yes | Yes | Used for the category filter |
| Create order | Yes | Yes | Only this provider's lines; COD sends only this provider's share |
| Order list / order details | Yes | Gated | Status polling and reconciliation need a verified orders field map |
| **Response schemas** | **No** | — | Every response is read through an administrator-verified field map; editing a map un-verifies it |
| Webhooks (product push, order status) | **No** | — | Status is polled (`fulfillment:poll`, every 15 min) |
| Cancel / return an order | **No** | — | Cancellation is reported as unsupported; an operator closes the shipment by hand, with a reason |
| Read / change margin | **No** | — | The admin page states this plainly; margin shown is *our selling price − supplier cost* only |

## Torod Point (LogesTechs, company ID 714)

| Capability | API | Documented | Built | Notes |
|---|---|---|---|---|
| Create warehouse (fulfillment) order | Fulfillment v5 `POST /public/fulfillment/order` | Yes | Yes | Headers `company-id`, `username`, `password`; the warehouse then creates its own last-mile package |
| List warehouse orders | v5 `GET /public/fulfillment/order` | Yes (no response schema) | Gated | Used to reconcile an ambiguous submission by our invoice number; needs a verified orders map |
| Product list | v5 `GET /public/fulfillment/product` | Yes (no response schema) | Gated | **Does not document a stock quantity** — see below |
| Product bulk upsert | v5 `POST /public/fulfillment/product/bulk` | Yes | Gated | Off by default, previewable, **never sends quantity** |
| Product categories | v5 | Yes | Yes | |
| Cities | Lastmile v2 `GET /addresses/cities` | Yes | Yes | Exact match after Arabic/English normalisation + explicit aliases; unknown → held |
| Package status | Lastmile v2 `GET /guests/packages/status` | Yes | Yes | Also used to confirm webhook claims |
| Cancel package | Lastmile v2 `…/packages/{barcode}/cancel` | Yes | Yes | Needs the package barcode (learned from the first webhook) |
| Air waybill (AWB) | Lastmile v2 `…/packages/pdf` | Yes | Yes | Fetched on demand, never stored |
| **Create last-mile shipment** | Lastmile v2 | Yes | **Deliberately never called** | A warehouse order already creates its own shipment; calling this too would ship the parcel twice |
| Webhooks (Lastmile statuses, Fulfillment CREATED / PICKED / PACKED) | — | Yes | Yes | Unsigned: secret in the URL; terminal claims are confirmed with the provider |
| **Warehouse inventory endpoint** | — | **No** | — | See below |
| **Cancel a warehouse order** | — | **No** | — | Reported as unsupported |
| **Warehouse order status by id** | — | **No** | — | Status arrives by webhook and the order list |
| **Webhook signature** | — | **No** | — | Authentication is the secret in the URL (plus an optional IP allow-list) |
| **Cash-on-delivery collection-method values** | — | **No** | — | COD held until an administrator enters the exact value |
| **Error response format** | — | **No** | — | Errors are classified from HTTP status; bodies are logged redacted |

### The stock question (brief item 4)

*Does Get Product List return available stock?* The Fulfillment API v5 document describes the request but gives
**no response schema and no quantity field**, and it documents **no inventory endpoint**. So:

* the product list is read and staged; whatever stock-looking number it returns is shown as **"not confirmed as
  available quantity"**;
* warehouse stock drives availability **only if** (a) an administrator has verified the products field map **and**
  (b) a person ticked *"I confirm the stock field is the available quantity"* in the Torod Point settings — the
  moment of that statement is recorded, and un-ticking withdraws it;
* otherwise stock for a mapped product is **manual** (typed by a person, labelled *Entered manually*) and never
  silently treated as synced;
* **what is needed from LogesTechs:** the response schema of the product list and either a confirmation that its
  stock field is the available quantity or the name of their inventory endpoint.

## What to ask each provider for

| Provider | Ask for |
|---|---|
| M5AZN | Response schemas for catalogue, stock-change feed and orders; a status webhook or a documented status vocabulary; a cancel / return call; whether (and how) cash on delivery is settled; whether a margin can be read or set through the API |
| LogesTechs | Product-list response schema and stock/inventory endpoint; Add-Order and Get-Orders response schemas (to read the order reference); allowed `collectionMethod` values; a warehouse-order cancel call; a webhook signature or fixed source IPs; the error response format |
