# Handoff — the security set is published and read, a cross-company settlement defect is fixed, and an order's own currency reaches its money rows

**Date:** 2026-11-22 01:10 UTC
**Branch (docs):** `claude/exciting-shannon-4mh2ok`, head `10738724`
**Stage:** development under the owner's standing delegation. Nothing merged into `main`, nothing deployed, no production database touched, no key rotated, no force push, no history rewrite.

## 0. The numbers, and one trap that nearly corrupted them

**1753 criteria · 822 MET · 396 CODED · 535 MISSING · 69.5% implemented.** Generated at `10738724`, zero evidence failures.

**The trap, recorded because it will catch the next session too.** `/home/user/lavkoo` was **four commits behind `origin/claude/exciting-shannon-4mh2ok`** while two tracker rulings sat only on the remote. Regenerating the tracker from that stale checkout printed `811 MET / 360 CODED` and would have **silently reverted two published rulings** had it been committed. It was caught by diffing the generated files against the committed ones before committing, which showed only three criteria changing when more had been expected.

> **Before you regenerate, run `git fetch origin <your branch> && git merge --ff-only FETCH_HEAD` in `/home/user/lavkoo` itself.** A worktree elsewhere being up to date does not make the main checkout up to date, and the generator reads whatever tree it is run in.

## 1. The security set is published, and its CI is read

The owner approved publishing the public security fixes with the Hotfix path kept separate from Main and the two patch sets never applied on top of each other.

| line | branch | head | state |
|---|---|---|---|
| main-line delivery | `private/deliver-main` | `13bfc69e` | draft [lavkoo#151](https://github.com/fullstackfull/lavkoo/pull/151), 9 commits, 38 files, +2940/−37 |
| hotfix line | `private/deliver-hotfix` | `0d3ab24d` | separate, **zero commits shared above `main`** |

The two lines are structurally incapable of stacking: they share no commit above `main` (`c5c04b7b`). No exploitation detail, key or reproduction appears in any body, commit message or comment on either.

**A correction I owe the record.** I first closed this item on **local test runs alone and had not read its CI.** Reading it job by job is what produced the real evidence, which is far stronger than what I had reported: **6,324 tests / 792,815 assertions** for the whole suite on PHP 8.2 and again on 8.3, and **4,021 / 35,468** in strict mode on MariaDB 11.4.2. Five jobs are red and **every one of them fails on the same single pre-existing test** — `TorodShipmentDiscoveryTest::test_the_scheduled_run_links_what_appeared_later_...`, whose fixture takes the purchase time from the clock. **Nothing on the branch is responsible for any red job.**

**And that failure is calendar-dependent, not permanent** — a second correction. It did **not** fail on run `44950487867` (2026-11-21), so the same head can read red one day and green the next. Its one-line repair has existed since the start as draft [lavkoo#1](https://github.com/fullstackfull/lavkoo/pull/1) (`gcc/p0-discovery-test-calendar-independent`, commit `50e9037e`, "the fixture records a purchase ten hours ago") and **is not on `main`**, which is why every pull request cut from `main` inherits the red. Merging it is the owner's call; it was not taken.

## 2. [lavkoo#152](https://github.com/fullstackfull/lavkoo/pull/152) — one company's notification address settled another company's payment

`gcc/core-payment-accounts`, head **`450f5940`**, four commits. Found by driving the shipped route, not by reading it.

**Before.** A company's *authentic* delivery, correctly signed, at *its own* notification address, settled **another company's payment**: HTTP 200 "settled", `is_paid` 0 → 1, and the success hook run once — which creates the order, decrements stock, books the shipment or credits a wallet — plus twelve columns written on the other company's attempt row. The mismatch shape gave the same cross-tenant write without settling (ten columns). Reachable on the shipped default gateway list.

**After.** Flat 401. Nothing paid, no hook, no event, the other company's attempt row byte-identical. The route now answers three questions before the delivery is recorded and before anything reads or writes the attempt: **whose gateway, whose secret, and whose payment** — the last read from the stamp written when the payment started, never derived again, so an account edited or disabled afterwards cannot change the answer.

Two edge cases the lane left unstated, verified independently here: a delivery naming no attempt of ours passes through by intent and its handler acts on nothing; and the stamp comparison **fails closed** when an attempt carries no payment row.

**The lane was honest where gilding was easy.** Its first attempt to mutate the gateway check **proved nothing** — 17 tests, 0 failures — because the test used the cross-company payment, so the whose-payment guard refused the delivery anyway and the gateway check was doing no visible work. It rewrote the test so the gateway became the only possible objection, and only then did the mutation go red. It said so itself.

**CI on `450f5940`, run `44950487867`, read job by job: 22 of 23 jobs success**, the one red the deployment overlay, which is red by design on every run and base. The SQLite jobs run the whole suite unfiltered on PHP 8.2 and 8.3, both green, so all 17 tests of `CompanyWebhookTakesOnlyItsOwnDeliveriesTest` passed there.

**D2-09 stays at three × CODED.** What now stands in the way of MET is **a definition, not a missing test**: the sentence "a draft on `main`, dormant by default". The tracker is inconsistent with itself here — **D1-04 index 2 and D1-05 index 4 were ruled MET on exactly that footing.** It was deliberately not resolved in this work's own favour: a raise to MET must not rest on the word of whoever did the work. **The owner has to settle it.**

### The mechanism behind the stray language keys, explained and fixed

`translate()` in `app/Utils/language.php` **writes any unknown key** into `resources/lang/<locale>/new-messages.php`, which is `include`d on every `translate()` call — i.e. every request. The new admin partial passed a **database column value** into it, so a value an operator typed into a row became a permanent edit to a shipped language file. The suite only ever looked clean because `tests/Support/RestoresLanguageFiles` restores those files afterwards. **That is the mechanism behind the stray language keys cleaned out of this project's working tree in three separate commits.** Fixed on the branch by translating fixed literals in a `match` and letting the raw value through. The older screens' own `translate($column)` occurrences are **named, not silently widened into this change** — whether they warrant their own change is an open decision.

A SQLite-only false green was caught by the real engine along the way: `payment_gateway_accounts.environment` is `string(8)`, so the nine-character probe `'staging-7'` was truncated by MySQL and the rendered screen no longer contained it. Fixed with a seven-character constant.

## 3. [lavkoo#153](https://github.com/fullstackfull/lavkoo/pull/153) — the order's own currency on its money rows and three more surfaces (D1-06)

`gcc/p1-order-currency-on-every-surface`, final head **`b7ea97b6`**, eleven commits, base `main` through `gcc/p1-checkout-currency-context` (`ccb490a1`). **Both independent reviewers ruled the pre-fix head `63dd2f8f` defective**; one fix round answered them and changed **six behaviours, three of them money errors**.

**What a Kuwaiti order of 30.000 KD printed before**, measured through the real routes after a real cash-on-delivery API checkout: the customer invoice was an outright **HTTP 500** (`getCurrencyCode(): Return value must be of type string, null returned`) and, with only that fault fixed, **112.50SAR**; the panel invoice 112.50SAR; the export 112.50SAR; **both order e-mails 112.50SAR**, read where production writes them — the listener is `ShouldQueue`, so the queued job was run the way a worker runs it, with the checkout's request, session and bound storefront gone; two orders in one export 937.50SAR / 0.00SAR / 112.50SAR; and the same Kuwaiti invoice served by the **Emirati** storefront printed **30.00AED** — a dirham sign on a Kuwaiti sale with no conversion at all. 112.50 is 30 × 3.75: a figure nobody was charged.

**After:** 30.000KD on all of them; one export document holding **three decimals of dinars beside two of dirhams**; the Emirati-served Kuwaiti invoice 30.000KD; `session('currency_code')` still `SAR` after an administrator prints it, so the document does not change the money its reader is shown next; and an order whose currency row has since been **switched off** still prints 30.000KD.

The mechanism reads the order's own record — not the request, not the storefront switch — at the five invoice render sites, at `OrderManager::storeInvoice` and at `EmailTemplateTrait::sendingMail`, the one seam every order mail passes through. Exports use a per-cell helper, because no single currency can be put in force for a page whose rows are different sales.

**The money rows:** `order_details`, `order_transactions`, `refund_requests`, `refund_transactions` carry additive nullable `currency_code` and `currency_rate` written from the order's record, with **nine of nine writer sites mutation-proved**, plus the ordering trap (stamp before record) and the documented idempotency — eleven mutations, all caught. **73 tests / 526 assertions green on SQLite and on MariaDB 10.11.14** at `b7ea97b6`; the storefront group 1,227/5,883 and 1,265/6,092.

**The dormancy correction, recorded by the lane rather than hidden.** The byte-identical proof (123,493 bytes, 0 differing, two worktrees with the `vendor/composer` hardlink broken) was measured on a **multi-currency** platform only, where the default storefront records no currency. On a **single-currency** platform the default storefront *is* stamped, and there the change was **not** byte-identical: it replaced the operator's decimals and **floored a charged 10.555 to 10.55**. Found by the reviewers, fixed, re-proved by the base/head diff.

**Rulings: D1-06 index 0 MISSING → CODED, index 2 stays CODED with the other three surfaces added, index 3 stays MISSING and says why it is now partial.** Nothing to MET. Three and a half of four surfaces hold: courier cash came from PR 147, invoice holds on five render sites and both app paths, e-mail holds, export holds on four of seven documents plus the order report PDF as a fifth — **the three export documents whose rows are transaction rows do not**, and `RefundTransactionService::getPDFData()` still sums across currencies on a route that cannot run at all (`compact($PDFData)` resolves nothing and `data('Y')` is not a function, so it throws before reaching any money; fixing a dead route's arithmetic is a change nobody can verify).

## 4. A migration overlap, checked so nobody re-derives it

Two branches add the same two columns to the same two tables:

| migration | branch | columns on `refund_requests` / `refund_transactions` |
|---|---|---|
| `2026_11_27_100000_add_order_currency_to_order_money_rows` | PR 153 | `currency_code VARCHAR(8)`, `currency_rate DECIMAL(20,8)` |
| `2026_12_03_100000_add_order_money_to_refund_tables` | PR 101 | the same two, plus `storefront_country`, `legal_entity_id` |

**The types are identical and both guard every column with `Schema::hasColumn`, so both can run in either order.** One narrow hazard, recorded not fixed: PR 153's `down()` would drop `currency_code` and `currency_rate` from the refund tables while PR 101's migration stays recorded as run. Both `down()` methods refuse while any row carries a currency, so the window is small, and redesigning another branch's migration was not taken on.

## 5. How much of the plan is actually codeable

Of **535 MISSING** criteria, a keyword classification puts **~516 as programmatically implementable** and **~20 as blocked**: 14 awaiting an owner decision, 4 needing a production value nobody here can read, 2 a release or store step. **~20 is a lower bound on blocked**, because the classifier reads words and not meaning — axis M in particular holds criteria that need the live server. Every blocked requirement is already indexed in `docs/gcc/tracker/owner-decisions.md`, so nothing is unblockable for want of a list.

Per axis, MISSING: A 58, B 40, C 20, D 59, E 45, F 22, G 30, H 65, I 60, J 25, K 38, L 31, M 87.

## 6. Open owner decisions, as the owner's and unmade

1. Whether to merge [lavkoo#1](https://github.com/fullstackfull/lavkoo/pull/1). **One line, test-only, and until it lands every pull request's CI reads red on a failure none of them caused.** The cheapest decision on this list.
2. Whether a draft that is dormant by default can be MET (§2). The tracker currently answers both ways.
3. Whether a company's notification address should accept a payment carrying **no** stamp. The branch refuses it, conservatively.
4. Whether to apply the two new tables' migrations on live data.
5. Key rotation — **not done**, as instructed.
6. A minor-unit field before any 1/1000-currency market (KWD, BHD, OMR).
7. `SHOW CREATE TABLE users` before merging PR 142.
8. The legal-entity producer for E-17.2.
9. A unique key over the ledger idempotency tuple.
10. The payout rule for a seller holding two chains, and whether a payout may be sized against a mixed-currency balance.
11. The two loyalty conversions that disagree: 1000 points pay **5.00** on the web and **10.00** in the API at rate 2.
12. Refund policy across currency movements — the original amount paid, or the amount at today's rate — plus credit notes and whether a refund may only ever return to the original merchant account. **This one governs the lane running now** (§7).
13. Whether the older screens' `translate($column)` occurrences warrant their own change.

## 7. In flight

**D1-10** — a refund gives back what the buyer paid, in the currency, through the merchant account and the company that took the payment. Branch `gcc/p1-refund-through-original-account`, built from a merge of `gcc/p1-refund-from-original-payment` (`d100dd01`, which carries PR 101) and `gcc/core-payment-accounts` (`450f5940`). Four MISSING criteria. Because decision 12 above is unmade, the mechanism is being built **switched off by default**, with the off path proved byte-identical rather than asserted.

Its one asset worth knowing about: `RefundFromOriginalPayment` was built on PR 103 and deliberately **not adopted** — a test there fails if any file under `app/` refers to it. That test is the record of the decision, and it is being rewritten to say what becomes true rather than deleted.

## 8. Still queued

- **The admin and vendor tills do not refuse an over-quantity line** — they floor `current_stock` to 0 and charge. A money defect, held only while the refund lane is in flight.
- The three transaction-row export documents (§3).
- `order_item_commissions.currency`, which writes the system default over an amount in the storefront's currency.

## 9. Operational note: the container filled up

Disk reached **129 MB free of 252 GB** and a `git add` died of it mid-session. Ninety-one git worktrees had accumulated. **Fifty-nine were pruned** — every detached one whose HEAD is reachable from a remote ref and whose tree is clean, plus every branch worktree identical to its pushed remote — which freed **22 GB**. Nine private measurement worktrees were kept deliberately: their heads are on local-only branches that must not be pushed.

```
git worktree list --porcelain      # detached? clean? reachable from origin?
git branch -r --contains <head>    # empty means it exists nowhere but here — KEEP IT
git worktree remove <path>         # refuses a dirty tree, which is the safety you want
```

---

**Rules honoured throughout:** nothing merged into `main`, no production deploy, no production database access, no real payment or shipment, no key rotation, no force push or history rewrite, no CI bypass, no security gate weakened, and no financial, security or legal approval recorded on anyone's behalf.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
