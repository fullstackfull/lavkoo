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

## 2a. Sandbox on a Saudi MyFatoorah account, step by step

Do all of this before anything Live. Nothing below moves real money.

**1. Get a sandbox account.** The public demo account on `demo.myfatoorah.com` is a Kuwaiti one and its
token only works for KWD — useless for testing a SAR integration. Ask MyFatoorah's technical support
(the address is on their contact page; it changes, so it is not written down here) for a **Saudi test
account**, naming the currency (SAR) and the methods you want enabled: mada, Visa/Mastercard, Apple Pay,
STC Pay. They enable methods per account, so ask explicitly.

**2. Create the API token.** In the sandbox portal: *Integration Settings → API Key → Create*. Give it
the permissions this integration uses — create payment, payment details, payment methods, and refunds if
you want to test those. Copy the token once; the portal will not show it again.

**3. Enter it in the admin panel.** *Payment & Withdraw Methods Setup → MyFatoorah*, Mode = **Sandbox
(test)**, paste into **Test API key**, save. Then press **Test sandbox connection**. It should report the
methods your account has enabled. If it reports none in SAR, the account is not set up for Saudi yet —
go back to step 1 rather than working around it.

**4. Enable the webhook.** Sandbox portal → *Webhook*: paste the URL the admin page shows
(`https://pharmacyksa.com/api/myfatoorah/webhook`), enable the **secure key**, copy it, and paste it into
**Test webhook secret key** in the admin panel. Subscribe at least to **PAYMENT_STATUS_CHANGED**.

**5. Switch it on.** `MYFATOORAH_ENABLED=true` in `.env`, `php artisan optimize:clear`, then the gateway
toggle on the payment-gateway card. The store-wide *Digital payment* switch must be on too.

**6. Pay a sandbox payment, once per method.** MyFatoorah's sandbox test cards are in their own docs
(*Testing Cards*) and change from time to time, so take them from the portal rather than from here. For
each method, check on the admin page that:

- the attempt reaches **paid**, with the amount and currency you expected;
- exactly **one** order exists for it;
- the webhook delivery shows outcome **settled** under *Recent webhook deliveries*.

**7. Check the three states deliberately**, because the differences are the point:

| What to do | What should happen |
|---|---|
| Pay successfully | attempt `paid`, one order, webhook `settled` |
| Cancel on the hosted page | attempt `cancelled`, no order, customer sees the cancelled page |
| Use a card the sandbox declines | attempt `failed`, no order |
| Close the tab immediately after paying | the webhook settles it; the order appears without the customer returning |
| Pay, then open the old page again and pay again | the second is `mismatch`, flagged, **no second order** |

**8. Check the amount end to end.** Put a basket together with shipping, a discount and tax so the total
is not a round number, and confirm the attempt's amount, the MyFatoorah invoice and the order total all
agree to the halala. This is also the check that tells you which of MyFatoorah's three amount fields is
authoritative on your account (§8.4).

**9. Test a refund** while still in sandbox: a partial one, then the remainder, then confirm a third is
refused. Remember the status only reaches `executed` when someone marks it so after reading the portal —
MyFatoorah executes refunds after review.

**10. Only then consider Live**, and as a separate decision: a new token from the Saudi production
portal, a new webhook secret, Mode = Live, and one small real payment watched end to end before the
gateway is left on.

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
keyed with the portal secret as raw binary, base64-encoded. **Every** delivery must verify; an unsigned,
wrongly signed, or unverifiable one gets a flat `401` and is not even recorded.

An earlier version let an event type through unverified on the grounds that the processor would only
re-read the API rather than believe the body. That was wrong twice over: the re-read is itself an
outbound provider call, so anyone who could reach the URL could spend our MyFatoorah rate limit and fill
our tables; and "we only do something small with untrusted input" is not a basis for accepting untrusted
input on a payment endpoint.

Two things to know:

- **The amount is not signed.** MyFatoorah signs only `Invoice.Id`, `Invoice.Status`,
  `Transaction.Status`, `Transaction.PaymentId` and `Invoice.ExternalIdentifier`. A valid signature
  proves the notification is MyFatoorah's and nothing about how much was paid — which is why a verified
  webhook still only triggers a server-to-server read, never a decision.
- **Only PAYMENT_STATUS_CHANGED can be verified.** MyFatoorah publishes the signed-field order for that
  event only, so no other event type can be authenticated and all of them are refused. Refund state is
  not lost by that: it is confirmed by an operator against the portal, which is how this integration
  treats refunds anyway.

`MYFATOORAH_WEBHOOK_REQUIRE_SIGNATURE=false` exists only to debug a sandbox, and **has no effect at all
while the gateway is in live mode** — an accepted unsigned production webhook is an open door to marking
orders paid, and that must not be reachable by an environment variable. The admin page shows a red
warning while it is off.

---

## 4. How a payment is decided

The only thing allowed to declare a payment real is `PaymentVerificationService`, over a
server-to-server `GET /v3/payments/{paymentId}` authenticated with our own API key.

Ownership is proved **before** the money is even read, and a payment that cannot be proved to be ours is
never accepted however well the amount lines up:

1. `Data.Invoice.ExternalIdentifier` must be present. It is our own reference, sent when the invoice was
   created and echoed back, and it is the only field that ties their money to our order. A response
   without it proves nothing.
2. It must equal **this** attempt's reference.
3. The invoice id must match the one recorded when the invoice was created, whenever both are known —
   a second, independent tie that catches a right reference describing a different invoice.

A matching amount is emphatically not proof: two customers checking out the same basket produce two
invoices for the identical amount in the identical currency, so an amount check alone would let either
one's payment settle the other's order. Only once ownership holds are the money checks made:

4. `Data.Invoice.Status` is `PAID`;
5. the amount matches what was recorded when the attempt was created, to the halala;
6. the currency matches (SAR).

If (4) holds but any other does not, the status becomes `mismatch` — never `paid`, never `failed`. Real
money has moved and a human has to look. The admin page shows these in red at the top.

A network failure, a timeout or a rejected API key is **`unknown`**, not a failure. Nothing in this
integration runs the failure hook because a call did not come back — that is how a network blip cancels
an order a customer has paid for.

Four independent things can learn that a payment succeeded — the customer's redirect, the webhook, the
app polling, and the reconciliation sweep — and all four funnel into `PaymentSettlementService::settle()`.

### One live payment per order

A unique index on `(payment_request_id, live_slot)` says it: at most one attempt per order may be live —
anything short of a terminal state. While one is live, a second payment is refused, because either the
customer is looking at its payment page (and should be sent back to it) or its create call is
unaccounted for (and we cannot know whether MyFatoorah already has an invoice).

This was three wrong designs before it was right, and the MySQL concurrency test found each one:

- a plain read inside a transaction could not see a sibling's just-committed attempt, because MySQL's
  REPEATABLE READ gives the transaction a snapshot from when it began;
- making that read a locking one took gap locks on the `payment_request_id` range, so **different
  orders** blocked each other;
- releasing the slot as soon as the payment page arrived left a window only as long as the create call —
  microseconds against a fast provider — and six simultaneous checkouts each slipped through it.

A unique index needs neither a snapshot nor a lock, and orders do not interact.

An attempt whose create call never came back is additionally left alone for a grace period
(`MYFATOORAH_IN_DOUBT_GRACE`, default connect + read timeout + 30s) before anything is concluded about
it: "MyFatoorah has no invoice for this reference" and "MyFatoorah has not been told yet" are both a 404,
and acting on the second as though it were the first is how a second invoice gets created. There is also
a cap (`MYFATOORAH_MAX_ATTEMPTS`, default 10) so a flapping provider cannot let one order mint invoices
without limit.

**A 404 only proves absence on an endpoint we have seen work.** MyFatoorah publishes no literal path for
"Get Invoice by InvoiceId" or "Get Invoice by ExternalIdentifier", so on an account that does not serve
them — or does not permit them — *every* lookup 404s, which is indistinguishable from a genuinely absent
invoice. Absence is therefore only concluded once that endpoint has answered successfully at least once
for the mode, which is recorded the first time it does. Until then the attempt stays in doubt, holds its
order's live slot, is recorded with `lookup_unproven`, and is counted on the admin page under *these need
a person*. An operator who has checked the portal releases it with **No invoice in portal: release** —
a person answering what the API will not. There is no automatic path to that action.

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
- The right to run the hook is **leased** with a conditional UPDATE on `hook_started_at`, so exactly one
  process is inside it at a time. `settled_at` alone was not enough: a second delivery arriving while the
  first was still inside the hook found `settled_at` set and `hook_completed_at` still null, and the
  order-already-exists check could not help because the first caller had not created that order yet —
  creating it is most of the way through the hook's work. The lease goes stale after
  `MYFATOORAH_HOOK_LEASE_MINUTES` (default 15), so a process killed mid-hook cannot hold the order
  hostage.
- Each lease carries a **fencing token**, and a run may only record its outcome while its own token is
  still on the row. A hook that outran its lease therefore cannot overwrite the result of whoever took it.
- **A hook that has started is never re-run unattended, however stale its lease.** The token stops a
  stale *write*; it cannot stop the side effects from having happened. By the time a lease expires the
  first process may have taken the stock and be on its way to the order and the shipment, and the
  order-already-exists check only helps once the order exists. Nothing here can tell a dead process from
  a slow one, so these become human-review items (`needsAttention()['hook_unverifiable']`). An operator
  who has checked **the order, the stock reservation, the shipment and the wallet** applies it with
  **Apply payment (after review)**.
- Only a hook that **never began** (`hook_started_at` null — the process died between marking the payment
  paid and starting the hook) is finished automatically, because there nothing can have been duplicated.
- A **wallet top-up** is never retried either way: `add_fund_to_wallet_success` credits a balance with no
  reference to look the credit up by, so there is no idempotency boundary at all. It is left for an
  operator, who can read the wallet's own history.
- `hook_completed_at` records that it finished. An attempt with `settled_at` set and
  `hook_completed_at` null is an **interrupted settlement** — the one state that can take the money and
  lose the order. It is queryable by exactly that pair, counted on the admin page, and finished by
  `recover()`.

---

## 5. Reconciliation

```bash
php artisan myfatoorah:reconcile
```

It is registered on the platform's existing Laravel scheduler
(`app/Console/ScheduleDefinition.php`), every ten minutes with `withoutOverlapping`. **No second cron
entry is needed and none should be added** — the server already runs `schedule:run` every minute.

It is registered only while **both** switches are on, and both ship off:

```
MYFATOORAH_ENABLED=true
MYFATOORAH_SCHEDULE_ENABLED=true
```

They answer different questions: the first is "may this deployment talk to MyFatoorah", the second is
"should this deployment be the one sweeping". A staging copy of production wants the first without the
second — two hosts sweeping one account double the provider reads to no purpose.

There is also a **Reconcile now** button on the admin page, which does not depend on either switch.

It asks MyFatoorah about every in-flight attempt and settles the ones that are paid, through the same
verify-then-settle path a webhook uses — so a payment it recovers cannot produce a second order for one a
webhook already recovered. It is bounded (batch size, a minimum interval before re-asking, a pause
between calls) because MyFatoorah documents no rate limits.

It is safe to run concurrently with itself and with live webhooks.

---

## 5a. An invoice we abandoned, paid anyway

Cancelling an attempt cancels **our record** of it. MyFatoorah is not told, and its invoice stays
payable — so a customer who still has the old hosted page open can pay it minutes after the basket
changed. The amount on that invoice is the amount we asked for at the time, so every money check would
pass and the order would be released for the wrong total.

Two guards, both before the amount is accepted:

- a payment against an attempt we put into `cancelled` or `expired` is `paid_after_abandonment` — a
  mismatch for a person to refund or apply deliberately, never a settlement. (`failed` is excluded: that
  is MyFatoorah's own verdict, and a later "paid" for the same invoice is the true answer.)
- the attempt's amount snapshot is re-checked against what the order is owed **now**; a difference is
  `request_amount_changed`. This also covers an order edited mid-payment with no abandonment marker.

Tested both single-process and, in `MyFatoorahConcurrencyTest`, with the stale invoice and the live one
paid at the same instant in separate processes: one order, for the new total, and the stale payment
flagged.

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

until `is_final`. The endpoint needs no login — a guest checkout has no token, and the customer may be
coming back from an external browser that never had one — so the unguessable reference is the credential,
and a signed-in customer may only read their own. It is rate limited two ways: 90 requests a minute per
address on the route, and a floor of a few seconds between provider reads for the same attempt, because a
per-address limit cannot stop requests spread over many addresses from becoming the same flood at
MyFatoorah.

Three outcomes, and the third is the point:

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
8. **Anything on a real Android or iOS device.** What was and was not run is in §10; the device gap is
   spelled out below because it is the largest remaining one.

### 8a. Android and iOS tests that were NOT run

This environment has **no Android SDK and no macOS**, so no `.apk`, `.aab` or `.ipa` was produced and
nothing ran on a device or simulator. The Dart compiles (`flutter build bundle` succeeds) and the logic
is unit-tested, but every item below needs real hardware:

| Not run | Why it matters | Platform |
|---|---|---|
| 3-D Secure step on mada and on Visa/Mastercard | The bank's page runs inside the WebView and may navigate in ways only a device shows | both |
| STC Pay hand-off | Leaves the WebView for the STC Pay app via a non-`http(s)` scheme; the app hands those to the OS, but whether the customer comes *back* cleanly is a device behaviour | both |
| Apple Pay | Needs the domain registered with MyFatoorah (`RegisterApplePayDomain`) and a real device with a card in Wallet. Not available in a simulator | iOS |
| Google Pay | Same shape as Apple Pay, needs a device with a card | Android |
| Returning to the app from an external browser | Where the OS sends the customer back, and whether the screen is still alive to poll | both |
| Killing the app mid-payment and reopening | The server settles regardless; what the customer *sees* on reopening is untested | both |
| Losing connectivity during the return | Should show "confirming", never a failure. The logic is tested; the real radio behaviour is not | both |
| A release build | Only a debug kernel was built. R8/ProGuard on Android and bitcode/signing on iOS can behave differently | both |
| Arabic RTL on the payment and confirming screens | Strings are in place and the key test passes, but the layout was never rendered | both |

Suggested order on a device: a successful mada payment, then a cancellation, then a 3-D Secure card, then
STC Pay, then Apple Pay / Google Pay, then kill-and-reopen, then aeroplane mode on the way back.

To build once an SDK is available:

```bash
flutter build appbundle --release    # Android
flutter build ipa --release          # iOS, needs macOS and Xcode
```

---

## 9. Deploying, verifying, rolling back

### Deploy

1. Merge the backend PR. **Do not** run migrations on the server without approval; when approved:
   ```bash
   php artisan migrate   # five migrations, all additive
   php artisan optimize:clear
   ```
   Three new tables (`myfatoorah_payment_attempts`, `myfatoorah_webhook_events`,
   `myfatoorah_refunds`), one added column on the first of those, and one inactive row in
   `addon_settings`. Nothing existing is altered or dropped.
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

### Backend, SQLite (the fast suite)

```bash
./vendor/bin/phpunit tests/Feature/MyFatoorah/ tests/Unit/MyFatoorah/
```

115 tests, 409 assertions. `Http::preventStrayRequests()` is on throughout: any request a test has not
explicitly faked fails it, so nothing here can reach the real sandbox.

They cover the gateway's switches and modes; encryption at rest and that a blank field keeps a stored key;
creating a payment from the server's own amount; reuse instead of a second invoice on a double-tap; a
basket that changed mid-payment; a timeout while creating; a payment URL that is not a MyFatoorah host;
paid / failed / pending / cancelled / expired; a wrong amount, a wrong currency and another order's
payment; a network failure and a rejected key both being `unknown` rather than failures; a confirmed
payment surviving a stale failure notice; settling five times; two attempts both paid; an interrupted
settlement and its recovery, including when the order already exists; webhook before and after the
redirect; the same webhook ten times; a second caller arriving while the hook is mid-flight; a lease left
behind by a killed process; no signature, a wrong signature and a tampered payload; an unknown reference
not being matched by a colliding invoice id; the body not being stored; reconciliation recovering a lost
payment and reporting an unavailable invoice lookup; full and partial refunds, the paid amount as a
ceiling, pending requests counting against it, and an unknown refund outcome; the method catalogue read
from the account; the gateway being hidden from both checkouts unless it can actually take a payment; and
that it registers switched off with one card and no duplicate.

### Backend, real MySQL/MariaDB (concurrency)

```bash
DB_CONNECTION=mysql DB_DATABASE=<something>_test DB_USERNAME=… DB_PASSWORD=… \
  ./vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/Concurrency/MyFatoorahConcurrencyTest.php
```

Six tests, in separate real processes against a real server, because the thing being tested cannot be
expressed in SQLite — an in-memory database is private to one process:

- six simultaneous checkouts for one order create **exactly one** invoice;
- simultaneous checkouts for **different** orders do not block each other (the gap-lock regression);
- six simultaneous retries behind an attempt in doubt create **no** invoice while the lookup is unproven;
- and exactly **one** replacement once it is proven;
- a stale invoice and the live one paid at the same instant create one order, for the right money, with
  the stale payment flagged;
- six simultaneous deliveries of one payment create one order.

These are the tests that found the REPEATABLE READ snapshot bug, the gap-lock bug and the
slot-released-too-early bug. They skip automatically on SQLite, and run in CI on both MySQL 8.0 and
MariaDB 11.4.2.

### App

```bash
flutter pub get
flutter analyze
flutter test
flutter test test/myfatoorah_payment_status_test.dart test/myfatoorah_return_url_test.dart
```

The two MyFatoorah files are 24 tests over the part that replaced the substring match on the redirect
URL. The state tests pin that paid, failed, cancelled and expired are definite while pending, confirming
and anything unrecognised are **not** — so a server that grows a new state cannot make the app tell a
paying customer their payment failed. The return tests pin that only our own callback on our own host
counts, and that a URL merely containing `success`, `fail` or `cancel` means nothing at all, which is
exactly what the old code reacted to.

What was actually run for this change, on Flutter 3.32.0 / Dart 3.8.0:

| Step | Result |
|---|---|
| `flutter pub get` | resolved, 349 dependencies |
| `flutter analyze` (whole project) | **0 errors**; 263 pre-existing infos/warnings (deprecated `withOpacity`, dead code in `price_converter.dart`, and so on) |
| `flutter analyze` (the six changed files, explicitly) | **No issues found** |
| `flutter build bundle` | **succeeded** — the app, including these changes, really compiles |
| `flutter test` | 115 tests including **24 new MyFatoorah tests, all passing**. 2 failures, both in `test/dynamic_theme_render_matrix_test.dart` and both **failing identically on `main`** — pre-existing, nothing to do with payments. `localization_keys_test.dart` passes for `en.json` and `ar.json`, which checks the new strings. |

Not run: `.apk`/`.aab` (no Android SDK here), `.ipa` (no macOS), and anything on a device or simulator.

**Passing tests are not evidence that a real payment works.** See §8.
