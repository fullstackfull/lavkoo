# Controlled test orders — M5AZN and Torod Point

> **Prepared, not authorised, not run.** This plan sends nothing. Every step that can create something at a provider is behind
> **Gate C** of the [pilot deployment](pilot-deployment.md#8-controlled-test-orders): your written authorisation, per provider
> (template in [live-verification](live-verification/README.md#authorization-template-copy-into-the-ticket--email-before-any-level-2-call)).
> Until you give it, `FULFILLMENT_EXTERNAL_WRITES_ENABLED` stays `false` and the transport refuses every write.

A test order is a **real order**: the provider's warehouse or supplier will pick it and a courier may ship it. Neither provider
gives us a way to undo one from here (M5AZN documents no cancel endpoint; Torod documents no warehouse-order cancel, only a
package cancel once the warehouse has made the package). So the plan is built around two things: **read the request before it can be
sent**, and **send exactly one order at a time**.

## What the tests prove (and why the go-live checklist asks for them)

| Proof | Why nothing else can establish it |
|---|---|
| The provider **accepts** our order payload and answers with a reference we can read | neither provider documents the order-creation response; the read-only run cannot create an order |
| We **track** the order: a status update arrives (webhook or poll), is matched to the shipment and mapped | the status vocabulary of M5AZN is undocumented; Torod's webhook is unsigned and first-sight |
| The money we send is right: prepaid, or the exact cash to collect | an error here costs real money |
| **No duplicate**: pressing *Release* twice, reloading, or a timeout does not create a second order | the provider's dashboard is the only independent witness |

These are exactly the two checklist items that block turning automatic sending on: *One real order accepted by the provider* and
*A status update received and understood*.

## Before the first order (all of it, per provider)

* Phases 0–7 of the pilot deployment are done: credentials saved, `Test connection` passes, a read-only verification has **passed** in the
  last 14 days, every field map is **verified** (M5AZN: also the status map).
* **The provider knows.** Tell your M5AZN / LogesTechs contact, in writing, that a test order is coming: how to recognise it, the time
  window, what to do with it (ship it to the staff address, or hold and cancel it, and how). The order is recognised differently at
  each provider:

  | Provider | Identified by | Where it shows |
  |---|---|---|
  | M5AZN | our **numeric order id** (the `id` field of the request). M5AZN's request has no invoice-number field, so `{order id}-MZ1`, our own reference for the shipment, never reaches M5AZN | M5AZN's order list, by that number |
  | Torod Point | `invoiceNumber` = **`{order id}-TP1`** | the warehouse order list, by that invoice number |

  Ask LogesTechs whether a **sandbox company** exists; if it does, use it.
* **The test product** exists at the provider with stock, is cheap, is not a controlled medicine, has no expiry blocking and has an
  approved mapping:
  * *M5AZN:* import it through the [import workflow](m5azn-import-workflow.md) (it arrives unpublished, assigned to M5AZN, with an
    approved mapping), then publish it for the window.
  * *Torod Point:* turn **Read warehouse products** on (without it the next button does nothing), then **Sync warehouse products
    now**, approve the match on *Product matching* (SKU or barcode is only a suggestion), tick *Also assign this product to Torod
    Point*. Also **Sync cities now**: the test address's city must resolve to a Torod city.
  * *Torod Point only:* **webhooks are set up** (*Generate webhook secret*, *Accept webhooks* on, both URLs given to LogesTechs, a
    delivery confirmed — [operations §3](operations.md)). A Torod package barcode is learned only from a webhook event, so without
    it step 8 can never pass.
  * Only the test products are assigned to the provider. Everything else stays in-house.
* **A staff customer account**, with a valid Saudi mobile (`05XXXXXXXX`) and a delivery address in a city the provider serves, that
  belong to a person who will receive the parcel or can refuse it.
* A fresh **database backup** (`bash "$PKG/tools/backup.sh" --app="$APP" --to="$BACKUPS/data" --db-only`) and the rollback table of the
  runbook within reach.
* You know **how the queue runs** (phase 0). Whatever it is, *Release and send now* / *Approve and send this order* run inside your own
  request: the browser asks you to confirm, the page waits for the provider's answer (about 20 seconds at most per call) and then
  tells you what it came to. **After every `.env` change below run `php artisan queue:restart`** and confirm the worker restarted: a
  worker that started earlier keeps the old values.
* Nobody is running a promotion on the test product. A real customer who buys it during the window creates a real order that waits for
  approval; that is safe, but it is a real order, so keep the window short.

## The sequence (one provider, one order)

Each step says what to look at and what ends the test.

| # | Do | Look at | Pass | Stop and… |
|---|---|---|---|---|
| 1 | Provider page: *Send orders to this provider* **on**, **Dry run on**, *Send automatically (no approval per order)* **off**. `FULFILLMENT_EXTERNAL_WRITES_ENABLED` still `false`. | the mode reads READ-ONLY; the checklist shows *Sending to providers allowed on this server: not met* (expected) | as stated | — |
| 2 | Place the order as the staff customer (quantity 1) and **pay it** for real by a method the store offers (an order is eligible when it is paid, or cash on delivery). In the admin, take it to a status your provider settings dispatch on (default *Confirmed* or *Processing*). | *Provider shipments* on the order: the shipment appears within a minute | status **Dry run (not sent)** — with writes off, because a dry run sends nothing | a **Held** status with a reason (city, phone, mapping, currency, *The provider is not fully configured*): fix the cause. Most are released by the sweep once the cause is gone; otherwise press the release button: **Record the dry run again** while *Dry run* is on (it re-checks everything and records the request again, sending nothing), **Release and send now** once it is off. (*Approve and send this order* is the button only for a shipment that is *Waiting for an operator to approve*.) A money hold (*mixed cash on delivery*, *currency unconvertible*) cannot be released by a person: fix its cause |
| 3 | Open the shipment. Read **Request recorded by the dry run**. | M5AZN: `id` = the order number; Torod Point: `invoiceNumber` `…-TP1`; product and SKU ids exactly as the provider knows them; quantity 1; amounts; city; the cash-on-delivery amount (0 when prepaid); no line of another provider | the request matches what the provider documents and what you ordered | anything unexpected: **do not go on**. Fix the data or the mapping and place a new order |
| 4 | `FULFILLMENT_EXTERNAL_WRITES_ENABLED=true` in `.env`, then `php artisan queue:restart`; provider page: **Dry run off**; *Save settings*. | the mode reads **LIVE — writes to providers are allowed (the provider switches decide)**; the shipment is still *Dry run (not sent)* | nothing was sent | if the shipment changed status, a switch is wrong: set writes back to `false` and `queue:restart` |
| 5 | On the shipment, press **Release and send now** **once** and confirm the question. | a green message "The provider accepted the order"; the status is *Accepted by provider*; the **provider reference** appears; the *recent API calls* card shows one create call with the HTTP status | one call, `2xx`, a reference | see *When the result is not clean* below. An amber message "The provider may have received the order, but its answer did not arrive" means **do not send again**. **Never press Release a second time on a shipment that was sent.** |
| 6 | In the provider's own dashboard, find the order: M5AZN by our **order number**, Torod Point by the **invoice number**. | exactly **one** order; the lines, quantities and the address match; (Torod) the order is in the warehouse list | one order | a second order, or none although we show a reference: stop, tell us, do not retry |
| 7 | Read the API log row of the create call. Fill in **Where the provider order reference is in its response** (the path of the reference). | the redacted response | the path resolves to the reference we stored | — |
| 8 | Wait for the first status update. M5AZN: no webhook exists, so the scheduler asks every 15 minutes and each open shipment is asked at most every 30 minutes (the first useful word may take longer); Torod: **only the webhook can start it**: the Fulfillment webhook (`CREATED`, `PICKED`, `PACKED`, display only), then, once the warehouse has made the package, the Lastmile webhook, after which the package can also be polled. Without the webhook registered (see *Before the first order*) nothing ever arrives. | the shipment's *Status history*: a `webhook` or `poll` row, outcome *applied*, a mapped status | at least one **understood** update | an *unmapped* word: add it to the status map (M5AZN) and wait for the next poll. A silent shipment after 48 h: *System checks → shipment tracking* flags it |
| 9 | Follow the parcel to delivery (or to the refusal you arranged). | the parent order's status; tracking link; (Torod) the package barcode appears | delivered, or returned as agreed | — |
| 10 | Set `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false`, run `php artisan queue:restart`. Re-run the read-only checks (*Live verification (read-only)*). | the mode reads READ-ONLY again; the checklist | *One real order accepted by the provider* and *A status update received and understood* now **met** | — |

Turn **Send automatically** on only after step 10 — and only through [Gate D](pilot-deployment.md#9-turning-automatic-sending-on).

### What the recorded request cannot tell you

* The request is **built again** from the order, the mapping, the city and the provider settings at the moment you release it, and
  money is re-checked then. The fingerprint is displayed for comparison, not enforced. So review and release **back to back**, and
  place a new rehearsal order after changing the mapping, the city, a provider setting or the order itself.
* **Phone numbers and e-mail addresses are masked** in what is stored, so the format of the phone (`00966…` or local) cannot be read
  there. Compare it with the phone on the order page, and with the *Receiver phone format* setting of the Torod page.
* **Neither provider's request has a currency field.** Amounts are converted to the provider's currency (`FULFILLMENT_CURRENCY`,
  `SAR`) before they are sent; an order that cannot be converted is held *currency unconvertible* instead of being sent with a wrong
  figure. The shipment card shows the currency and the amounts.

## When the result is not clean

| You see | It means | Do — and do not |
|---|---|---|
| **Failed**, with a provider message | the provider **refused** the order: nothing was created | read the message; fix the data or the mapping; *Release* again (that resets the attempts). The reason is recorded |
| **Waiting to retry** | a transient failure that is **known** not to have created an order (login refused, rate limit, no connection) | wait. Because *Send automatically* is off in this phase, the retry does **not** send by itself: after the back-off (1, 5, 15 minutes, then 1 hour) the shipment returns to *Waiting for an operator to approve sending this order*. Pressing **Approve and send this order** once more is then correct: nothing was created, so it cannot duplicate. A fifth failed attempt ends as *Failed* |
| **Outcome unknown — checking** | the request may or may not have reached the provider (a timeout, a `2xx` with no readable reference, or a redirect: redirects are never followed, and a provider that redirects after a create has usually created the order) | **do not Release.** The system looks the order up at the provider by our invoice number. If the provider dashboard shows the order: *Link an order that already exists at the provider*, with its reference and what you checked. If it shows none: *Confirm the provider has no such order*, with what you checked — **this sends the order again at once, exactly as Release would, and cannot be undone from here** (the browser asks you to confirm; check the dashboard twice first). Both need a written reason |
| **Needs attention** · *duplicate provider reference* | the provider accepted the order but returned a reference another shipment already carries | compare both in the provider's dashboard; do not send anything else until it is explained |
| **Held** · *Waiting for an operator to approve* | working as intended: automatic sending is off | nothing; that is the approval gate |
| The provider dashboard shows an order we have no reference for | an ambiguous send that did reach the provider | treat as *Outcome unknown* above; tell us, with the invoice number |

Cancelling a test order that has been accepted:

* **M5AZN:** there is no cancel call. Cancel through your M5AZN contact or dashboard; then press **Mark as cancelled by hand** on the
  shipment (a reason is required).
* **Torod Point:** before the warehouse has made a package no cancel exists: cancel in the Torod Point dashboard, then **Mark as cancelled by
  hand**. After the package exists, **Cancel at the provider** calls the Lastmile cancel; it becomes final only when Torod reports the
  package cancelled.

## Further tests, in this order, only after the first one passed

1. **Cash on delivery** (single provider): the cash to collect on the shipment equals the order total minus anything already paid; the
   provider's dashboard shows the same amount; ask the provider how and when the cash is settled. Tick *Cash-on-delivery settlement verified* on the
   provider page only when they have confirmed in writing.
2. **A mixed basket** (one product from each provider, prepaid): two shipments, each containing only its own lines (the provider's
   dashboard is the witness); the customer saw the notice *"يحتوي طلبك على منتجات من مستودعات مختلفة…"* before paying; cash on delivery
   is not offered for that basket.
3. **A refused path with no provider involved** (safe at any level): an address in a city the provider does not serve, or a phone that is not a
   Saudi mobile. The shipment must be **Held** with that reason and nothing sent.
4. **Cancel before acceptance:** cancel the parent order while the shipment is *Dry run* or *Held*. No provider call is made; the shipment
   is closed. (After acceptance, with writes on, cancelling the store order also asks Torod Point to cancel the accepted package by
   itself, provided the package barcode is known (it comes in a status webhook), the Lastmile credentials are saved and the provider is switched on; see *Cancellations* under the armed table of the runbook. M5AZN has no cancel call, so you are told to cancel in its dashboard.)

## What to keep

* The shipment ids and our invoice numbers, the provider's order references, and a screenshot of the provider's dashboard showing the
  order (staff data only).
* The **API log rows** of the create call (credentials and contact details removed, the person's details hidden by key) and the
  **evidence JSON** of the verification run before and after. Read them before they leave the server: neither can recognise a name
  or an address in free text.
* The path you wrote into *Where the provider order reference is in its response*, and any status words you added to the map. Add the
  reviewed evidence to the repository under `docs/integrations/recorded-schemas/<provider>/` by pull request.

## Not allowed, whatever the circumstances

* Using a real customer's order as the test.
* Turning **Send automatically** on during a test.
* Pressing **Release** on a shipment that is **Outcome unknown**, or a second time on a shipment that was already sent (a shipment
  that is *Waiting for an operator to approve* again after a known-not-sent failure is the one exception: see above).
* Leaving `FULFILLMENT_EXTERNAL_WRITES_ENABLED=true` after the window.
* Setting the Lastmile *create shipment* call to be used for Torod orders: the warehouse already creates the shipment, and a second one
  would ship the parcel twice. It is not implemented and must not be.
