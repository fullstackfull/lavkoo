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
| [109](https://github.com/fullstackfull/lavkoo/pull/109) | `bb55ab83` | #90 | a wallet or loyalty ledger row says what stands behind it (order, refund or payment), who made a manual top-up and the market it was made in: **recording only, no amount or balance changes**; one migration of 13 nullable columns on the two ledgers (**awaiting this review**) | not read (not yet run) |
| [110](https://github.com/fullstackfull/lavkoo/pull/110) | `13eda6f1` | #87 | a storefront's business pages (terms, privacy, refund, return, cancellation, shipping) show **its own current legal text** in the visitor's language, or nothing; the agreement links of the checkout and the registration name the storefront | not read (not yet run) |
| [111](https://github.com/fullstackfull/lavkoo/pull/111) | `d8f6a7ea` | #105 | a storefront is a draft, ready, active or paused (browse only, maintenance, redirect), kept in the database and audited, and **only an active one sells**: a person must say a country is open | not read (not yet run) |
| [114](https://github.com/fullstackfull/lavkoo/pull/114) | `ce7dd532` | #105 | the legal text of a storefront is written, approved by someone other than its author, published from a day and revoked through **one audited service and a command**; nothing wrote those rows before | run 346 running when written |

## What changes for money and for the law

- **Tax follows the storefront and never falls back to the Saudi setup (#99).** A storefront with no setup of its own is taxed with nothing, and cannot sell until a person with the authority records, in the business setting `storefront_tax_approval_<country>`, either the approval of its setup (it goes stale when the rates, type, included flag or shipping tax change) or a documented decision that it charges none (who, where it is written, when; an audit line; a tool or assistant as approver is refused). Command: `gcc:storefront:tax-approve`. **The review decides who may approve and on what document.**
- **The tax reports under-reported multi-line orders (#102).** The rows of `order_taxes` kept half of each line's tax (and a third of the shipping tax in the test): the order, its lines and its invoice were right. New orders now write rows that add up. Orders already placed keep their rows; `orders:tax-rows-reconcile` lists them and writes nothing. **The review decides whether to restate them, to leave them and note the period, or to read the reports from the order's own tax.**
- **Refunds (#101, #103).** #101 records the order's currency, rate and selling company on a refund row. #103 computes what a refund gives back as its share of the original payment in the currency it was paid in (so a rate change does not move it) and previews it next to today's conversion (a wallet refund converts the stored amount at the rate of the refund day). No refund calls it: **the review decides the policy.**
- **A market needs an independent legal review of its texts before it sells (#105).** `gcc:storefront:legal-review` records who reviewed (a lawyer or a firm of that country), where the opinion is written and the day, with an audit line; a tool or an assistant is refused, and nothing here reviews anything. An empty configuration of required documents no longer reads as ready. A recorded review stays until it is withdrawn: **whether a new effective version of a text needs a new review is the decision to take** (if it did, correcting a comma would close a market).
- **A wallet or loyalty ledger row now says what stands behind it (#109).** Thirteen nullable columns on `wallet_transactions` and `loyalty_point_transactions`: the order, order group, refund or payment, the administrator or seller who made a manual movement, and the storefront, currency and selling company of the market. Nothing is back-filled, no amount, balance or `reference` changes, and nothing is written until the migration has run; both models hide the columns so a customer's statement keeps its keys. The finance review should read: whether a customer may ever see who credited their wallet, the unit of the ledger amounts against the currency column (E04-F02 is open), and the idempotency key (E-05.3) that the columns make possible and this does not add.
- **A person must open a country (#111).** Before this, a storefront sold as soon as its texts, review, currency and tax decision were in place. Now it sells only when a person has also marked it ready and approved it with a reference, and it can be stopped (browse only, maintenance, redirect) with a reason and a customer message; every change is audited. This is a legal and commercial control, not an accounting one: who may press it (and whether two people are needed) is the owner's.
- **What a storefront shows as its legal text (#110).** In a storefront other than the Saudi one, the page that stands for a document type shows only that storefront's current approved text in the visitor's language: never another country's text and never the Saudi text as a stand-in. The legal reviewer should see that the texts exist (nothing writes them yet) and decide whether English may stand in for a missing Arabic text.
- **The legal text of a country gets a writer (#114).** `gcc:storefront:legal-text` copies a template into a draft, edits it, approves it (**someone other than the last person who changed it**), publishes it from a day that is now or later, revokes it or discards it; every step is audited with the admin's id and the hash of the words, never the words. A text that is still the template is not approved; a published text is never edited (a change is a new version that starts as a copy, and the old text stays in force until the new one takes effect); a text never takes effect in the past nor before a text of the same type and locale that is already scheduled; a revoked text in force keeps the storefront closed until a replacement takes effect; nothing is deleted. The person is an admin id typed by whoever runs the command: there is no permission gate and no admin screen yet, and publishing and revoking ask for no second person. The reviewer should read the rules in `docs/gcc/I2-03-LEGAL-TEXT-PUBLISHER.md`, not the words of any text.
- **The company on the order and the invoice (#90)** and the **storefront's own money (#87)** change what a customer is charged and sees in a storefront that is switched on; both are off by default.

## Open decisions (from the pull request bodies)

1. The tax rate, model and registration of each storefront, or a documented decision that it charges none, and who approves it.
2. Whether a storefront trades tax-included at all (the amount of a tax-included price is recorded nowhere yet).
3. The refund policy across currency movements; credit notes; whether a refund may return only to the original merchant account; whether the shipping and its tax are refunded.
4. What happens to the orders already placed (their `order_taxes` rows; their refunds).
5. The selling company of each storefront, the invoice numbering and the invoice currency.
6. Who may record the independent legal review of a market, and whether a new version of a legal text withdraws it.
7. Who may mark a storefront ready, activate it and pause it, and whether activation needs two people (#111).
8. Whether a customer may see who credited their wallet, and the idempotency key of a ledger row (#109).
9. Whether a storefront may fall back to its English text when the Arabic one is missing (#110).
10. Who may take each step of the legal text workflow (draft, approve, publish, revoke), whether publishing and revoking need two people, and where the templates come from (#114).

## Outcome (written by the reviewer, in the pull request and here)

| Reviewer (role, country) | Date | Heads read | Outcome | Link |
|---|---|---|---|---|
| | | | | |
