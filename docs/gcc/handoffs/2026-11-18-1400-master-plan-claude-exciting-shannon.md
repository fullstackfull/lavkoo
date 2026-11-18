# Handoff: closing criteria, the tax gate, the refund computation and the first CI reads (2026-11-18, afternoon)

Sanitized. Nothing here is a secret or personal data. Every pull request is a **Draft**. None is merged, none is deployed, no production data or key was touched, G0 stays open, and the deferred risks DR-1 to DR-5 are still open (no key was rotated). This continues [the handoff of 12:00](2026-11-18-1200-master-plan-claude-exciting-shannon.md); read it first, it is not repeated here.

## What moved since 12:00 (measured on the criteria, not on the pull request count)

Against the tracker published at `d4faf579` (1,669 criteria: 653 MET, 356 CODED, 660 MISSING) the regenerated tracker has **1,675 criteria: 659 MET, 358 CODED, 658 MISSING**.

| Movement | Count | Which |
|---|---:|---|
| MISSING to CODED, a whole criterion | 1 | M2-01 criterion 4: the Saudi baseline pack is a named job on MySQL 8 and MariaDB 11.4.2 and a pull request cannot change a golden answer without a person's marker (#104) |
| MISSING to CODED, half of a criterion (the other half stays MISSING) | 2 | D1-10: the computation of a refund from the original payment exists and returns exactly what was paid across several refunds (#103); the refund flow does not call it yet, on purpose |
| New criteria, already CODED | 4 | D1-07: the rows of an order's tax add up to its tax (#102), the orders whose rows differ can be listed (#102), a storefront cannot sell before its tax is approved (#99); M1-13: the review packs R1 to R6 exist |
| CODED to MET | 5 | D1-03 criteria 1 to 3 and D1-07's registration criterion (run 302 on #90, `26995055`), M2-15's overlay criterion |
| MISSING to MET | 1 | D1-04 criterion 3: an order carries storefront and entity keys apart from the seller (run 302) |

No whole requirement became READY FOR OWNER REVIEW: every one still has criteria that are CODED but not proven on a green head, or MISSING, and no reviewer is named (see below).

## What was built

- **#99, the gate (`f2942370`).** A storefront cannot sell until its tax is approved: either its own rates or a documented decision that it charges no tax (`gcc:storefront:tax-approve`, a person's command). The Saudi setup is **never** a fallback for another country (the old fallback is removed), and `gcc:storefront:currency-readiness` says which storefronts are blocked and why. 47 + 16 + 22 + 11 + 27 tests; the union's strict-mode run is 209 tests green.
- **#102, the order tax rows (`d2d130f8`, stacked on #98).** In a cart of several lines each `order_taxes` row recorded a share of the tax of its line (the divisor added the rates of every line). The rows now add up to the tax of the order, and the order and its lines are unchanged. **Historical rows are not rewritten**: `orders:tax-rows-reconcile` lists the orders whose rows differ from their tax, read-only. A report that adds the rows up will show more tax after this than before: that is the correction, and it waits for the finance review ([R6](reviews/R6-legal-finance-gcc-money.md)).
- **#103, the refund from the original payment (`d100dd01`, stacked on #101).** `RefundFromOriginalPayment` works out what a refund gives back from the gateway record of the order (amount and currency), else from the order's own record, in the decimals of the currency, exactly across several refunds, and does not move when the exchange rate does (37.50 against today's 35.00 in the test). `refunds:preview-original-payment` shows both figures for one refund request. **No refund calls it and nothing is paid**: a test fails for any other file under `app/` that refers to the service. Which of the two a refund gives back is the owner's decision.
- **#104, the Saudi baseline job (`c1aac4f7`, stacked on #97).** `Saudi baseline pack, MySQL 8` and `, MariaDB 11.4.2` run the golden answers and can be required by name; `Saudi baseline expectations unchanged` fails a pull request that edits or deletes an existing golden file unless its title carries `[saudi-baseline-change]`, written by a person.
- **Review packs R1 to R6** under [reviews/](reviews/): one short file for each review (the pull requests and heads, what changes for money or security, what to read first, what a reviewer decides). `REVIEW_GATES.md` links them. **No outcome is recorded and none was requested**: the repository's only collaborator is the owner, and an approval from Claude or from CI is never a review.

## CI read since 12:00 (per job; details in the [audit](../audits/2026-11-18-all-in-one-integration/README.md))

- #90, run 302 (`26995055`): 22 of 23 jobs green, the overlay job red by design.
- #95, run 307: one test, a Torod fixture with a fixed date; repaired on #97 and brought into #95, #91 and #104 (`177d80bc`).
- #87, run 308 (`44b53616`): the three readiness tests, on MySQL 8 and MariaDB only (a table left by an earlier class); repaired in `fd40399c`, tests only. The Storefront group (602 tests) passes on a local MySQL 8.0.46.
- #96, run 310 (`6f55384e`): the same three tests and seven of `CustomerAcrossStorefrontsTest` (`users.referred_by` is missing from the test's table; SQLite answers an unknown quoted column with a string). Repaired in `03311d46` (the class adds the column when it is missing; the base's repair is merged in); the Storefront directory (709 tests) passes on a local MySQL 8.0.46.
- Not yet read, queued: the first runs of #98 to #104 and of #87's new head, and run 324 on the integration (`706ac87c`).

## Findings for the owner

1. **A report that adds `order_taxes` up has been under-reporting** for every order of a multi-line cart since the module began; #102 corrects the rows of new orders and lists the old ones without touching them. Decide with finance whether and how old rows are corrected.
2. **A new country cannot sell before someone decides its tax**, even when everything else is ready. That is intended; the cost is one command per country once the decision exists.
3. **The refund policy across exchange rates is yours** (the original amount, or the amount at the rate of the refund day): the computation is ready and unused.
4. Still open from before: the included-tax amount is not recorded (D1-07 criterion 7), the merchant account of a refund needs the gateway accounts of D2, and E-05 / E-07 wait for the three wallet decisions.

## The three biggest gaps to a complete six-country system (from the tracker's section 3)

1. **The money of a storefront is not end to end**: the refund flow, the included tax, the invoice currency, the gateway accounts.
2. **Wallet and loyalty are still one global balance** (E-01 to E-07, J-09): nothing separates a customer's balances by market, and the ledger rows name no market, currency or company.
3. **There is no selling company in the data** beyond the registry of #90: no number series, no gateway account and no settlement by company, and the owner has not named the entities.

Behind them: the two Flutter apps (K and J: 85 criteria MISSING, verified only by the apps' own CI), per-storefront themes (F: 36), and the per-market notification, legal-text and marketing profiles (I: 95).

## Things the next session must know

1. A push to a pull request cancels the run of its previous head, and the runners are saturated. Read a run before pushing again to the same pull request when you can.
2. `git ls-files --others --exclude-standard` before every commit; and a stray `resources/lang/*/new-messages.php` line means a test process shared a working tree: restore it, never commit it.
3. A test world that opens a storefront must also approve its tax (`approveNoTaxFor()` in `tests/Support/ApprovesStorefrontTax.php`).
4. On a real engine tables persist from one test class to the next: a test that needs a column or an absent table must say so itself (`Schema::hasColumn` / `dropIfExists`), as the repairs of #87 and #96 do.
5. The tracker generator and the ledger check are in `docs/gcc/tools/`; the check must exit 0 before a push of this branch.

## Next, in order

1. Read the first runs of #98 to #104, #87 and the union per job; fix a red only on the pull request that owns it; flip the CODED criteria their green jobs prove (D1-07, D1-10, M2-01, M2-02, M2-04, E-03).
2. Close the next missing criterion of each axis the owner named: the legal readiness of a market (I2-02, I2-07), the attribution of a ledger row (E-05), the per-storefront theme (F-01), the seller's who-am-I (K-17).
3. Name the reviewers: the owner is asked once, in the final message of the session, which person answers R1 to R6.
