# M5AZN

Our store is a **seller account** on M5AZN. M5AZN has no push to us, so we *pull*.

## Connecting

Admin → Fulfillment providers → **M5AZN**: seller email and password (stored encrypted, write-only), the platform
name M5AZN should see, country, and the store category imported products go into. **Test connection** performs the
documented login only. Nothing outbound happens until the environment switch, *Integration enabled* and the
relevant feature switch are on.

## Catalogue and import

1. You select products inside your M5AZN account.
2. `fulfillment:sync` (every 30 min, or **Sync products now**) pulls "my products" and stages the raw rows
   (`provider_catalog_items`, de-duplicated by a payload hash).
3. When a catalogue field map is **verified** and *Import selected products into the store automatically* is on, each
   staged row is imported **idempotently by M5AZN's own id**: product, variants, images, SKU, barcode, supplier
   cost, retail price and stock. The product is created **unpublished** unless *Publish imported products* is on,
   and is assigned to `m5azn` automatically.
4. A row whose SKU or barcode already exists in the store is **never adopted**: it is flagged *Needs review*; an
   administrator can import it as a new product on purpose.

## Prices — one source of truth, no double markup

| Policy | Selling price | Supplier cost |
|---|---|---|
| **Store-controlled** (default) | Set once when the product is created; later syncs never overwrite it | Tracked on the mapping with history (`provider_price_changes`); never written into `products.purchase_price` |
| **Supplier retail** | Follows M5AZN's retail price, no markup | Tracked as above |

Order lines snapshot their price at checkout, so history is preserved when prices later change. The M5AZN page shows
cost, selling price and *selling price − cost*, and says that **M5AZN's documentation provides no way to read or
change its own margin**; a *minimum margin %* setting flags products below it.

## Stock

Optional (*Follow M5AZN stock levels*, needs a verified inventory map): the mapping's provider stock is kept from
the `qty-changed` feed. A product known to be out of stock holds the order instead of sending it; a figure older than
6 hours is treated as unknown and does not.

## Orders

Only M5AZN-owned lines are sent, in the documented create-order body, once the order is paid (or COD) and its status
is one of the configured dispatch statuses. The order reference is read from the response through the configured
path; **a 2xx without a reference is not treated as acceptance** and is never re-sent — it goes to reconciliation.
Reconciliation finds our order id in the order list (needs a verified orders map) or waits for an operator.

## Tracking and status

M5AZN documents no webhook, so shipments are polled (every 15 min) and mapped through the **status map** you
define (M5AZN's status words are not documented). A shipment M5AZN cannot report on is closed by an operator with a
reason. Customers see M5AZN-fulfilled items as an ordinary shipment — never the provider's name.
