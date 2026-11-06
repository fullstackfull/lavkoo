# Live API verification — how it is done, and the gate on every write

Everything in this repository was built and tested against **documents and mocked responses**. Neither provider's
response schemas are fully documented, so a number of facts can only be established by talking to the real systems.
These checklists say exactly which calls to make, which evidence to keep, what counts as a pass, and what each
answer unlocks in the integration.

* [M5AZN checklist](m5azn.md) · [Torod Point / LogesTechs checklist](torod-point.md)
* Requests to send to the vendors: [M5AZN](../vendor-requests/m5azn-technical-request.md) ·
  [LogesTechs](../vendor-requests/logestechs-technical-request.md)

## Authorization levels — nothing runs above Level 1 without it in writing

| Level | What it may do | Needs |
|---|---|---|
| **0 — no network** | Everything done so far: unit/feature tests with `Http::fake()`, `Http::preventStrayRequests()`. | nothing |
| **1 — read-only live** | Login, list, details, status reads. No order, product, stock, price or cancel call. | Credentials entered by the store owner in the Integration Center (write-only fields). A named person runs it. |
| **2 — live writes** | Create / cancel a test order, push a product, anything that changes state at the provider. | **Explicit written authorization** (template below) naming provider, environment, the exact calls, the test data, a time window and a rollback. Sandbox if the vendor has one; otherwise a test product/customer and an immediate cancel, with the vendor told in advance so no goods move. |
| **3 — real dispatch** | The integration sends real customer orders. | Go-live decision in the [readiness report](../readiness-report.md) — never as part of verification. |

No Level 2 call is made by this repository, by the tests, by a scheduled job, by `fulfillment:verify`, by the Integration Center's
*Run the read-only checks* or by its *Test connection* button (which perform only the documented login and read calls). The transport
enforces this: with `FULFILLMENT_EXTERNAL_WRITES_ENABLED=false` (the default) no order, product, stock or cancellation request can
leave the server whatever is switched on in the admin panel, and an operation that is not classified as a read is treated as a write.

On the pilot server the levels map to the [pilot deployment](../pilot-deployment.md) like this: **Level 1** is phase 6 (the
integration on, writes off, *Send orders* off, *Dry run* on) and can be run from the provider page or with
`php artisan fulfillment:verify m5azn|torod_point`; **Level 2** is phase 8, the [controlled test orders](../controlled-test-orders.md)
(a dry-run rehearsal, then one order released by a person), and needs the authorisation below; **Level 3** is phase 9.
Between sessions, set `FULFILLMENT_EXTERNAL_WRITES_ENABLED` back to `false`.

### Authorization template (copy into the ticket / email before any Level 2 call)

```
Provider:            M5AZN | Torod Point (LogesTechs, company 714)
Environment:         sandbox | production account (test data only)
Authorized by:       <name, role, date/time, how confirmed>
Run by:              <name>
Window:              <start – end>, store owner on call
Calls (exhaustive):  <e.g. POST /public/fulfillment/order ×1 with invoiceNumber VERIFY-<date>-1; cancel ×1>
Test data:           <product id/SKU, receiver = a staff phone/address, COD amount>
Vendor informed:     <who, when, how — so the warehouse/courier does not act>
Rollback:            <cancel call / vendor contact / manual dashboard step>
Stop conditions:     <any unexpected 2xx/side effect; any real customer data in a response>
Evidence kept at:    <ticket link>
```

## How to capture a sample safely

Use the application, not a script, wherever it can do the call — it redacts secrets and keeps the audit trail:

1. **Integration Center → provider → Field map → *Fetch sample*** performs the provider's documented **read** call and shows
   the raw rows. Choose the path for each logical field, *Verify*; the screen reports what could not be read.
2. **Shipments → API calls** (and *Logs*) shows each request/response the application made, with credentials and tokens
   replaced by `[REDACTED]`. Export from there instead of copying from a terminal.
3. If a script (curl/Postman) is unavoidable: use a throwaway shell history, never paste the token into a ticket, and run
   `jq` to strip personal data before saving.

**Redact before storing or sending anywhere:** passwords, bearer tokens, `Authorization` headers, webhook secrets, phone
numbers, e-mail addresses, street addresses, customer and receiver names. Keep ids, SKUs, status words, field names,
number formats, HTTP status codes and error shapes — those are what the integration needs.

## Evidence record (one per call)

```
Check id:      M-2 / T-5 …
Date/time:     …    Run by: …    Level: 1 | 2    Authorization ref: …
Request:       METHOD host/path  query: …  body: (redacted)
Response:      HTTP …  headers of interest: …  body: (redacted, complete enough to show every field)
Observed:      <facts only>
Pass/fail:     <against the criterion in the checklist>
Setting made:  <field-map path / status map / setting, or "none">
```

## What "verified" means in the application

* A **field map** is *verified* only when an administrator has chosen a path for every required field **and** the screen
  confirmed it against a fresh real sample. Editing a map un-verifies it. Imports, stock sync and order polling refuse
  to run on an unverified map (that is deliberate: nothing guesses a response shape).
* A **status map** (M5AZN) turns the provider's status words into ours. A word that is not in the map changes nothing and
  is shown on the shipment (*unmapped*), so an incomplete map is visible, never silently wrong.
* **Stock** (Torod Point) drives availability only after a verified products map **and** a person ticks *"I confirm the
  stock field is the available quantity"* — and only if LogesTechs has actually said so. Until then stock is manual.

## Stop conditions

Stop the session and report, instead of continuing, if: a response contains customer data that is not ours; a call
returns a success for something that should have been refused; the provider shows a real shipment/pickup created by a
test; any credential appears in a log or a response; the vendor asks you to stop.
