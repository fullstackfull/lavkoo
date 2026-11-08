# MyFatoorah (Saudi Arabia)

Hosted-payment-page integration for the storefront and the KSA customer app, against MyFatoorah's
API v3 on the Saudi production host (`api-sa.myfatoorah.com`) and the shared sandbox
(`apitest.myfatoorah.com`).

Everything below is switched **off** until someone deliberately turns it on. A fresh deployment of this
change offers no new payment method to anybody.

---

## 1. What was wrong before

`fatoorah` appeared in four places in this codebase — `GlobalConstant::GATEWAYS_PAYMENT_METHODS`,
`Library/Constant.php`, the admin validation rules in `PaymentMethodController` and
`PaymentMethodUpdateRequest`, and the supported-currency map in `PaymentGatewayTrait` (KWD, SAR) — and
the route table in `app/Traits/Payment.php` pointed it at `payment/fatoorah/pay`.

None of it worked, for four independent reasons:

| Gate | What it did |
|---|---|
| `GlobalConstant::DEFAULT_PAYMENT_GATEWAYS` did not list `fatoorah` | the admin gateway page lists the intersection of that constant and the rows in `addon_settings`, so no card was ever rendered |
| No row in `addon_settings` with `key_name = 'fatoorah'` | nothing for a card to render even once the constant allowed it |
| `Helpers::getDefaultPaymentGateways()` did not list `fatoorah` | `payment_gateways()` filters on that list, and it feeds **both** the web checkout and the `payment_methods` array the mobile app reads from `/api/v1/config` — so even an enabled gateway would never be offered |
| No controller, no route | `payment/fatoorah/pay` was a 404 |

So it was a **name with no integration**, not a broken integration.

**The key stays `fatoorah`.** It is what every list in the codebase already used and what any historical
`payment_requests.payment_method` value carries. A second key (`my_fatoorah`) would orphan those rows and
put two MyFatoorah cards side by side on the settings page. The customer-facing name lives in
`additional_data.gateway_title` ("MyFatoorah — ماي فاتورة السعودية"), which is what the storefront and the
app display. `payment/myfatoorah/*` and `payment/fatoorah/*` both resolve, so an installed app build or a
queued link pointing at the old path still works.

---

## 2. Turning it on

1. **Environment** — add to `.env` and clear config cache:
   ```
   MYFATOORAH_ENABLED=true
   ```
   This is a deployment-level kill switch. With it off, no outbound MyFatoorah call is made at all —
   not a payment, not even a connection test. Payments **already in flight** are still verified
   (`MYFATOORAH_VERIFY_WHEN_DISABLED`, on by default), so switching it off cannot strand money.

2. **Admin panel** — *Payment & Withdraw Methods Setup → MyFatoorah* (the gear on the MyFatoorah card,
   or `/admin/third-party/payment-method/myfatoorah`).

   - Pick **Sandbox** or **Live**.
   - Paste the **Test API Key** and/or **Live API Key**. Create these in the MyFatoorah portal under
     *Integration Settings → API Key*. Each country on a multi-country account has its own token; use
     the Saudi one for live.
   - Paste the **Webhook Secret Key** for the mode you are using (portal → *Webhook*, enable the
     secure key).
   - Press **Test sandbox connection** / **Test live connection**. This is a read
     (`GET /v3/payment-methods`) — it proves the key works and lists the methods your account actually
     has enabled, without creating a test invoice.
   - Optionally tick which methods the hosted page should show. Leave all unticked to show everything
     the account allows, which is usually what you want.
   - **Save.**

3. **Switch the gateway on** — the on/off toggle is on the normal payment-gateway card, with every
   other gateway. The store-wide *Digital payment* switch (Business Information) must also be on.

Keys are stored `Crypt::encryptString`-encrypted in the `addon_settings` row. They are **never rendered
back** — not masked, not truncated. An empty key field on save means "keep the stored one", which is the
normal case; the page says so.

The page shows, at the top, a single sentence saying whether the gateway is taking payments and, if not,
exactly which of the above is missing.

---

## 3. Webhook

Register this URL in the MyFatoorah portal (*Integration Settings → Webhook*), and enable the
**secure key**:

```
https://pharmacyksa.com/api/myfatoorah/webhook
```

The admin page shows it with a copy button. The same URL is also sent with every invoice as
`IntegrationUrls.Webhook`, so notifications work even before anyone pastes it into the portal.

Subscribe at least to **PAYMENT_STATUS_CHANGED**.

Authentication is the `MyFatoorah-Signature` header: HMAC-SHA256 of a documented five-field string,
keyed with the portal secret as raw binary, base64-encoded. An unsigned or wrongly signed delivery gets
a flat `401`.

Two things to know:

- **The amount is not signed.** MyFatoorah signs only `Invoice.Id`, `Invoice.Status`,
  `Transaction.Status`, `Transaction.PaymentId` and `Invoice.ExternalIdentifier`. A valid signature
  proves the notification is MyFatoorah's and nothing about how much was paid — which is why a verified
  webhook still only triggers a server-to-server read, never a decision.
- **Only PAYMENT_STATUS_CHANGED can be verified.** MyFatoorah publishes the signed-field order for that
  event only. Other event types are recorded and used purely as a prompt to re-read the API; their
  contents are never believed.

`MYFATOORAH_WEBHOOK_REQUIRE_SIGNATURE=false` exists only to debug a sandbox. The admin page shows a red
warning while it is off. It must be `true` in production.

---

## 4. How a payment is decided

The only thing allowed to declare a payment real is `PaymentVerificationService`, over a
server-to-server `GET /v3/payments/{paymentId}` authenticated with our own API key.

Four things must all agree before an attempt becomes `paid`:

1. `Data.Invoice.Status` is `PAID`;
2. the amount matches what was recorded when the attempt was created, to the halala;
3. the currency matches (SAR);
4. `Data.Invoice.ExternalIdentifier` is **this** attempt's reference.

If (1) holds but any of (2)–(4) does not, the status becomes `mismatch` — never `paid`, never `failed`.
Real money has moved and a human has to look. The admin page shows these in red at the top.

A network failure, a timeout or a rejected API key is **`unknown`**, not a failure. Nothing in this
integration runs the failure hook because a call did not come back — that is how a network blip cancels
an order a customer has paid for.

Four independent things can learn that a payment succeeded — the customer's redirect, the webhook, the
app polling, and the reconciliation sweep — and all four funnel into `PaymentSettlementService::settle()`.

### Exactly once

- `settle()` refuses anything not already verified as `paid`.
- The right to run the success hook is claimed in a transaction that locks the attempt row and the
  `payment_requests` row, with a **conditional** `UPDATE ... WHERE is_paid = 0`. Several simultaneous
  deliveries all reach it; one affects a row and the rest affect none. A database guarantee, not a
  check-then-act, so it holds across processes.
- The hook runs **outside** that transaction, because it creates an order, decrements stock and books a
  shipment with a courier — work a rollback cannot undo. Before it runs, the store is asked whether an
  order already carries this transaction reference (`orders.transaction_ref`); if one does, the hook
  already did its job on an earlier interrupted run and is not called again.
- `hook_completed_at` records that it finished. An attempt with `settled_at` set and
  `hook_completed_at` null is an **interrupted settlement** — the one state that can take the money and
  lose the order. It is queryable by exactly that pair, counted on the admin page, and finished by
  `recover()`.

---

## 5. Reconciliation

```bash
php artisan myfatoorah:reconcile
```

Suggested cron (**not added for you** — changing cron needs your approval):

```
*/10 * * * * cd /path/to/app && php artisan myfatoorah:reconcile >> storage/logs/myfatoorah-reconcile.log 2>&1
```

There is also a **Reconcile now** button on the admin page.

It asks MyFatoorah about every in-flight attempt and settles the ones that are paid, through the same
verify-then-settle path a webhook uses — so a payment it recovers cannot produce a second order for one a
webhook already recovered. It is bounded (batch size, a minimum interval before re-asking, a pause
between calls) because MyFatoorah documents no rate limits.

It is safe to run concurrently with itself and with live webhooks.

---

## 6. Refunds

A MyFatoorah refund is a **request**: `POST /v3/refunds` answers with status `Pending` and their finance
team executes it after review. So:

- `requested` / `pending` mean the money has **not** moved yet;
- only `executed` means it has.

Nothing in the store tells a customer they have been refunded until `executed`, and an operator sets that
after confirming it in the MyFatoorah portal (**Mark as executed** on the admin page).

Guarded against:

- refunding more than was paid — the total already requested is summed under a row lock on the attempt,
  in the same transaction as the insert, so two operators clicking at once cannot each pass a check the
  other invalidates. Requests still under review count against the paid amount.
- refunding twice for one decision — `external_identifier` is unique and derived from the attempt plus an
  idempotency key, so a double-submitted form collides in the database instead of sending two refunds.
- an unknown outcome — if the call times out, the row stays `requested`, **not** failed, so nobody can
  send a second refund for the same money. It must be checked in the portal before retrying.

**A refund never returns anything to stock.** Money coming back is not evidence that goods did; the
store's existing returns workflow owns that.

---

## 7. The mobile app

The app needed a code change, released once. After that, enabling and disabling the gateway is entirely
dynamic from the admin panel.

**Appearance is already dynamic and needed no new code**: the app renders
`configModel.paymentMethods` from `/api/v1/config`, which is driven by `payment_gateways()`. Adding
`fatoorah` to `Helpers::getDefaultPaymentGateways()` is what makes it appear, with the title and logo
from `additional_data`. No API key or secret is ever sent to the app — `ConfigController` already strips
`live_values`/`test_values`.

What did change in the app:

| File | Why |
|---|---|
| `lib/features/checkout/domain/services/myfatoorah_payment_service.dart` | new: asks the server for the real outcome, with backoff |
| `lib/features/checkout/domain/models/myfatoorah_payment_status.dart` | new: paid / definitely-failed / not-known-yet |
| `lib/features/checkout/screens/digital_payment_order_place_screen.dart` | stops reading the outcome out of the redirect URL; hands wallet/bank app schemes to the OS |
| `lib/features/splash/controllers/splash_controller.dart` | `refreshPaymentMethods()` — re-reads just the payment options |
| `lib/features/checkout/screens/checkout_screen.dart` | calls it when checkout opens, so a gateway switched off stops appearing |
| `lib/utill/app_constants.dart`, `assets/language/*.json` | the status endpoint and six new strings (Arabic included) |

**The bug this fixes.** The screen decided a payment had succeeded because the redirect it landed on
contained the substring `success`:

```dart
bool _isRedirectUrl(String url) {
  return ((url.contains('success') && url.contains('token')) || url.contains('fail') ...
```

That is wrong whenever the customer loses connectivity on the way back from the bank, and forgeable by
anything that can put a URL in front of the WebView. For MyFatoorah the redirect is now treated as
nothing more than "the customer is back": the app takes the reference off the callback URL and asks

```
GET /api/v1/payment/myfatoorah/status?reference=<reference>
```

until `is_final`. Three outcomes, and the third is the point:

- `paid` — and only once the order actually exists. Only then does the app show "order placed".
- `failed` / `cancelled` / `expired` — a definite answer from MyFatoorah.
- `pending` / `confirming` — we do not know yet. The app shows **"we are confirming your payment, do
  not pay again"**, never a failure. Telling someone whose money has gone that their payment failed is
  the worst thing this screen can do, and it is what every naive implementation does on a timeout.

Closing the app, backing out, or losing the network during the return are all safe: the server finishes
the job from the webhook or the sweep, and the order does not depend on the screen staying open.

Other gateways are untouched — `_isRedirectUrl` returns early only for MyFatoorah, so their behaviour is
byte-for-byte what it was.

### Building the app

```bash
flutter pub get
flutter analyze
flutter build appbundle --release    # Android
flutter build ipa --release          # iOS
```

No new Flutter dependency was added. `flutter_inappwebview`, `url_launcher` and `webview_flutter` were
already in `pubspec.yaml`.

---

## 8. What still needs real credentials or a live account

These could not be exercised here and must be confirmed on the real account:

1. **A real payment end to end.** Every test uses faked HTTP. Passing tests prove the logic, not that
   money moves. Do one sandbox payment per method you have enabled, then one small live one.
2. **Which methods your account actually has.** mada, Visa, Mastercard, Apple Pay, STC Pay and Google Pay
   are each enabled per account. Press *Refresh payment methods* and check the list — nothing here
   hard-codes method ids from the documentation's examples.
3. **The webhook signature against a real delivery.** The algorithm is implemented from the documented
   scheme and its worked example, and is unit-tested against that example, but no real delivery has been
   seen. Send a sandbox payment and confirm the delivery is accepted (admin page → *Recent webhook
   deliveries*, outcome `settled`). **If real deliveries are rejected, do not switch signature checking
   off in production** — capture one and compare.
4. **Which amount field is authoritative.** MyFatoorah reports a payment in up to three currencies
   (base, display, pay) and does not document which is the captured one. On a Saudi account charged in
   SAR all three should read SAR and agree; the verifier looks for the first pairing actually in the
   currency we charged and flags a mismatch otherwise. Confirm against a sandbox payment.
5. **The invoice-lookup endpoint.** Reconciliation can only recover a payment whose id nothing ever told
   us (customer closed the tab *and* the webhook was lost) by asking the invoice who paid it.
   MyFatoorah's reference lists "Get Invoice by InvoiceId" and "Get Invoice by ExternalIdentifier" under
   v3 but **does not publish their literal paths**, so `myfatoorah.endpoints.invoice_details` and
   `invoice_by_reference` are a best reading of their scheme and are configurable for that reason. If an
   account answers 404, that case is reported as needing a person (`last_error_code = no_payment_id`)
   rather than guessed at — nothing is ever settled on the strength of a path we are unsure of. Confirm
   both paths with MyFatoorah support and correct them in `.env` if needed.
6. **Refund permissions.** `POST /v3/refunds` needs the refund permission on the API key. The v2
   `/v2/MakeRefund` path is kept in config as a fallback for an account only permitted that one.
7. **Apple Pay** needs the domain registering with MyFatoorah (`RegisterApplePayDomain`) and is subject
   to their own onboarding. Not exercised.
8. **3-D Secure, STC Pay and Apple Pay app hand-offs on real devices.** The app now hands non-`http(s)`
   schemes to the OS, which is what those flows need, but this was not run on a physical device — there
   is no Flutter SDK in the build environment used for this change, so the Dart was not compiled or
   analysed either. **Run `flutter analyze` and a device test before shipping the app.**

---

## 9. Deploying, verifying, rolling back

### Deploy

1. Merge the backend PR. **Do not** run migrations on the server without approval; when approved:
   ```bash
   php artisan migrate   # four migrations, all additive
   php artisan optimize:clear
   ```
   Three new tables (`myfatoorah_payment_attempts`, `myfatoorah_webhook_events`,
   `myfatoorah_refunds`) and one inactive row in `addon_settings`. Nothing existing is altered or
   dropped.
2. `MYFATOORAH_ENABLED=true`, then configure and test in the admin panel as in §2.
3. Register the webhook (§3).
4. Add the reconciliation cron (§5) once approved.
5. Release the app build.

### Verify

- The MyFatoorah card appears on the payment-gateway page and can be switched on and off.
- Connection test passes for the mode you configured; *Account status* shows the time and result.
- MyFatoorah appears at web checkout, and in the app after the payment sheet refreshes.
- One sandbox payment: the attempt reaches `paid`, exactly one order is created, the webhook delivery
  shows `settled`.
- Switch the gateway off: it disappears from both checkouts, and the server refuses new payments through
  it while still verifying anything in flight.
- The other gateways still work exactly as before.

### Roll back

The safest rollback is the **switch, not the code**: turn the gateway off in the admin panel, or set
`MYFATOORAH_ENABLED=false`. Either stops new payments immediately while leaving everything already taken
verifiable, reconcilable and refundable — which is the whole reason verification is independent of the
kill switch.

If the code must be reverted, do it with the gateway switched off and **no payments in flight**
(admin page → no attempts in `created`, `redirected` or `pending`). The migrations' `down()` drops the
three new tables, and removes the `addon_settings` row only if it was never configured — a row holding a
live key, or one a paid order's history points at, is left alone.

---

## 10. Tests

```bash
./vendor/bin/phpunit tests/Feature/MyFatoorah/
```

86 tests, 319 assertions. `Http::preventStrayRequests()` is on throughout: any request a test has not
explicitly faked fails it, so nothing here can reach the real sandbox.

They cover the gateway's switches and modes; encryption at rest and that a blank field keeps a stored key;
creating a payment from the server's own amount; reuse instead of a second invoice on a double-tap; a
basket that changed mid-payment; a timeout while creating; a payment URL that is not a MyFatoorah host;
paid / failed / pending / cancelled / expired; a wrong amount, a wrong currency and another order's
payment; a network failure and a rejected key both being `unknown` rather than failures; a confirmed
payment surviving a stale failure notice; settling five times; two attempts both paid; an interrupted
settlement and its recovery, including when the order already exists; webhook before and after the
redirect; the same webhook ten times; no signature, a wrong signature and a tampered payload; an unknown
reference not being matched by a colliding invoice id; the body not being stored; reconciliation
recovering a lost payment and reporting an unavailable invoice lookup; full and partial refunds, the paid
amount as a ceiling, pending requests counting against it, and an unknown refund outcome; the method
catalogue read from the account; and that the gateway registers switched off with one card and no
duplicate.

**Passing tests are not evidence that a real payment works.** See §8.
