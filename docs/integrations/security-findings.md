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

* **No trusted proxies are configured** (`app/Http/Middleware/TrustProxies.php`). Behind a CDN or reverse proxy the
  application sees the proxy's address: the webhook rate limit becomes one shared bucket and an IP allow-list can never
  match. Set the proxies per environment (see [operations](operations.md#1-deploy-nothing-is-sent-until-you-switch-it-on), item 6).
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
