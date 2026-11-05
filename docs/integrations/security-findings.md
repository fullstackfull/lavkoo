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
