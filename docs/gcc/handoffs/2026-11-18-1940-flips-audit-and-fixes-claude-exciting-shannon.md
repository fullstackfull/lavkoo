# Handoff: the audit of the 76 flips, the review fixes of the seller and customer apps, seven criteria closed or closed again (2026-11-18, 19:40 UTC)

Sanitized. Nothing here is a secret or personal data. Every pull request is a **Draft**. None is merged, none is deployed, no production data or key was touched, G0 stays open, and the deferred risks DR-1 to DR-5 are still open. This continues [the handoff of 18:30](2026-11-18-1830-master-plan-claude-exciting-shannon.md) and corrects it where it says so below.

## Measured (the tracker of this commit against `b222e816`)

| | Before | After |
|---|---:|---:|
| Criteria | 1,675 | 1,675 |
| MET | 736 | **736** |
| CODED | 350 | **350** |
| MISSING | 589 | 589 |
| Code completion / proven by a passing test / release readiness | 64.8% / 43.9% / 7.7% | 64.8% / 43.9% / 7.7% |

| Movement | Count | Which |
|---|---:|---|
| MISSING to CODED | 0 | none |
| CODED to MET | **5** | K-06.1 (the key of a sale), K-06.3 (a 200 refusal is not a sale), E-11.4 (the same key, under the wallet requirement), K-V3.0 (a queued sale is never dropped silently): lavkooappseller #8, head `7c20717`, run 28; **E-05.2** (the ledger records the actor of a manual admin top-up): lavkoo #109, head `bb55ab83`, run 337 |
| MET to CODED | **5** | the audit below: I2-16.4, I2-07.2, I1-12.3, M2-04.0, F-02.3 |
| Set back by the audit and met again on a fix | 2 | K-07.4 (the rating counts approved reviews only): the audit found the card of the seller's product list still averaging every review; fixed in lavkooappseller #9 `673aa9cd`, run 26. J-08.5 (the app honours maintenance): no test could notice the dashboard branch being deleted; pinned in lavkooapp #17 `7713cd8` and #18 `4e3a895`, runs 31 and 32 |
| Evidence rewritten, state kept | 24 | see the audit |
| A whole requirement READY FOR OWNER REVIEW | 0 | no reviewer is named; **no approval was recorded by Claude or by CI** |

The three biggest remaining gaps are the same three and are measured in the tracker (section 3): the money of a storefront is not end to end, wallets and loyalty are one global balance, and there is no selling-company dimension. **A pull request or a commit count is not progress; these numbers are.**

## The audit of the 76 criteria that moved (workflow `wf_cb876f57-ad1`, read-only)

Thirteen units, two verifiers for each (one reading the CI, one reading the tests and the code), a judge for every criterion either of them flagged. **45 criteria were supported by both lenses; 31 were flagged**: 24 keep their state with evidence that now says only what ran, **6 went back to CODED** and **1 to MISSING**; two of those seven (J-08.5 and K-07.4) are met again on a fix below. Every decision, its reason and the judge's replacement text are in `docs/gcc/tools/tracker-generator/data/flips-audit-2026-11-18.json`, and the generator applies them.

What the audit found, by kind:

1. **The strict-mode job was listed as proof for tests it does not run.** Its path list holds neither `tests/Feature/Storefront` nor `tests/Feature/Address`, so what runs there has run under strict mode only locally. The shared sentence of run 342 and the texts of 16 criteria now say so.
2. **"Stamped for every order path" was false.** The storefront, currency and company are stamped by the web and API checkout and by the seller app's POS; the **admin POS, the vendor POS and the sales-channel imports do not stamp** (A1-10.3, A2-09.2, A2-09.3, F-14.2). Whether those orders count as storefront orders is an open owner decision.
3. **Tests described as run that are read as source, and local runs written next to CI.** K-17.1, E-04.3, M2-01.2, M2-01.3, F-03.2, F-03.4, D1-05.4, D1-07.5, I1-12.4, I1-13.1, I1-13.2 and others say which test runs where and which numbers are the author's.
4. **A stale statement.** I2-14.4 said the apps do not read the stop yet; the customer app does (lavkooapp #15 and #17).
5. **A sentence larger than the proof** (the seven below).

| Back to | Criterion | What the audit found | What returns it to MET |
|---|---|---|---|
| CODED | I2-16.4 (refunds, webhooks, settlements, shipments and support keep working in every stop mode) | No test runs any of them while a storefront is paused; the open-route test is not run in any one mode; callbacks and webhooks are not in its list | A continuity test per stop mode (PR 111) |
| CODED | I2-07.2 (each non-default country has a recorded independent legal review) | The mechanism is proven; **no record exists for any country**, and independence is free text | The owner records a real review for each country that is to sell |
| CODED | I1-12.3 (a KW coupon is stored, shown and redeemed in dinars) | Stored and shown are proven; **no test applies a KW coupon to a KW cart** | One apply test in a KW request (PR 87) |
| CODED | M2-04.0 (one users row signs in through any storefront) | The test never signs in; it reads the account through a token | A test that signs one customer in through two storefronts (PR 96) |
| CODED | F-02.3 (publish, restore and schedule audit lines name the storefront) | The two lines only the schedule writes carry no storefront and nothing runs `ThemePublishDue` | Pass the storefront into those lines and test the command (PR 112) |
| CODED, then met again | J-08.5 (the app honours maintenance and redirect) | No test builds the dashboard; the branch that shows the maintenance screen was read as source, so deleting it failed nothing | Done: `test/storefront_maintenance_dashboard_test.dart` pins the branch (4 mutations killed); runs 31 and 32 green. The dashboard itself is still not built by a test |
| MISSING, then fixed | K-07.4 (every average of reviews counts approved ones) | `product_widget.dart`, which the sentence names, still summed every review with a force-unwrap; the guard test looked for one spelling of the sum | Done: `673aa9cd`, three widget tests and a guard that reads every statement under `lib` |

## What was fixed and pushed (each with its CI read per step)

An independent review of the seller POS commit found a **high** defect: the POS screen ran its own cleanup on every HTTP 200, so a **refused sale emptied the priced lines and the retry recorded an order of no amount**. The fixes, with behaviour tests on the real classes (the temporary mutations of these fixes, 18 on #8, 6 on #9 and 4 on #18, are each killed by a named test):

- **lavkooappseller #8 (`8a32ac7`, `7c20717`).** The cart controller and the POS screen ask one rule (`PosOrderAnswer.isPlacedAnswer`). The outbox rebuilds its queue by sale id from what it holds when a replay ends: a sale queued during a replay is kept, a removed one stays removed, reopened ones stay reopened. The sheet says how many sales wait (never "all synced" after a retry), shows a refusal with no reason as the general message, and removes a sale only on an explicit yes; the banner's Retry reopens the failed sales when every sale has failed. **Run 28: 70 tests in the seven POS files pass in the named step; the full `Test` step ends 211 passed, 1 failed, the one failure being the known `app_version_gate_test`** (pubspec 1.0.0 against the backend minimum 2.0.1; not touched, not bypassed).
- **lavkooappseller #9 (`673aa9cd`).** A tap on a notification opens nothing while the seller is signed out (before, an old notification tapped on the login screen opened a screen that asked the server with no credential: a 401 and a session wipe over the login screen); the product card counts approved reviews only. **Run 26: 26 tests in the named step; full step 167 passed, 1 failed (the same known test).**
- **lavkooappseller #10 (`99927e8`)**, the merge of #9's head into the branch that gives every pull request a CI run: run 27, the same counts.
- **lavkooapp #18 (`8de2259`, then `4e3a895`).** A storefront change loads again only the wallet and points the customer had asked for (before: three requests for data no screen showed, on every change). **Run 30: both jobs success** (531 passed and 23 skipped with storefronts off, 309 and 11 with them on; the whole project stays at the 255 recorded findings). `4e3a895` merges #17's pin of the dashboard branch: **run 32 green** (534 and 312), and #17's own run 31 on `7713cd8` (516 and 294).

## CI read per job today (finished since the last handoff)

- **lavkoo run 348 (#114, `9f099f97`) finished: 22 of 23 jobs success**; the deployment overlay is red on a pull request run by design. The strict-mode MariaDB job and the last sales-channel job passed.
- **lavkoo run 337 (#109, `bb55ab83`, the re-run of the cancelled first attempt): 20 of 23 jobs success** when read (Tests SQLite 8.2 and 8.3, Storefront tests on MySQL 8 and MariaDB 11.4.2, Address, Fulfillment and security, the sales-channel groups that had finished), the overlay red by design, and the strict-mode job and one sales-channel MySQL job still running; they hold none of the ledger tests and are not claimed. That is the proof of **E-05.2**; E-05.1 and E-05.5 stay CODED because their sentences say every ledger row, and point-of-sale debits, points conversions and loyalty currency are not covered.
- **lavkoo run 350 (#115, `2120fa3b`, a documentation-only commit after `f5913ab7`; it replaced run 349): 21 of 23 jobs success** (Tests SQLite 8.2 and 8.3, Storefront tests on MySQL 8 and MariaDB 11.4.2, Address, Fulfillment and security, the strict-mode job), the overlay red by design, and **one sales-channel MySQL job (A-M, which holds none of these tests) still running when read; it is not claimed.**
- Not re-run yet because the account's capacity is shared by three repositories: the cancelled per-pull-request runs of #82 and #107 to #111 (the criteria they would prove are already proven by the integration run 342 or by their own earlier runs, so no criterion waits on them).

## Still running

- **The lavkoo review-fix workflow (`wf_8ee2024f-f26`)** is applying an independent review of #114 and #115 (a hash bound to approve and publish, an invalid-UTF-8 refusal, the newest-version rules, UTC display on the board, the date upper bound). **Its results are not integrated: nothing from it is pushed or claimed here.** Pushing to #114 and #115 starts new runs; the evidence of I2-01.3, I2-03.3, I2-03.4 and I2-11.4 stays tied to `9f099f97`, `f5913ab7` and `2120fa3b` until the new heads' runs are read.

## Findings of the independent review that are still open (nothing below is a high defect)

The review (nine finders, 37 findings, 17 confirmed, 3 refuted, 17 low and not verified) found one high and sixteen medium defects; the seller and customer app ones are fixed above and the backend ones (#114 and #115) are in the running workflow. What is left:

1. **UPC-A (K-05).** The app now asks the camera for the retail codes and reads a UPC-A pack as an EAN-13 with a leading zero; the server's product lookup matches the stored code exactly, so a pack stored with twelve digits is not found. That is the server's matching and is not changed here.
2. **Network failures burn the ten attempts like refusals (seller #8).** A sale that failed only because the connection did, ten times, is shown as a sale the server refused. The stored reason for a refusal that gave none is the sentinel `sale_refused`; the sheet shows the general message for it, but the stored value is the sentinel.
3. **The review count next to the approved-only rating (seller #9)** is the server's `reviews_count`; whether it counts unapproved reviews is not checked.
4. **No test of the target-to-screen mapping of a push** other than reading the source of the opener (seller #9).
5. **Customer app (#18).** The points header and the convert limit read `ProfileController`, which is not reloaded when the ledger is; a reload on every storefront change can raise an error snackbar or a 401 sign-out from a screen the customer is not on (now limited to the ledgers the customer had asked for); the test does not cover page two of a ledger or a failed reload.
6. **Backend legal texts (#114 and #115), the ones the running workflow does not take:** the sanitiser lets a backslash link pass as relative and glues the words of an unknown block tag together (a low and a medium); a concurrent draft of one type and locale is an uncaught database exception; the command lets an admin that is switched off take every step; an empty `--country`, `--type` or `--locale` makes a message with an empty code.

## Housekeeping and findings for the owner

1. **A stray file I made**: `/six-one-mysql.txt` at the root of the sandbox is 275 bytes of PHPUnit output from a scratch MySQL run (a wrong output path). It is in no repository. The environment refuses a removal there; please delete it by hand.
2. **A temporary branch** `gcc/tmp-analyzer-gate-proof` (`f73dd469`) exists in `lavkooapp`: it proved that the analyzer gate fails on a new finding. It carries no pull request. Delete it when convenient; it cannot be deleted with the tools of this session.
3. **The backend accepts an empty `modified_cart` in a POS sale** and records an order of no amount; the app no longer sends one after a refusal, but the server should refuse it (not changed here, it is POS money).
4. **A seller-app release is needed** for any of the seller fixes to reach a device (store submission is not part of this work), and **lavkoo #35 must be merged** for the Idempotency-Key to do anything: main's POS writer ignores the header.
5. **The version-gate test is still red on the seller app** and is still the owner's decision (A, B or C); nothing was done to it.
6. **83 Arabic strings still need a native reader** (the legal-text screens, the maintenance screen and the sheet of unsent sales).
7. **Reviewers for R1 to R6** are still not named; the packs are in `REVIEW_GATES.md`. Naming them is the owner's call, and an approval is recorded only from a person.
