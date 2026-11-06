# Technical request to M5AZN — seller API (Pharmacy KSA seller account)

**From:** Pharmacy KSA (pharmacyksa.com), a seller account on M5AZN · **Subject:** response schemas, product-selection
behaviour, order semantics and tracking for the seller API, so that products we select on M5AZN appear in our store
automatically and orders and their status flow reliably.

Thank you for the public API reference (m5azn-api.stoplight.io). We have integrated against it. It documents the requests
but **not the responses**, and a few behaviours decide whether the integration can be safe. Please answer with **real JSON
samples** (credentials and personal data redacted) wherever a sample is requested, or a Postman/OpenAPI file containing them.

## A. Selecting products ("Supply / Import") — our main workflow

1. When a seller selects a product in the M5AZN dashboard (the **Supply/Import** action), which API list does it then
   appear in — `GET /api/v1/seller/myproductlist` (default `product_type=local`), or only under `global`, `ali_express`
   or `digital`? Is there an approval or processing state before it appears?
2. **How quickly** does a selected product appear in that list (seconds / minutes / next batch)? Does un-selecting remove
   it again? Is there a way to be told (webhook) or to ask only for *changes since a timestamp*?
3. Please send a complete sample response of `myproductlist`: envelope, **pagination** (`page`, `per_page`, `total`,
   `next`?), maximum page size, and for each product: id, name (Arabic/English), SKU, barcode, **description (is it
   HTML?)**, stock, **our cost**, **suggested retail price**, images (and the **host names** image URLs are served
   from), and the structure of **variants** (size/colour) including each variant's own SKU, price and stock.
4. Same for `GET /api/v1/product/details/{id}`, for a simple product and one with variants.

## B. Stock

5. `GET /api/v1/products/qty-changed`: parameters that work (`days`, `hours`, a timestamp?), the response rows
   (product id, variant, quantity), how far back it can look, and its recommended polling rate. Is the quantity
   **available to sell** (net of orders already placed through the API)?

## C. Orders

6. `POST /api/v1/orders` — sample **success** and **validation-error** responses. Which field is **M5AZN's order
   reference**? We send our own order number as `id`.
7. **Duplicate protection:** what happens if the same `id` is submitted twice (we never resend blindly, but a network
   timeout can leave us unsure)? Rejected, returns the existing order, or creates a second one? Is there a way to look an
   order up by our `id` (a filter on `GET /api/v1/orders`)?
8. **Cash on delivery:** exact meaning of `payment_method: "cod"`, `cod_amount` versus `total`, who collects, and how and when it is settled with us. What
   happens for a part-paid order (`cod_amount` smaller than `total`)?
9. What does M5AZN do when a product is out of stock at order time (error code/body; back-order)?
10. `GET /api/v1/orders` and `GET /api/orders/{id}`: sample responses — envelope, pagination, the **status field**,
    the **complete list of status values** and which are final.
11. Is there a **cancel** or **return** call? If not, what is the process and its turnaround?
12. Is there a **webhook** for order status changes (and product/stock changes)? If not, the recommended polling rate.
13. Is there any API to **read or change our margin / price rules**?

## D. Environment

14. A **sandbox** (base URL + test seller account) where we may create and cancel test orders without goods moving.
15. Rate limits, timeouts, maintenance windows, versioning and deprecation policy, the **error response format**.
16. A technical contact and expected response time.

## What we will do with the answers

* Verify the response field maps and switch on the automatic import and stock follow (we poll; default every 30 minutes,
  configurable down to 5).
* Turn on automatic delivery status once the status list is known. Until then a shipment's delivery is closed by an
  operator, and nothing is guessed from an unknown status word.

## For your reference — what we send today

`POST /api/v1/orders` with a bearer token from `POST /api/v1/auth/login`; body: `id`, `total`, `payment_method`
(`cod`|`card`), `cod_amount`, `order_created_at` (`Y-m-d H:i:s`), `platform_name`,
`shipping_info{address,country,country_code,city}`, `customer_info{first_name,last_name,email,phone}`,
`items[{id,product_id,sku,name,qty_ordered}]` — **only the lines M5AZN fulfils**. Please tell us if anything is wrong or missing.
