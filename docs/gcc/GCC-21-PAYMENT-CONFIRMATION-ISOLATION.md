# GCC-21 — Payment confirmation isolated per company and gateway account

**Approved:** owner instruction of 2026-11-22 (see [DECISIONS.md](./DECISIONS.md), "Owner decisions, 2026-11-22").
**Status:** approved intent, staged. Not started as code. **The new refusal must not be switched on before the initiation-side binding is complete** — that ordering is part of the approval, not an implementation choice.

## What was approved

> أريد عزل تأكيدات الدفع حسب الشركة وحساب البوابة. اربط عنوان التأكيد المتوقع بالدفعة عند إنشائها. امنع عنوان المنصة العام من تأكيد دفعات الشركات الجديدة التي بدأت باستخدام عنوان شركة مستقل، مع الحفاظ على تأكيد الدفعات القديمة التي بدأت بعنوان المنصة. نفّذ ذلك تدريجيًا مع اختبارات موجهة، ولا تفعّل السلوك الجديد قبل اكتمال ربط بدء الدفع.

Four obligations, and the fourth constrains the order of the other three:

1. A payment's **expected confirmation address is bound to the payment when the payment is created**.
2. The **public platform address stops confirming** payments of companies that began under an independent company address.
3. Payments that **began under the platform address keep being confirmed there** — no historical payment is stranded.
4. **Staged, with focused tests**, and the refusal is **not enabled until (1) is complete**.

## Why the ordering matters, concretely

Switching the refusal on first would strand live money. Today `PaymentInitiationService` sends `route('myfatoorah.webhook')` — the platform address — as the `IntegrationUrls.Webhook` of **every** payment, stamped or not (`app/Services/MyFatoorah/PaymentInitiationService.php:467`). So at this moment **no payment anywhere has actually begun under a company address.** A platform-address refusal keyed on the company stamp would therefore refuse payments whose notifications can only ever arrive at the platform address, and those payments would never settle.

## What already exists, and what is actually missing

This is not a green field. `PaymentAccountBinding` (PR 152, branch `gcc/core-payment-accounts`) already stamps a payment at creation with its market, company (frozen identity), account, gateway, environment, currency and rate, under the key `gcc_payment_account` in `payment_requests.additional_data`; `accountOf()` reads back **what the payment was stamped with, never a fresh resolution**. Each account already carries a `webhook_path_token`, and `GatewayAccountRegistry` already resolves a company address from it.

So three things are missing, and only three:

| # | Missing | Where |
| --- | --- | --- |
| 1 | The stamp records the **account**, not the **address the payment expects**. `accountOf()` answers null for a payment whose account row was deleted — and a company-bound payment that answers null would read as platform-confirmable, which is the wrong direction to fail in. | `PaymentTransactionRecord` (9 fields, none of them the expected address) |
| 2 | Initiation sends the platform address to the gateway for every payment. | `PaymentInitiationService.php:467` |
| 3 | The platform address does not consult the stamp at all. | `MyFatoorahWebhookController` |

## The three stages

### Stage A — the payment records the address it expects (no behaviour change)

Add to the stamp a field naming the **expected confirmation route**: the platform address, or a named company account's address. Captured at creation, **write-once**, and deliberately **not re-derived** — so an account that is later edited, disabled or **deleted** cannot turn a company-bound payment back into a platform-confirmable one. Absent or unstamped reads as **platform**, which is every payment placed today, every legacy payment and every Saudi payment.

Focused tests: a payment started while accounts are off records the platform address; a payment started against a company account records that account's address; editing, disabling and deleting the account afterwards leave what the payment recorded **byte-for-byte unchanged**; a payment with no stamp reads as platform.

No refusal, no routing change. This stage alone is what obligation (4) names as the precondition.

### Stage B — initiation sends the address the payment recorded

`PaymentInitiationService` sends the company account's address for a payment stamped with one, and the platform address otherwise. Focused tests capture the **outgoing** `IntegrationUrls.Webhook` and assert it per case, including that an unstamped payment still sends exactly the platform address it sends today.

### Stage C — the platform address refuses what began elsewhere (behind a switch that ships OFF)

The platform address refuses a payment whose recorded expectation is a company address, and goes on accepting every payment whose expectation is the platform address or absent. Focused tests: an old platform-started payment still settles at the platform address; a company-started payment is refused there and accepted at its own; and **with the switch off, today's behaviour is reproduced exactly** — the dormancy pin, asserted rather than assumed.

## Dependency, and it is not optional

Stage C compares a token taken from the URL. `payment_gateway_accounts.webhook_path_token` is the one column of that table left out of its migration's `ascii_bin` block, which an independent verification measured as a real engine divergence: the same signature with only the token's letter case changed answers **401 on SQLite and 200 "settled" on MariaDB**. **Stage C must not be built before that collation fix lands**, or the refusal it adds would itself be case-blind on MariaDB.

## What this does not authorize

No merge to main, no deploy, no production migration, no key rotation. The stages land as draft pull requests on the payment-accounts lane.
