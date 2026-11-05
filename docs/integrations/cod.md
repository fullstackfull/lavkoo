# Cash on delivery across providers

The rule that protects money: **one collector collects the whole amount the customer still owes — never two
parties each asked for the full amount.**

| Situation | Result |
|---|---|
| Prepaid order | Providers are told nothing to collect (`cod_amount = 0`). |
| COD, all provider lines belong to **one** provider and nothing is delivered in-house | That provider collects everything still owed (order total − already paid). |
| COD, **two providers** | **Held** (`hold_mixed_cod_blocked`) unless the exception below applies. |
| COD, one provider **plus in-house** lines | **Always held.** The in-house flow books the whole `order_amount` as the delivery man's cash in hand, so a split can never be made safely. |
| Amount cannot be converted to the provider currency | Held (`hold_currency_unconvertible`). Nothing is sent. |

**The exception (mixed COD between providers).** Allowed only when *all* of these are true:
`FULFILLMENT_ALLOW_MIXED_COD=true` in the environment, **and** every provider involved has *Cash-on-delivery
settlement verified* ticked in its settings (a person confirmed with that provider how collected cash is paid out),
**and** no in-house delivery shares the order. The amount is then split pro rata by shipped value in whole halalas,
and the parts always sum exactly to what is owed (`CodAllocator`).

A held order is a safe state: nothing is sent, nothing is lost; fix the cause (or cancel the order) and the sweep
releases it. A *money* hold cannot be released by an operator button — only by fixing its cause.

## Torod Point specifics

The Fulfillment API v5 has a collection-method field whose allowed values the document does not list. Until an
administrator enters the exact value LogesTechs confirms (**Cash-on-delivery collection method** in the Torod Point
settings) every COD order for Torod Point stays held (`hold_provider_not_ready`). Prepaid orders are unaffected.

## M5AZN specifics

M5AZN's documented create-order body carries `payment_method` (`cod` or `card`) and `cod_amount`; the integration
sends exactly the amount this provider collects. How M5AZN settles that cash is not documented, so:

* **Provider supports cash on delivery** (default on): switching it off holds every COD order for M5AZN
  (`hold_provider_not_ready`) while prepaid orders keep flowing.
* **Cash-on-delivery settlement verified** (default off) only matters for the mixed-COD exception above.
