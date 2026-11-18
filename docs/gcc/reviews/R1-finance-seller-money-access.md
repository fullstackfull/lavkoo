# R1: Finance, seller money access

As of 2026-11-18. **Not requested. No reviewer is named. No outcome is recorded.** The register is [REVIEW_GATES.md](../REVIEW_GATES.md). Nothing here approves anything: a review is a person's, and neither Claude nor CI fills the outcome.

## What the reviewer decides

Whether a seller may read or change what these pull requests stop them reading or changing, and whether anything a seller legitimately needs was cut off.

## Pull requests (all drafts, base `main`)

| PR | Head | What it is | CI of that head |
|---|---|---|---|
| [lavkoo 52](https://github.com/fullstackfull/lavkoo/pull/52) | `ead8e2a8` | the seller APIs act only on the seller's own products and reviews (130 files; the seller order tests and the shared product rule) | 20 of 21 jobs green, the deployment-overlay job red by design (read 2026-11-18) |
| [lavkoo 100](https://github.com/fullstackfull/lavkoo/pull/100) | `9908240c` | a customer embedded in an order, refund, review, chat message, order transaction, restock request or support ticket is serialized without `wallet_balance`, `loyalty_point` and `referral_code` | not read (queued) |

## What changes for a seller (money and access)

- **PR 52:** a seller can act only on their own products and reviews through the seller APIs. Read the seller order controllers it covers first (`app/Http/Controllers/RestAPI/v2/seller/OrderController.php`, `v3/seller/OrderController.php`), then the shared product rule.
- **PR 100:** any payload that embeds a customer (an order, a refund, a review, a chat message, an order transaction, a restock request, a support ticket) no longer carries the customer's wallet balance, loyalty points or referral code, two levels down too. The customer's own payload still carries them. The rule is on the models (`App\Traits\HidesCustomerBalances`, seven models), so it covers every response that embeds the relation; the tests did not call the seller endpoints themselves.

## Evidence

PR 52: the seller API order tests of the pull request. PR 100: `EmbeddedCustomerBalancesTest` (12 tests: the seven models, two levels down, the attributes stay readable, the customer's own payload keeps them; mutations of the trait and of the list fail it); the seller, permission, refund, POS and marketplace directories (3,889 tests) pass with PR 100 merged, on SQLite.

## Questions for the reviewer

1. Does any seller or admin screen show a customer's balance from an order payload on purpose? (The POS customer lookup is a separate, still open item: master tracker E-04, "an explicit resource that returns only the seller's market balance".)
2. Is it right that a seller may still see a customer's name and contact on an order they fulfil?

## Outcome (written by the reviewer, in the pull request and here)

| Reviewer | Date | Head read | Outcome | Link |
|---|---|---|---|---|
| | | | | |
