# D1-10: a refund worked out from the original payment (prepared, not adopted)

Master tracker requirement D1-10, criteria 2 and 4 ("a refund is computed in the order's own currency and not re-converted at the refund-time rate"; "a refund returns exactly the amount paid after a rate change"). **This is the computation and a read-only preview. No refund calls it, nothing is paid, nothing is written, and no refund policy is adopted: which of the two a refund gives back is the owner's decision and the finance review's.**

## What happens today

A refund request keeps one amount, in the amounts the order is stored in. When the administrator refunds it to the customer's wallet (`RefundStatusService::getRefundStatusProcessData`) the credit is `usdToDefaultCurrency($refund['amount'])`: the stored amount converted **at the rate of the day of the refund**. A customer who paid 100 SAR when the riyal stood at 3.75 to the base unit, and is refunded when it stands at 3.50, gets 35.00 back for a refund that is 37.50 of the payment. The stored amount says which share of the order is given back; it does not say how much money that was.

## The computation

`App\Services\Storefront\RefundFromOriginalPayment::of($order, $refundAmount, $alreadyRefunded)` answers what the refund gives back **in the currency of the original payment**:

1. **The gateway record**, when the order keeps a transaction reference: the one paid row of `payment_requests` with that `transaction_id` (its `payment_amount` and `currency_code` are what the gateway took). Two paid rows with one reference are ambiguous and are not guessed between. A payment of several orders of one cart (they share the reference) is shared among them by their stored amounts.
2. **The order's own record**, otherwise: the currency it was sold in and the rate that turns a stored amount into it (`orders.currency_code`, `currency_rate`, written by GCC-05 for a storefront with its own money, where the rate is one).
3. **Nothing** (`null`): an order that recorded neither is not guessed at, and the conversion the platform makes today stays what decides.

The refund is the share of the payment that the refunded amount stands for. Each refund is the difference of two roundings of the **cumulative** share, in the decimals of the currency (three for the dinar and the rial, two otherwise; `config('gcc.storefronts.currency.decimals')`), so the refunds of one order add up to exactly what was paid for it whatever their number: 3.333, 3.334 and 3.333 of 10.000 KWD, not three times 3.333. A refund that would take more than the order's amount is capped at it and says so (`capped`). It does not use today's rate at all: changing the exchange rate moves the conversion of today and leaves this where it was. It does the arithmetic on exact decimals (the project's `Money`), never on floats.

`php artisan refunds:preview-original-payment {refund}` prints, for one refund request, the original payment, what the refund gives back worked out from it, and what the platform converts it to today, with the difference. It writes nothing.

## Not done here, and why

- **No refund uses it.** A test fails for any file under `app/` other than the preview that refers to the service, so the refund flow keeps doing what it always did until a policy is adopted.
- **The merchant account and the company the refund goes back through** (criterion 3) need the gateway accounts of each company (D2); the refund rows already name the company of the order (the first criterion).
- **Shipping.** The stored amounts the share is taken over are the order's amount, which includes shipping; whether a refund of every line also gives back the shipping, and the tax that was on it, is the policy's.
- **Payments that are not in `payment_requests`** (cash on delivery, an offline payment, a wallet) have no gateway record; for them the order's own record is the only source, and an order placed on the default storefront before GCC-05 has neither, so it answers `null`.

## Tests

`RefundFromOriginalPaymentTest` (26): the share of a gateway payment; that it does not move when the exchange rate does while the conversion of today does (37.50 against 35.00); three decimals; that the refunds of an order split in four different ways add up to exactly what was paid; the cap; a payment of two orders; the order's own record and its rate; the gateway record winning over it; an order with nothing recorded, an unpaid payment, two paid rows, a database without the gateway ledger and a record with no amount or currency (all `null` or the order's record); amounts that are not plain numbers; that every statement is a select and no refund refers to the service; and the preview (the two figures side by side, earlier refunds counted without the one shown, no original payment, a refund or order that does not exist, nothing written). Mutations of the cumulative rounding, the cap, the cart share, the paid filter, the ambiguity rule and the rate each fail the matching tests.

## Open owner decisions

1. The refund policy across currency movements: the original amount (this computation) or the amount at the current rate (today).
2. Credit notes, and whether a refund may return only to the original merchant account.
3. Whether the shipping and its tax are refunded with the last line.
4. What an order that recorded no original payment is refunded with.
