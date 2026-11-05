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

## At checkout: cash on delivery is not offered when it could not be collected safely

A hold after the fact leaves a customer with an accepted order that nobody ships. So the payment step does **not offer
cash on delivery** for a basket that would be shipped by more than one collector (two providers, or a provider plus the
shop's own delivery) unless the mixed-COD exception holds, and the web and API placement refuse it with an explanatory
message. Each cart group is judged on its own, because each becomes one order (a basket of two orders, one per provider,
is fine). Paying online is always available. The router and dispatcher keep their holds as the last line of defence.

## Money is assessed again just before every send

The cash a provider is told to collect is decided when the shipment is created **and again when it is claimed for sending**
(`FulfillmentMoney`), because an order can be paid, part-paid, or have its collection rules changed in between. An order
paid after routing is sent as prepaid with nothing to collect; a part-payment lowers the cash; a split that is no longer
allowed holds the shipment. Any change is applied before the request and recorded on the shipment's history
(`money_reassessed_before_send`). **After** a shipment has been accepted by a provider, a later payment or refund is not
propagated (neither provider documents an update call): handle it with the provider.

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
