# Security findings

Findings from the production-readiness review. "Pre-existing" means present on the base commit `34dce65`,
before the fulfillment integration.

## SEC-1 — Unauthenticated admin routes (pre-existing, CRITICAL) — FIXED

`routes/admin/routes.php` registered twelve routes in a bare `Route::prefix('admin')` group that carried **no
authentication middleware**, and none of the four controllers checks authentication itself:

| Routes | Controller |
|---|---|
| `GET /admin/otp/settings`, `PUT /admin/zender/settings`, `POST /admin/zender/test`, `POST /admin/zender/toggle` | `ZenderSettingController` (Lynomia/Zender OTP gateway) |
| `GET /admin/integrations/manual-message`, `POST …/check`, `POST …/send` | `ManualMessageController` |
| `GET /admin/integrations/cart-marketing`, `POST …/send` | `CartMarketingController` |
| `GET /admin/app-carousel`, `POST …/update`, `POST …/delete-image` | `AppCarouselController` |

**What a stranger on the internet could do**

1. **Steal the OTP-gateway API secret.** `PUT /admin/zender/settings` accepts any `base_url`; `POST /admin/zender/test`
   then calls that URL with the stored (decrypted) API secret. Point the base URL at a server you control and the
   store's secret arrives in your request log.
2. **Intercept or forge OTP / login messages**, change the OTP message template (phishing text in a trusted sender),
   or switch the gateway off (customers can no longer verify their phone).
3. **Send arbitrary messages to arbitrary phone numbers through the store's messaging account** (`manual-message/send`),
   and trigger bulk "abandoned cart" campaigns (`cart-marketing/send`) — spam under the store's name, at its cost,
   with reputation and number-ban risk.
4. Upload or delete the mobile app's home-screen carousel images.

**Fix.** The group now carries the same guard as every other admin route (`admin`, `actch:admin_panel`) plus a module
permission: `3rd_party_setup` for the OTP gateway, manual message and cart marketing; `marketing` for the app carousel.
The `POST /admin/zender/toggle` route pointed at a controller method that did not exist (a 500 even for a signed-in
admin); the method now exists.

**Proof.** `tests/Feature/Security/AdminRouteAuthenticationTest` (a) walks *every* registered route and fails if any
`/admin/*` route or `Admin\*` controller route lacks the `admin` middleware (only the sign-in screens are exempt),
(b) shows a stranger gets a 404 from each of the twelve routes and that nothing is sent over HTTP and no setting
changes, (c) shows an admin without the module is turned away and one with it gets in, (d) shows the stored secret is
never rendered back. The same test **fails against the old routes file** (4 failures) and passes against the fix.

**What you must also do (we cannot see production).**
* Treat the **Lynomia/Zender API secret as compromised if the site was reachable from the internet**: rotate it with
  the gateway provider, then enter the new one in *Third-party setup → SMS*.
* Check the stored gateway **base URL** and **OTP message template** are what you expect.
* Review the gateway account's sent-message log for messages nobody on the team sent.
* Search the web-server access logs for requests to `/admin/zender/`, `/admin/otp/settings`,
  `/admin/integrations/manual-message`, `/admin/integrations/cart-marketing` and `/admin/app-carousel` from addresses
  that are not yours.

Other route families were audited the same way (vendor panel, seller API, delivery-man API): no further unguarded
non-public route was found. The seller API `auth/*` and `registration` endpoints are public by design and throttled.

## SEC-2 — A real-looking `APP_KEY` is committed in `.env.example` (pre-existing, HIGH until confirmed)

`.env.example` contains `APP_KEY=base64:…`. If **production uses that same key**, anyone with the repository can
decrypt session/cookie payloads and every value encrypted with it (including the provider credentials this feature
stores in `integration_settings`, and the Zender secret). **Confirm production's key is different.** If it is not,
generate a new key, expect all users to be signed out, and re-enter every encrypted credential (Zender, M5AZN,
Torod Point) afterwards. CI uses the example key for throwaway test databases only.

## SEC-3 — Webhook secret travels in the URL (design constraint, MEDIUM)

LogesTechs webhooks are unsigned and carry no custom header, so authentication is the secret inside the URL
(`/api/fulfillment-webhooks/torod-point/{secret}/{type}`). The route is registered outside the `api` middleware
group so the platform's request telemetry never records it, and the secret is compared in constant time. The URL does
however appear in **web-server access logs and any reverse proxy / WAF log**. Mitigate: restrict who can read those
logs, rotate the secret on staff changes or suspicion (*Rotate webhook secret*), and set the optional IP allow-list as
soon as LogesTechs publishes source addresses. A forged `delivered`/`cancelled` claim is not believed without the
provider's own status call confirming it, which limits what a leaked secret can do.

---

# Findings in the fulfillment integration itself (second review pass)

Three independent reviewers read the checkout, payment, inventory, fulfillment, webhook and order-status changes; every
finding below was **reproduced or proven from the code before it was fixed**, and each fix has a test that fails on the
old behaviour (several were mutation-checked: the fix was reverted and the test confirmed to fail).

## SEC-4 — A forged webhook could mark our shipment delivered with someone else's package (HIGH) — FIXED

The Lastmile webhooks are unsigned (the URL secret is the only credential). A terminal claim ("delivered", "cancelled",
"returned") is confirmed by asking Lastmile for the package's status — but the **barcode asked about came from the payload**,
while the shipment was found by **our invoice number**. A payload pairing our invoice with a barcode of *any* delivered
package of the account therefore confirmed our shipment as delivered, and the foreign barcode was then stored as ours.

**Fix.** Confirmation asks about the package **we** hold for that shipment (a claim for a shipment with no known package
waits); a package barcode is learned only from an event that names our invoice number **and** reports the cash we asked
for; a package whose status call reports different cash does not confirm; an event whose barcode and invoice point at two
different shipments is left unmatched; payload keys are read case-insensitively. **Proof:** `TorodWebhookTest` (five new
cases; the first fails on the old confirm logic: *"applied"* instead of *"received"*).
**Residual:** the first event for a package is trusted on first sight (LogesTechs' webhook carries no signature); ask for
`invoiceNumber` on the Fulfillment webhook, source IPs or a signature ([request](vendor-requests/logestechs-technical-request.md), items 15, 16, 23, 24).

## SEC-5 — Supplier HTML printed to every customer (HIGH) — FIXED

An imported M5AZN description was stored as the product's HTML, which the storefront prints unescaped: a supplier (or anyone
who can edit a supplier listing) could run script in every customer's browser. **Fix:** an allow-list sanitiser
(`HtmlSanitizer`) rebuilds the document from recognised tags and attributes only — no script/style/frame/form/SVG/MathML,
no event handlers, no inline CSS, links limited to http(s)/mailto/tel, images to https; every value is escaped.
**Proof:** `HtmlSanitizerTest` (34 attack strings including entity/tab-obfuscated `javascript:`, `data:` URLs, SVG/MathML,
`srcdoc`, noscript mutation, broken nesting, attribute breakout) plus an importer test. **Residual:** supplier product
*names* are not rewritten (the storefront escapes them); descriptions lose inline styling.

## SEC-6 — Remote image fetching (MEDIUM) — FIXED

Images referenced by a supplier payload are downloaded. SVG was accepted (a stored SVG can carry script that runs when
opened), the file type was taken from the response's claim, the host was resolved twice (DNS rebinding could point the
second lookup inward), and the size cap was applied after the whole body was read. **Fix:** SVG refused; type taken from the
bytes (JPEG/PNG/GIF/WebP); the connection is pinned to the address that passed the public-address check; oversize rejected
on headers. **Proof:** `M5aznImporterTest` (SVG, spoofed type, HTML-as-image, oversize, private addresses).

## SEC-7 — Provider text reached the admin's browser as HTML (MEDIUM) — FIXED

The toast library prints its message as HTML inside a `<script>` block. Provider error bodies, supplier names and typed
text reached it unescaped (`</script><script>…` breaks out). **Fix:** `SafeToast` escapes every untrusted part; a test
scans every fulfillment admin controller and fails on any toast that carries a variable outside `SafeToast`. The
`confirm()` dialogs on the same pages now embed their text as JSON.

## SEC-8 — Provider credentials copied into the session on a failed save (MEDIUM) — FIXED

A failed validation flashes the submitted input to the session store. The list of fields never to flash lived in
`App\Exceptions\Handler`, **which this application does not use** (Laravel 12's `withExceptions` in `bootstrap/app.php` is
in force), so the Fulfillment/Lastmile username, e-mail and password fields would have been written to the session
store. **Fix:** they are excluded where it takes effect. **Proof:** `AdminPagesTest`. **Note:** `App\Exceptions\Handler`
is dead code; its `$dontFlash` list does nothing.

## SEC-9 — A GET that calls a provider; unthrottled provider calls (LOW–MEDIUM) — FIXED

*Download AWB* was a GET that called the provider with our credentials and then redirected to whatever URL came back
(forgeable from another site; an open redirect). It is now a POST (CSRF-protected), redirects only to https hosts on
`amazonaws.com` / `logestechs.com`, and every admin POST that calls a provider is rate limited (a script, a forged form or
a click-happy session cannot hammer a provider's API into locking our account).

## SEC-10 — Smaller defects (LOW) — FIXED

* *Generate webhook secret* **wiped the provider's configured base URL** (the save treated "not supplied" as "clear").
  `null` now means keep; a blank form field means "use the default".
* The webhook IP allow-list accepted only exact addresses and silently accepted garbage; it now accepts CIDR ranges and
  IPv6 and rejects malformed entries.
* The unmatched-event retry always looked at the oldest 200 events, so old noise could starve a fresh event; newest first.
* A variant with no price was imported with price 0 and stock, i.e. **sellable for nothing**; it is imported out of stock
  and stock sync keeps it so.
* The fulfillment-status endpoint compared a guest id with a numeric column (see SEC-11).

## SEC-11 — MySQL reads text as 0 in a numeric comparison (MEDIUM, found by the first real-MySQL run) — FIXED in our code

`config/database.php` runs MySQL with `'strict' => false`. Comparing a numeric column with the text `abc` is then a
comparison with `0`, so `GET …/order/fulfillments?order_id=…&guest_id=abc` would match an order stored with
`customer_id = 0` (a walk-in sale). A guest id must now be a positive integer. **Pre-existing, not changed:** the platform's
own guest endpoints (`track_order_details_history`, `getOrderById`, the cart queries) use the same comparison. They are
outside this change; whoever owns them should validate guest ids the same way.

## SEC-12 — Environment facts to know (INFO)

* **No proxy is trusted unless `TRUSTED_PROXIES` is set.** `bootstrap/app.php` wires the framework's `TrustProxies`
  middleware, which reads `config/trustedproxy.php`; `app/Http/Middleware/TrustProxies.php` is never used, so editing it has
  no effect. Behind a CDN or reverse proxy the application then sees the proxy's address: the webhook rate limit becomes one
  shared bucket and an IP allow-list can never match. Set `TRUSTED_PROXIES` (see [operations](operations.md#1-deploy-nothing-is-sent-until-you-switch-it-on), item 6);
  *Integration Center → System checks → client address* tells you whether it worked.
* `App\Exceptions\Handler` is unused (dead code); `app/Http/Controllers/DeliverySyriaWebhookController.php` (root
  namespace) is not routed — the routed one is `Api\DeliverySyriaWebhookController`.
* Webhook secrets in URL paths appear in web-server access logs (SEC-3).

## Not changed, deliberately (non-blockers)

| Item | Why it is left | Mitigation |
|---|---|---|
| The first webhook for a package is trusted on first sight | No signature, no `invoiceNumber` on the Fulfillment webhook | Invoice + cash cross-check; terminal claims verified; vendor request items 15, 16, 23, 24 |
| Torod's order reference is read through an optional configured path, otherwise from a short list of likely fields | The Add Order response is undocumented | A 2xx without a readable reference is *outcome unknown* and never re-sent; the path is set from the T-9 sample ([checklist](live-verification/torod-point.md)) |
| Field-map screens accept any path; sample payloads are shown to admins | Admin-only, `3rd_party_setup` module | Samples are redacted before storage/display; numeric bounds are applied where values are used |
| Supplier product names are stored as given | Escaped at output by the storefront | Descriptions (the HTML-bearing field) are sanitised |

---

# Findings from preparing the pilot deployment (second look at the platform, not at the integration)

These are properties of the existing platform found while working out how to deploy safely onto it. None is used by the
integration and none is fixed here (they are outside the change), but each affects how the pilot is run, and each needs an owner decision.

## SEC-13 — The whole application directory may be web-served (pre-existing, HIGH until checked)

`index.php` at the repository root defines `DOMAIN_POINTED_DIRECTORY = 'root'` and the root `.htaccess` hands any existing file to
the browser; it only denies `.env`. If the site's document root is the application root rather than `public/`, then
`storage/logs/laravel.log`, `storage/app/**` (KYC documents), `composer.json`, `.git/` and
`public/assets/back-end/Apple-AuthKey.p8` and `public/assets/back-end/Apple-AuthKey (copy).txt` are downloadable by anyone who asks for their address.
**Check** with the loop in [pilot-deployment §0](pilot-deployment.md#0-look-at-the-server-first-read-only); every path must answer 403 or 404.
**Fix** by pointing the document root at `public/`, or by denying everything except `index.php`, `public/` and `storage/app/public`.
What this project does about it: backups and the deployment package are kept **outside** the tree (the scripts refuse a
destination inside it however the path is written, and the runbook has you name a directory outside every document root, because
the scripts cannot know where the web server's root is), and the verification-evidence directory ships an `.htaccess` that denies
direct access (Apache).

## SEC-14 — One-time passwords are constant (pre-existing, HIGH)

`config('app.mode')` is never defined, so the code paths that check for `'live'` are dead and every verification code is `123456`
(six digits) or `1234` (four). Anyone who knows a customer's phone number can pass phone verification. Unrelated to this integration;
needs a fix by whoever owns the authentication code, or a conscious decision to accept it.

## SEC-15 — Secrets committed to the repository (pre-existing, rotate whatever is real)

* Four tracked ECDSA private-key files (`Apple-AuthKey.p8` and `Apple-AuthKey (copy).txt`, each under `public/assets/back-end/` and
  `resources/assets/back-end/`); the two under `public/` are web-served.
* The licence purchase key and buyer name in `config/system-addons.php`, which the platform rewrites after each licence check.
* Three public maintenance routes (`/diag-write-xk29`, `/find-img-xk29`, `/run-fix-xk29`) guarded only by literal query keys in
  `routes/web/routes.php`: they write test files, chmod `storage/app/public` recursively, search the project tree and run `storage:link`.
* A Firebase web `apiKey` in `firebase-messaging-sw.js` (public by design for Firebase web, but part of the project's identity).
* `POST /activation-check` is unauthenticated and rewrites licence state.

This project adds a pattern check (`scripts/ci/leak-guard.php`, run by CI) as a net against **new** leaks of this kind. It reads every
line each commit of a change adds, and the net difference, and refuses a private key, a recognisable token or JWT, credentials in a
URL, a secret-named key with a secret-looking value (quoted or in a configuration file), an environment file, key material, a database
dump or archive, a binary it cannot read, or what looks like a real person's phone number or e-mail address. A secret added and removed
again in the same change is still reported: it is in the pushed history and has to be rotated. It never prints what it matched, treats
test fixtures and language files as made-up data (but never real keys), and accepts a false alarm only through a reasoned entry in
`scripts/ci/leak-guard-allow.json`. It is a net, not a promise: it recognises shapes (credentials, keys, tokens, e-mail addresses and
unbroken Saudi mobile numbers), it does **not** see a phone number written with spaces or dots, a national id, coordinates, or a customer's
name or street, and a person reading what is committed remains the control. That matters most for the verification evidence and API-log
rows the runbook suggests keeping under `docs/integrations/recorded-schemas/`: read every example and value before it is committed.

## SEC-16 — Personal data: where this integration keeps it, and where it does not

An earlier revision of this documentation said only masked phone numbers and e-mail addresses were stored, and later that names and
addresses were removed everywhere. Both were too broad. What the code does, and where it stops:

**The rules.** *By pattern, everywhere:* e-mail addresses and phone numbers (a run of nine digits or more, with the separators people use
between them: spaces of every kind, tabs, line breaks, dots, slashes, brackets, every dash; Arabic-Indic and full-width digits; several
numbers one after another, or a number beside an order number, are masked whole; ISO dates are set aside) are masked in any text this
integration stores or logs; credentials (`Bearer` values and `Basic`/`Token`/`Digest` credentials of twelve characters or more that look like one — sixteen or longer, or holding a digit, `+ / =` or a capital letter inside — not the ordinary word after `Basic` in a product name, `Authorization`/`Cookie`/`X-Api-Key`
header lines, `password=`/`api_key=`/`token=` pairs including joined names such as `DB_PASSWORD` or `client_secret`, `?key=` query values,
the user and password in a URL, JWTs, private-key blocks) are removed; control characters are dropped; the scan is bounded (20 KB of
text, bounded patterns) and, if a pattern cannot run, the text is hidden rather than let through. A number glued to letters
(a prefix such as `tel` directly before the digits) is read as a code, like a barcode. *By key:* a value under a key that names a person, a contact detail, an address, a
postal code or an identity (`receiver`, `consignee`, `customer`, `address`, `phone`, `email`, `note`, `national`, `iqama`, `passport`,
`iban`, `zip`, `postal_code`, `lat`, `lng`…) is hidden, and so is **everything nested under** such a key except codes. *Strictly*, for
answers that are about people (sign-in, order list, order detail, package status): only an allow-list survives: strings shaped like
codes (a plain word only if it holds a digit or an underscore, or sits under a key that names a code, an enumeration or a status), a status phrase of up to three words (thirty characters; one enumeration token may be longer) under a key that
ends in *status*, *state* or *stage* (any language), numbers that are too short to be a phone number or a national id (timestamps are
kept), booleans; and keys that name a courier, a rider, an agent, a recipient or end in *name* are hidden whatever the value. The keys
of an object that are themselves a phone number or an e-mail address are not kept either (several such keys stay separate entries).
**What none of it can do** is recognise a name or a street in free text under a key it has no reason to suspect, outside strict
mode; inside strict mode a lone first name is hidden only because a plain word, in capitals too, is not shaped like a code, and a status of
up to three words ("Delivered to Sara") is kept if the provider puts a name in it; longer phrases are hidden because courier statuses often do. A status value that strict mode hides is counted: the
evidence shows `hidden` beside the status words, and the check warns, so a gap in the vocabulary shows as a number. A map keyed by
numbers of nine digits or more (13-digit barcodes) has its keys hidden, because a phone number written without a plus sign looks the same.

| Place | Holds | Why |
|---|---|---|
| `provider_fulfillments`, `provider_fulfillment_items` | order reference, amounts, provider references, status; no name, phone or address column. `last_error_message` is the provider's own error text with credentials, e-mail addresses and phone numbers removed (a name or an address it echoes is **not**). For a shipment that needs attention, `last_error_message` also holds the courier's free-text note (phone numbers and e-mail addresses removed, 250 characters), because it is what the operator has to act on; a name or an address in it is **not** removed. Proof-of-delivery links are **not** kept. The provider's status words are stored scrubbed and cut to their column | these are our own order facts |
| `provider_fulfillments.meta` (a dry run's *payload preview*) | the request that would have been sent: name, address and items as they are on the order, **phone and e-mail masked, also when typed into a note or an address line**, with a keyed fingerprint (an HMAC with `APP_KEY`, so it cannot be used to recover the masked phone). Kept **only while the shipment waits as a dry run**: any way out of that state (released, held, failed, cancelled, accepted, a status from the provider, a claim given back) removes it, and `fulfillment:prune` clears it from any shipment older than the retention (60 days), whatever became of it | shown to the administrator so an order is never approved blind; it is data the order already holds |
| `provider_fulfillment_events.payload` | **only the fields status processing reads** (status, barcode, time, package id, invoice number, cash amount, postponed date, the courier's note scrubbed of phone numbers and e-mail addresses). The receiver, the courier and proof-of-delivery links in the provider's body are not stored. **Cleared after 60 days** by `fulfillment:prune`; the status history stays | the webhook de-duplication ledger and what status processing reads |
| `provider_api_logs` | request and response; for orders, packages and sign-ins **strictly** redacted (see above), for catalogue and reference calls (products, categories, cities) with personal keys hidden but product and city names kept; credentials and tokens removed. Deleted after 60 days | support evidence |
| `storage/app/fulfillment-evidence/` | the **shape** of each provider answer; strict for sign-in, order list, order detail and package status. Built to leave out credentials and personal data, **not guaranteed**: a person reads a file before it is shared. Denied to the web server on Apache only | the record the field maps are finalised from; not tracked by git |
| Log files (`storage/logs`) | exception messages **this integration writes** with credentials, e-mail addresses and phone numbers removed and shortened (`PiiRedactor::scrub`). A fulfillment job that throws, and a person's own send or cancel from the admin pages, let out only the exception's class, where it failed and a scrubbed message, so the queue worker's report, `failed_jobs` and the framework's report are clean too | a provider's error text may echo what was sent |
| `failed_jobs` (the fulfillment queue) | that scrubbed message; deleted after 60 days by `fulfillment:prune` (other queues' rows are not touched) | the queue's own record |
| `integration_settings.credentials` | provider credentials, **encrypted** with `APP_KEY` | see SEC-2 |

What this integration does **not** control: the platform's own debug writers (OTP and phone numbers in `ZenderOtpService`, parcel bodies in
`DeliverySyriaService`, gateway callbacks) and `laravel.log` truncation (hourly for non-local requests). Because this is a pharmacy, order lines
tied to a customer are health-adjacent: backups and exports of the database are personal data and must be handled that way (the backup
scripts keep them outside the web root, mode 700, never in GitHub).
