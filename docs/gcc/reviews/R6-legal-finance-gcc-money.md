# R6: Legal and finance, the GCC money and the selling companies

As of 2026-11-18. **Not requested. No reviewer is named. No outcome is recorded.** The register is [REVIEW_GATES.md](../REVIEW_GATES.md). These are decisions of the owner and rules of each country, not code: no pull request here chooses a tax rate, a company, a refund policy or a contract term, and none changes a live financial setting. Every change below is off by default and on a draft branch; **any actual change in a financial obligation or in a historical report waits for this review before it is merged.**

## What the reviewers decide

For each of the five storefronts other than Saudi Arabia: the tax rate and model, the tax registration and who approves them; the selling company; the invoice numbering; whether a refund returns the original amount or the amount at today's rate; and what happens to the orders already placed. A lawyer of each country reads the terms the storefront shows.

## Pull requests (all drafts)

| PR | Head | Base | What it is | CI of that head |
|---|---|---|---|---|
| [87](https://github.com/fullstackfull/lavkoo/pull/87) | `fd40399c` | `gcc/p1-admin-product-country` | the money of a storefront: its own currency, amounts, shipping, coupons; its orders and payments respect it; the wallet is closed in its currency | not read (running) |
| [90](https://github.com/fullstackfull/lavkoo/pull/90) | `26995055` | #87 | the company that sells in each country: registered, stamped on the order, named on its invoice | not read (running) |
| [98](https://github.com/fullstackfull/lavkoo/pull/98) | `718edb99` | `main` | what an order keeps of its tax is pinned by tests (and the tax row stops writing `''` into an integer column) | not read (queued) |
| [99](https://github.com/fullstackfull/lavkoo/pull/99) | `9a57012e` | #87 | a storefront is taxed by its own setup, never the Saudi one, and **does not sell before its tax is approved or documented as none** | not read (queued) |
| [101](https://github.com/fullstackfull/lavkoo/pull/101) | `1447d433` | #90 | a refund names the money and company of its order (recording only) | not read (queued) |
| [102](https://github.com/fullstackfull/lavkoo/pull/102) | `d2d130f8` | #98 | the rows of `order_taxes` add up to the order's tax; a read-only command lists the orders whose rows do not (**awaiting this review**) | not read (queued) |
| [103](https://github.com/fullstackfull/lavkoo/pull/103) | `d100dd01` | #101 | a refund worked out from the original payment, **prepared and not adopted** | not read (queued) |
| [105](https://github.com/fullstackfull/lavkoo/pull/105) | `01cefbc1` | #99 | a market does not sell on an empty legal requirement, nor before an **independent legal review of its texts** is on record (who, where the opinion is written, the day); records what a person tells it and reviews nothing | not read (queued) |

## What changes for money and for the law

- **Tax follows the storefront and never falls back to the Saudi setup (#99).** A storefront with no setup of its own is taxed with nothing, and cannot sell until a person with the authority records, in the business setting `storefront_tax_approval_<country>`, either the approval of its setup (it goes stale when the rates, type, included flag or shipping tax change) or a documented decision that it charges none (who, where it is written, when; an audit line; a tool or assistant as approver is refused). Command: `gcc:storefront:tax-approve`. **The review decides who may approve and on what document.**
- **The tax reports under-reported multi-line orders (#102).** The rows of `order_taxes` kept half of each line's tax (and a third of the shipping tax in the test): the order, its lines and its invoice were right. New orders now write rows that add up. Orders already placed keep their rows; `orders:tax-rows-reconcile` lists them and writes nothing. **The review decides whether to restate them, to leave them and note the period, or to read the reports from the order's own tax.**
- **Refunds (#101, #103).** #101 records the order's currency, rate and selling company on a refund row. #103 computes what a refund gives back as its share of the original payment in the currency it was paid in (so a rate change does not move it) and previews it next to today's conversion (a wallet refund converts the stored amount at the rate of the refund day). No refund calls it: **the review decides the policy.**
- **A market needs an independent legal review of its texts before it sells (#105).** `gcc:storefront:legal-review` records who reviewed (a lawyer or a firm of that country), where the opinion is written and the day, with an audit line; a tool or an assistant is refused, and nothing here reviews anything. An empty configuration of required documents no longer reads as ready. A recorded review stays until it is withdrawn: **whether a new effective version of a text needs a new review is the decision to take** (if it did, correcting a comma would close a market).
- **The company on the order and the invoice (#90)** and the **storefront's own money (#87)** change what a customer is charged and sees in a storefront that is switched on; both are off by default.

## Open decisions (from the pull request bodies)

1. The tax rate, model and registration of each storefront, or a documented decision that it charges none, and who approves it.
2. Whether a storefront trades tax-included at all (the amount of a tax-included price is recorded nowhere yet).
3. The refund policy across currency movements; credit notes; whether a refund may return only to the original merchant account; whether the shipping and its tax are refunded.
4. What happens to the orders already placed (their `order_taxes` rows; their refunds).
5. The selling company of each storefront, the invoice numbering and the invoice currency.
6. Who may record the independent legal review of a market, and whether a new version of a legal text withdraws it.

## Outcome (written by the reviewer, in the pull request and here)

| Reviewer (role, country) | Date | Heads read | Outcome | Link |
|---|---|---|---|---|
| | | | | |
